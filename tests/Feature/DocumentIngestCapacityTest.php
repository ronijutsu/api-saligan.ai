<?php

use App\Enums\DocumentStatus;
use App\Exceptions\DocumentProcessingException;
use App\Jobs\Middleware\LimitUserIngestConcurrency;
use App\Jobs\ProcessDocumentUpload;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Ai\EmbeddingService;
use App\Services\Billing\AiCosting;
use App\Services\Documents\DocumentChunker;
use App\Services\Documents\DocumentClassifier;
use App\Services\Documents\DocumentIndexQuota;
use App\Services\Documents\DocumentIngestLimits;
use App\Services\Documents\ImageOcrExtractor;
use App\Services\Documents\StoredFiles;
use App\Services\Documents\TextExtractor;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;

/**
 * The ingestion caps that keep one upload from becoming unbounded storage.
 *
 * A 25 MB text-heavy PDF chunks into tens of thousands of halfvec(768) rows and
 * thousands of embedding requests, so each guard here is the difference between
 * a bounded document and an open-ended bill.
 */
beforeEach(function () {
    Storage::fake('local');
    Http::fake([
        '*/embeddings' => function (Request $request) {
            $inputs = $request->data()['texts'] ?? [];

            return Http::response([
                'embeddings' => array_map(fn () => array_fill(0, 768, 0.5), $inputs),
            ], 200);
        },
    ]);
});

/**
 * Run the job with the real collaborators, as the queue would.
 */
function runIngest(Document $document): void
{
    (new ProcessDocumentUpload($document))->handle(
        app(TextExtractor::class),
        app(ImageOcrExtractor::class),
        app(DocumentChunker::class),
        app(EmbeddingService::class),
        app(StoredFiles::class),
        app(DocumentClassifier::class),
        app(DocumentIngestLimits::class),
        app(DocumentIndexQuota::class),
    );
}

function queuedDocument(User $user, string $path, string $text, string $mime = 'text/plain'): Document
{
    Storage::put($path, $text);

    return Document::factory()->for($user)->create([
        'storage_path' => $path,
        'mime_type' => $mime,
        'status' => DocumentStatus::Queued,
    ]);
}

it('refuses text longer than the extracted-character ceiling', function () {
    Config::set('saligan.documents.max_extracted_characters', 100);

    $document = queuedDocument(User::factory()->create(), 'documents/long.txt', str_repeat('a', 200));

    expect(fn () => runIngest($document))
        ->toThrow(DocumentProcessingException::class, 'too large to index');

    expect($document->chunks()->count())->toBe(0);
});

it('refuses a document that chunks past the passage ceiling', function () {
    Config::set('saligan.documents.max_extracted_characters', 10_000_000);
    Config::set('saligan.documents.max_chunks_per_document', 3);

    // Four paragraphs far larger than the 500-character chunk size produce
    // more passages than the ceiling allows.
    $text = implode("\n\n", array_fill(0, 4, str_repeat('tenancy and agrarian reform. ', 200)));

    $document = queuedDocument(User::factory()->create(), 'documents/wide.txt', $text);

    expect(fn () => runIngest($document))
        ->toThrow(DocumentProcessingException::class, 'too long to index');

    expect($document->chunks()->count())->toBe(0);
});

it('refuses an upload that would exceed the account index quota', function () {
    Config::set('saligan.documents.max_indexed_chunks_per_user', 2);

    $user = User::factory()->create();
    $document = queuedDocument($user, 'documents/quota.txt', implode("\n\n", array_fill(0, 6, str_repeat('lease and tenancy. ', 100))));

    // The user already holds the whole allowance.
    DocumentChunk::factory()->count(2)->for($user)->create();

    expect(fn () => runIngest($document))
        ->toThrow(DocumentProcessingException::class, 'document index is full');

    expect($document->chunks()->count())->toBe(0);
});

it('counts the quota across the organization for a team plan', function () {
    Config::set('saligan.documents.max_indexed_chunks_per_user', 2);

    $organization = Organization::factory()->create();
    $owner = User::factory()->ownerOf($organization)->create();
    $colleague = User::factory()->memberOf($organization)->create();

    // A teammate's passages consume the shared allowance.
    DocumentChunk::factory()->count(2)->for($colleague)->create();

    $quota = app(DocumentIndexQuota::class);

    expect($quota->used($owner))->toBe(2)
        ->and($quota->remaining($owner))->toBe(0)
        ->and($quota->hasRoom($owner))->toBeFalse();
});

it('keeps finished passages and re-embeds only the missing ones on a retry', function () {
    $user = User::factory()->create();

    $text = implode("\n\n", array_fill(0, 30, str_repeat('agrarian reform jurisprudence. ', 30)));
    $document = queuedDocument($user, 'documents/resume.txt', $text);

    runIngest($document);

    $total = $document->chunks()->count();
    expect($total)->toBeGreaterThan(2);

    // Simulate a job that died partway through: the first passage is already
    // persisted, the rest are not.
    $document->chunks()->where('chunk_index', '>', 0)->delete();
    $document->update(['status' => DocumentStatus::Queued]);

    Http::fake([
        '*/embeddings' => function (Request $request) {
            $inputs = $request->data()['texts'] ?? [];

            return Http::response([
                'embeddings' => array_map(fn () => array_fill(0, 768, 0.5), $inputs),
            ], 200);
        },
    ]);

    runIngest($document);

    // Every passage is present again, and the reserved one was not duplicated.
    expect($document->fresh()->status)->toBe(DocumentStatus::Ready)
        ->and($document->chunks()->count())->toBe($total)
        ->and($document->chunks()->pluck('chunk_index')->unique())->toHaveCount($total);

    Http::assertSentCount(1);
});

it('writes passages with multi-row inserts, one statement per batch', function () {
    Config::set('saligan.documents.insert_batch_size', 2);

    $user = User::factory()->create();

    $text = implode("\n\n", array_fill(0, 30, str_repeat('agrarian reform jurisprudence. ', 30)));
    $document = queuedDocument($user, 'documents/batched.txt', $text);

    $inserts = 0;

    DB::listen(function ($query) use (&$inserts): void {
        if (str_starts_with(strtolower(trim($query->sql)), 'insert into "document_chunks"')) {
            $inserts++;
        }
    });

    runIngest($document);

    $count = $document->chunks()->count();

    // The passages are all present, and they arrived in statements holding two
    // rows each — not one INSERT per passage. That is the difference between a
    // document taking seconds to write and taking minutes.
    expect($count)->toBeGreaterThan(3)
        ->and($inserts)->toBe((int) ceil($count / 2));
});

it('drops passages left over from a longer previous attempt', function () {
    $user = User::factory()->create();

    $document = queuedDocument($user, 'documents/shrink.txt', 'A short note.');

    // A stale passage from an earlier, longer version of this document.
    DocumentChunk::factory()->for($user)->create([
        'document_id' => $document->id,
        'chunk_index' => 9,
    ]);

    runIngest($document);

    expect($document->chunks()->count())->toBe(1)
        ->and($document->chunks()->first()->chunk_index)->toBe(0);
});

it('refuses a scan with more pages than the OCR ceiling and does not call the model', function () {
    Config::set('saligan.documents.max_ocr_pages', 2);

    $user = User::factory()->create();
    Subscription::factory()->for($user)->create([
        'plan_id' => Plan::factory()->pro()->create()->id,
    ]);

    Storage::put('documents/long-scan.pdf', '%PDF-1.4 fake bytes');

    $document = Document::factory()->for($user)->create([
        'storage_path' => 'documents/long-scan.pdf',
        'mime_type' => 'application/pdf',
        'status' => DocumentStatus::Queued,
    ]);

    $extractor = Mockery::mock(TextExtractor::class);
    $extractor->shouldReceive('extractMarkdown')->once()->andReturn('   ');
    $extractor->shouldReceive('pageCount')->once()->andReturn(9);

    $ocr = Mockery::mock(ImageOcrExtractor::class);
    // The point of the ceiling: the model never runs, so the upload costs nothing.
    $ocr->shouldNotReceive('extract');

    expect(fn () => (new ProcessDocumentUpload($document))->handle(
        $extractor,
        $ocr,
        app(DocumentChunker::class),
        app(EmbeddingService::class),
        app(StoredFiles::class),
        app(DocumentClassifier::class),
    ))->toThrow(DocumentProcessingException::class, 'too long to read');
});

it('prices OCR by the page rather than per upload', function () {
    expect(AiCosting::ocrCostUsd(1))->toBe(0.02)
        ->and(AiCosting::ocrCostUsd(50))->toBe(1.0)
        // The flat add-on priced a 300-page scan the same as a one-page photo.
        ->and(AiCosting::ocrCostUsd(300))->toBeGreaterThan(AiCosting::OCR_ADDON_USD);
});

it('releases an ingest when the account has no free concurrency slot', function () {
    Config::set('saligan.documents.max_concurrent_ingests_per_user', 1);
    Cache::flush();

    $userId = 7;
    $middleware = new LimitUserIngestConcurrency($userId);

    // Occupy the account's only slot.
    $held = Cache::lock("document-ingest:user:{$userId}:slot:1", 60);
    expect($held->get())->toBeTrue();

    $job = Mockery::mock();
    $job->shouldReceive('release')->once()->with(30)->andReturnNull();

    $ran = false;

    $middleware->handle($job, function () use (&$ran): void {
        $ran = true;
    });

    expect($ran)->toBeFalse();

    $held->release();
});

it('runs the ingest when a concurrency slot is free', function () {
    Config::set('saligan.documents.max_concurrent_ingests_per_user', 2);
    Cache::flush();

    $middleware = new LimitUserIngestConcurrency(11);
    $job = Mockery::mock();
    $job->shouldNotReceive('release');

    $ran = false;

    $middleware->handle($job, function () use (&$ran): void {
        $ran = true;
    });

    expect($ran)->toBeTrue();

    // The slot is handed back, so the next upload can use it.
    $again = false;
    $middleware->handle($job, function () use (&$again): void {
        $again = true;
    });

    expect($again)->toBeTrue();
});

it('refuses to embed when the index was built with a different model', function () {
    // Two embedding models can emit the same number of dimensions, so nothing
    // downstream would notice the switch: the rows insert and retrieval just
    // gets worse. Ingestion refuses until the corpus is re-embedded.
    $user = User::factory()->create();

    Document::factory()->for($user)->create([
        'embedding_model' => 'gemini/gemini-embedding-2',
    ]);

    $document = queuedDocument($user, 'documents/model-drift.txt', 'A short note about tenancy.');

    Config::set('saligan.embedding.provider', 'ollama');
    Config::set('saligan.embedding.model', 'qwen3-embedding:latest');

    expect(fn () => runIngest($document))
        ->toThrow(DocumentProcessingException::class, 'Re-embed the existing documents');

    expect($document->chunks()->count())->toBe(0);
});

it('records the embedding model on the document it indexed', function () {
    $user = User::factory()->create();
    $document = queuedDocument($user, 'documents/model-record.txt', 'A short note about tenancy.');

    Config::set('saligan.embedding.provider', 'ollama');
    Config::set('saligan.embedding.model', 'qwen3-embedding:latest');

    runIngest($document);

    expect($document->fresh()->embedding_model)->toBe('ollama/qwen3-embedding:latest');
});
