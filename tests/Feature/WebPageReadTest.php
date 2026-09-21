<?php

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Crawler\ParsedPage;
use App\Services\Crawler\PdfAdapter;
use App\Services\Web\WebPageReader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('saligan.crawler.block_private_addresses', false);
    Cache::flush();

    $this->user = User::factory()->create();
    Subscription::factory()->for($this->user)->create([
        'plan_id' => Plan::factory()->pro()->create()->id,
    ]);

    $this->page = '<html><head><title>ORUS Registration Guide</title></head><body>'
        .'<p>Businesses register through the Online Registration and Update System before opening.</p>'
        .'<p>The BIR issues a Certificate of Registration once the documents are verified and fees are paid.</p>'
        .'</body></html>';
});

it('requires authentication', function () {
    $this->postJson('/api/web-pages/read', ['url' => 'https://orus.bir.gov.ph'])->assertStatus(401);
});

it('reads a cited page with a digest and marks the passage the snippet came from', function () {
    Http::fake([
        'orus.bir.gov.ph/*' => Http::response($this->page, 200, ['Content-Type' => 'text/html; charset=utf-8']),
        '*/crawler/digest' => Http::response(['digest' => 'Nature: A registration guide.']),
    ]);

    $response = $this->signInAs($this->user)
        ->postJson('/api/web-pages/read', [
            'url' => 'https://orus.bir.gov.ph/guide',
            'snippets' => ['… issues a Certificate of Registration once the documents are verified …'],
        ])
        ->assertOk();

    expect($response->json('data.title'))->toBe('ORUS Registration Guide')
        ->and($response->json('data.digest'))->toBe('Nature: A registration guide.')
        ->and($response->json('data.has_digest'))->toBeTrue()
        ->and($response->json('data.chunks.0.content'))->toContain('Online Registration and Update System')
        ->and($response->json('data.cited_chunk_indexes'))->toBe([0]);
});

it('still returns the text when a digest cannot be produced', function () {
    Http::fake([
        'orus.bir.gov.ph/*' => Http::response($this->page, 200, ['Content-Type' => 'text/html']),
        '*/crawler/digest' => Http::response(['message' => 'down'], 500),
    ]);

    $response = $this->signInAs($this->user)
        ->postJson('/api/web-pages/read', ['url' => 'https://orus.bir.gov.ph/guide'])
        ->assertOk();

    expect($response->json('data.has_digest'))->toBeFalse()
        ->and($response->json('data.chunks'))->not->toBeEmpty()
        ->and($response->json('data.cited_chunk_indexes'))->toBe([]);
});

function scannedPdfFake(): void
{
    $pdf = Mockery::mock(PdfAdapter::class);
    $pdf->shouldReceive('parse')->andReturn(new ParsedPage(
        title: 'RMC No. 91-2024.pdf',
        lawName: null,
        grNumber: null,
        promulgationDate: null,
        text: '',
    ));
    app()->instance(PdfAdapter::class, $pdf);
}

function crawlerCapture(array $body): array
{
    return [
        'crawler.test/captures' => Http::response($body),
        'www.bir.gov.ph/*' => Http::response('%PDF-1.4 scan', 200, ['Content-Type' => 'application/pdf']),
    ];
}

it('says a scanned PDF cannot be read when no crawler is configured', function () {
    scannedPdfFake();
    Http::fake(['www.bir.gov.ph/*' => Http::response('%PDF-1.4 scan', 200, ['Content-Type' => 'application/pdf'])]);

    $this->signInAs($this->user)
        ->postJson('/api/web-pages/read', ['url' => 'https://www.bir.gov.ph/scan.pdf'])
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'scan'));
});

describe('with the legal crawler configured', function () {
    beforeEach(function () {
        config()->set('saligan.legal_crawler.url', 'http://crawler.test');
        config()->set('saligan.legal_crawler.secret', 'crawler-secret');
        scannedPdfFake();
    });

    it('hands a scanned PDF to the crawler and reports that it is being read', function () {
        Http::fake(crawlerCapture(['id' => 'abc', 'status' => 'running']));

        $this->signInAs($this->user)
            ->postJson('/api/web-pages/read', ['url' => 'https://www.bir.gov.ph/scan.pdf'])
            ->assertStatus(202)
            ->assertJsonPath('status', 'processing');

        Http::assertSent(fn ($request) => $request->url() === 'http://crawler.test/captures'
            && $request->hasHeader('Authorization', 'Bearer crawler-secret')
            && $request['url'] === 'https://www.bir.gov.ph/scan.pdf');
    });

    it('serves the recognised text with its digest and marks the cited passage', function () {
        Http::fake(crawlerCapture([
            'id' => 'abc',
            'status' => 'done',
            'title' => 'RMC No. 91-2024',
            'text' => "Registration of books of accounts is done through ORUS within thirty days.\n\n---\n\nThe loose documentary stamp tax of P30.00 is paid through e-payment channels.",
            'digest' => 'Nature: A revenue circular.',
            'ocr_used' => true,
            'page_count' => 9,
        ]));

        $response = $this->signInAs($this->user)
            ->postJson('/api/web-pages/read', [
                'url' => 'https://www.bir.gov.ph/scan.pdf',
                'snippets' => ['Pay the documentary stamp tax of ₱30.00 using e-payment channels'],
            ])
            ->assertOk();

        expect($response->json('data.title'))->toBe('RMC No. 91-2024')
            ->and($response->json('data.digest'))->toBe('Nature: A revenue circular.')
            ->and($response->json('data.chunks.0.content'))->toContain('ORUS')
            ->and($response->json('data.chunks.0.content'))->not->toContain('---')
            ->and($response->json('data.cited_chunk_indexes'))->not->toBeEmpty();
    });

    it('does not download a scan again on every poll, and keeps polling until it is done', function () {
        $state = ['status' => 'running'];

        Http::fake([
            'crawler.test/captures' => function () use (&$state) {
                return Http::response($state + ['id' => 'abc']);
            },
            'www.bir.gov.ph/*' => Http::response('%PDF-1.4 scan', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $poll = fn () => $this->signInAs($this->user)
            ->postJson('/api/web-pages/read', ['url' => 'https://www.bir.gov.ph/scan.pdf']);

        $poll()->assertStatus(202);
        $poll()->assertStatus(202);

        $state = ['status' => 'done', 'title' => 'RMC', 'text' => 'Registration of books through ORUS is required.', 'digest' => null];
        $poll()->assertOk();

        Http::assertSentCount(4);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'bir.gov.ph'));
    });

    it('explains why the crawler could not read it', function (string $code, string $expected) {
        Http::fake(crawlerCapture(['id' => 'abc', 'status' => 'failed', 'error_code' => $code]));

        $this->signInAs($this->user)
            ->postJson('/api/web-pages/read', ['url' => 'https://www.bir.gov.ph/scan.pdf'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message) => str_contains($message, $expected));
    })->with([
        'too long' => ['too_many_pages', 'too long'],
        'ocr failed' => ['ocr_failed', 'text recognition'],
        'no text' => ['no_text', 'text recognition'],
    ]);

    it('falls back to the scan message when the crawler is unreachable', function () {
        Http::fake([
            'crawler.test/*' => Http::response('down', 500),
            'www.bir.gov.ph/*' => Http::response('%PDF-1.4 scan', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $this->signInAs($this->user)
            ->postJson('/api/web-pages/read', ['url' => 'https://www.bir.gov.ph/scan.pdf'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'scan'));
    });
});

it('refuses a page that is not a public address', function () {
    config()->set('saligan.crawler.block_private_addresses', true);
    Http::fake();

    $this->signInAs($this->user)
        ->postJson('/api/web-pages/read', ['url' => 'http://127.0.0.1/admin'])
        ->assertStatus(422);

    Http::assertNothingSent();
});

it('reads a PDF, whatever type the server labels it', function () {
    $pdf = Mockery::mock(PdfAdapter::class);
    $pdf->shouldReceive('parse')->once()->andReturn(new ParsedPage(
        title: 'Revenue Regulations No. 7-2024',
        lawName: null,
        grNumber: null,
        promulgationDate: null,
        text: "Section 1. Scope.\nThese Regulations apply to all taxpayers.",
    ));
    app()->instance(PdfAdapter::class, $pdf);

    Http::fake([
        'www.bir.gov.ph/*' => Http::response('%PDF-1.7 fake body', 200, ['Content-Type' => 'application/octet-stream']),
        '*/crawler/digest' => Http::response(['digest' => 'Nature: Revenue regulation.']),
    ]);

    $response = $this->signInAs($this->user)
        ->postJson('/api/web-pages/read', [
            'url' => 'https://www.bir.gov.ph/images/RR_No_7-2024.pdf',
            'snippets' => ['These Regulations apply to all taxpayers.'],
        ])
        ->assertOk();

    expect($response->json('data.title'))->toBe('Revenue Regulations No. 7-2024')
        ->and($response->json('data.has_digest'))->toBeTrue()
        ->and($response->json('data.cited_chunk_indexes'))->toBe([0]);
});

it('refuses a page that is not html, text or a PDF', function () {
    Http::fake([
        'orus.bir.gov.ph/*' => Http::response('GIF89a', 200, ['Content-Type' => 'image/gif']),
    ]);

    $this->signInAs($this->user)
        ->postJson('/api/web-pages/read', ['url' => 'https://orus.bir.gov.ph/banner.gif'])
        ->assertStatus(422);
});

it('rejects a url that is not http or https', function () {
    $this->signInAs($this->user)
        ->postJson('/api/web-pages/read', ['url' => 'file:///etc/passwd'])
        ->assertStatus(422);
});

function orusPassages(): array
{
    return [
        0 => 'The Online Registration and Update System (ORUS) replaces the manual filing of Form 1901 for self-employed individuals. Taxpayers create an ORUS account and select the transaction they need.',
        1 => 'Payment of the loose documentary stamp tax of P30.00 for the Certificate of Registration may be made through authorized agent banks or the e-payment channels of the Bureau.',
        2 => 'Books of accounts, whether manual, loose-leaf or computerized, must be registered before use. The registration of books is completed within thirty days from the start of business.',
        3 => 'Contact the Revenue District Office having jurisdiction over the place of business for assistance. Office hours are from Monday to Friday.',
    ];
}

it('places a paraphrased span on the passage it paraphrases', function () {
    $reader = app(WebPageReader::class);

    $cited = $reader->citedIndexes(orusPassages(), [
        'Pay the ₱30.00 documentary stamp tax for the Certificate of Registration using BIR e-payment channels',
    ]);

    expect($cited)->toBe([1]);
});

it('places each span of a snippet on its own passage', function () {
    $reader = app(WebPageReader::class);

    $cited = $reader->citedIndexes(orusPassages(), [
        'Self-employed individuals create an ORUS account instead of filing Form 1901 … Registered books of accounts must be completed within thirty days of starting business',
    ]);

    expect($cited)->toBe([0, 2]);
});

it('marks nothing when no passage resembles the span', function () {
    $reader = app(WebPageReader::class);

    expect($reader->citedIndexes(orusPassages(), [
        'Typhoon signal number three was raised over Cebu province on Tuesday evening',
        'Short span',
    ]))->toBe([]);
});

it('does not mark a passage for sharing only common words with the span', function () {
    $reader = app(WebPageReader::class);

    expect($reader->citedIndexes(orusPassages(), [
        'The office may be contacted for the place of business of the taxpayer on Monday',
    ]))->not->toContain(0)->not->toContain(2);
});

it('marks nothing for a span whose specific words the page never uses', function () {
    $reader = app(WebPageReader::class);

    expect($reader->citedIndexes(orusPassages(), [
        'The annual registration fee of P500.00 was abolished and the penalty for late renewal of the permit was removed',
    ]))->toBe([]);
});

it('still prefers a verbatim match', function () {
    $reader = app(WebPageReader::class);

    expect($reader->citedIndexes(orusPassages(), [
        'Contact the Revenue District Office having jurisdiction over the place of business',
    ]))->toBe([3]);
});
