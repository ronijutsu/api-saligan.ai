<?php

use App\Enums\DocumentStatus;
use App\Enums\LabelKind;
use App\Jobs\ProcessDocumentUpload;
use App\Models\Document;
use App\Models\Label;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Ai\EmbeddingService;
use App\Services\Documents\DocumentChunker;
use App\Services\Documents\DocumentClassifier;
use App\Services\Documents\ImageOcrExtractor;
use App\Services\Documents\StoredFiles;
use App\Services\Documents\TextExtractor;
use Database\Seeders\LabelSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
 * The inline path. Classification runs in ai-provider (ADR-011), so every
 * answer here is faked at `POST /documents/classify`, which is the boundary the
 * classifier actually speaks to.
 */
beforeEach(function () {
    (new LabelSeeder)->run();

    config([
        'saligan.documents.classification.enabled' => true,
        // This suite is about classifying inline. Batching is switched on in
        // most real .env files, and with it on a document is queued rather
        // than filed — so it is pinned off here instead of left to whichever
        // machine the suite runs on.
        'saligan.documents.classification.batch.enabled' => false,
    ]);

    $this->user = User::factory()->create();

    // Auto-filing is half of the document-intelligence feature, so the whole
    // suite needs a plan that carries it — otherwise ingestion skips the
    // classifier and every expectation here is about work that never ran.
    Subscription::factory()->for($this->user)->create([
        'plan_id' => Plan::factory()->pro()->create()->id,
    ]);

    $this->document = Document::factory()->for($this->user)->create([
        'original_filename' => 'judicial-affidavit-cruz.pdf',
        'title' => 'Judicial Affidavit of Cruz',
    ]);

    $this->excerpt = 'JUDICIAL AFFIDAVIT of JUAN CRUZ, of legal age, Filipino, after having been duly sworn...';

    $this->classify = fn (?Document $document = null) => app(DocumentClassifier::class)
        ->classify($document ?? $this->document, $this->excerpt);

    // One fake per test, never appended: Http::fake() merges stubs and the
    // first match wins, so a second call would never be reached.
    $this->returns = fn (array $categories): mixed => Http::fake([
        '*/documents/classify' => Http::response(['categories' => $categories]),
    ]);
});

it('files a document under the categories the model returns', function () {
    ($this->returns)([['slug' => 'evidence-testimonial', 'confidence' => 0.94]]);

    ($this->classify)();

    $labels = $this->document->fresh()->labels;

    expect($labels->pluck('slug')->all())->toBe(['evidence-testimonial'])
        ->and($labels->first()->pivot->source)->toBe('ai')
        ->and((float) $labels->first()->pivot->confidence)->toBe(0.94)
        ->and($labels->first()->pivot->assigned_by)->toBeNull();
});

it('files a document under every category it genuinely belongs to', function () {
    ($this->returns)([
        ['slug' => 'pleading', 'confidence' => 0.91],
        ['slug' => 'procedural-compliance', 'confidence' => 0.78],
    ]);

    ($this->classify)();

    expect($this->document->fresh()->labels->pluck('slug')->sort()->values()->all())
        ->toBe(['pleading', 'procedural-compliance']);
});

it('drops a category the model is not confident enough about', function () {
    ($this->returns)([
        ['slug' => 'evidence-testimonial', 'confidence' => 0.88],
        ['slug' => 'correspondence', 'confidence' => 0.21],
    ]);

    ($this->classify)();

    expect($this->document->fresh()->labels->pluck('slug')->all())->toBe(['evidence-testimonial']);
});

it('leaves a document unfiled when the model cannot place it', function () {
    ($this->returns)([]);

    ($this->classify)();

    expect($this->document->fresh()->labels)->toHaveCount(0);
});

it('never overwrites a filing a person made', function () {
    $pleading = Label::where('kind', LabelKind::DocumentCategory)->where('slug', 'pleading')->firstOrFail();
    $this->document->syncLabels([$pleading], $this->user);

    Http::fake();

    ($this->classify)();

    expect($this->document->fresh()->labels->pluck('slug')->all())->toBe(['pleading']);
    Http::assertNothingSent();
});

it('keeps only the most confident categories when the model names too many', function () {
    config(['saligan.documents.classification.max_categories' => 2]);

    ($this->returns)([
        ['slug' => 'pleading', 'confidence' => 0.71],
        ['slug' => 'evidence-documentary', 'confidence' => 0.95],
        ['slug' => 'correspondence', 'confidence' => 0.83],
    ]);

    ($this->classify)();

    expect($this->document->fresh()->labels->pluck('slug')->sort()->values()->all())
        ->toBe(['correspondence', 'evidence-documentary']);
});

it('ignores a category that is not in the vocabulary', function () {
    ($this->returns)([
        ['slug' => 'invented-category', 'confidence' => 0.99],
        ['slug' => 'pleading', 'confidence' => 0.81],
    ]);

    ($this->classify)();

    expect($this->document->fresh()->labels->pluck('slug')->all())->toBe(['pleading']);
});

it('can file under a category the firm added itself', function () {
    $organization = Organization::factory()->create();
    $member = User::factory()->memberOf($organization)->create();
    $document = Document::factory()->for($member)->create();

    Label::factory()->forOrganization($organization, $member)->create([
        'slug' => 'barangay-conciliation',
        'name' => 'Barangay Conciliation',
    ]);

    ($this->returns)([['slug' => 'barangay-conciliation', 'confidence' => 0.9]]);

    ($this->classify)($document);

    expect($document->fresh()->labels->pluck('slug')->all())->toBe(['barangay-conciliation']);
});

it('cannot file under a category belonging to another firm', function () {
    Label::factory()->forOrganization(Organization::factory()->create())->create([
        'slug' => 'someone-elses-category',
        'name' => "Someone Else's Category",
    ]);

    ($this->returns)([['slug' => 'someone-elses-category', 'confidence' => 0.99]]);

    ($this->classify)();

    expect($this->document->fresh()->labels)->toHaveCount(0);
});

it('does not reach a model when classification is switched off', function () {
    config(['saligan.documents.classification.enabled' => false]);

    Http::fake();

    ($this->classify)();

    expect($this->document->fresh()->labels)->toHaveCount(0);
    Http::assertNothingSent();
});

it('leaves the document unfiled rather than failing when the model errors', function () {
    Http::fake(['*/documents/classify' => Http::response(['message' => 'the provider is down'], 500)]);

    ($this->classify)();

    expect($this->document->fresh()->labels)->toHaveCount(0);
});

it('files an uploaded document as part of ingestion', function () {
    Storage::fake('local');
    Http::fake([
        '*/embeddings' => fn (Request $request) => Http::response([
            'embeddings' => array_map(
                fn () => array_fill(0, 768, 0.5),
                $request->data()['texts'] ?? [],
            ),
        ], 200),
        '*/documents/classify' => Http::response([
            'categories' => [['slug' => 'evidence-testimonial', 'confidence' => 0.93]],
        ]),
    ]);

    Storage::put('documents/affidavit.txt', implode("\n\n", array_fill(0, 20, $this->excerpt)));

    $document = Document::factory()->for($this->user)->create([
        'storage_path' => 'documents/affidavit.txt',
        'mime_type' => 'text/plain',
        'status' => DocumentStatus::Queued,
    ]);

    (new ProcessDocumentUpload($document))->handle(
        app(TextExtractor::class),
        app(ImageOcrExtractor::class),
        app(DocumentChunker::class),
        app(EmbeddingService::class),
        app(StoredFiles::class),
        app(DocumentClassifier::class),
    );

    expect($document->fresh()->status)->toBe(DocumentStatus::Ready)
        ->and($document->fresh()->labels->pluck('slug')->all())->toBe(['evidence-testimonial']);
});

it('still finishes ingestion when classification fails', function () {
    Storage::fake('local');
    Http::fake([
        '*/embeddings' => fn (Request $request) => Http::response([
            'embeddings' => array_map(
                fn () => array_fill(0, 768, 0.5),
                $request->data()['texts'] ?? [],
            ),
        ], 200),
        '*/documents/classify' => Http::response(['message' => 'the provider is down'], 500),
    ]);

    Storage::put('documents/affidavit.txt', implode("\n\n", array_fill(0, 20, $this->excerpt)));

    $document = Document::factory()->for($this->user)->create([
        'storage_path' => 'documents/affidavit.txt',
        'mime_type' => 'text/plain',
        'status' => DocumentStatus::Queued,
    ]);

    (new ProcessDocumentUpload($document))->handle(
        app(TextExtractor::class),
        app(ImageOcrExtractor::class),
        app(DocumentChunker::class),
        app(EmbeddingService::class),
        app(StoredFiles::class),
        app(DocumentClassifier::class),
    );

    expect($document->fresh()->status)->toBe(DocumentStatus::Ready)
        ->and($document->fresh()->chunks()->count())->toBeGreaterThan(0)
        ->and($document->fresh()->labels)->toHaveCount(0);
});
