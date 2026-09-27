<?php

use App\Enums\CrawlStatus;
use App\Enums\KnowledgeType;
use App\Enums\LegalSourceCategory;
use App\Jobs\CrawlLegalSourcePage;
use App\Models\CrawledPage;
use App\Models\LegalSource;
use App\Services\Ai\EmbeddingService;
use App\Services\Crawler\CrawlerAdapterFactory;
use App\Services\Crawler\RobotsTxt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('defaults existing authority rows to legal knowledge', function () {
    $source = LegalSource::factory()->create();
    $page = CrawledPage::factory()->for($source)->create();

    expect($source->fresh()->knowledge_type)->toBe(KnowledgeType::Legal)
        ->and($page->fresh()->knowledge_type)->toBe(KnowledgeType::Legal);
});

it('exposes the six core and five banking standard profiles', function () {
    expect(config('standards.profiles'))->toHaveKeys([
        'iso-9001', 'iso-iec-27001', 'iso-14001', 'iso-45001', 'iso-31000',
        'iso-37301', 'iso-20022', 'iso-8583', 'iso-9362', 'iso-4217', 'iso-17442',
    ]);

    expect(config('standards.profiles.iso-20022.code'))->toBe('ISO 20022')
        ->and(config('standards.profiles.iso-8583.code'))->toBe('ISO 8583')
        ->and(config('standards.profiles.iso-17442.code'))->toBe('ISO 17442');
});

it('casts standard metadata dates and enums', function () {
    $page = CrawledPage::factory()->uploaded()->create([
        'knowledge_type' => KnowledgeType::Standard,
        'category' => LegalSourceCategory::Standard,
        'standard_code' => 'ISO 20022',
        'standard_edition' => '2019',
        'standard_issuer' => 'ISO',
        'standard_status' => 'current',
        'standard_publication_date' => '2019-11-01',
        'standard_review_date' => '2024-11-01',
        'rights_basis' => 'licensed_copy',
    ]);

    expect($page->knowledge_type)->toBe(KnowledgeType::Standard)
        ->and($page->standard_publication_date->toDateString())->toBe('2019-11-01')
        ->and($page->standard_review_date->toDateString())->toBe('2024-11-01');
});

it('propagates standard metadata when crawling a standard source', function () {
    Storage::fake('local');

    $source = LegalSource::factory()->create([
        'base_domain' => 'iso.org',
        'knowledge_type' => KnowledgeType::Standard,
        'category' => LegalSourceCategory::Standard,
    ]);

    Http::fake([
        '*/robots.txt' => Http::response("User-agent: *\nDisallow:\n", 200),
        '*/embeddings' => Http::response(['embeddings' => [array_fill(0, 768, 0.25)]], 200),
        '*' => Http::response('<html><body><p>ISO standard summary.</p></body></html>', 200),
    ]);

    (new CrawlLegalSourcePage($source, 'https://iso.org/standard/20022'))
        ->handle(app(EmbeddingService::class), new CrawlerAdapterFactory, new RobotsTxt);

    $page = CrawledPage::where('url', 'https://iso.org/standard/20022')->firstOrFail();

    expect($page->knowledge_type)->toBe(KnowledgeType::Standard)
        ->and($page->category)->toBe(LegalSourceCategory::Standard)
        ->and($page->rights_basis)->toBe('public_summary')
        ->and($page->crawl_status)->toBe(CrawlStatus::Ok);
});
