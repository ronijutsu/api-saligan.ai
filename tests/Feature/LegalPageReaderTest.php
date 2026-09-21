<?php

use App\Enums\CrawlStatus;
use App\Enums\LegalSourceCategory;
use App\Jobs\CaptureCitedLegalPage;
use App\Models\CrawledPage;
use App\Models\LegalChunk;
use App\Models\LegalSource;
use App\Models\User;
use Database\Seeders\LegalSourceSeeder;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('serves a crawled authority with its chunks in reading order', function () {
    $page = CrawledPage::factory()->for(LegalSource::factory())->create([
        'law_name' => 'RA No. 6657',
        'category' => LegalSourceCategory::Law,
        'digest' => 'Nature: A statute establishing agrarian reform.',
        'crawl_status' => CrawlStatus::Ok,
    ]);

    // Created out of order to prove the endpoint sorts rather than relying on
    // insertion order — the reader renders these as the document body.
    LegalChunk::factory()->for($page)->create(['chunk_index' => 2, 'content' => 'Section 6. Retention limits.']);
    LegalChunk::factory()->for($page)->create(['chunk_index' => 0, 'content' => 'Section 2. Declaration of policy.']);
    LegalChunk::factory()->for($page)->create(['chunk_index' => 1, 'content' => 'Section 4. Scope.']);

    $response = $this->signInAs($this->user)
        ->getJson("/api/legal-pages/{$page->id}")
        ->assertOk();

    expect($response->json('data.law_name'))->toBe('RA No. 6657')
        ->and($response->json('data.category'))->toBe('law')
        ->and($response->json('data.kind'))->toBe('crawled')
        ->and($response->json('data.has_digest'))->toBeTrue()
        ->and($response->json('data.chunks.*.index'))->toBe([0, 1, 2])
        ->and($response->json('data.chunks.0.content'))->toBe('Section 2. Declaration of policy.');
});

it('serves standard metadata in the existing reader response', function () {
    $page = CrawledPage::factory()->standard()->create([
        'title' => 'Universal financial messaging standard',
        'url' => 'https://www.iso.org/standard/20022.html',
        'standard_code' => 'ISO 20022',
        'standard_edition' => '2019',
        'standard_issuer' => 'ISO',
        'standard_status' => 'current',
        'standard_publication_date' => '2019-11-01',
        'standard_review_date' => '2024-11-01',
        'rights_basis' => 'licensed_copy',
        'digest' => 'A standard for structured financial messages.',
        'crawl_status' => CrawlStatus::Ok,
    ]);
    LegalChunk::factory()->for($page)->create(['content' => 'Standard content.']);

    $response = $this->signInAs($this->user)
        ->getJson("/api/legal-pages/{$page->id}")
        ->assertOk();

    expect($response->json('data.knowledge_type'))->toBe('standard')
        ->and($response->json('data.standard_code'))->toBe('ISO 20022')
        ->and($response->json('data.standard_edition'))->toBe('2019')
        ->and($response->json('data.standard_issuer'))->toBe('ISO')
        ->and($response->json('data.standard_status'))->toBe('current')
        ->and($response->json('data.standard_publication_date'))->toBe('2019-11-01')
        ->and($response->json('data.standard_review_date'))->toBe('2024-11-01')
        ->and($response->json('data.rights_basis'))->toBe('licensed_copy')
        ->and($response->json('data.title'))->toBe('Universal financial messaging standard')
        ->and($response->json('data.url'))->toBe('https://www.iso.org/standard/20022.html')
        ->and($response->json('data.has_digest'))->toBeTrue();
});

it('requires authentication to read an authority', function () {
    $page = CrawledPage::factory()->for(LegalSource::factory())->create();

    $this->getJson("/api/legal-pages/{$page->id}")->assertUnauthorized();
});

it('resolves an external URL to the crawled page holding it', function () {
    $page = CrawledPage::factory()->for(LegalSource::factory())->create([
        'url' => 'https://elibrary.judiciary.gov.ph/thebookshelf/showdocsfriendly/1/67891',
        'crawl_status' => CrawlStatus::Ok,
    ]);

    $this->signInAs($this->user)
        ->getJson('/api/legal-pages/resolve?url='.urlencode('https://elibrary.judiciary.gov.ph/thebookshelf/showdocsfriendly/1/67891/'))
        ->assertOk()
        ->assertJsonPath('data.id', $page->id);
});

it('returns nothing for a URL that has not been crawled', function () {
    $this->signInAs($this->user)
        ->getJson('/api/legal-pages/resolve?url='.urlencode('https://elibrary.judiciary.gov.ph/thebookshelf/showdocsfriendly/1/99999'))
        ->assertOk()
        ->assertJsonPath('data', null);
});

it('does not resolve a page whose crawl failed', function () {
    CrawledPage::factory()->for(LegalSource::factory())->create([
        'url' => 'https://lawphil.net/statutes/repacts/ra1988/ra_6657_1988.html',
        'crawl_status' => CrawlStatus::Failed,
    ]);

    $this->signInAs($this->user)
        ->getJson('/api/legal-pages/resolve?url='.urlencode('https://lawphil.net/statutes/repacts/ra1988/ra_6657_1988.html'))
        ->assertOk()
        ->assertJsonPath('data', null);
});

it('captures only official legal domains', function () {
    // The URL comes from a web-search result, which the model influences, so
    // this job must never be a way to make the server fetch arbitrary hosts.
    expect(CaptureCitedLegalPage::shouldCapture('https://elibrary.judiciary.gov.ph/thebookshelf/showdocsfriendly/1/67891'))->toBeTrue()
        ->and(CaptureCitedLegalPage::shouldCapture('https://lawphil.net/statutes/ra_6657.html'))->toBeTrue()
        ->and(CaptureCitedLegalPage::shouldCapture('https://evil.example.com/malware'))->toBeFalse()
        ->and(CaptureCitedLegalPage::shouldCapture('http://169.254.169.254/latest/meta-data/'))->toBeFalse()
        ->and(CaptureCitedLegalPage::shouldCapture('not-a-url'))->toBeFalse();
});

it('captures pages from the other official publishers, and every allowed host has a source that owns it', function () {
    expect(CaptureCitedLegalPage::shouldCapture('https://www.senate.gov.ph/republic_acts/ra%209653.pdf'))->toBeTrue()
        ->and(CaptureCitedLegalPage::shouldCapture('https://ca.judiciary.gov.ph/decisions/1'))->toBeTrue()
        ->and(CaptureCitedLegalPage::shouldCapture('https://www.dole.gov.ph/issuances/da-1'))->toBeTrue();

    $this->seed(LegalSourceSeeder::class);

    // A host with no owning source is skipped by the capture job, so the
    // allowlist and the seeded sources have to stay in step.
    foreach (CaptureCitedLegalPage::allowedHosts() as $host) {
        expect(LegalSource::query()->whereIn('base_domain', [$host, preg_replace('/^www\./', '', $host)])->exists())
            ->toBeTrue("No legal source owns {$host}");
    }

    expect(LegalSource::query()->where('base_domain', 'dole.gov.ph')->value('is_active'))->toBeFalse();
});

it('does not capture a page already in the knowledge base', function () {
    $url = 'https://elibrary.judiciary.gov.ph/thebookshelf/showdocsfriendly/1/67891';

    expect(CaptureCitedLegalPage::shouldCapture($url))->toBeTrue();

    CrawledPage::factory()->for(LegalSource::factory())->create(['url' => $url]);

    expect(CaptureCitedLegalPage::shouldCapture($url))->toBeFalse();
});
