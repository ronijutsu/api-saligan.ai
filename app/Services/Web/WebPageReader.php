<?php

namespace App\Services\Web;

use App\Exceptions\ScannedPdf;
use App\Exceptions\UnreadableWebPage;
use App\Exceptions\WebPageProcessing;
use App\Services\Crawler\GenericAdapter;
use App\Services\Crawler\LegalDigestService;
use App\Services\Crawler\PdfAdapter;
use App\Services\Documents\DocumentChunker;
use App\Support\OutboundUrl;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Reads a page the assistant cited from the web, so it can be opened in the
 * reader with a digest and its cited passages marked — the same treatment a
 * crawled authority or an uploaded document gets.
 *
 * Nothing about the page is stored: a web citation only ever carries a title,
 * a link and a search snippet, and the page belongs to someone else. It is
 * fetched on first read and held in the cache, so a popular source is digested
 * once rather than once per reader.
 */
class WebPageReader
{
    /** The most of a web page that is read. Full decisions run to megabytes. */
    private const MAX_BYTES = 2097152;

    /**
     * The most of a PDF that is read. Regulations are routinely published as
     * PDFs, and a truncated one cannot be parsed at all, so this is generous.
     */
    private const MAX_PDF_BYTES = 16777216;

    private const CACHE_HOURS = 12;

    /** Fragments shorter than this are too generic to place a snippet by. */
    private const MIN_FRAGMENT = 20;

    /** A span needs this many distinctive words, on the span and the page, to be placed. */
    private const MIN_TERMS = 3;

    /** The share of a span's weighted vocabulary a passage must hold to count as its source. */
    private const MIN_SHARE = 0.5;

    /**
     * The share of a span's distinctive words a passage must hold, by count.
     * Weighting alone lets a few words every passage shares ("tax", "payment")
     * place a span whose specific words the page never uses.
     */
    private const MIN_COVERAGE = 0.6;

    /** What a word the page never uses costs a passage, against one it does. */
    private const UNKNOWN_TERM_WEIGHT = 0.5;

    private const MAX_PER_SPAN = 3;

    private const COMMON_WORDS = [
        'the', 'and', 'for', 'that', 'with', 'from', 'this', 'are', 'was', 'were', 'has', 'have',
        'had', 'been', 'will', 'shall', 'may', 'can', 'not', 'its', 'their', 'which', 'when',
        'where', 'who', 'all', 'any', 'each', 'such', 'under', 'into', 'upon', 'than', 'then',
        'also', 'other', 'more', 'must', 'these', 'those', 'they', 'them', 'you', 'your', 'our',
    ];

    public function __construct(
        private readonly LegalDigestService $digests,
        private readonly DocumentChunker $chunker,
        private readonly GenericAdapter $adapter,
        private readonly PdfAdapter $pdfAdapter,
        private readonly LegalCrawlerClient $crawler,
    ) {}

    /**
     * The page's text as passages, with a digest.
     *
     * @return array{title: string|null, digest: string|null, chunks: array<int, string>}
     *
     * @throws UnreadableWebPage When the page cannot be read, with the reason.
     * @throws WebPageProcessing When a scanned PDF is still being read with OCR.
     */
    public function read(string $url): array
    {
        $key = 'web-page-read:'.sha1($url);
        $scanKey = 'web-page-scanned:'.sha1($url);

        $cached = Cache::get($key);

        if (is_array($cached) && isset($cached['unreadable'])) {
            throw new UnreadableWebPage($cached['unreadable']);
        }

        if (is_array($cached)) {
            return $cached;
        }

        // A scan already recognised as one goes straight back to the crawler:
        // downloading it again to find out it is still a scan would be the
        // whole cost of every poll.
        if (Cache::has($scanKey)) {
            return $this->remember($key, $this->viaCrawler($url));
        }

        try {
            $page = $this->fetch($url);
        } catch (ScannedPdf $scan) {
            Cache::put($scanKey, true, now()->addHour());

            return $this->remember($key, $this->viaCrawler($url, $scan));
        } catch (UnreadableWebPage $exception) {
            // Remembered briefly so reopening a bad link does not fetch it
            // again, but not for long: a site that was down comes back.
            Cache::put($key, ['unreadable' => $exception->getMessage()], now()->addHour());

            throw $exception;
        }

        return $this->remember($key, $page);
    }

    /**
     * @param  array{title: string|null, digest: string|null, chunks: array<int, string>}  $page
     * @return array{title: string|null, digest: string|null, chunks: array<int, string>}
     */
    protected function remember(string $key, array $page): array
    {
        Cache::put($key, $page, now()->addHours(self::CACHE_HOURS));

        return $page;
    }

    /**
     * Hand a scanned PDF to the crawler, which downloads it and runs OCR.
     *
     * OCR outlasts a request, so this starts the capture the first time and
     * reports where it stands on every later call.
     *
     * @return array{title: string|null, digest: string|null, chunks: array<int, string>}
     *
     * @throws UnreadableWebPage
     * @throws WebPageProcessing
     */
    protected function viaCrawler(string $url, ?ScannedPdf $scan = null): array
    {
        if (! $this->crawler->enabled()) {
            throw $scan ?? new ScannedPdf;
        }

        try {
            $capture = $this->crawler->capture($url);
        } catch (Throwable) {
            throw $scan ?? new ScannedPdf;
        }

        $status = $capture['status'] ?? 'failed';

        if (in_array($status, ['queued', 'running'], true)) {
            throw new WebPageProcessing;
        }

        if ($status !== 'done') {
            throw match ($capture['error_code'] ?? null) {
                'too_many_pages' => UnreadableWebPage::tooLong(),
                'blocked', 'unreachable' => UnreadableWebPage::unreachable(),
                'not_pdf' => UnreadableWebPage::unsupported(),
                default => UnreadableWebPage::unrecognised(),
            };
        }

        $text = trim((string) preg_replace('/^\s*---\s*$/m', '', (string) ($capture['text'] ?? '')));
        $chunks = $this->chunker->chunk($text);

        if ($chunks === []) {
            throw UnreadableWebPage::unrecognised();
        }

        return [
            'title' => filled($capture['title'] ?? null) ? Str::limit((string) $capture['title'], 180) : null,
            'digest' => filled($capture['digest'] ?? null) ? (string) $capture['digest'] : null,
            'chunks' => $chunks,
        ];
    }

    /**
     * Which passages of the page the search snippets came from.
     *
     * A snippet is what the search backed with the page, and is usually the
     * model's paraphrase of it rather than a quote — so it is matched by
     * meaning-bearing words, not by string. Each span (snippets are elided
     * with "…") is looked for verbatim first; failing that, every passage is
     * scored on how much of the span's vocabulary it holds, rare words and
     * figures counting for more than common ones, and the passages that hold
     * most of it are the cited ones. A span nothing on the page resembles
     * marks nothing, rather than the least-bad passage.
     *
     * @param  array<int, string>  $chunks
     * @param  array<int, string>  $snippets
     * @return array<int, int>
     */
    public function citedIndexes(array $chunks, array $snippets): array
    {
        if ($chunks === []) {
            return [];
        }

        $haystacks = array_map(fn (string $chunk): string => $this->flatten($chunk), $chunks);
        $vocabularies = array_map(fn (string $chunk): array => array_flip($this->terms($chunk)), $chunks);
        $weights = $this->weights($vocabularies);
        $cited = [];

        foreach ($snippets as $snippet) {
            $spans = array_filter(
                array_map(fn (string $part): string => $this->flatten($part), preg_split('/…|\.{3}/u', $snippet) ?: []),
                fn (string $span): bool => mb_strlen($span) >= self::MIN_FRAGMENT,
            );

            foreach ($spans as $span) {
                $verbatim = array_keys(array_filter($haystacks, fn (string $haystack): bool => str_contains($haystack, $span)));

                foreach ($verbatim === [] ? $this->closestChunks($span, $vocabularies, $weights) : $verbatim as $index) {
                    $cited[$index] = true;
                }
            }
        }

        $indexes = array_keys($cited);
        sort($indexes);

        return $indexes;
    }

    /**
     * @return array{title: string|null, digest: string|null, chunks: array<int, string>}
     *
     * @throws UnreadableWebPage
     */
    protected function fetch(string $url): array
    {
        if (! OutboundUrl::isFetchable($url)) {
            throw UnreadableWebPage::unreachable();
        }

        try {
            $response = Http::timeout(max(1, (int) config('saligan.web_search.read_timeout', 10)))
                ->withHeaders(['User-Agent' => config('saligan.crawler.user_agent')])
                // The url came from a search result, so every hop is vetted
                // rather than followed on trust — a public page is free to
                // redirect to an internal address.
                ->withOptions([
                    'stream' => true,
                    'allow_redirects' => [
                        'max' => 5,
                        'strict' => true,
                        'referer' => false,
                        'protocols' => ['http', 'https'],
                        'on_redirect' => function ($request, $response, $uri): void {
                            if (! OutboundUrl::isFetchable((string) $uri)) {
                                throw new RuntimeException("Refused to follow a redirect to a non-public address: {$uri}");
                            }
                        },
                    ],
                ])
                ->get($url);
        } catch (Throwable) {
            throw UnreadableWebPage::unreachable();
        }

        if (! $response->successful()) {
            throw UnreadableWebPage::unreachable();
        }

        if (! $this->isReadable($response)) {
            throw UnreadableWebPage::unsupported();
        }

        try {
            $body = $this->body($response);
        } catch (Throwable) {
            throw UnreadableWebPage::unreachable();
        }

        if ($body === null) {
            throw UnreadableWebPage::unsupported();
        }

        $isPdf = str_starts_with($body, '%PDF-');

        $parsed = $isPdf
            ? $this->pdfAdapter->parse($body, $url)
            : $this->adapter->parse($this->toUtf8($body), $url);

        $text = trim((string) preg_replace('/\n+/', "\n\n", $parsed->text));

        if ($text === '') {
            throw $isPdf ? new ScannedPdf : UnreadableWebPage::empty();
        }

        return [
            'title' => $parsed->title !== '' ? Str::limit($parsed->title, 180) : null,
            'digest' => $this->digests->generate($text, $parsed->title !== '' ? $parsed->title : null),
            'chunks' => $this->chunker->chunk($text),
        ];
    }

    protected function isReadable(Response $response): bool
    {
        $type = strtolower($response->header('Content-Type'));

        // Servers often label a PDF as octet-stream, so the bytes decide
        // whether it is one; only types that are plainly something else
        // (images, video, archives) are turned away here.
        return $type === ''
            || str_contains($type, 'html')
            || str_contains($type, 'text/plain')
            || str_contains($type, 'pdf')
            || str_contains($type, 'octet-stream');
    }

    /**
     * The body, read in full up to the cap for its kind. A stream hands back
     * whatever has arrived rather than what was asked for, so a single read
     * would silently truncate a page. Null when a PDF exceeds its cap, since a
     * cut-off PDF cannot be parsed.
     */
    protected function body(Response $response): ?string
    {
        $stream = $response->toPsrResponse()->getBody();
        $body = '';

        while (! $stream->eof() && strlen($body) < self::MAX_PDF_BYTES) {
            $body .= $stream->read(65536);

            if (! str_starts_with($body, '%PDF-') && strlen($body) >= self::MAX_BYTES) {
                break;
            }
        }

        if (str_starts_with($body, '%PDF-') && ! $stream->eof()) {
            return null;
        }

        return $body;
    }

    protected function toUtf8(string $body): string
    {
        return mb_check_encoding($body, 'UTF-8')
            ? $body
            : mb_convert_encoding($body, 'UTF-8', 'Windows-1252');
    }

    protected function flatten(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }

    /**
     * How much a word says about where it appears: the fewer passages hold it,
     * the more it points at any one of them.
     *
     * @param  array<int, array<string, int>>  $vocabularies
     * @return array<string, float>
     */
    protected function weights(array $vocabularies): array
    {
        $frequency = [];

        foreach ($vocabularies as $vocabulary) {
            foreach ($vocabulary as $term => $_) {
                $frequency[$term] = ($frequency[$term] ?? 0) + 1;
            }
        }

        $total = count($vocabularies);

        return array_map(fn (int $count): float => log(1 + $total / $count), $frequency);
    }

    /**
     * The passages holding most of a span's vocabulary, best first, or none
     * when even the best holds too little of it to be where it came from.
     *
     * @param  array<int, array<string, int>>  $vocabularies
     * @param  array<string, float>  $weights
     * @return array<int, int>
     */
    protected function closestChunks(string $span, array $vocabularies, array $weights): array
    {
        $wanted = $this->terms($span);

        if (count($wanted) < self::MIN_TERMS) {
            return [];
        }

        // A word the page never uses is the model's own wording; it should
        // cost a passage some of its score, but never all of it.
        $unknown = array_sum(array_map(fn (string $term): float => isset($weights[$term]) ? 0.0 : 1.0, $wanted));
        $possible = array_sum(array_map(fn (string $term): float => $weights[$term] ?? 0.0, $wanted)) + $unknown * self::UNKNOWN_TERM_WEIGHT;
        $scores = [];

        foreach ($vocabularies as $index => $vocabulary) {
            $matched = array_values(array_filter($wanted, fn (string $term): bool => isset($vocabulary[$term])));

            if (count($matched) >= self::MIN_TERMS && count($matched) / count($wanted) >= self::MIN_COVERAGE && $possible > 0) {
                $scores[$index] = array_sum(array_map(fn (string $term): float => $weights[$term], $matched)) / $possible;
            }
        }

        arsort($scores);
        $best = reset($scores);

        if ($best === false || $best < self::MIN_SHARE) {
            return [];
        }

        return array_slice(
            array_keys(array_filter($scores, fn (float $score): bool => $score >= max(self::MIN_SHARE, $best * 0.85))),
            0,
            self::MAX_PER_SPAN,
        );
    }

    /**
     * The words of a text that say something about it: lowercased, stripped
     * of the words every sentence has, and trimmed to a stem so "registers"
     * and "registered" meet. Figures and identifiers are kept whole.
     *
     * @return array<int, string>
     */
    protected function terms(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+(?:[.\-][\p{N}]+)*/u', mb_strtolower($text), $matches);

        $terms = [];

        foreach ($matches[0] as $word) {
            $hasDigit = preg_match('/\d/', $word) === 1;

            if (! $hasDigit && (mb_strlen($word) < 3 || in_array($word, self::COMMON_WORDS, true))) {
                continue;
            }

            $terms[$hasDigit ? $word : $this->stem($word)] = true;
        }

        return array_keys($terms);
    }

    protected function stem(string $word): string
    {
        foreach (['ations', 'ation', 'ing', 'ed', 'es', 's'] as $suffix) {
            if (str_ends_with($word, $suffix) && mb_strlen($word) - mb_strlen($suffix) >= 4) {
                return mb_substr($word, 0, mb_strlen($word) - mb_strlen($suffix));
            }
        }

        return $word;
    }
}
