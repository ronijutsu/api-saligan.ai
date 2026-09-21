<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Citation as CitationData;
use Laravel\Ai\Streaming\Events\Citation;
use Laravel\Ai\Streaming\Events\ProviderToolEvent;
use Laravel\Ai\Streaming\Events\StreamEvent;

/**
 * Normalizes web-search citations the provider grounds the answer in, shared
 * by the streaming path (live "citation" SSE events) and the persistence path
 * (the web_citations metadata stored on the message) so both agree on what a
 * citation is, how duplicates are merged, and how cards are numbered.
 *
 * Gemini exposes citations as grounding metadata on the streamed response
 * meta. Anthropic surfaces them as Citation events and, for URLs cited
 * without an attached location, as the raw results of the
 * web_search_tool_result provider tool events.
 */
final class WebCitationParser
{
    /**
     * Normalize the citations carried by a streamed response's meta
     * (Gemini grounding metadata).
     *
     * @return array<int, array{url: string, title: string|null, snippet?: string|null}>
     */
    public static function fromMeta(Collection $citations): array
    {
        $items = [];

        foreach ($citations as $citation) {
            if (! $citation instanceof CitationData) {
                continue;
            }

            $url = $citation->url ?? null;

            if (! is_string($url) || $url === '' || WebSourceResolver::isAsset($url)) {
                continue;
            }

            $items[] = [
                'url' => $url,
                'title' => is_string($citation->title ?? null) ? $citation->title : null,
            ];
        }

        return $items;
    }

    /**
     * Normalize the citations carried by a single stream event: an
     * Anthropic/OpenRouter Citation event, or the raw results of a
     * web_search_tool_result provider tool event.
     *
     * @return array<int, array{url: string, title: string|null, snippet?: string|null}>
     */
    public static function fromEvent(StreamEvent $event): array
    {
        if ($event instanceof Citation) {
            $url = $event->citation->url ?? null;

            if (! is_string($url) || $url === '' || WebSourceResolver::isAsset($url)) {
                return [];
            }

            return [[
                'url' => $url,
                'title' => is_string($event->citation->title ?? null) ? $event->citation->title : null,
            ]];
        }

        if ($event instanceof ProviderToolEvent && $event->type === 'web_search_tool_result') {
            $items = [];

            foreach ($event->data['search_results'] ?? [] as $result) {
                $url = $result['url'] ?? null;

                if (! is_string($url) || $url === '' || WebSourceResolver::isAsset($url)) {
                    continue;
                }

                $items[] = [
                    'url' => $url,
                    'title' => is_string($result['title'] ?? null) ? $result['title'] : null,
                    'snippet' => is_string($result['snippet'] ?? null) ? $result['snippet'] : null,
                ];
            }

            return $items;
        }

        return [];
    }

    /**
     * Merge normalized citation items into a deduplicated map keyed by url,
     * preserving first-seen order. The snippet is merged into an already-seen
     * url rather than overwriting it.
     *
     * @param  array<int, array{url: string, title: string|null, snippet?: string|null}>  $items
     * @return array<string, array{url: string, title: string|null, snippet?: string|null}>
     */
    public static function merge(array $items): array
    {
        $seen = [];

        foreach ($items as $item) {
            $url = $item['url'];

            if (isset($seen[$url])) {
                $seen[$url]['snippet'] ??= $item['snippet'] ?? null;

                continue;
            }

            $seen[$url] = $item;
        }

        return $seen;
    }

    /**
     * The source-shaped entry the UI renders for a single citation, numbered
     * in first-seen order — the same numbering MessageSources applies to the
     * persisted web_citations metadata, so live and reloaded cards line up.
     *
     * @param  array{url: string, title: string|null, snippet?: string|null}  $citation
     * @return array<string, mixed>
     */
    public static function source(array $citation, int $index): array
    {
        return [
            'type' => 'web',
            'index' => $index,
            'label' => $citation['title'],
            'title' => $citation['title'],
            'source_name' => null,
            'url' => $citation['url'],
            'domain' => parse_url($citation['url'], PHP_URL_HOST) ?: null,
            'excerpt' => isset($citation['snippet']) && is_string($citation['snippet'])
                ? Str::limit($citation['snippet'], 300)
                : null,
        ];
    }
}
