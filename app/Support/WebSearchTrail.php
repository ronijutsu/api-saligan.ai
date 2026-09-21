<?php

namespace App\Support;

/**
 * Shapes the delegated web search's progress into frames the UI can animate.
 *
 * A grounded search is the longest silent stretch of a chat turn: the model
 * stops producing tokens, a second model goes and reads the web, and until it
 * comes back the user has a spinner and a sentence. The sites being read are
 * the most interesting thing happening in the product at that moment, so they
 * are streamed as they are known and cleared the instant the search ends.
 *
 * These frames are transient by contract. They are not citations — the
 * citation cards are emitted separately and persist on the message — and the
 * client is expected to drop the whole trail on `done`. A site that appears
 * here has been read; only the ones that survive into a citation were used.
 */
final class WebSearchTrail
{
    /**
     * The trail's frame for a search that has just been issued.
     *
     * @return array<string, mixed>
     */
    public static function start(string $query): array
    {
        return ['phase' => 'start', 'query' => self::clip($query)];
    }

    /**
     * The pages grounding returned, before any of them has been identified.
     * At this point all that is known is the host, which is why the UI shows
     * domains first and titles a beat later.
     *
     * @param  array<int, array{url: string, title: string|null, snippet?: string|null}>  $citations
     * @return array<string, mixed>
     */
    public static function reading(array $citations): array
    {
        return ['phase' => 'reading', 'sources' => array_values(array_filter(array_map(
            static fn (array $citation): ?array => self::pendingRow($citation),
            $citations,
        )))];
    }

    /**
     * A row for a page that has not been fetched yet. A grounding redirect
     * names the redirector rather than the site, so its host is replaced by
     * the publisher domain the search titled it with — or the row is left out
     * when it gave none, rather than showing the redirector as if it were
     * being read.
     *
     * @param  array{url?: string, title?: string|null}  $citation
     * @return array<string, mixed>|null
     */
    private static function pendingRow(array $citation): ?array
    {
        $url = $citation['url'] ?? null;
        $row = self::row($url, null, null);

        if ($row === null || ! WebSourceResolver::isRedirector((string) $url)) {
            return $row;
        }

        $title = trim((string) ($citation['title'] ?? ''));

        if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $title) !== 1) {
            return null;
        }

        return ['domain' => preg_replace('/^www\./i', '', $title)] + $row;
    }

    /**
     * The same pages once each has been fetched and named by its own title.
     *
     * @param  array<int, array{index: int, url: string, title: string|null}>  $sources
     * @return array<string, mixed>
     */
    public static function read(array $sources): array
    {
        return ['phase' => 'read', 'sources' => array_values(array_filter(array_map(
            static fn (array $source): ?array => self::row(
                $source['url'] ?? null,
                $source['title'] ?? null,
                isset($source['index']) ? (int) $source['index'] : null,
            ),
            $sources,
        )))];
    }

    /**
     * @return array<string, mixed>
     */
    public static function done(int $count): array
    {
        return ['phase' => 'done', 'count' => $count];
    }

    /**
     * A search that could not be run or came back with nothing. The trail
     * still closes — the UI must never be left with a list of sites under a
     * finished answer.
     *
     * @return array<string, mixed>
     */
    public static function failed(string $reason): array
    {
        return ['phase' => 'done', 'count' => 0, 'reason' => $reason];
    }

    /**
     * One row of the trail: the host is what the UI leads with, the title
     * fills in when it is known, and the index ties the row to the citation
     * card it becomes.
     *
     * @return array<string, mixed>|null
     */
    private static function row(?string $url, ?string $title, ?int $index): ?array
    {
        if (! is_string($url) || $url === '' || WebSourceResolver::isAsset($url)) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return [
            'url' => $url,
            'domain' => is_string($host) ? preg_replace('/^www\./', '', $host) : null,
            'title' => is_string($title) && trim($title) !== '' ? self::clip(trim($title), 120) : null,
            'index' => $index,
        ];
    }

    private static function clip(string $text, int $width = 90): string
    {
        return mb_strimwidth($text, 0, $width, '…');
    }
}
