<?php

namespace App\Services\Documents;

use App\Exceptions\DocumentProcessingException;

/**
 * The per-document ingestion caps.
 *
 * Every chunk becomes a halfvec(768) row plus an HNSW entry — measured at
 * roughly 3.6 kB — and every chunk is one more thing to embed. Without a
 * ceiling, a single well-formed 25 MB PDF is enough to add ~210 MB of vectors
 * and ~3,600 embedding requests, so these limits turn "one document" into a
 * bounded cost rather than an open one.
 *
 * The messages are user-facing: they say what happened and what to do instead,
 * because "the document could not be processed" gives the uploader nothing to
 * act on.
 */
class DocumentIngestLimits
{
    /**
     * Refuse text that is longer than the extracted-character ceiling before it
     * is chunked, which is far cheaper than chunking it and then measuring.
     */
    public function assertTextSize(string $text): void
    {
        $limit = $this->maxExtractedCharacters();
        $length = mb_strlen($text);

        if ($length <= $limit) {
            return;
        }

        throw new DocumentProcessingException(sprintf(
            'This document is too large to index: it contains about %s characters and the limit is %s. '
            .'Split it into smaller files, or upload only the relevant sections.',
            number_format($length),
            number_format($limit),
        ));
    }

    /**
     * Refuse a document that chunks into more passages than the ceiling.
     *
     * Checked separately from the character count because paragraph-aware
     * chunking and overlap mean the two are not the same number.
     */
    public function assertChunkCount(int $chunks): void
    {
        $limit = $this->maxChunksPerDocument();

        if ($chunks <= $limit) {
            return;
        }

        throw new DocumentProcessingException(sprintf(
            'This document is too long to index: it would create %s passages and the limit is %s. '
            .'Split it into smaller files, or upload only the relevant sections.',
            number_format($chunks),
            number_format($limit),
        ));
    }

    /**
     * Refuse a scan with more pages than the OCR ceiling, since vision OCR runs
     * and bills per page.
     */
    public function assertOcrPages(int $pages): void
    {
        $limit = $this->maxOcrPages();

        if ($pages <= $limit) {
            return;
        }

        throw new DocumentProcessingException(sprintf(
            'This scan is too long to read: it has %s pages and the limit is %s. '
            .'Upload the relevant pages, or a text-based PDF/DOCX instead.',
            number_format($pages),
            number_format($limit),
        ));
    }

    public function maxExtractedCharacters(): int
    {
        return max(1, (int) config('saligan.documents.max_extracted_characters', 2_500_000));
    }

    public function maxChunksPerDocument(): int
    {
        return max(1, (int) config('saligan.documents.max_chunks_per_document', 5000));
    }

    public function maxOcrPages(): int
    {
        return max(1, (int) config('saligan.documents.max_ocr_pages', 50));
    }
}
