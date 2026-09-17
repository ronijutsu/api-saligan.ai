<?php

namespace App\Services\Crawler;

use App\Models\CrawledPage;
use App\Models\LegalDigestRequest;
use App\Services\Ai\PythonAiClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Drives batched digesting: gathers the crawled authorities waiting on a digest
 * into one batch, and writes the digests when the answers land.
 *
 * Only the work nobody is watching goes through here — the nightly crawl and the
 * bulk backfill, which between them digest hundreds of pages at a time that no
 * reader has asked for yet. A digest generated because someone opened a source
 * stays inline: they are waiting on it, and a batch takes up to a day.
 *
 * The batch lifecycle (which provider, submission, polling, result parsing)
 * lives in ai-provider; this class is the thin client for its three routes and
 * keeps the queue, which is product state (ADR-011, generalised to digests).
 */
class LegalDigestBatcher
{
    public function __construct(
        private readonly LegalDigestService $digests,
        private readonly PythonAiClient $python,
    ) {
        //
    }

    /**
     * Queue a page for the next digest batch, replacing any request already
     * waiting for it. Returns null when this page has nothing to digest.
     */
    public function enqueue(CrawledPage $page, string $text): ?LegalDigestRequest
    {
        if (trim($text) === '') {
            return null;
        }

        return LegalDigestRequest::updateOrCreate(
            ['crawled_page_id' => $page->id],
            [
                'excerpt' => $this->digests->excerpt($text),
                'status' => LegalDigestRequest::STATUS_PENDING,
                'batch_id' => null,
                'error' => null,
                'submitted_at' => null,
                'completed_at' => null,
            ],
        );
    }

    /**
     * Submit the queued pages as one batch, returning its id — or null when
     * there was nothing worth sending.
     */
    public function submit(?int $limit = null): ?string
    {
        if (! $this->digests->batches()) {
            return null;
        }

        $limit ??= (int) config('saligan.crawler.digest.batch.max_requests', 200);

        $queued = LegalDigestRequest::query()
            ->pending()
            ->with('page')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($queued->isEmpty()) {
            return null;
        }

        [$requests, $submitting] = $this->buildRequests($queued);

        if ($requests === []) {
            return null;
        }

        try {
            $response = $this->python->call('/crawler/digest/batches', [
                'requests' => $requests,
            ]);
        } catch (Throwable $exception) {
            // Left pending on purpose: a failed submission is a transport
            // problem, and the next sweep should try these pages again rather
            // than abandoning them undigested.
            Log::warning('Legal digest batch could not be submitted.', [
                'requests' => count($requests),
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        $batchId = (string) ($response['batch_id'] ?? '');

        if ($batchId === '') {
            Log::warning('Legal digest batch submission returned no batch id.', [
                'requests' => count($requests),
                'response' => $response,
            ]);

            return null;
        }

        LegalDigestRequest::query()
            ->whereIn('id', $submitting)
            ->update([
                'status' => LegalDigestRequest::STATUS_SUBMITTED,
                'batch_id' => $batchId,
                'submitted_at' => now(),
                'updated_at' => now(),
            ]);

        return $batchId;
    }

    /**
     * Poll every open batch and write the digests whose answers have landed.
     *
     * Returns the number of requests closed out, answered or not.
     */
    public function collect(): int
    {
        if (! $this->digests->batches()) {
            return 0;
        }

        $batchIds = LegalDigestRequest::query()
            ->submitted()
            ->whereNotNull('batch_id')
            ->distinct()
            ->pluck('batch_id');

        $closed = 0;

        foreach ($batchIds as $batchId) {
            $closed += $this->collectBatch((string) $batchId);
        }

        return $closed;
    }

    /**
     * Build one batch request per queued page, failing the ones that can no
     * longer be digested.
     *
     * @param  Collection<int, LegalDigestRequest>  $queued
     * @return array{0: array<int, array{custom_id: string, text: string, title: ?string, kind: string}>, 1: array<int, int>}
     */
    protected function buildRequests(Collection $queued): array
    {
        $requests = [];
        $submitting = [];

        foreach ($queued as $request) {
            $page = $request->page;

            if ($page === null) {
                $request->markFailed('The page no longer exists.');

                continue;
            }

            $requests[] = [
                'custom_id' => $request->customId(),
                'text' => (string) $request->excerpt,
                'title' => $page->title,
                // Only the bulk producers batch, and they digest authorities;
                // a case brief is written on demand, inline.
                'kind' => 'authority',
            ];

            $submitting[] = $request->id;
        }

        return [$requests, $submitting];
    }

    /**
     * Poll one batch. A batch still running is left alone; an ended one is read
     * to completion and every request in it closed out.
     */
    protected function collectBatch(string $batchId): int
    {
        try {
            $status = $this->python->get("/crawler/digest/batches/{$batchId}");
        } catch (Throwable $exception) {
            // A batch the provider no longer knows about is a dead letter: the
            // pages behind it will never be answered.
            if ($exception instanceof RequestException && $exception->response->status() === 404) {
                return $this->failRemaining($batchId, 'The batch is no longer available.');
            }

            Log::warning('Legal digest batch could not be polled.', [
                'batch_id' => $batchId,
                'exception' => $exception->getMessage(),
            ]);

            return 0;
        }

        $state = (string) ($status['status'] ?? '');

        // A failed batch is terminal but has no results to read: close every
        // request behind it out here so none sits submitted forever.
        if ($state === 'failed') {
            return $this->failRemaining($batchId, 'The batch failed before this page was digested.');
        }

        if ($state !== 'ended') {
            return 0;
        }

        try {
            $response = $this->python->get("/crawler/digest/batches/{$batchId}/results");
        } catch (Throwable $exception) {
            Log::warning('Legal digest batch results could not be read.', [
                'batch_id' => $batchId,
                'exception' => $exception->getMessage(),
            ]);

            return 0;
        }

        $requests = LegalDigestRequest::query()
            ->submitted()
            ->where('batch_id', $batchId)
            ->with('page')
            ->get()
            ->keyBy(fn (LegalDigestRequest $request): string => $request->customId());

        $closed = 0;

        foreach ($response['results'] ?? [] as $result) {
            if (! is_array($result)) {
                continue;
            }

            $request = $requests->get((string) ($result['custom_id'] ?? ''));

            if ($request === null) {
                continue;
            }

            $this->applyResult($request, $result);
            $closed++;
        }

        // A request the batch never answered for. Rare, but it must not sit
        // submitted forever: closing it out leaves the page undigested, which
        // the reader already handles by falling back to full text.
        $closed += $this->failRemaining($batchId, 'The batch ended without a result for this page.');

        return $closed;
    }

    /**
     * Write one result to its page.
     *
     * @param  array<string, mixed>  $result
     */
    protected function applyResult(LegalDigestRequest $request, array $result): void
    {
        $status = (string) ($result['status'] ?? 'errored');

        if ($status !== 'succeeded') {
            $request->markFailed(match ($status) {
                'expired' => 'The batch expired before this page was digested.',
                'cancelled' => 'The batch was cancelled.',
                default => 'The model returned an error for this page.',
            });

            return;
        }

        $page = $request->page;

        if ($page === null) {
            $request->markFailed('The page no longer exists.');

            return;
        }

        $digest = $this->digests->read((string) ($result['digest'] ?? ''));

        // NO_DIGEST is a real answer: the model read an index or an error page
        // rather than an authority. The request is done, and the page keeps no
        // digest — re-queueing it would just buy the same answer again.
        if ($digest === null) {
            $request->markSucceeded();

            return;
        }

        $page->forceFill([
            'digest' => $digest,
            'digest_generated_at' => now(),
        ])->save();

        $request->markSucceeded();
    }

    /**
     * Close out every request still waiting on a batch that will not answer it,
     * returning how many there were.
     */
    protected function failRemaining(string $batchId, string $reason): int
    {
        $stranded = LegalDigestRequest::query()
            ->submitted()
            ->where('batch_id', $batchId)
            ->get();

        foreach ($stranded as $request) {
            $request->markFailed($reason);
        }

        return $stranded->count();
    }
}
