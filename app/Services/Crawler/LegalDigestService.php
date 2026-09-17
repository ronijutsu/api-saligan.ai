<?php

namespace App\Services\Crawler;

use App\Services\Ai\PythonAiClient;
use Throwable;

/**
 * Generates the short digest shown at the top of a source in the reader.
 *
 * Digests are produced once, at crawl time, and stored on the page — every
 * reader of a given case sees the same digest, so generating it per view would
 * repeat identical work and put a model call in the path of opening a source.
 *
 * The model call runs in ai-provider (`POST /crawler/digest`); this class owns
 * the excerpt it sends and the answer it is willing to store, and is the
 * boundary the read path and the crawl both talk to (ADR-011).
 */
class LegalDigestService
{
    /**
     * Roughly how much of the document the model is given. Philippine Supreme
     * Court decisions run long; the opening carries the caption, parties, and
     * facts, and the closing carries the disposition, so both ends are sent
     * rather than a single truncated prefix.
     */
    private const HEAD_CHARS = 14000;

    private const TAIL_CHARS = 6000;

    /**
     * The providers ai-provider can batch digests on. Kept in step with the
     * backends it implements: enqueueing work nothing will collect leaves a page
     * undigested forever rather than merely late.
     *
     * @var array<int, string>
     */
    protected const BATCH_PROVIDERS = ['gemini'];

    public function __construct(
        private readonly PythonAiClient $python,
    ) {}

    /**
     * A digest for the given authority, or null when one could not be produced.
     *
     * Never throws: a missing digest degrades the reader to full text, which is
     * still perfectly usable, so a model outage must not fail the crawl that
     * carries the actual legal text.
     */
    public function generate(string $text, ?string $title = null): ?string
    {
        return $this->digest($text, $title, 'authority');
    }

    /**
     * Generate a living brief from the complete contents of a case.
     */
    public function generateCase(string $text): ?string
    {
        return $this->digest($text, null, 'case');
    }

    /**
     * Run one digest through ai-provider. The prompt itself is built there from
     * the excerpt, title and kind, so the inline and batched writers cannot
     * drift apart.
     */
    protected function digest(string $text, ?string $title, string $kind): ?string
    {
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        try {
            $response = $this->python->call('/crawler/digest', [
                'text' => $this->excerpt($text),
                'title' => $title,
                'kind' => $kind,
            ]);

            return $this->read((string) ($response['digest'] ?? ''));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The head and tail of a long document, joined by an elision marker so the
     * model is not led into treating the two halves as contiguous. Also what a
     * queued digest stores: the queue must not carry a whole decision's text.
     */
    public function excerpt(string $text): string
    {
        if (mb_strlen($text) <= self::HEAD_CHARS + self::TAIL_CHARS) {
            return $text;
        }

        return mb_substr($text, 0, self::HEAD_CHARS)
            ."\n\n[... middle of the document omitted ...]\n\n"
            .mb_substr($text, -self::TAIL_CHARS);
    }

    /**
     * A model answer as a stored digest, or null when there is nothing worth
     * storing. NO_DIGEST is the model saying the page was an index or an error
     * page rather than an authority; an empty answer means the same thing.
     */
    public function read(string $answer): ?string
    {
        $digest = trim($answer);

        return $digest === '' || $digest === 'NO_DIGEST' ? null : $digest;
    }

    /**
     * Whether digests should be batched rather than written inline.
     *
     * Batching only applies to work nobody is waiting on — the crawl and the
     * bulk backfill. A digest generated on first read stays inline whatever
     * this says: a reader is watching, and a batch takes up to a day.
     */
    public function batches(): bool
    {
        if (! config('saligan.crawler.digest.batch.enabled', false)) {
            return false;
        }

        $provider = (string) config('saligan.crawler.digest.provider', 'gemini');

        return in_array($provider, self::BATCH_PROVIDERS, true);
    }
}
