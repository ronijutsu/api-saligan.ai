<?php

namespace App\Services\Documents;

use App\Models\DocumentClassificationRequest;
use App\Models\Label;
use App\Services\Ai\PythonAiClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Drives batched document classification: gathers the documents waiting to be
 * filed into one batch, and files them when the answers land.
 *
 * The two halves run on their own schedules — submitting is cheap and can wait
 * for a worthwhile batch to accumulate, while collecting only needs to keep up
 * with batches ending.
 *
 * The batch lifecycle (which provider, submission, polling, result parsing)
 * lives in ai-provider; this class is the thin client for its three routes and
 * keeps the queue, which is product state (ADR-011).
 */
class DocumentClassificationBatcher
{
    public function __construct(
        private readonly DocumentClassifier $classifier,
        private readonly PythonAiClient $python,
    ) {
        //
    }

    /**
     * Submit the queued documents as one batch, returning its id — or null
     * when there was nothing worth sending.
     */
    public function submit(?int $limit = null): ?string
    {
        if (! $this->classifier->batches()) {
            return null;
        }

        $limit ??= (int) config('saligan.documents.classification.batch.max_requests', 500);

        $queued = DocumentClassificationRequest::query()
            ->pending()
            ->with('document.user')
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
            $response = $this->python->call('/documents/classify/batches', [
                'requests' => $requests,
            ]);
        } catch (Throwable $exception) {
            // Left pending on purpose: a failed submission is a transport
            // problem, and the next sweep should try these documents again
            // rather than abandoning them unfiled.
            Log::warning('Document classification batch could not be submitted.', [
                'requests' => count($requests),
                'exception' => $exception->getMessage(),
                'response' => $this->responseBody($exception),
            ]);

            return null;
        }

        $batchId = (string) ($response['batch_id'] ?? '');

        if ($batchId === '') {
            Log::warning('Document classification batch submission returned no batch id.', [
                'requests' => count($requests),
                'response' => $response,
            ]);

            return null;
        }

        DocumentClassificationRequest::query()
            ->whereIn('id', $submitting)
            ->update([
                'status' => DocumentClassificationRequest::STATUS_SUBMITTED,
                'batch_id' => $batchId,
                'submitted_at' => now(),
                'updated_at' => now(),
            ]);

        return $batchId;
    }

    /**
     * Poll every open batch and file the documents whose answers have landed.
     *
     * Returns the number of requests closed out, answered or not.
     */
    public function collect(): int
    {
        if (! $this->classifier->batches()) {
            return 0;
        }

        $batchIds = DocumentClassificationRequest::query()
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
     * Build one batch request per queued document, failing the ones that can
     * no longer be classified.
     *
     * @param  Collection<int, DocumentClassificationRequest>  $queued
     * @return array{0: array<int, array{custom_id: string, filename: string, title: string, text: string, vocabulary: array<int, array{slug: string, name: string, description: ?string}>}>, 1: array<int, int>}
     */
    protected function buildRequests(Collection $queued): array
    {
        $requests = [];
        $submitting = [];

        foreach ($queued as $request) {
            $document = $request->document;

            if ($document === null) {
                $request->markFailed('The document no longer exists.');

                continue;
            }

            $vocabulary = $this->classifier->vocabularyFor($document);

            if ($vocabulary->isEmpty()) {
                $request->markFailed('No categories are available to file this document under.');

                continue;
            }

            $requests[] = [
                'custom_id' => $request->customId(),
                'filename' => (string) $document->original_filename,
                'title' => (string) $document->title,
                'text' => (string) $request->excerpt,
                'vocabulary' => $vocabulary->map(fn (Label $label): array => [
                    'slug' => $label->slug,
                    'name' => $label->name,
                    'description' => $label->description,
                ])->values()->all(),
            ];

            $submitting[] = $request->id;
        }

        return [$requests, $submitting];
    }

    /**
     * Poll one batch. A batch still running is left alone; an ended one is
     * read to completion and every request in it closed out.
     */
    protected function collectBatch(string $batchId): int
    {
        try {
            $status = $this->python->get("/documents/classify/batches/{$batchId}");
        } catch (Throwable $exception) {
            // A batch the provider no longer knows about — both providers
            // expire results eventually — is a dead letter: the documents
            // behind it will never be answered.
            if ($exception instanceof RequestException && $exception->response->status() === 404) {
                return $this->failRemaining($batchId, 'The batch is no longer available.');
            }

            Log::warning('Document classification batch could not be polled.', [
                'batch_id' => $batchId,
                'exception' => $exception->getMessage(),
                'response' => $this->responseBody($exception),
            ]);

            return 0;
        }

        $state = (string) ($status['status'] ?? '');

        // A failed batch is terminal but has no results to read: every request
        // behind it is closed out here so none sits submitted forever.
        if ($state === 'failed') {
            return $this->failRemaining($batchId, 'The batch failed before this document was classified.');
        }

        if ($state !== 'ended') {
            return 0;
        }

        try {
            $response = $this->python->get("/documents/classify/batches/{$batchId}/results");
        } catch (Throwable $exception) {
            Log::warning('Document classification batch results could not be read.', [
                'batch_id' => $batchId,
                'exception' => $exception->getMessage(),
                'response' => $this->responseBody($exception),
            ]);

            return 0;
        }

        $requests = DocumentClassificationRequest::query()
            ->submitted()
            ->where('batch_id', $batchId)
            ->with('document')
            ->get()
            ->keyBy(fn (DocumentClassificationRequest $request): string => $request->customId());

        $closed = 0;

        foreach ($response['results'] ?? [] as $result) {
            if (! is_array($result)) {
                continue;
            }

            $customId = (string) ($result['custom_id'] ?? '');
            $request = $requests->get($customId);

            if ($request === null) {
                continue;
            }

            $this->applyResult($request, $result);
            $closed++;
        }

        // A request the batch never answered for. Rare, but it must not sit
        // submitted forever: closing it out leaves the document unfiled in the
        // Unfiled queue, where a person can still file it.
        $closed += $this->failRemaining($batchId, 'The batch ended without a result for this document.');

        return $closed;
    }

    /**
     * Apply one result to its document.
     *
     * @param  array<string, mixed>  $result
     */
    protected function applyResult(DocumentClassificationRequest $request, array $result): void
    {
        $status = (string) ($result['status'] ?? 'errored');

        if ($status !== 'succeeded') {
            $request->markFailed(match ($status) {
                'expired' => 'The batch expired before this document was classified.',
                'cancelled' => 'The batch was cancelled.',
                default => 'The model returned an error for this document.',
            });

            return;
        }

        $document = $request->document;

        if ($document === null) {
            $request->markFailed('The document no longer exists.');

            return;
        }

        try {
            $this->classifier->apply($document, $this->categoriesFrom($result));

            $request->markSucceeded();
        } catch (Throwable $exception) {
            Log::warning('A classified document could not be filed.', [
                'document_id' => $document->id,
                'exception' => $exception->getMessage(),
            ]);

            $request->markFailed('The answer could not be applied.');
        }
    }

    /**
     * The categories out of a succeeded result, as `apply()` reads them.
     *
     * @param  array<string, mixed>  $result
     * @return array<int, array{slug: string, confidence: float}>
     */
    protected function categoriesFrom(array $result): array
    {
        $categories = $result['categories'] ?? [];

        if (! is_array($categories)) {
            return [];
        }

        $candidates = [];

        foreach ($categories as $category) {
            if (! is_array($category) || ! isset($category['slug'])) {
                continue;
            }

            $candidates[] = [
                'slug' => (string) $category['slug'],
                'confidence' => (float) ($category['confidence'] ?? 0.0),
            ];
        }

        return $candidates;
    }

    /**
     * The provider's error body, when the failure was an HTTP error. The
     * exception message alone only says "HTTP request returned status code
     * 400" — the reason is in the body, and a log that cannot see it is a log
     * that cannot be acted on.
     */
    protected function responseBody(Throwable $exception): ?string
    {
        return $exception instanceof RequestException
            ? $exception->response->body()
            : null;
    }

    /**
     * Close out every request still waiting on a batch that will not answer
     * it, returning how many there were.
     */
    protected function failRemaining(string $batchId, string $reason): int
    {
        $stranded = DocumentClassificationRequest::query()
            ->submitted()
            ->where('batch_id', $batchId)
            ->get();

        foreach ($stranded as $request) {
            $request->markFailed($reason);
        }

        return $stranded->count();
    }
}
