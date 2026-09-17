<?php

use App\Enums\KnowledgeType;
use App\Models\CrawledPage;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\LegalCase;
use App\Models\LegalChunk;
use App\Models\LegalSource;
use App\Models\User;
use App\Services\Ai\EmbeddingService;
use App\Services\Retrieval\RetrievalResult;
use App\Services\Retrieval\RetrievalService;
use App\Support\CitationTokens;
use Illuminate\Support\Facades\Http;
use Mockery;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('retrieves legal context and the users own document chunks by similarity', function () {
    $source = LegalSource::factory()->create(['base_domain' => 'lawphil.net']);
    $page = CrawledPage::factory()->for($source)->create();
    $legalChunk = LegalChunk::factory()->for($page)->create([
        'content' => 'Comprehensive Agrarian Reform Program coverage rules.',
        'embedding' => array_fill(0, 768, 1.0),
    ]);

    $document = Document::factory()->for($this->user)->create();
    $docChunk = DocumentChunk::factory()->for($document)->for($this->user)->create([
        'content' => 'My notes on agrarian reform.',
        'embedding' => array_fill(0, 768, 1.0),
    ]);

    $otherUser = User::factory()->create();
    $otherDocument = Document::factory()->for($otherUser)->create();
    DocumentChunk::factory()->for($otherDocument)->for($otherUser)->create([
        'embedding' => array_fill(0, 768, 1.0),
    ]);

    Http::fake([
        '*/api/embed' => Http::response(['embeddings' => [array_fill(0, 768, 1.0)]], 200),
        // The Python provider answers the same query-vector request on its own
        // route; faking both keeps the test honest whichever engine serves it.
        '*/embeddings' => Http::response(['embeddings' => [array_fill(0, 768, 1.0)]], 200),
    ]);

    $result = app(RetrievalService::class)->retrieve($this->user, 'agrarian reform');

    expect($result->legalChunks)->toHaveCount(1)
        ->and($result->legalChunks->first()->id)->toBe($legalChunk->id)
        ->and($result->documentChunks)->toHaveCount(1)
        ->and($result->documentChunks->first()->id)->toBe($docChunk->id)
        ->and($result->isEmpty())->toBeFalse()
        ->and($result->legalChunkIds())->toBe([$legalChunk->id])
        ->and($result->documentChunkIds())->toBe([$docChunk->id]);
});

it('filters chunks below the minimum similarity threshold', function () {
    $page = CrawledPage::factory()->create();
    LegalChunk::factory()->for($page)->create([
        'content' => 'Unrelated civil procedure text.',
        'embedding' => array_fill(0, 768, -1.0),
    ]);

    Http::fake([
        '*/api/embed' => Http::response(['embeddings' => [array_fill(0, 768, 1.0)]], 200),
        // The Python provider answers the same query-vector request on its own
        // route; faking both keeps the test honest whichever engine serves it.
        '*/embeddings' => Http::response(['embeddings' => [array_fill(0, 768, 1.0)]], 200),
    ]);

    $result = app(RetrievalService::class)->retrieve($this->user, 'agrarian reform');

    expect($result->isEmpty())->toBeTrue();
});

it('separates standard chunks from legal chunks and applies the base standard limit', function () {
    config([
        'saligan.retrieval.base_max_standard_chunks' => 2,
        'saligan.retrieval.base_max_legal_chunks' => 4,
    ]);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')
        ->once()
        ->with('standards and law')
        ->andReturn(array_fill(0, 768, 1.0));
    $this->app->instance(EmbeddingService::class, $embedding);

    $legalPage = CrawledPage::factory()->create([
        'knowledge_type' => KnowledgeType::Legal,
    ]);
    $legalChunk = LegalChunk::factory()->for($legalPage)->create([
        'content' => 'Philippine legal authority.',
        'embedding' => array_fill(0, 768, 1.0),
    ]);

    $standardPage = CrawledPage::factory()->standard()->create();
    $standardChunks = LegalChunk::factory()->count(3)->for($standardPage)->create([
        'embedding' => array_fill(0, 768, 1.0),
    ]);

    $result = app(RetrievalService::class)->retrieve($this->user, 'standards and law');

    expect($result->legalChunks->pluck('id')->all())->toBe([$legalChunk->id])
        ->and($result->standardChunks)->toHaveCount(2)
        ->and($result->standardChunks->pluck('id')->diff($standardChunks->pluck('id')))->toBeEmpty()
        ->and($result->standardChunks->pluck('id'))->not->toContain($legalChunk->id);
});

it('applies the deep-research standard retrieval limit', function () {
    config([
        'saligan.retrieval.base_max_standard_chunks' => 1,
        'saligan.retrieval.max_standard_chunks' => 3,
    ]);
    $this->user->forceFill(['is_admin' => true])->save();

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')
        ->once()
        ->with('deep standards research')
        ->andReturn(array_fill(0, 768, 1.0));
    $this->app->instance(EmbeddingService::class, $embedding);

    $standardPage = CrawledPage::factory()->standard()->create();
    $standardChunks = LegalChunk::factory()->count(4)->for($standardPage)->create([
        'embedding' => array_fill(0, 768, 1.0),
    ]);

    $result = app(RetrievalService::class)->retrieve($this->user, 'deep standards research');

    expect($result->standardChunks)->toHaveCount(3)
        ->and($result->standardChunks->pluck('id')->diff($standardChunks->pluck('id')))->toBeEmpty();
});

it('scopes document retrieval to the documents attached to a case', function () {
    $case = LegalCase::factory()->for($this->user)->create();
    $otherCase = LegalCase::factory()->for($this->user)->create();

    $inCase = Document::factory()->for($this->user)->create(['case_id' => $case->id]);
    $inOtherCase = Document::factory()->for($this->user)->create(['case_id' => $otherCase->id]);

    $caseChunk = DocumentChunk::factory()->for($inCase)->for($this->user)->create([
        'content' => 'Agrarian reform notes inside this case.',
        'embedding' => array_fill(0, 768, 1.0),
    ]);
    DocumentChunk::factory()->for($inOtherCase)->for($this->user)->create([
        'content' => 'Agrarian reform notes in another case.',
        'embedding' => array_fill(0, 768, 1.0),
    ]);

    Http::fake([
        '*/api/embed' => Http::response(['embeddings' => [array_fill(0, 768, 1.0)]], 200),
        // The Python provider answers the same query-vector request on its own
        // route; faking both keeps the test honest whichever engine serves it.
        '*/embeddings' => Http::response(['embeddings' => [array_fill(0, 768, 1.0)]], 200),
    ]);

    $result = app(RetrievalService::class)->retrieve($this->user, 'agrarian reform', $case);

    expect($result->documentChunks)->toHaveCount(1)
        ->and($result->documentChunks->first()->id)->toBe($caseChunk->id);
});

it('builds a context block with labeled sources', function () {
    $source = LegalSource::factory()->create(['name' => 'LawPhil']);
    $page = CrawledPage::factory()->for($source)->create(['law_name' => 'RA No. 6657']);
    LegalChunk::factory()->for($page)->create([
        'content' => 'Agrarian reform coverage.',
        'embedding' => array_fill(0, 768, 1.0),
    ]);

    $document = Document::factory()->for($this->user)->create([
        'title' => 'Case Notes',
        'original_filename' => 'case-notes.pdf',
    ]);
    DocumentChunk::factory()->for($document)->for($this->user)->create([
        'content' => 'My notes.',
        'embedding' => array_fill(0, 768, 1.0),
    ]);

    Http::fake([
        '*/api/embed' => Http::response(['embeddings' => [array_fill(0, 768, 1.0)]], 200),
        // The Python provider answers the same query-vector request on its own
        // route; faking both keeps the test honest whichever engine serves it.
        '*/embeddings' => Http::response(['embeddings' => [array_fill(0, 768, 1.0)]], 200),
    ]);

    $tokens = CitationTokens::assign([(string) $page->id, (string) $document->id]);

    $block = app(RetrievalService::class)->retrieve($this->user, 'agrarian reform')->contextBlock();

    expect($block)
        ->toContain('[SRC '.$tokens[(string) $page->id].']')
        ->toContain('RA No. 6657')
        ->toContain('[DOC '.$tokens[(string) $document->id].']')
        ->toContain('case-notes.pdf');
});

it('keeps the citation marker visible while fencing source metadata and content', function () {
    $page = CrawledPage::factory()->create([
        'law_name' => "Injected\nsource label",
        'url' => 'https://example.test/source',
    ]);
    $chunk = LegalChunk::factory()->for($page)->create([
        'content' => 'Retrieved source content.',
    ]);
    $token = CitationTokens::assign([(string) $page->id])[(string) $page->id];

    $context = (new RetrievalResult(collect([$chunk]), collect()))->contextBlock();
    $marker = '[SRC '.$token.']';
    $start = strpos($context, '[[UNTRUSTED DATA START]]');
    $end = strpos($context, '[[UNTRUSTED DATA END]]');

    expect(strpos($context, $marker))->toBeLessThan($start)
        ->and($start)->toBeInt()
        ->and($end)->toBeInt()
        ->and(substr($context, $start, $end - $start))->toContain('Injected source label')
        ->toContain('URL: https://example.test/source')
        ->toContain('Retrieved source content.');
});

it('labels each distinct source exactly once when it has multiple chunks', function () {
    $source = LegalSource::factory()->create(['name' => 'LawPhil']);
    $page = CrawledPage::factory()->for($source)->create(['law_name' => 'RA No. 6657']);
    LegalChunk::factory()->for($page)->create([
        'content' => 'First section of RA 6657.',
        'embedding' => array_fill(0, 768, 1.0),
    ]);
    LegalChunk::factory()->for($page)->create([
        'content' => 'Second section of RA 6657.',
        'embedding' => array_fill(0, 768, 1.0),
    ]);

    $document = Document::factory()->for($this->user)->create([
        'title' => 'Case Notes',
        'original_filename' => 'case-notes.pdf',
    ]);
    DocumentChunk::factory()->for($document)->for($this->user)->create([
        'content' => 'First note.',
        'embedding' => array_fill(0, 768, 1.0),
    ]);
    DocumentChunk::factory()->for($document)->for($this->user)->create([
        'content' => 'Second note.',
        'embedding' => array_fill(0, 768, 1.0),
    ]);

    Http::fake([
        '*/api/embed' => Http::response(['embeddings' => [array_fill(0, 768, 1.0)]], 200),
        // The Python provider answers the same query-vector request on its own
        // route; faking both keeps the test honest whichever engine serves it.
        '*/embeddings' => Http::response(['embeddings' => [array_fill(0, 768, 1.0)]], 200),
    ]);

    $tokens = CitationTokens::assign([(string) $page->id, (string) $document->id]);
    $srcMarker = '[SRC '.$tokens[(string) $page->id].']';
    $docMarker = '[DOC '.$tokens[(string) $document->id].']';

    $block = app(RetrievalService::class)->retrieve($this->user, 'agrarian reform')->contextBlock();

    expect($block)
        ->toContain($srcMarker)
        ->toContain('First section of RA 6657.')
        ->toContain('Second section of RA 6657.')
        ->toContain($docMarker)
        ->toContain('case-notes.pdf');

    expect(substr_count($block, $srcMarker))->toBe(1)
        ->and(substr_count($block, $docMarker))->toBe(1);
});
