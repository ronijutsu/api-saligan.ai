<?php

namespace App\Jobs;

use App\Models\CrawledPage;
use App\Models\LegalSource;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Bring an authority the assistant cited from the web into the knowledge base.
 *
 * Answers grounded in a web search link out to the original site. Capturing
 * that page means the next person to cite the same decision reads it inside
 * Batayan, with a digest and the cited passage highlighted, instead of being
 * handed off to the source site.
 *
 * Deliberately asynchronous: the answer has already been delivered from the
 * search result, so crawling can happen after the fact. Doing it during the
 * turn would add seconds to every web-grounded answer and let a slow source
 * site fail in the user's face.
 */
class CaptureCitedLegalPage implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public readonly string $url) {}

    /**
     * Hosts this job will fetch.
     *
     * The URL comes from a web-search result, which is model-influenced input,
     * so it is never fetched on trust. Only the official Philippine legal
     * publishers the knowledge base is already built from are allowed — this
     * job must not become a way to make the server fetch arbitrary URLs.
     *
     * @return array<int, string>
     */
    public static function allowedHosts(): array
    {
        return [
            'elibrary.judiciary.gov.ph',
            'sc.judiciary.gov.ph',
            'lawphil.net',
            'www.lawphil.net',
            'officialgazette.gov.ph',
            'www.officialgazette.gov.ph',
            'dar.gov.ph',
            'www.dar.gov.ph',
            'denr.gov.ph',
            'lra.gov.ph',
            'bir.gov.ph',
            'www.bir.gov.ph',
            'senate.gov.ph',
            'www.senate.gov.ph',
            'congress.gov.ph',
            'www.congress.gov.ph',
            'ca.judiciary.gov.ph',
            'dole.gov.ph',
            'www.dole.gov.ph',
            'sec.gov.ph',
            'www.sec.gov.ph',
            'doj.gov.ph',
            'www.doj.gov.ph',
            'privacy.gov.ph',
            'www.privacy.gov.ph',
            'dhsud.gov.ph',
            'www.dhsud.gov.ph',
            'ipophil.gov.ph',
            'www.ipophil.gov.ph',
            'www.denr.gov.ph',
        ];
    }

    /**
     * Whether a URL is worth capturing: an allowed host, and not already held.
     */
    public static function shouldCapture(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '' || ! in_array($host, self::allowedHosts(), true)) {
            return false;
        }

        return ! CrawledPage::query()->where('url', $url)->exists();
    }

    public function handle(): void
    {
        if (! config('saligan.crawler.enabled')) {
            return;
        }

        if (! self::shouldCapture($this->url)) {
            return;
        }

        $host = strtolower((string) parse_url($this->url, PHP_URL_HOST));

        // Attach the page to the source that owns its domain, so it inherits
        // that source's recrawl schedule and shows up in the admin listing
        // beside everything else from the same publisher.
        $source = LegalSource::query()
            ->where('base_domain', $host)
            ->orWhere('base_domain', preg_replace('/^www\./', '', $host))
            ->first();

        if ($source === null) {
            Log::info('Skipped capturing a cited page: no legal source owns its domain.', [
                'url' => $this->url,
                'host' => $host,
            ]);

            return;
        }

        // Depth 0: capture this page only. Following its links would pull an
        // unbounded slice of the site in behind a single citation.
        CrawlLegalSourcePage::dispatch($source, $this->url, 0)
            ->onQueue(config('saligan.crawler.queue'));
    }
}
