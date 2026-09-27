<?php

namespace App\Services\Web;

use Illuminate\Support\Facades\Http;

/**
 * The legal crawler's on-demand capture service: downloads a PDF and, when it
 * is a scan, runs it through OCR. Started once per URL and polled — asking
 * again reports where the capture stands rather than starting another.
 */
class LegalCrawlerClient
{
    public function enabled(): bool
    {
        return filled(config('saligan.legal_crawler.url')) && filled(config('saligan.legal_crawler.secret'));
    }

    /**
     * @return array{status: string, title?: string|null, text?: string|null, digest?: string|null, ocr_used?: bool, page_count?: int|null, error_code?: string|null}
     */
    public function capture(string $url): array
    {
        return Http::baseUrl((string) config('saligan.legal_crawler.url'))
            ->acceptJson()
            ->withToken((string) config('saligan.legal_crawler.secret'))
            ->connectTimeout(5)
            ->timeout((int) config('saligan.legal_crawler.timeout', 15))
            ->post('/captures', ['url' => $url])
            ->throw()
            ->json();
    }
}
