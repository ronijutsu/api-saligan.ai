<?php

use App\Models\Document;
use App\Models\DocumentClassificationRequest;
use App\Models\User;
use App\Services\Documents\DocumentClassificationBatcher;
use App\Services\Documents\DocumentClassifier;
use Database\Seeders\LabelSeeder;
use Illuminate\Support\Facades\Http;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;

/*
 * The Laravel side of the provider question. Mapping a provider's batch
 * envelope, states and result nesting into a category list now lives in
 * ai-provider (covered by its pytest suite); what remains here is Laravel's own
 * decision — which providers it will queue work for at all, and how the three
 * batch routes are driven. Kept as a second, smaller batch suite so the
 * provider gate cannot regress unnoticed.
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

    $this->classify = fn () => app(DocumentClassifier::class)->classify($this->document, $this->excerpt);

    // Held as state rather than re-faked per call: Http::fake() appends stubs
    // instead of replacing them, so a second fake would never win.
    $this->jobStatus = 'in_progress';
    $this->batchResults = [];
    $this->pollStatus = 200;
    $this->submitStatus = 201;

    Http::fake([
        '*/documents/classify/batches/*/results' => fn () => Http::response(['results' => $this->batchResults]),
        '*/documents/classify/batches/*' => fn () => $this->pollStatus === 200
            ? Http::response(['status' => $this->jobStatus, 'counts' => ['total' => 1, 'succeeded' => 1, 'failed' => 0]])
            : Http::response(['message' => 'not found'], $this->pollStatus),
        '*/documents/classify/batches' => fn () => $this->submitStatus === 201
            ? Http::response([
                'batch_id' => 'batches/abc123',
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

    $this->answer = fn (DocumentClassificationRequest $request, array $categories): array => [
        'custom_id' => $request->customId(),
        'status' => 'succeeded',
        'categories' => $categories,
    ];
});

it('queues a document rather than classifying it inline', function () {
    ($this->classify)();

    expect(DocumentClassificationRequest::sole()->status)
        ->toBe(DocumentClassificationRequest::STATUS_PENDING);

    Http::assertNothingSent();
});

it('submits the queued documents as one batch', function () {
    ($this->classify)();

    expect(app(DocumentClassificationBatcher::class)->submit())->toBe('batches/abc123');

    $request = DocumentClassificationRequest::sole();

    expect($request->status)->toBe(DocumentClassificationRequest::STATUS_SUBMITTED)
        ->and($request->batch_id)->toBe('batches/abc123');
});

it('leaves a request submitted while the job is still running', function () {
    ($this->classify)();
    app(DocumentClassificationBatcher::class)->submit();
    app(DocumentClassificationBatcher::class)->collect();

    expect(DocumentClassificationRequest::sole()->status)
        ->toBe(DocumentClassificationRequest::STATUS_SUBMITTED);
});

it('files a document once its job has succeeded', function () {
    ($this->classify)();
    app(DocumentClassificationBatcher::class)->submit();

    $request = DocumentClassificationRequest::sole();

    ($this->batchEnds)([
        ($this->answer)($request, [
            ['slug' => 'evidence-testimonial', 'confidence' => 0.94],
            ['slug' => 'correspondence', 'confidence' => 0.12],
        ]),
    ]);

    expect(app(DocumentClassificationBatcher::class)->collect())->toBe(1);

    $labels = $this->document->fresh()->labels;

    // The confidence floor applies exactly as it does inline.
    expect($labels->pluck('slug')->all())->toBe(['evidence-testimonial'])
        ->and((float) $labels->first()->pivot->confidence)->toBe(0.94)
        ->and($request->fresh()->status)->toBe(DocumentClassificationRequest::STATUS_SUCCEEDED);
});

it('ignores a result that does not match a queued request', function () {
    ($this->classify)();
    app(DocumentClassificationBatcher::class)->submit();

    ($this->batchEnds)([
        ['custom_id' => 'dcr_99999', 'status' => 'succeeded', 'categories' => [['slug' => 'pleading', 'confidence' => 0.99]]],
    ]);

    app(DocumentClassificationBatcher::class)->collect();

    // The unmatched result files nothing, and the document it might have been
    // for is left unfiled rather than being filed under a guess.
    expect($this->document->fresh()->labels)->toBeEmpty();
});

it('closes out a request the model returned an error for', function () {
    ($this->classify)();
    app(DocumentClassificationBatcher::class)->submit();

    $request = DocumentClassificationRequest::sole();

    ($this->batchEnds)([
        ['custom_id' => $request->customId(), 'status' => 'errored', 'categories' => []],
    ]);

    app(DocumentClassificationBatcher::class)->collect();

    expect($request->fresh()->status)->toBe(DocumentClassificationRequest::STATUS_FAILED)
        ->and($this->document->fresh()->labels)->toBeEmpty();
});

/*
 * A job that failed outright answers nothing. The requests behind it must not
 * sit submitted forever — closing them out leaves the documents in the Unfiled
 * queue, where a person can still file them.
 */
it('closes out every request when the job failed outright', function () {
    ($this->classify)();
    app(DocumentClassificationBatcher::class)->submit();

    $this->jobStatus = 'failed';

    app(DocumentClassificationBatcher::class)->collect();

    expect(DocumentClassificationRequest::sole()->status)
        ->toBe(DocumentClassificationRequest::STATUS_FAILED);
});

it('closes out a request whose job is no longer available', function () {
    ($this->classify)();
    app(DocumentClassificationBatcher::class)->submit();

    $this->pollStatus = 404;

    app(DocumentClassificationBatcher::class)->collect();

    expect(DocumentClassificationRequest::sole()->status)
        ->toBe(DocumentClassificationRequest::STATUS_FAILED);
});

it('leaves a request pending when the batch cannot be submitted', function () {
    $handler = new TestHandler;

    config([
        'logging.channels.array' => [
            'driver' => 'custom',
            'via' => fn (array $config) => new MonologLogger('array', [$handler]),
        ],
        'logging.default' => 'array',
    ]);

    ($this->classify)();

    $this->submitStatus = 400;

    expect(app(DocumentClassificationBatcher::class)->submit())->toBeNull()
        ->and(DocumentClassificationRequest::sole()->status)
        ->toBe(DocumentClassificationRequest::STATUS_PENDING)
        ->and($handler->hasWarningThatContains('could not be submitted'))->toBeTrue();
});

it('classifies inline when the configured provider has no batch backend', function () {
    config([
        'saligan.documents.classification.provider' => 'openai',
    ]);

    Http::fake([
        '*/documents/classify' => Http::response([
            'categories' => [['slug' => 'pleading', 'confidence' => 0.9]],
        ]),
    ]);

    expect(app(DocumentClassifier::class)->batches())->toBeFalse();

    ($this->classify)();

    expect($this->document->fresh()->labels->pluck('slug')->all())->toBe(['pleading']);
});
