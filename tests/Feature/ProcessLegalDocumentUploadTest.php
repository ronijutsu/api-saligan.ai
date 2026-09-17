<?php

use App\Enums\CrawlStatus;
use App\Exceptions\DocumentProcessingException;
use App\Jobs\ProcessLegalDocumentUpload;
use App\Models\CrawledPage;
use App\Models\LegalChunk;
use App\Services\Ai\EmbeddingService;
use App\Services\Crawler\LegalDigestService;
use App\Services\Documents\DocumentChunker;
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

function digestStub(): string
{
    return 'Digest: Ruling.';
}

it('extracts, chunks, embeds, and stores chunks for an uploaded document', function () {
    $text = implode("\n\n", array_fill(0, 40, str_repeat('Comprehensive Agrarian Reform Program coverage rules. ', 20)));

    Storage::put('legal-uploads/memo.txt', $text);

    $page = CrawledPage::factory()->uploaded()->create([
        'storage_path' => 'legal-uploads/memo.txt',
        'mime_type' => 'text/plain',
        'crawl_status' => CrawlStatus::Pending,
    ]);

    $digests = Mockery::mock(LegalDigestService::class);
    $digests->shouldReceive('generate')->once()->andReturn(digestStub());

    (new ProcessLegalDocumentUpload($page))->handle(
        app(TextExtractor::class),
        app(ImageOcrExtractor::class),
        app(DocumentChunker::class),
        app(EmbeddingService::class),
        $digests,
        app(StoredFiles::class),
    );

    expect($page->fresh()->crawl_status)->toBe(CrawlStatus::Ok)
        ->and($page->fresh()->digest)->toBe(digestStub())
        ->and($page->fresh()->digest_generated_at)->not->toBeNull()
        ->and($page->chunks()->count())->toBeGreaterThan(1)
        ->and($page->chunks()->first()->embedding)->toHaveCount(768);
});

it('stores a single chunk for a short uploaded document', function () {
    Storage::put('legal-uploads/short.txt', 'A short legal note.');

    $page = CrawledPage::factory()->uploaded()->create([
        'storage_path' => 'legal-uploads/short.txt',
        'mime_type' => 'text/plain',
        'crawl_status' => CrawlStatus::Pending,
    ]);

    (new ProcessLegalDocumentUpload($page))->handle(
        app(TextExtractor::class),
        app(ImageOcrExtractor::class),
        app(DocumentChunker::class),
        app(EmbeddingService::class),
        app(LegalDigestService::class),
        app(StoredFiles::class),
    );

    expect($page->fresh()->crawl_status)->toBe(CrawlStatus::Ok)
        ->and(LegalChunk::where('crawled_page_id', $page->id)->count())->toBe(1);
});

it('uses OCR to read a scanned PDF with no text layer', function () {
    Storage::put('legal-uploads/scan.pdf', '%PDF-1.4 fake bytes');

    $page = CrawledPage::factory()->uploaded()->create([
        'storage_path' => 'legal-uploads/scan.pdf',
        'mime_type' => 'application/pdf',
        'crawl_status' => CrawlStatus::Pending,
    ]);

    $extractor = Mockery::mock(TextExtractor::class);
    $extractor->shouldReceive('extractMarkdown')->once()->andReturn('   ');

    $ocr = Mockery::mock(ImageOcrExtractor::class);
    $ocr->shouldReceive('extract')
        ->once()
        ->with(Mockery::any(), 'application/pdf')
        ->andReturn(str_repeat('DEED OF ABSOLUTE SALE. Lot 22-A, TCT No. T-61204. ', 60));

    (new ProcessLegalDocumentUpload($page))->handle(
        $extractor,
        $ocr,
        app(DocumentChunker::class),
        app(EmbeddingService::class),
        app(LegalDigestService::class),
        app(StoredFiles::class),
    );

    expect($page->fresh()->crawl_status)->toBe(CrawlStatus::Ok)
        ->and($page->chunks()->count())->toBeGreaterThan(0)
        ->and($page->chunks()->first()->content)->toContain('TCT No. T-61204');
});

it('marks an upload as failed when no text can be read', function () {
    Storage::put('legal-uploads/empty.txt', '   ');

    $page = CrawledPage::factory()->uploaded()->create([
        'storage_path' => 'legal-uploads/empty.txt',
        'mime_type' => 'text/plain',
        'crawl_status' => CrawlStatus::Pending,
    ]);

    $job = new ProcessLegalDocumentUpload($page);

    try {
        $job->handle(
            app(TextExtractor::class),
            app(ImageOcrExtractor::class),
            app(DocumentChunker::class),
            app(EmbeddingService::class),
            app(LegalDigestService::class),
            app(StoredFiles::class),
        );
        $this->fail('Expected an exception for empty text.');
    } catch (DocumentProcessingException) {
        $job->failed(new DocumentProcessingException('No text could be read from this file. Upload a clearer or text-based copy.'));
    }

    expect($page->fresh()->crawl_status)->toBe(CrawlStatus::Failed)
        ->and($page->fresh()->last_error)->toContain('No text could be read from this file.');
});

it('marks an upload as failed when its file has been removed', function () {
    $page = CrawledPage::factory()->uploaded()->create([
        'storage_path' => 'legal-uploads/gone.pdf',
        'mime_type' => 'application/pdf',
        'crawl_status' => CrawlStatus::Pending,
    ]);

    $job = new ProcessLegalDocumentUpload($page);

    expect(fn () => $job->handle(
        app(TextExtractor::class),
        app(ImageOcrExtractor::class),
        app(DocumentChunker::class),
        app(EmbeddingService::class),
        app(LegalDigestService::class),
        app(StoredFiles::class),
    ))->toThrow(DocumentProcessingException::class, 'The uploaded file is no longer available.');
});

it('fails loudly when the embedding count does not match the chunk count', function () {
    Storage::put('legal-uploads/memo.txt', implode("\n\n", array_fill(0, 5, str_repeat('jurisprudence text. ', 120))));

    $page = CrawledPage::factory()->uploaded()->create([
        'storage_path' => 'legal-uploads/memo.txt',
        'mime_type' => 'text/plain',
        'crawl_status' => CrawlStatus::Pending,
    ]);

    $embeddings = Mockery::mock(EmbeddingService::class);
    $embeddings->shouldReceive('embedMany')->once()->andReturn([array_fill(0, 768, 0.5)]);

    $job = new ProcessLegalDocumentUpload($page);

    try {
        $job->handle(
            app(TextExtractor::class),
            app(ImageOcrExtractor::class),
            app(DocumentChunker::class),
            $embeddings,
            app(LegalDigestService::class),
            app(StoredFiles::class),
        );
        $this->fail('Expected a RuntimeException for the vector/chunk mismatch.');
    } catch (RuntimeException) {
        $job->failed(new RuntimeException('Embedding count mismatch: 2 chunks in, 1 vectors out.'));
    }

    expect($page->fresh()->crawl_status)->toBe(CrawlStatus::Failed)
        ->and($page->fresh()->last_error)->toBe(
            'The document could not be processed. Please try uploading it again or contact support.',
        )
        ->and(LegalChunk::where('crawled_page_id', $page->id)->count())->toBe(0);
});

it('sanitizes invalid UTF-8 in uploaded text', function () {
    $badText = "REPUBLIC OF THE PHILIPPINES\xFF\xFE notarized.";

    Storage::put('legal-uploads/scan.txt', $badText);

    $page = CrawledPage::factory()->uploaded()->create([
        'storage_path' => 'legal-uploads/scan.txt',
        'mime_type' => 'text/plain',
        'crawl_status' => CrawlStatus::Pending,
    ]);

    (new ProcessLegalDocumentUpload($page))->handle(
        app(TextExtractor::class),
        app(ImageOcrExtractor::class),
        app(DocumentChunker::class),
        app(EmbeddingService::class),
        app(LegalDigestService::class),
        app(StoredFiles::class),
    );

    $content = $page->chunks()->first()->content;

    expect($page->fresh()->crawl_status)->toBe(CrawlStatus::Ok)
        ->and($content)->toContain('REPUBLIC OF THE PHILIPPINES')
        ->and($content)->not->toContain("\xFF")
        ->and(mb_check_encoding($content, 'UTF-8'))->toBeTrue();
});

it('skips documents that already succeeded', function () {
    $page = CrawledPage::factory()->uploaded()->create();

    $extractor = Mockery::mock(TextExtractor::class);
    $extractor->shouldNotReceive('extractMarkdown');

    (new ProcessLegalDocumentUpload($page))->handle(
        $extractor,
        app(ImageOcrExtractor::class),
        app(DocumentChunker::class),
        app(EmbeddingService::class),
        app(LegalDigestService::class),
        app(StoredFiles::class),
    );

    expect($page->fresh()->crawl_status)->toBe(CrawlStatus::Ok);
});

it('logs internal failures but stores a generic user-facing message', function () {
    $page = CrawledPage::factory()->uploaded()->create();

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message) {
            return str_contains($message, 'boom: database exploded');
        });

    (new ProcessLegalDocumentUpload($page))->failed(
        new RuntimeException('boom: database exploded'),
    );

    expect($page->fresh()->crawl_status)->toBe(CrawlStatus::Failed)
        ->and($page->fresh()->last_error)->toBe(
            'The document could not be processed. Please try uploading it again or contact support.',
        );
});

it('exposes the safe message of a DocumentProcessingException to the user', function () {
    $page = CrawledPage::factory()->uploaded()->create();

    (new ProcessLegalDocumentUpload($page))->failed(
        new DocumentProcessingException('No text could be read from this file.'),
    );

    expect($page->fresh()->last_error)->toContain('No text could be read from this file.');
});

it('prevents two workers from ingesting the same upload at once', function () {
    $page = CrawledPage::factory()->uploaded()->create();

    $middleware = (new ProcessLegalDocumentUpload($page))->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class);
});
