<?php

use App\Enums\CrawlStatus;
use App\Jobs\CrawlLegalSourcePage;
use App\Models\CrawledPage;
use App\Models\LegalChunk;
use App\Models\LegalDigestRequest;
use App\Models\LegalSource;
use App\Models\User;
use App\Services\Ai\EmbeddingService;
use App\Services\Crawler\CrawlerAdapterFactory;
use App\Services\Crawler\LegalDigestBatcher;
use App\Services\Crawler\LegalDigestService;
use App\Services\Crawler\RobotsTxt;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * Batched digesting covers the bulk producers only — the nightly crawl and the
 * backfill. The read-path digest stays inline, and has its own coverage; the
 * point of these tests is that the split holds.
 *
 * The batch lifecycle lives in ai-provider (ADR-011 generalised), so the
 * boundary faked here is its three routes: submit, poll, and read results.
 * Which provider and model serve a digest batch is Python's business and is
 * covered by the ai-provider suite, not this one.
 */

beforeEach(function () {
    config([
        'saligan.crawler.digest.provider' => 'gemini',
        'saligan.crawler.digest.batch.enabled' => true,
    ]);

    $this->page = CrawledPage::factory()->create([
        'title' => 'People v. Dela Cruz, G.R. No. 123456',
    ]);

    $this->text = 'DECISION. This is an appeal from the Court of Appeals affirming the conviction of the accused...';

    // Held as state rather than re-faked per call: Http::fake() appends stubs
    // instead of replacing them, so a second fake would never win. The results
    // pattern is listed first because `*/batches/*` also matches it.
    $this->jobStatus = 'in_progress';
    $this->batchResults = [];
    $this->pollStatus = 200;
    $this->submitStatus = 201;

    Http::fake([
        '*/crawler/digest/batches/*/results' => fn () => Http::response([
            'results' => $this->batchResults,
        ]),
        '*/crawler/digest/batches/*' => fn () => $this->pollStatus === 200
            ? Http::response([
                'status' => $this->jobStatus,
                'counts' => ['total' => 1, 'succeeded' => 1, 'failed' => 0],
            ])
            : Http::response(['message' => 'not found'], $this->pollStatus),
        '*/crawler/digest/batches' => fn () => $this->submitStatus === 201
            ? Http::response([
                'batch_id' => 'batches/digest1',
                'provider' => 'gemini',
                'model' => 'gemini-3.6-flash',
                'submitted_at' => '2026-09-17T08:00:00+00:00',
            ], 201)
            : Http::response(['message' => 'unavailable'], $this->submitStatus),
    ]);

    $this->batchEnds = function (array $results = []): void {
        $this->jobStatus = 'ended';
        $this->batchResults = $results;
    };

    $this->answer = fn (LegalDigestRequest $request, string $digest): array => [
        'custom_id' => $request->customId(),
        'status' => 'succeeded',
        'digest' => $digest,
    ];

    $this->digest = "Nature: An appeal from the Court of Appeals.\nFacts: The accused was convicted...";
});

it('queues a page rather than digesting it', function () {
    app(LegalDigestBatcher::class)->enqueue($this->page, $this->text);

    $request = LegalDigestRequest::sole();

    expect($request->crawled_page_id)->toBe($this->page->id)
        ->and($request->status)->toBe(LegalDigestRequest::STATUS_PENDING)
        ->and($request->excerpt)->toContain('DECISION')
        ->and($this->page->fresh()->digest)->toBeNull();

    Http::assertNothingSent();
});

it('re-queues a page rather than queueing it twice', function () {
    app(LegalDigestBatcher::class)->enqueue($this->page, $this->text);
    app(LegalDigestBatcher::class)->enqueue($this->page, 'A later crawl of the same authority.');

    expect(LegalDigestRequest::count())->toBe(1)
        ->and(LegalDigestRequest::sole()->excerpt)->toContain('A later crawl');
});

it('queues nothing for a page with no text', function () {
    expect(app(LegalDigestBatcher::class)->enqueue($this->page, '   '))->toBeNull()
        ->and(LegalDigestRequest::count())->toBe(0);
});

it('submits the queued pages as one batch', function () {
    app(LegalDigestBatcher::class)->enqueue($this->page, $this->text);

    expect(app(LegalDigestBatcher::class)->submit())->toBe('batches/digest1');

    $request = LegalDigestRequest::sole();

    expect($request->status)->toBe(LegalDigestRequest::STATUS_SUBMITTED)
        ->and($request->batch_id)->toBe('batches/digest1');

    Http::assertSent(function (Request $sent) use ($request): bool {
        if (! str_ends_with($sent->url(), '/crawler/digest/batches') || $sent->method() !== 'POST') {
            return false;
        }

        $payload = $sent->data()['requests'][0];

        // The exact contract in ADR-011: the id maps the answer back, and the
        // excerpt travels as `text` rather than as a pre-rendered prompt.
        return $payload['custom_id'] === $request->customId()
            && str_contains($payload['text'], 'DECISION')
            && $payload['title'] === 'People v. Dela Cruz, G.R. No. 123456'
            && $payload['kind'] === 'authority';
    });
});

it('writes the digest once the batch has ended', function () {
    app(LegalDigestBatcher::class)->enqueue($this->page, $this->text);
    app(LegalDigestBatcher::class)->submit();

    $request = LegalDigestRequest::sole();

    ($this->batchEnds)([($this->answer)($request, $this->digest)]);

    expect(app(LegalDigestBatcher::class)->collect())->toBe(1);

    $page = $this->page->fresh();

    expect($page->digest)->toBe($this->digest)
        ->and($page->digest_generated_at)->not->toBeNull()
        ->and($request->fresh()->status)->toBe(LegalDigestRequest::STATUS_SUCCEEDED);
});

/*
 * NO_DIGEST is a real answer — the model read an index or an error page rather
 * than an authority. The request is done, not failed: re-queueing it would buy
 * the same answer again.
 */
it('closes out a page the model declined to digest, without failing it', function () {
    app(LegalDigestBatcher::class)->enqueue($this->page, $this->text);
    app(LegalDigestBatcher::class)->submit();

    $request = LegalDigestRequest::sole();

    ($this->batchEnds)([($this->answer)($request, 'NO_DIGEST')]);

    app(LegalDigestBatcher::class)->collect();

    expect($this->page->fresh()->digest)->toBeNull()
        ->and($request->fresh()->status)->toBe(LegalDigestRequest::STATUS_SUCCEEDED);
});

it('leaves a request submitted while the job is still running', function () {
    app(LegalDigestBatcher::class)->enqueue($this->page, $this->text);
    app(LegalDigestBatcher::class)->submit();
    app(LegalDigestBatcher::class)->collect();

    expect(LegalDigestRequest::sole()->status)->toBe(LegalDigestRequest::STATUS_SUBMITTED);
});

it('closes out a request whose batch ended without answering it', function () {
    app(LegalDigestBatcher::class)->enqueue($this->page, $this->text);
    app(LegalDigestBatcher::class)->submit();

    ($this->batchEnds)();

    app(LegalDigestBatcher::class)->collect();

    expect(LegalDigestRequest::sole()->status)->toBe(LegalDigestRequest::STATUS_FAILED)
        ->and($this->page->fresh()->digest)->toBeNull();
});

/*
 * The crawl is the bulk producer this exists for: a nightly run fetches
 * hundreds of authorities nobody has asked for, and digesting each inline is
 * the spend the batch discount is meant to halve.
 */
it('queues from the crawl instead of digesting each page inline', function () {
    $source = LegalSource::factory()->create(['base_domain' => 'lawphil.net']);

    Http::fake([
        '*/robots.txt' => Http::response("User-agent: *\nDisallow:\n", 200),
        '*/embeddings' => Http::response(fakeEmbedResponse(), 200),
        '*' => Http::response('<html><body><p>Republic Act No. 6657 coverage rules.</p></body></html>', 200),
    ]);

    (new CrawlLegalSourcePage($source, 'https://lawphil.net/statutes/repacts/repacts.html'))
        ->handle(app(EmbeddingService::class), new CrawlerAdapterFactory, new RobotsTxt);

    $request = LegalDigestRequest::sole();

    expect($request->status)->toBe(LegalDigestRequest::STATUS_PENDING)
        ->and($request->page->digest)->toBeNull()
        ->and($request->excerpt)->toContain('Republic Act No. 6657');

    // Queued, not sent — submitting is the scheduled sweep's job, so a crawl
    // of 500 pages still costs exactly one batch rather than 500 calls.
    Http::assertNotSent(fn (Request $sent): bool => str_contains($sent->url(), '/crawler/digest/batches'));
});

it('does not batch when the digest provider has no batch API', function () {
    config(['saligan.crawler.digest.provider' => 'ollama']);

    expect(app(LegalDigestService::class)->batches())->toBeFalse()
        ->and(app(LegalDigestBatcher::class)->submit())->toBeNull();
});

it('does not batch when digesting is switched off entirely', function () {
    config(['saligan.crawler.digest.provider' => 'none']);

    expect(app(LegalDigestService::class)->batches())->toBeFalse();
});

it('does not batch when the flag is off', function () {
    config(['saligan.crawler.digest.batch.enabled' => false]);

    expect(app(LegalDigestService::class)->batches())->toBeFalse();
});

/*
 * The whole point of the split: batching is for work nobody is waiting on. A
 * reader who opens an undigested source is waiting, so that digest is still
 * written on the spot — the batch flag must not reach the read path and leave
 * them waiting a day for a summary.
 */
it('never queues a digest for a reader opening an undigested source', function () {
    expect(app(LegalDigestService::class)->batches())->toBeTrue();

    $page = CrawledPage::factory()->for(LegalSource::factory())->create([
        'digest' => null,
        'crawl_status' => CrawlStatus::Ok,
    ]);

    LegalChunk::factory()->for($page)->create([
        'chunk_index' => 0,
        'content' => 'Section 2. Declaration of policy.',
    ]);

    $this->signInAs(User::factory()->create())
        ->getJson("/api/legal-pages/{$page->id}")
        ->assertOk();

    expect(LegalDigestRequest::count())->toBe(0);
});
