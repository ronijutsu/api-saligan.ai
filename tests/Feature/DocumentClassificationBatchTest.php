<?php

use App\Models\Document;
use App\Models\DocumentClassificationRequest;
use App\Models\Label;
use App\Models\User;
use App\Services\Documents\DocumentClassificationBatcher;
use App\Services\Documents\DocumentClassifier;
use Database\Seeders\LabelSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * Batched classification (ADR-011). The batch lifecycle lives in ai-provider,
 * so the boundary faked here is its three routes: submit, poll, and read
 * results. Which provider and model serve the batch is Python's business and
 * is covered by the ai-provider suite, not this one.
 */
beforeEach(function () {
    (new LabelSeeder)->run();

    config([
        'saligan.documents.classification.enabled' => true,
        'saligan.documents.classification.provider' => 'gemini',
        'saligan.documents.classification.batch.enabled' => true,
    ]);

    $this->user = User::factory()->create();

    $this->document = Document::factory()->for($this->user)->create([
        'original_filename' => 'judicial-affidavit-cruz.pdf',
        'title' => 'Judicial Affidavit of Cruz',
    ]);

    $this->excerpt = 'JUDICIAL AFFIDAVIT of JUAN CRUZ, of legal age, Filipino, after having been duly sworn...';

    $this->classify = fn (?Document $document = null) => app(DocumentClassifier::class)
        ->classify($document ?? $this->document, $this->excerpt);

    // Held as state rather than re-faked per call: Http::fake() appends stubs
    // instead of replacing them, so a second fake would never win. The results
    // pattern is listed first because `*/batches/*` also matches it.
    $this->jobStatus = 'in_progress';
    $this->batchResults = [];
    $this->pollStatus = 200;
    $this->submitStatus = 201;

    Http::fake([
        '*/documents/classify/batches/*/results' => fn () => Http::response([
            'results' => $this->batchResults,
        ]),
        '*/documents/classify/batches/*' => fn () => $this->pollStatus === 200
            ? Http::response([
                'status' => $this->jobStatus,
                'counts' => ['total' => 1, 'succeeded' => 1, 'failed' => 0],
            ])
            : Http::response(['message' => 'not found'], $this->pollStatus),
        '*/documents/classify/batches' => fn () => $this->submitStatus === 201
            ? Http::response([
                'batch_id' => 'batches/abc',
                'provider' => 'gemini',
                'model' => 'gemini-3.6-flash',
                'submitted_at' => '2026-09-17T08:00:00+00:00',
            ], 201)
            : Http::response(['message' => 'overloaded'], $this->submitStatus),
    ]);

    $this->batchEnds = function (array $results = []): void {
        $this->jobStatus = 'ended';
        $this->batchResults = $results;
    };

    $this->succeededResult = fn (DocumentClassificationRequest $request, array $categories): array => [
        'custom_id' => $request->customId(),
        'status' => 'succeeded',
        'categories' => $categories,
    ];
});

it('queues a document instead of classifying it inline when batching is on', function () {
    ($this->classify)();

    $request = DocumentClassificationRequest::sole();

    expect($request->document_id)->toBe($this->document->id)
        ->and($request->status)->toBe(DocumentClassificationRequest::STATUS_PENDING)
        ->and($request->excerpt)->toContain('JUDICIAL AFFIDAVIT of JUAN CRUZ')
        ->and($this->document->fresh()->labels)->toBeEmpty();

    Http::assertNothingSent();
});

it('keeps classifying inline when the provider has no batches API', function () {
    config(['saligan.documents.classification.provider' => 'openai']);

    Http::fake([
        '*/documents/classify' => Http::response([
            'categories' => [['slug' => 'evidence-testimonial', 'confidence' => 0.94]],
        ]),
    ]);

    ($this->classify)();

    expect(DocumentClassificationRequest::count())->toBe(0)
        ->and($this->document->fresh()->labels->pluck('slug')->all())->toBe(['evidence-testimonial']);
});

it('stores the queued excerpt encrypted at rest', function () {
    ($this->classify)();

    $stored = (string) DB::table('document_classification_requests')->value('excerpt');

    // Encrypted, so the document's text is not readable straight off the row —
    // the documents themselves are stored encrypted and this excerpt of one
    // must not be the hole in that.
    expect($stored)->not->toContain('JUDICIAL AFFIDAVIT')
        ->and(DocumentClassificationRequest::sole()->excerpt)->toContain('JUDICIAL AFFIDAVIT');
});

it('re-queues a document rather than queueing it twice', function () {
    ($this->classify)();
    app(DocumentClassifier::class)->classify($this->document, 'A different extraction of the same file.');

    expect(DocumentClassificationRequest::count())->toBe(1)
        ->and(DocumentClassificationRequest::sole()->excerpt)->toContain('A different extraction');
});

it('submits the queued documents as one batch', function () {
    ($this->classify)();

    $batchId = app(DocumentClassificationBatcher::class)->submit();

    expect($batchId)->toBe('batches/abc');

    $request = DocumentClassificationRequest::sole();

    expect($request->status)->toBe(DocumentClassificationRequest::STATUS_SUBMITTED)
        ->and($request->batch_id)->toBe('batches/abc')
        ->and($request->submitted_at)->not->toBeNull();

    Http::assertSent(function (Request $sent) use ($request): bool {
        if (! str_ends_with($sent->url(), '/documents/classify/batches') || $sent->method() !== 'POST') {
            return false;
        }

        $payload = $sent->data()['requests'][0];

        // The exact contract in ADR-011: the id maps the answer back, and the
        // excerpt travels as `text` rather than as a pre-rendered prompt.
        return $payload['custom_id'] === $request->customId()
            && $payload['filename'] === 'judicial-affidavit-cruz.pdf'
            && $payload['title'] === 'Judicial Affidavit of Cruz'
            && str_contains($payload['text'], 'JUDICIAL AFFIDAVIT')
            && in_array('evidence-testimonial', array_column($payload['vocabulary'], 'slug'), true);
    });
});

it('submits nothing when no documents are queued', function () {
    expect(app(DocumentClassificationBatcher::class)->submit())->toBeNull();

    Http::assertNothingSent();
});

it('leaves a request submitted while its batch is still running', function () {
    ($this->classify)();
    app(DocumentClassificationBatcher::class)->submit();
    app(DocumentClassificationBatcher::class)->collect();

    expect(DocumentClassificationRequest::sole()->status)
        ->toBe(DocumentClassificationRequest::STATUS_SUBMITTED);
});

it('files a document once its batch has ended', function () {
    ($this->classify)();
    app(DocumentClassificationBatcher::class)->submit();

    $request = DocumentClassificationRequest::sole();

    ($this->batchEnds)([
        ($this->succeededResult)($request, [
            ['slug' => 'evidence-testimonial', 'confidence' => 0.94],
            ['slug' => 'correspondence', 'confidence' => 0.12],
        ]),
    ]);

    expect(app(DocumentClassificationBatcher::class)->collect())->toBe(1);

    $labels = $this->document->fresh()->labels;

    // The confidence floor still applies: the same answer read inline would
    // have dropped the second category too.
    expect($labels->pluck('slug')->all())->toBe(['evidence-testimonial'])
        ->and($labels->first()->pivot->source)->toBe('ai')
        ->and((float) $labels->first()->pivot->confidence)->toBe(0.94)
        ->and($request->fresh()->status)->toBe(DocumentClassificationRequest::STATUS_SUCCEEDED);
});

it('never overwrites a filing made by hand while the batch was running', function () {
    ($this->classify)();
    app(DocumentClassificationBatcher::class)->submit();

    $request = DocumentClassificationRequest::sole();

    // The lawyer files it themselves before the answer comes back.
    $pleading = Label::where('slug', 'pleading')->sole();
    $this->document->labels()->attach($pleading->id, ['source' => 'user', 'assigned_by' => $this->user->id]);

    ($this->batchEnds)([
        ($this->succeededResult)($request, [['slug' => 'evidence-testimonial', 'confidence' => 0.99]]),
    ]);

    app(DocumentClassificationBatcher::class)->collect();

    expect($this->document->fresh()->labels->pluck('slug')->all())->toBe(['pleading']);
});

it('closes out a request the model could not answer', function () {
    ($this->classify)();
    app(DocumentClassificationBatcher::class)->submit();

    $request = DocumentClassificationRequest::sole();

    ($this->batchEnds)([
        ['custom_id' => $request->customId(), 'status' => 'expired', 'categories' => []],
    ]);

    app(DocumentClassificationBatcher::class)->collect();

    expect($request->fresh()->status)->toBe(DocumentClassificationRequest::STATUS_FAILED)
        ->and($request->fresh()->error)->toContain('expired')
        ->and($this->document->fresh()->labels)->toBeEmpty();
});

it('closes out a request whose batch ended without answering it', function () {
    ($this->classify)();
    app(DocumentClassificationBatcher::class)->submit();

    ($this->batchEnds)();

    app(DocumentClassificationBatcher::class)->collect();

    expect(DocumentClassificationRequest::sole()->status)
        ->toBe(DocumentClassificationRequest::STATUS_FAILED);
});

it('closes out a request whose batch is no longer available', function () {
    ($this->classify)();
    app(DocumentClassificationBatcher::class)->submit();

    $this->pollStatus = 404;

    app(DocumentClassificationBatcher::class)->collect();

    expect(DocumentClassificationRequest::sole()->status)
        ->toBe(DocumentClassificationRequest::STATUS_FAILED);
});

it('leaves a request pending when the batch cannot be submitted', function () {
    ($this->classify)();

    $this->submitStatus = 529;

    expect(app(DocumentClassificationBatcher::class)->submit())->toBeNull()
        ->and(DocumentClassificationRequest::sole()->status)->toBe(DocumentClassificationRequest::STATUS_PENDING);
});

it('leaves the document unfiled when the answer names no usable category', function () {
    ($this->classify)();
    app(DocumentClassificationBatcher::class)->submit();

    $request = DocumentClassificationRequest::sole();

    // ai-provider turns an unparseable answer into "succeeded, no categories":
    // the model replied, it just could not place the document.
    ($this->batchEnds)([
        ['custom_id' => $request->customId(), 'status' => 'succeeded', 'categories' => []],
    ]);

    app(DocumentClassificationBatcher::class)->collect();

    expect($this->document->fresh()->labels)->toBeEmpty()
        ->and($request->fresh()->status)->toBe(DocumentClassificationRequest::STATUS_SUCCEEDED);
});
