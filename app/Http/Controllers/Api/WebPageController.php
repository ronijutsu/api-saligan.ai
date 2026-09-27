<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\UnreadableWebPage;
use App\Exceptions\WebPageProcessing;
use App\Http\Controllers\Controller;
use App\Services\Web\WebPageReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebPageController extends Controller
{
    /**
     * A page the assistant cited from the web, for reading inside the app.
     *
     * The snippets are what the search returned for this page; the passages
     * they came from are reported so the reader can mark them as cited.
     */
    public function read(Request $request, WebPageReader $reader): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'url:http,https', 'max:2048'],
            'snippets' => ['sometimes', 'array', 'max:10'],
            'snippets.*' => ['string', 'max:2000'],
        ]);

        try {
            $page = $reader->read($validated['url']);
        } catch (WebPageProcessing $processing) {
            return response()->json([
                'status' => 'processing',
                'message' => $processing->getMessage(),
                'retry_after' => $processing->retryAfter,
            ], 202);
        } catch (UnreadableWebPage $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'url' => $validated['url'],
                'title' => $page['title'],
                'digest' => $page['digest'],
                'has_digest' => filled($page['digest']),
                'chunks' => collect($page['chunks'])->map(fn (string $content, int $index): array => [
                    'id' => "web-{$index}",
                    'index' => $index,
                    'content' => $content,
                ])->values(),
                'cited_chunk_indexes' => $reader->citedIndexes($page['chunks'], $validated['snippets'] ?? []),
            ],
        ]);
    }
}
