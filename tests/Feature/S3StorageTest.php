<?php

use App\Enums\CrawlStatus;
use App\Enums\DocumentStatus;
use App\Jobs\ProcessDocumentUpload;
use App\Jobs\ProcessLegalDocumentUpload;
use App\Models\CrawledPage;
use App\Models\Document;
use App\Models\User;
use App\Services\Ai\EmbeddingService;
use App\Services\Crawler\LegalDigestService;
use App\Services\Documents\DocumentChunker;
use App\Services\Documents\DocumentClassifier;
use App\Services\Documents\DocumentEncryptor;
use App\Services\Documents\ImageOcrExtractor;
use App\Services\Documents\StoredFiles;
use App\Services\Documents\TextExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Exercises the storage layer against a disk that is not the local filesystem.
 *
 * Every other test in the suite runs on `local`, where `Storage::path()` works
 * and stream handles are seekable — precisely the two assumptions an object
 * store breaks. Pointing the default disk at a faked `s3` disk drives the code
 * down the remote branches: streamed encryption and upload, header parsing over
 * a non-seekable read, verification on a second stream, and downloading to a
 * local copy for the extractors.
 */
beforeEach(function () {
    // The fake is local underneath, but it is registered under a disk whose
    // configured driver is `s3`, which is what the code branches on.
    config()->set('filesystems.default', 's3');
    Storage::fake('s3');

    // Legacy v1 files are irrelevant here and the local .env may forbid them.
    config()->set('saligan.documents.require_authenticated_encryption', false);

    Http::fake([
        '*/embeddings' => function (Request $request) {
            $inputs = $request->data()['texts'] ?? [];

            return Http::response([
                'embeddings' => array_map(
                    fn () => array_fill(0, 768, 0.5),
                    $inputs,
                ),
            ], 200);
        },
    ]);
});

it('encrypts to and decrypts from a non-local disk', function () {
    $encryptor = app(DocumentEncryptor::class);

    $source = tempnam(sys_get_temp_dir(), 'src_');
    file_put_contents($source, 'Amount due: PHP 10,000.00');

    $encryptor->encrypt($source, 'documents/on-s3.txt');
    @unlink($source);

    Storage::disk('s3')->assertExists('documents/on-s3.txt');

    expect($encryptor->isEncrypted('documents/on-s3.txt'))->toBeTrue()
        ->and($encryptor->formatVersion('documents/on-s3.txt'))->toBe(2);

    $decrypted = $encryptor->decryptToTemp('documents/on-s3.txt');

    expect(file_get_contents($decrypted))->toBe('Amount due: PHP 10,000.00');

    @unlink($decrypted);
});

it('round-trips a document larger than one cipher chunk on a non-local disk', function () {
    // Bigger than the 1 MiB CHUNK_SIZE, so the block-counter arithmetic and the
    // multi-read paths are genuinely exercised rather than short-circuited.
    $content = str_repeat('The quick brown fox. ', 100_000);

    $source = tempnam(sys_get_temp_dir(), 'src_');
    file_put_contents($source, $content);

    app(DocumentEncryptor::class)->encrypt($source, 'documents/large.txt');
    @unlink($source);

    $decrypted = app(DocumentEncryptor::class)->decryptToTemp('documents/large.txt');

    expect(hash_file('sha256', $decrypted))->toBe(hash('sha256', $content));

    @unlink($decrypted);
});

it('streams a decrypted document off a non-local disk', function () {
    $content = str_repeat('sensitive contents ', 500);

    $source = tempnam(sys_get_temp_dir(), 'src_');
    file_put_contents($source, $content);

    app(DocumentEncryptor::class)->encrypt($source, 'documents/streamed.txt');
    @unlink($source);

    $streamed = '';

    foreach (app(DocumentEncryptor::class)->decryptStream('documents/streamed.txt') as $chunk) {
        $streamed .= $chunk;
    }

    expect($streamed)->toBe($content);
});

it('still detects tampering on a non-local disk', function () {
    $source = tempnam(sys_get_temp_dir(), 'src_');
    file_put_contents($source, 'Amount due: PHP 10,000.00');

    app(DocumentEncryptor::class)->encrypt($source, 'documents/tampered.txt');
    @unlink($source);

    $stored = Storage::disk('s3')->get('documents/tampered.txt');
    $target = strlen($stored) - 5;
    $stored[$target] = chr(ord($stored[$target]) ^ 0x01);

    Storage::disk('s3')->put('documents/tampered.txt', $stored);

    expect(fn () => app(DocumentEncryptor::class)->decryptToTemp('documents/tampered.txt'))
        ->toThrow(RuntimeException::class, 'failed its integrity check');
});

it('downloads a remote file to a temporary local copy for the extractors', function () {
    Storage::disk('s3')->put('legal-uploads/memo.txt', 'a stored authority');

    $copy = app(StoredFiles::class)->plaintextCopy('legal-uploads/memo.txt');

    expect($copy->isTemporary())->toBeTrue()
        ->and(is_file($copy->path))->toBeTrue()
        ->and(file_get_contents($copy->path))->toBe('a stored authority');

    $path = $copy->path;
    $copy->discard();

    expect(is_file($path))->toBeFalse();
});

it('hands back the stored path itself on a local disk without copying', function () {
    config()->set('filesystems.default', 'local');
    Storage::fake('local');
    Storage::disk('local')->put('legal-uploads/memo.txt', 'a stored authority');

    $copy = app(StoredFiles::class)->plaintextCopy('legal-uploads/memo.txt');

    expect($copy->isTemporary())->toBeFalse()
        ->and($copy->path)->toBe(Storage::disk('local')->path('legal-uploads/memo.txt'));

    // A no-op: discarding must never delete the stored file itself.
    $copy->discard();

    Storage::disk('local')->assertExists('legal-uploads/memo.txt');
});

it('ingests, chunks, and embeds a document stored on a non-local disk', function () {
    $user = User::factory()->create();

    $text = str_repeat('Article 1. The obligation is demandable at once. ', 40);
    Storage::disk('s3')->put('documents/memo.txt', $text);

    $document = Document::factory()->for($user)->create([
        'storage_path' => 'documents/memo.txt',
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
        ->and($document->chunks()->count())->toBeGreaterThan(0)
        ->and($document->chunks()->first()->embedding)->not->toBeNull();
});

it('ingests an encrypted document stored on a non-local disk', function () {
    $user = User::factory()->create();

    $text = str_repeat('Article 2. Obligations arising from law are not presumed. ', 40);

    $source = tempnam(sys_get_temp_dir(), 'src_');
    file_put_contents($source, $text);

    app(DocumentEncryptor::class)->encrypt($source, 'documents/encrypted.txt');
    @unlink($source);

    $document = Document::factory()->for($user)->create([
        'storage_path' => 'documents/encrypted.txt',
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
        ->and($document->chunks()->count())->toBeGreaterThan(0)
        ->and($document->chunks()->first()->content)->toContain('Obligations arising from law');
});

it('ingests a legal upload stored on a non-local disk', function () {
    $text = str_repeat('Section 1. This Act shall be known as the Civil Code. ', 40);
    Storage::disk('s3')->put('legal-uploads/act.txt', $text);

    $page = CrawledPage::factory()->uploaded()->create([
        'storage_path' => 'legal-uploads/act.txt',
        'mime_type' => 'text/plain',
        'crawl_status' => CrawlStatus::Pending,
    ]);

    $digests = Mockery::mock(LegalDigestService::class);
    $digests->shouldReceive('generate')->once()->andReturn('Digest: Ruling.');

    (new ProcessLegalDocumentUpload($page))->handle(
        app(TextExtractor::class),
        app(ImageOcrExtractor::class),
        app(DocumentChunker::class),
        app(EmbeddingService::class),
        $digests,
        app(StoredFiles::class),
    );

    expect($page->fresh()->crawl_status)->toBe(CrawlStatus::Ok)
        ->and($page->chunks()->count())->toBeGreaterThan(0);
});
