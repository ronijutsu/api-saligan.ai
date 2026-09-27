<?php

namespace Database\Seeders;

use App\Enums\LegalSourceCategory;
use App\Models\LegalSource;
use Illuminate\Database\Seeder;

class LegalSourceSeeder extends Seeder
{
    /**
     * Seed the allowlist of official Philippine legal sources to crawl.
     */
    public function run(): void
    {
        $sources = [
            [
                'name' => 'Supreme Court E-Library',
                'base_domain' => 'elibrary.judiciary.gov.ph',
                'seed_urls' => ['https://elibrary.judiciary.gov.ph/thebookshelf/showdocs'],
                'category' => LegalSourceCategory::Jurisprudence,
            ],
            [
                'name' => 'LawPhil',
                'base_domain' => 'lawphil.net',
                'seed_urls' => [
                    'https://lawphil.net/statutes/repacts/repacts.html',
                    'https://lawphil.net/judjuris/judjuris.html',
                ],
                'category' => LegalSourceCategory::Law,
            ],
            [
                'name' => 'Official Gazette',
                'base_domain' => 'officialgazette.gov.ph',
                'seed_urls' => ['https://www.officialgazette.gov.ph/laws/'],
                'category' => LegalSourceCategory::Law,
            ],
            [
                'name' => 'Land Registration Authority',
                'base_domain' => 'lra.gov.ph',
                'seed_urls' => ['https://www.lra.gov.ph/legal-issuances'],
                'category' => LegalSourceCategory::Issuance,
            ],
            [
                'name' => 'Department of Agrarian Reform',
                'base_domain' => 'dar.gov.ph',
                'seed_urls' => ['https://www.dar.gov.ph/legal-issuances'],
                'category' => LegalSourceCategory::Issuance,
            ],
            [
                'name' => 'Supreme Court Website',
                'base_domain' => 'sc.judiciary.gov.ph',
                'seed_urls' => ['https://sc.judiciary.gov.ph/important-judgments/'],
                'category' => LegalSourceCategory::Jurisprudence,
            ],
        ];

        foreach ($sources as $source) {
            LegalSource::updateOrCreate(
                ['base_domain' => $source['base_domain']],
                $source,
            );
        }

        // Registered so a page cited from one of these publishers can be
        // captured (the capture job attaches a page to the source that owns
        // its domain), but not crawled site-wide until an admin switches the
        // source on. Created once and never overwritten, so re-seeding cannot
        // undo an admin's choice.
        $onDemand = [
            ['Senate of the Philippines', 'senate.gov.ph', LegalSourceCategory::Law],
            ['Congress of the Philippines', 'congress.gov.ph', LegalSourceCategory::Law],
            ['Court of Appeals', 'ca.judiciary.gov.ph', LegalSourceCategory::Jurisprudence],
            ['Department of Labor and Employment', 'dole.gov.ph', LegalSourceCategory::Issuance],
            ['Securities and Exchange Commission', 'sec.gov.ph', LegalSourceCategory::Issuance],
            ['Department of Justice', 'doj.gov.ph', LegalSourceCategory::Issuance],
            ['National Privacy Commission', 'privacy.gov.ph', LegalSourceCategory::Issuance],
            ['Department of Human Settlements and Urban Development', 'dhsud.gov.ph', LegalSourceCategory::Issuance],
            ['Intellectual Property Office of the Philippines', 'ipophil.gov.ph', LegalSourceCategory::Issuance],
            ['Bureau of Internal Revenue', 'bir.gov.ph', LegalSourceCategory::Issuance],
            ['Department of Environment and Natural Resources', 'denr.gov.ph', LegalSourceCategory::Issuance],
        ];

        foreach ($onDemand as [$name, $domain, $category]) {
            LegalSource::firstOrCreate(
                ['base_domain' => $domain],
                [
                    'name' => $name,
                    'seed_urls' => ["https://www.{$domain}/"],
                    'category' => $category,
                    'is_active' => false,
                ],
            );
        }
    }
}
