<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Exceptions\DocumentProcessingException;
use App\Jobs\Middleware\LimitUserIngestConcurrency;
use App\Models\AiUsage;
use App\Models\Document;
use App\Services\Ai\EmbeddingService;
use App\Services\Billing\AiBudget;
use App\Services\Billing\AiCosting;
use App\Services\Documents\DocumentChunker;
use App\Services\Documents\DocumentClassifier;
use App\Services\Documents\DocumentIndexQuota;
use App\Services\Documents\DocumentIngestLimits;
use App\Services\Documents\ImageOcrExtractor;
use App\Services\Documents\StoredFiles;
use App\Services\Documents\TextExtractor;
use App\Support\PlanFeatures;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ProcessDocumentUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 900;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60, 300];

    public function __construct(public readonly Document $document)
    {
        //
    }

    /**
     * Guard against two workers ingesting the same document at once, and
     * against one account occupying the whole document-processing pool.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new LimitUserIngestConcurrency((int) ($this->document->user_id ?? 0)),
            (new WithoutOverlapping('document:'.$this->document->id))
                ->releaseAfter(60)
                ->expireAfter(600),
        ];
    }

    /**
     * Extract, chunk, and embed the uploaded document.
     */
    public function handle(
        TextExtractor $extractor,
        ImageOcrExtractor $ocr,
        DocumentChunker $chunker,
        EmbeddingService $embeddings,
        StoredFiles $files,
        DocumentClassifier $classifier,
        ?DocumentIngestLimits $limits = null,
        ?DocumentIndexQuota $quota = null,
    ): void {
        // Resolved lazily so the job's collaborators stay injectable in tests
        // that call handle() directly with the original argument list.
        $limits ??= app(DocumentIngestLimits::class);
        $quota ??= app(DocumentIndexQuota::class);

        $document = $this->document;

        if ($document->status === DocumentStatus::Ready) {
            return;
        }

        $document->update([
            'status' => DocumentStatus::Processing,
            'error_message' => null,
        ]);

        $mimeType = $document->mime_type ?? '';

        // Reading a scan and filing it into the case are what document
        // intelligence buys; extracting a text layer and embedding it are not.
        // A plan without the feature still ingests every text-based upload in
        // full — it simply does not get the model-powered half.
        $readsScans = $document->user !== null
            && PlanFeatures::has($document->user, PlanFeatures::DOCUMENT_INTELLIGENCE);

        // The extractors need a real local path. Encrypted documents are
        // decrypted, and documents on an object store downloaded, into a
        // temporary file that is removed as soon as extraction completes, so
        // plaintext never lingers on disk.
        $copy = $files->localCopy($document->storage_path);
        $ocrRan = false;
        $ocrPages = 0;

        try {
            if ($this->isImage($mimeType) && $readsScans) {
                // An image is a single page, and the ceiling is checked before
                // the model runs: refusing after paying for the transcription
                // would defeat the point of the ceiling.
                $ocrPages = 1;
                $limits->assertOcrPages($ocrPages);

                $text = $ocr->extract($copy->path, $mimeType);
                $ocrRan = true;
            } else {
                // Markdown, not flat text: these chunks are what the citation
                // reader shows, and a source is far easier to read — and to
                // find a cited passage in — with its own headings and lists
                // intact.
                $text = $extractor->extractMarkdown($copy->path, $mimeType);
            }

            // A scanned PDF has no text layer, so the parser returns nothing.
            // The pages are images, which is exactly what the OCR model reads,
            // so fall through to it rather than rejecting the upload.
            if ($readsScans && trim($this->sanitizeText($text)) === '' && ImageOcrExtractor::handles($mimeType) && ! $this->isImage($mimeType)) {
                $ocrPages = $extractor->pageCount($copy->path, $mimeType);
                $limits->assertOcrPages($ocrPages);

                $text = $ocr->extract($copy->path, $mimeType);
                $ocrRan = true;
            }
        } finally {
            $copy->discard();
        }

        // Extracted text (especially OCR output from photos) can carry invalid
        // UTF-8 byte sequences. Sanitize it so the subsequent embedding and
        // retrieval requests never fail with a "Malformed UTF-8" json_encode
        // error and so chunks are always stored as valid UTF-8.
        $text = $this->sanitizeText($text);

        if (trim($text) === '') {
            throw new DocumentProcessingException(match (true) {
                // Almost certainly a scan. Saying "upload a clearer copy" would
                // send them to fix a file that is not the problem — their plan
                // is, and that is something they can act on.
                ! $readsScans => 'This file has no text layer, so it can only be read by scanning it. Upgrade your plan to read scanned and photographed documents.',
                $this->isImage($mimeType) => 'No text could be read from the image. Upload a clearer image or a PDF/DOCX version.',
                default => 'No text could be read from this file, including by scanning it. Upload a clearer copy or a text-based PDF/DOCX version.',
            });
        }

        // Refuse oversized text before chunking it: measuring the characters is
        // O(1)-ish next to splitting 26M of them into passages.
        $limits->assertTextSize($text);

        $chunks = $chunker->chunk(
            $text,
            config('saligan.documents.chunk_size'),
            config('saligan.documents.chunk_overlap'),
        );

        if ($chunks === []) {
            throw new DocumentProcessingException('The extracted text produced no chunks.');
        }

        $limits->assertChunkCount(count($chunks));

        $this->embedAndStore($chunks, $embeddings, $quota);

        // File the document into the case file. This runs before the document
        // is marked ready so it lands already sorted, and it never throws: a
        // failed suggestion leaves the document unfiled, which is a state the
        // Unfiled queue already handles — and the same state a plan without
        // document intelligence leaves every upload in, to be filed by hand.
        if ($readsScans) {
            $classifier->classify($document, $text);
        }

        $document->update(['status' => DocumentStatus::Ready]);

        // Settle the upload's hold with what ingestion measurably spent.
        // Embeddings are measured from the extracted length; OCR is charged per
        // page and classification is a modeled flat add-on. Queue-level retries
        // share the one reservation — only this success settles it.
        $this->settleIngestUsage($document, $text, $ocrRan, $ocrPages, $readsScans);
    }

    /**
     * Embed and store the document's passages.
     *
     * Passage embedding is the expensive half of ingestion, and it is the half
     * a worker kill is most likely to interrupt. So it runs in segments: each
     * segment is embedded, then written in one transaction with multi-row
     * inserts. A retry finds the finished segments already persisted and skips
     * them, which is what stops a timeout from re-embedding — and re-paying
     * for — the whole document. It is also what makes the update itself cheap:
     * 58,000 single-row inserts each maintaining the HNSW index is minutes of
     * writes, while the same rows in batches of 500 is a fraction of that.
     *
     * @param  array<int, string>  $chunks
     */
    protected function embedAndStore(
        array $chunks,
        EmbeddingService $embeddings,
        DocumentIndexQuota $quota,
    ): void {
        $document = $this->document;
        $chunkCount = count($chunks);

        // Passages left over from a longer previous attempt would otherwise
        // linger past the end of the document.
        $document->chunks()->where('chunk_index', '>=', $chunkCount)->delete();

        $existing = $document->chunks()->pluck('chunk_index')->all();
        $have = array_flip(array_map('intval', $existing));

        $pending = [];

        for ($index = 0; $index < $chunkCount; $index++) {
            if (! isset($have[$index])) {
                $pending[] = $index;
            }
        }

        // Capacity is checked against the passages that are actually missing,
        // so a resumed retry is not asked to pay for room it already holds.
        if ($document->user !== null) {
            $quota->assertCanIndex($document->user, count($pending));
        }

        // The index must hold one model's vectors. Checked before a single
        // vector is requested, because the damage is silent: a same-dimension
        // model switch inserts happily and only degrades retrieval later.
        $embeddings->assertModelMatchesIndex(
            Document::query()
                ->whereNotNull('embedding_model')
                ->latest('updated_at')
                ->value('embedding_model'),
        );

        if ($pending === []) {
            return;
        }

        $document->forceFill(['embedding_model' => $embeddings->model()])->save();

        $segmentSize = max(1, (int) config('saligan.documents.embed_segment_chunks', 512));
        $batchSize = max(1, (int) config('saligan.documents.insert_batch_size', 500));

        foreach (array_chunk($pending, $segmentSize) as $segment) {
            $vectors = $embeddings->embedMany(
                array_map(fn (int $index): string => $chunks[$index], $segment),
            );

            if (count($vectors) !== count($segment)) {
                throw new RuntimeException(sprintf(
                    'Embedding count mismatch: %d chunks in, %d vectors out.',
                    count($segment),
                    count($vectors),
                ));
            }

            $rows = [];
            $now = now();

            foreach ($segment as $offset => $index) {
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'document_id' => $document->id,
                    'user_id' => $document->user_id,
                    'chunk_index' => $index,
                    'content' => $chunks[$index],
                    // pgvector's input format is the JSON array syntax, which is
                    // what the model's `array` cast already relies on. Kept
                    // explicit here because a multi-row insert bypasses casts.
                    'embedding' => json_encode($vectors[$offset], JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::transaction(function () use ($rows, $batchSize): void {
                foreach (array_chunk($rows, $batchSize) as $batch) {
                    DB::table('document_chunks')->insert($batch);
                }
            });
        }
    }

    protected function settleIngestUsage(
        Document $document,
        string $text,
        bool $ocrRan,
        int $ocrPages,
        bool $readsScans,
    ): void {
        if ($document->ai_usage_id === null) {
            return;
        }

        $reservation = AiUsage::query()->find($document->ai_usage_id);

        if ($reservation === null) {
            return;
        }

        $cost = AiCosting::embeddingCostUsd(AiCosting::estimateTokens($text));

        // Priced by the page, not per upload: a 300-page scan costs what 300
        // pages of vision OCR cost, which is the whole reason the page ceiling
        // exists.
        if ($ocrRan) {
            $cost += AiCosting::ocrCostUsd($ocrPages);
        }

        if ($readsScans) {
            $cost += AiCosting::CLASSIFY_ADDON_USD;
        }

        try {
            AiBudget::settle($reservation, [
                'cost_usd' => $cost,
                'attempts' => $this->attempts(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Whether the MIME type represents a bitmap image handled by the OCR
     * pipeline rather than the text extractors.
     */
    protected function isImage(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'image/');
    }

    /**
     * Replace invalid UTF-8 byte sequences so extracted text never breaks the
     * json_encode of downstream HTTP requests (embedding, chat retrieval) and
     * stored chunks are always valid UTF-8.
     *
     * mb_convert_encoding() between two 'UTF-8' encodings is a no-op for
     * malformed input, so instead we scrub with mb_scrub() (which replaces
     * invalid sequences) and explicitly drop null bytes, which Postgres
     * rejects outright and which no mb function will remove.
     */
    protected function sanitizeText(string $text): string
    {
        $text = str_replace("\0", '', $text);

        return mb_scrub($text, 'UTF-8');
    }

    /**
     * Mark the document as failed once retries are exhausted. The real
     * exception is logged; only a safe, user-facing message is persisted.
     */
    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            report($exception);
        }

        $message = $exception instanceof DocumentProcessingException
            ? $exception->getMessage()
            : 'The document could not be processed. Please try uploading it again or contact support.';

        $this->document->update([
            'status' => DocumentStatus::Failed,
            'error_message' => $message,
        ]);

        // A failed ingestion bills nothing: release the hold so the retry —
        // which reserves anew — is the single spend for this document. The
        // passages already persisted are left in place: they are paid for, and
        // the retry resumes from them rather than re-embedding the document.
        if ($this->document->ai_usage_id !== null) {
            $reservation = AiUsage::query()->find($this->document->ai_usage_id);

            if ($reservation !== null) {
                try {
                    AiBudget::release($reservation, failed: true);
                } catch (Throwable $releaseException) {
                    report($releaseException);
                }
            }
        }
    }
}
