<?php

use App\Ai\Tools\WebSearchTool;
use App\Ai\WebResearchAgent;
use App\Services\Chat\ChatService;
use App\Support\WebSearchCollector;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\UrlCitation;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Tools\Request;

/**
 * A fake search response carrying the grounding metadata the real Gemini
 * response would, since it is the citations — not the text — that become the
 * source cards.
 */
function fakeSearch(string $text, array $sources): TextResponse
{
    return new TextResponse($text, new Usage, new Meta(
        'gemini',
        'gemini-3.6-flash',
        new Collection(array_map(
            fn (array $source) => new UrlCitation($source[0], $source[1]),
            $sources,
        )),
    ));
}

it('answers a search with the sources the grounded model returned', function () {
    WebResearchAgent::fake([
        fakeSearch('Rule 70 Section 1 gives one year from last demand.', [
            ['https://lawphil.net/rule-70', 'LawPhil — Rule 70'],
            ['https://sc.judiciary.gov.ph/gr-12345', 'G.R. No. 12345'],
        ]),
    ]);

    $collector = new WebSearchCollector;

    $result = json_decode(
        (new WebSearchTool($collector))->handle(new Request(['query' => 'unlawful detainer prescriptive period'])),
        true,
    );

    expect($result['findings'])->toBe('Rule 70 Section 1 gives one year from last demand.')
        ->and($result['sources'])->toBe([
            ['cite_as' => '[Web 1]', 'title' => 'LawPhil — Rule 70', 'url' => 'https://lawphil.net/rule-70'],
            ['cite_as' => '[Web 2]', 'title' => 'G.R. No. 12345', 'url' => 'https://sc.judiciary.gov.ph/gr-12345'],
        ])
        ->and($collector->all())->toBe([
            ['url' => 'https://lawphil.net/rule-70', 'title' => 'LawPhil — Rule 70'],
            ['url' => 'https://sc.judiciary.gov.ph/gr-12345', 'title' => 'G.R. No. 12345'],
        ]);
});

it('runs the search on the configured flash model', function () {
    config()->set('saligan.web_search.provider', 'gemini');
    config()->set('saligan.web_search.model', 'gemini-3.6-flash');

    WebResearchAgent::fake([fakeSearch('Findings.', [['https://lawphil.net/ra-6657', 'RA 6657']])]);

    (new WebSearchTool(new WebSearchCollector))->handle(new Request(['query' => 'RA 6657 coverage']));

    WebResearchAgent::assertPrompted(function (AgentPrompt $prompt): bool {
        expect($prompt->provider->name())->toBe(Lab::Gemini->value)
            ->and($prompt->model)->toBe('gemini-3.6-flash')
            ->and($prompt->agent->tools())->toHaveCount(1);

        return $prompt->prompt === 'RA 6657 coverage';
    });
});

it('hands the model the page each source actually is', function () {
    Http::fake([
        'vertexaisearch.cloud.google.com/*' => Http::response('', 302, ['Location' => 'https://elibrary.judiciary.gov.ph/showdocs/186204']),
        'elibrary.judiciary.gov.ph/*' => Http::response(
            '<html><head><title>G.R. No. 186204 - Spouses Javier v. Spouses De Guzman</title></head></html>',
        ),
    ]);

    WebResearchAgent::fake([
        fakeSearch('A later case applying Depra v. Dumlao.', [
            ['https://vertexaisearch.cloud.google.com/grounding-api-redirect/AUZIYQ', 'judiciary.gov.ph'],
        ]),
    ]);

    $collector = new WebSearchCollector;

    $result = json_decode(
        (new WebSearchTool($collector))->handle(new Request(['query' => 'builder in good faith Article 448'])),
        true,
    );

    // Without this the model is told only "judiciary.gov.ph", which is what
    // let it label a later case with the name of the case that case quotes.
    expect($result['sources'][0]['title'])->toBe('G.R. No. 186204 - Spouses Javier v. Spouses De Guzman')
        ->and($collector->all()[0]['title'])->toBe('G.R. No. 186204 - Spouses Javier v. Spouses De Guzman');
});

it('keeps a source at the number it was first given across searches', function () {
    WebResearchAgent::fake([
        fakeSearch('First.', [['https://lawphil.net/ra-6657', 'RA 6657']]),
        fakeSearch('Second.', [
            ['https://lawphil.net/ra-6657', 'RA 6657'],
            ['https://officialgazette.gov.ph/ra-9700', 'RA 9700'],
        ]),
    ]);

    $collector = new WebSearchCollector;
    $tool = new WebSearchTool($collector);

    $tool->handle(new Request(['query' => 'RA 6657 coverage']));

    $second = json_decode($tool->handle(new Request(['query' => 'was RA 6657 amended'])), true);

    expect(array_column($second['sources'], 'cite_as'))->toBe(['[Web 1]', '[Web 2]'])
        ->and($collector->count())->toBe(2);
});

it('drains recorded sources once for live emission', function () {
    WebResearchAgent::fake([fakeSearch('Findings.', [['https://lawphil.net/ra-6657', 'RA 6657']])]);

    $collector = new WebSearchCollector;

    (new WebSearchTool($collector))->handle(new Request(['query' => 'RA 6657 coverage']));

    expect($collector->pull())->toBe([['url' => 'https://lawphil.net/ra-6657', 'title' => 'RA 6657']])
        ->and($collector->pull())->toBe([])
        ->and($collector->all())->toHaveCount(1);
});

it('reports an empty search without citable sources', function () {
    WebResearchAgent::fake([fakeSearch('Nothing on point was found.', [])]);

    $collector = new WebSearchCollector;

    $result = json_decode(
        (new WebSearchTool($collector))->handle(new Request(['query' => 'a query with no answer'])),
        true,
    );

    expect($result['sources'])->toBe([])
        ->and($result['findings'])->toContain('do not cite the web')
        ->and($collector->count())->toBe(0);
});

it('survives a failed search instead of failing the answer', function () {
    WebResearchAgent::fake(function () {
        throw new RuntimeException('Gemini is down');
    });

    $result = json_decode(
        (new WebSearchTool(new WebSearchCollector))->handle(new Request(['query' => 'RA 6657 coverage'])),
        true,
    );

    expect($result['sources'])->toBe([])
        ->and($result['findings'])->toContain('could not be completed');
});

it('caps the searches one answer may run', function () {
    config()->set('saligan.web_search.max_searches', 2);

    WebResearchAgent::fake(fn () => fakeSearch('Findings.', [['https://lawphil.net/ra-6657', 'RA 6657']]));

    $tool = new WebSearchTool(new WebSearchCollector);

    $tool->handle(new Request(['query' => 'first']));
    $tool->handle(new Request(['query' => 'second']));

    $third = json_decode($tool->handle(new Request(['query' => 'third'])), true);

    expect($third['sources'])->toBe([])
        ->and($third['findings'])->toContain('limit');
});

it('rejects an empty query without spending a search', function () {
    WebResearchAgent::fake()->preventStrayPrompts();

    $result = json_decode(
        (new WebSearchTool(new WebSearchCollector))->handle(new Request(['query' => '   '])),
        true,
    );

    expect($result['sources'])->toBe([]);
});

it('persists the delegated tool sources onto the message', function () {
    $collector = new WebSearchCollector;

    $collector->record([
        ['url' => 'https://lawphil.net/ra-6657', 'title' => 'RA 6657'],
    ]);

    $chat = new class($collector) extends ChatService
    {
        public function __construct(WebSearchCollector $collector)
        {
            $this->webSearchCitations = $collector;
        }

        public function extractWebCitations(StreamedAgentResponse $response): array
        {
            return $this->webCitations($response);
        }
    };

    $response = new StreamedAgentResponse('invocation', new Collection, new Meta('anthropic', 'claude-sonnet-5'));

    expect($chat->extractWebCitations($response))->toBe([
        ['url' => 'https://lawphil.net/ra-6657', 'title' => 'RA 6657'],
    ]);
});

it('never hands back an image or an unresolvable redirect as a source', function () {
    Http::fake(['*' => Http::response('unreachable', 503)]);

    WebResearchAgent::fake([
        fakeSearch('Findings.', [
            ['https://example.com/uploads/social-card.webp', 'example.com'],
            ['https://vertexaisearch.cloud.google.com/grounding-api-redirect/AUZIYQ', 'judiciary.gov.ph'],
            ['https://lawphil.net/ra-6657', 'RA 6657'],
        ]),
    ]);

    $collector = new WebSearchCollector;

    $result = json_decode(
        (new WebSearchTool($collector))->handle(new Request(['query' => 'RA 6657 coverage'])),
        true,
    );

    expect($result['sources'])->toHaveCount(1)
        ->and($result['sources'][0]['url'])->toBe('https://lawphil.net/ra-6657')
        ->and($result['sources'][0]['cite_as'])->toBe('[Web 1]')
        ->and($collector->count())->toBe(1);
});
