<?php

use App\Enums\DocumentStatus;
use App\Exceptions\DocumentProcessingException;
use App\Jobs\Middleware\LimitUserIngestConcurrency;
use App\Jobs\ProcessDocumentUpload;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Ai\EmbeddingService;
use App\Services\Documents\DocumentChunker;
use App\Services\Documents\DocumentClassifier;
use App\Services\Documents\DocumentEncryptor;
use App\Services\Documents\ImageOcrExtractor;
use App\Services\Documents\StoredFiles;
use App\Services\Documents\TextExtractor;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;

/**
 * A user whose plan reads scans and files them — the condition for the OCR and
 * classification halves of ingestion to run at all.
 */
function userWhoReadsScans(): User
{
    $user = User::factory()->create();

    Subscription::factory()->for($user)->create([
        'plan_id' => Plan::factory()->pro()->create()->id,
    ]);

    return $user;
}

beforeEach(function () {
    Storage::fake('local');
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

it('extracts, chunks, embeds, and stores chunks for a text document', function () {
    $user = User::factory()->create();

    $text = implode("\n\n", array_fill(0, 40, str_repeat('agrarian reform and land reform jurisprudence. ', 20)));

    Storage::put('documents/memo.txt', $text);

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
        ->and($document->chunks()->count())->toBeGreaterThan(1)
        ->and($document->chunks()->first()->user_id)->toBe($user->id)
        ->and($document->chunks()->first()->embedding)->toHaveCount(768);
});

it('stores a single chunk for a short text document', function () {
    $user = User::factory()->create();

    Storage::put('documents/short.txt', 'A short legal note.');

    $document = Document::factory()->for($user)->create([
        'storage_path' => 'documents/short.txt',
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
        ->and(DocumentChunk::where('document_id', $document->id)->count())->toBe(1);
});

it('extracts text from an encrypted stored document', function () {
    $user = User::factory()->create();

    $text = implode("\n\n", array_fill(0, 30, str_repeat('agricultural lease and tenancy. ', 20)));

    Storage::put('documents/plain.txt', $text);

    app(DocumentEncryptor::class)->encrypt(Storage::path('documents/plain.txt'), 'documents/encrypted.txt');

    Storage::delete('documents/plain.txt');

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
        ->and($document->chunks()->count())->toBeGreaterThan(1)
        ->and($document->chunks()->first()->content)->toContain('agricultural lease');

    // The plaintext must never have been written to the storage disk.
    expect(Storage::get('documents/encrypted.txt'))->toStartWith(DocumentEncryptor::MAGIC);
});

it('marks a document as failed when no text can be extracted', function () {
    $user = User::factory()->create();

    Storage::put('documents/empty.txt', '   ');

    $document = Document::factory()->for($user)->create([
        'storage_path' => 'documents/empty.txt',
        'mime_type' => 'text/plain',
        'status' => DocumentStatus::Queued,
    ]);

    $job = new ProcessDocumentUpload($document);

    try {
        $job->handle(
            app(TextExtractor::class),
            app(ImageOcrExtractor::class),
            app(DocumentChunker::class),
            app(EmbeddingService::class),
            app(StoredFiles::class),
            app(DocumentClassifier::class),
        );
        $this->fail('Expected an exception for empty text.');
    } catch (DocumentProcessingException) {
        $job->failed(new DocumentProcessingException('No extractable text was found in the file.'));
    }

    expect($document->fresh()->status)->toBe(DocumentStatus::Failed)
        ->and($document->fresh()->error_message)->toContain('No extractable text');
});

it('uses OCR to extract text from an uploaded image', function () {
    $user = userWhoReadsScans();

    Storage::put('documents/scan.png', 'fake image bytes');

    $document = Document::factory()->for($user)->create([
        'storage_path' => 'documents/scan.png',
        'mime_type' => 'image/png',
        'status' => DocumentStatus::Queued,
    ]);

    $ocr = Mockery::mock(ImageOcrExtractor::class);
    $ocr->shouldReceive('extract')
        ->once()
        ->withArgs(fn (string $path, string $mime) => $mime === 'image/png' && str_ends_with($path, 'documents/scan.png'))
        ->andReturn('REPUBLIC OF THE PHILIPPINES
Deed of Absolute Sale');

    (new ProcessDocumentUpload($document))->handle(
        app(TextExtractor::class),
        $ocr,
        app(DocumentChunker::class),
        app(EmbeddingService::class),
        app(StoredFiles::class),
        app(DocumentClassifier::class),
    );

    expect($document->fresh()->status)->toBe(DocumentStatus::Ready)
        ->and(DocumentChunk::where('document_id', $document->id)->count())->toBe(1)
        ->and(DocumentChunk::where('document_id', $document->id)->first()->content)
        ->toContain('Deed of Absolute Sale');
});

it('marks an image as failed when OCR returns no text', function () {
    $user = userWhoReadsScans();

    Storage::put('documents/blank.jpg', 'fake image bytes');

    $document = Document::factory()->for($user)->create([
        'storage_path' => 'documents/blank.jpg',
        'mime_type' => 'image/jpeg',
        'status' => DocumentStatus::Queued,
    ]);

    $ocr = Mockery::mock(ImageOcrExtractor::class);
    $ocr->shouldReceive('extract')->once()->andReturn('');

    $job = new ProcessDocumentUpload($document);

    try {
        $job->handle(
            app(TextExtractor::class),
            $ocr,
            app(DocumentChunker::class),
            app(EmbeddingService::class),
            app(StoredFiles::class),
            app(DocumentClassifier::class),
        );
        $this->fail('Expected an exception for empty OCR output.');
    } catch (DocumentProcessingException) {
        $job->failed(new DocumentProcessingException('No text could be read from the image.'));
    }

    expect($document->fresh()->status)->toBe(DocumentStatus::Failed)
        ->and($document->fresh()->error_message)->toContain('No text could be read from the image.');
});

it('sanitizes invalid UTF-8 so extracted text never breaks downstream requests', function () {
    $user = User::factory()->create();

    $badText = "REPUBLIC OF THE PHILIPPINES\xFF\xFE notarized.";

    Storage::put('documents/scan.txt', $badText);

    $document = Document::factory()->for($user)->create([
        'storage_path' => 'documents/scan.txt',
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

    $content = $document->chunks()->first()->content;

    expect($document->fresh()->status)->toBe(DocumentStatus::Ready)
        ->and($content)->toContain('REPUBLIC OF THE PHILIPPINES')
        ->and($content)->not->toContain("\xFF")
        ->and(mb_check_encoding($content, 'UTF-8'))->toBeTrue();
});

it('strips null bytes that Postgres would otherwise reject', function () {
    $user = User::factory()->create();

    $badText = "REPUBLIC OF THE PHILIPPINES\0\0 notarized.";

    Storage::put('documents/null-bytes.txt', $badText);

    $document = Document::factory()->for($user)->create([
        'storage_path' => 'documents/null-bytes.txt',
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
        ->and($document->chunks()->first()->content)->not->toContain("\0");
});

it('fails loudly when the embedding count does not match the chunk count', function () {
    $user = User::factory()->create();

    Storage::put('documents/memo.txt', implode("\n\n", array_fill(0, 5, str_repeat('jurisprudence text. ', 120))));

    $document = Document::factory()->for($user)->create([
        'storage_path' => 'documents/memo.txt',
        'mime_type' => 'text/plain',
        'status' => DocumentStatus::Queued,
    ]);

    $embeddings = Mockery::mock(EmbeddingService::class);
    // The job checks the index's model before embedding and records the one it
    // used; this test is about the count mismatch, so both are satisfied.
    $embeddings->shouldReceive('assertModelMatchesIndex')->once()->andReturnNull();
    $embeddings->shouldReceive('model')->andReturn('ollama/qwen3-embedding:latest');
    $embeddings->shouldReceive('embedMany')->once()->andReturn([array_fill(0, 768, 0.5)]);

    $job = new ProcessDocumentUpload($document);

    try {
        $job->handle(
            app(TextExtractor::class),
            app(ImageOcrExtractor::class),
            app(DocumentChunker::class),
            $embeddings,
            app(StoredFiles::class),
            app(DocumentClassifier::class),
        );
        $this->fail('Expected a RuntimeException for the vector/chunk mismatch.');
    } catch (RuntimeException) {
        $job->failed(new RuntimeException('Embedding count mismatch: 2 chunks in, 1 vectors out.'));
    }

    expect($document->fresh()->status)->toBe(DocumentStatus::Failed)
        ->and($document->fresh()->error_message)->toBe(
            'The document could not be processed. Please try uploading it again or contact support.',
        )
        ->and(DocumentChunk::where('document_id', $document->id)->count())->toBe(0);
});

it('logs internal failures but stores a generic user-facing message', function () {
    $document = Document::factory()->create();

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message) {
            return str_contains($message, 'boom: database exploded');
        });

    (new ProcessDocumentUpload($document))->failed(
        new RuntimeException('boom: database exploded'),
    );

    expect($document->fresh()->status)->toBe(DocumentStatus::Failed)
        ->and($document->fresh()->error_message)->toBe(
            'The document could not be processed. Please try uploading it again or contact support.',
        );
});

it('exposes the safe message of a DocumentProcessingException to the user', function () {
    $document = Document::factory()->create();

    (new ProcessDocumentUpload($document))->failed(
        new DocumentProcessingException('No extractable text was found in the file.'),
    );

    expect($document->fresh()->error_message)->toContain('No extractable text was found in the file.');
});

it('prevents two workers from processing the same document at once', function () {
    $document = Document::factory()->create();

    $middleware = (new ProcessDocumentUpload($document))->middleware();

    expect($middleware)->toHaveCount(2)
        ->and($middleware[0])->toBeInstanceOf(LimitUserIngestConcurrency::class)
        ->and($middleware[1])->toBeInstanceOf(WithoutOverlapping::class);
});

it('scans a PDF with no text layer instead of rejecting it', function () {
    // A scanned PDF is page images with no extractable text, so the parser
    // returns nothing and the vision model is the only way to read it.
    $user = userWhoReadsScans();

    Storage::put('documents/scan.pdf', '%PDF-1.4 fake bytes');

    $document = Document::factory()->for($user)->create([
        'storage_path' => 'documents/scan.pdf',
        'mime_type' => 'application/pdf',
        'status' => DocumentStatus::Queued,
    ]);

    $extractor = Mockery::mock(TextExtractor::class);
    $extractor->shouldReceive('extractMarkdown')->once()->andReturn('   ');
    $extractor->shouldReceive('pageCount')->once()->andReturn(1);

    $ocr = Mockery::mock(ImageOcrExtractor::class);
    $ocr->shouldReceive('extract')
        ->once()
        ->with(Mockery::any(), 'application/pdf')
        ->andReturn(str_repeat('DEED OF ABSOLUTE SALE. Lot 22-A, TCT No. T-61204. ', 60));

    (new ProcessDocumentUpload($document))->handle(
        $extractor,
        $ocr,
        app(DocumentChunker::class),
        app(EmbeddingService::class),
        app(StoredFiles::class),
        app(DocumentClassifier::class),
    );

    expect($document->fresh()->status)->toBe(DocumentStatus::Ready)
        ->and($document->chunks()->count())->toBeGreaterThan(0)
        ->and($document->chunks()->first()->content)->toContain('TCT No. T-61204');
});

it('fails a PDF only after scanning it has also come up empty', function () {
    $user = userWhoReadsScans();

    Storage::put('documents/blank.pdf', '%PDF-1.4 fake bytes');

    $document = Document::factory()->for($user)->create([
        'storage_path' => 'documents/blank.pdf',
        'mime_type' => 'application/pdf',
        'status' => DocumentStatus::Queued,
    ]);

    $extractor = Mockery::mock(TextExtractor::class);
    $extractor->shouldReceive('extractMarkdown')->once()->andReturn('');
    $extractor->shouldReceive('pageCount')->once()->andReturn(1);

    $ocr = Mockery::mock(ImageOcrExtractor::class);
    $ocr->shouldReceive('extract')->once()->andReturn('');

    expect(fn () => (new ProcessDocumentUpload($document))->handle(
        $extractor,
        $ocr,
        app(DocumentChunker::class),
        app(EmbeddingService::class),
        app(StoredFiles::class),
        app(DocumentClassifier::class),
    ))->toThrow(DocumentProcessingException::class);
});

it('does not re-scan a DOCX that yielded no text', function () {
    // Only images and PDFs are readable by the vision model; a DOCX with no
    // text is genuinely empty, so calling OCR on it would just burn a request.
    $user = userWhoReadsScans();

    Storage::put('documents/empty.docx', 'PK fake bytes');

    $document = Document::factory()->for($user)->create([
        'storage_path' => 'documents/empty.docx',
        'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'status' => DocumentStatus::Queued,
    ]);

    $extractor = Mockery::mock(TextExtractor::class);
    $extractor->shouldReceive('extractMarkdown')->once()->andReturn('');

    $ocr = Mockery::mock(ImageOcrExtractor::class);
    $ocr->shouldNotReceive('extract');

    expect(fn () => (new ProcessDocumentUpload($document))->handle(
        $extractor,
        $ocr,
        app(DocumentChunker::class),
        app(EmbeddingService::class),
        app(StoredFiles::class),
        app(DocumentClassifier::class),
    ))->toThrow(DocumentProcessingException::class);
});
