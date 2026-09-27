<?php

namespace App\Services\Documents;

use App\Services\Ai\PythonAiClient;

/**
 * Reads the text out of an image or a scanned PDF.
 *
 * The vision call runs in ai-provider (`POST /documents/ocr`); this class is
 * the boundary the ingestion pipeline talks to, so a provider swap never
 * reaches the jobs that call it.
 */
class ImageOcrExtractor
{
    public function __construct(
        private readonly PythonAiClient $python,
    ) {}

    /**
     * Whether this extractor can read the given file. Images and PDFs are both
     * handed to the vision model directly — a scanned PDF carries no text
     * layer, so the page images are the only thing to read.
     */
    public static function handles(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'image/') || $mimeType === 'application/pdf';
    }

    /**
     * Transcribe the text contained in a local image or PDF using a
     * vision-capable model.
     */
    public function extract(string $fullPath, string $mimeType): string
    {
        return trim((string) ($this->python->ocr($fullPath, $mimeType)['text'] ?? ''));
    }
}
