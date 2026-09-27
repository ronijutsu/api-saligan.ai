<?php

namespace App\Support;

use App\Models\User;

/**
 * What a plan can do, as opposed to how much of it {@see PlanLimits} allows.
 *
 * Every key in the CAPABILITIES group below is enforced somewhere in the
 * application — that is the rule this class exists to keep. A feature listed
 * on the pricing page that no code checks is a promise nobody can break and a
 * difference nobody can feel, which is what the previous flat list of strings
 * had become: `templates`, `exports`, and `web_search` sat on every tier
 * including the trial, and `expanded_capacity` could not be verified even in
 * principle.
 *
 * The SERVICES group is the deliberate exception. Setup, training, and support
 * response times are delivered by people under a contract, so no request can
 * be refused for lacking them. They are kept because they are real, and
 * grouped separately so the pricing table can render them apart from the
 * capability ladder rather than as ticks that imply a gate.
 */
final class PlanFeatures
{
    public const GROUP_CAPABILITY = 'capability';

    public const GROUP_SERVICE = 'service';

    /** Fill a template and generate a DOCX from it. Reading the library is free. */
    public const DRAFTING = 'drafting';

    /** Export an answer or draft as a Word document. */
    public const EXPORTS = 'exports';

    /** Upload, extract, preview, and download PDF documents. */
    public const PDF_DOCUMENTS = 'pdf_documents';

    /** Offer the chat model live web search on a turn. */
    public const WEB_SEARCH = 'web_search';

    /** OCR scans and photos, and file uploads into case-file categories. */
    public const DOCUMENT_INTELLIGENCE = 'document_intelligence';

    /** A larger retrieval and web-search budget on every answer. */
    public const DEEP_RESEARCH = 'deep_research';

    /** Answers written by the frontier model rather than the base one. */
    public const FRONTIER_MODEL = 'frontier_model';

    /** Create an organization, invite members, and buy seats. */
    public const TEAMS = 'teams';

    /** Track client profiles and move intake through pipeline stages. */
    public const CRM = 'crm';

    /** Connect Google Drive and Microsoft SharePoint add-ons. */
    public const INTEGRATIONS = 'integrations';

    /**
     * The one support promise. There is deliberately no "priority support"
     * tier beneath it: a queue position is not something a reader can check,
     * and offering two grades of support invited the question of what the
     * lesser one actually guaranteed. Either a plan can reach us at any hour
     * or it cannot.
     */
    public const SUPPORT_24_7 = 'support_24_7';

    public const GUIDED_SETUP = 'guided_setup';

    public const TEAM_TRAINING = 'team_training';

    /**
     * Every feature, in the order the pricing table reads them, with the copy
     * both frontends render.
     *
     * This is the single source of truth for feature labels. The app's pricing
     * page and the marketing table both build their rows from it rather than
     * from hand-maintained arrays that had already drifted apart.
     *
     * @return array<string, array{label: string, description: string, group: string}>
     */
    public static function catalogue(): array
    {
        return [
            self::DRAFTING => [
                'label' => 'Document drafting',
                'description' => 'Fill a legal template from your matter and generate the document.',
                'group' => self::GROUP_CAPABILITY,
            ],
            self::EXPORTS => [
                'label' => 'Word document export',
                'description' => 'Export any answer or draft as a formatted Word file.',
                'group' => self::GROUP_CAPABILITY,
            ],
            self::PDF_DOCUMENTS => [
                'label' => 'PDF access & export',
                'description' => 'Upload, read, preview, download, and export PDF documents.',
                'group' => self::GROUP_CAPABILITY,
            ],
            self::WEB_SEARCH => [
                'label' => 'Live web search',
                'description' => 'Check for amendments and find law published since the last crawl.',
                'group' => self::GROUP_CAPABILITY,
            ],
            self::DOCUMENT_INTELLIGENCE => [
                'label' => 'Scan & photo reading',
                'description' => 'Transcribe scanned and photographed documents, and file them into the case automatically.',
                'group' => self::GROUP_CAPABILITY,
            ],
            self::DEEP_RESEARCH => [
                'label' => 'Deep research',
                'description' => 'More authorities retrieved and more searches run before each answer.',
                'group' => self::GROUP_CAPABILITY,
            ],
            self::FRONTIER_MODEL => [
                'label' => 'Frontier answer model',
                'description' => 'Answers written by our most capable model, for longer and harder questions.',
                'group' => self::GROUP_CAPABILITY,
            ],
            self::TEAMS => [
                'label' => 'Team accounts',
                'description' => 'Invite colleagues onto shared matters and buy seats as the team grows.',
                'group' => self::GROUP_CAPABILITY,
            ],
            self::CRM => [
                'label' => 'Client management (CRM)',
                'description' => 'Track client profiles and move intake through configurable pipeline stages.',
                'group' => self::GROUP_CAPABILITY,
            ],
            self::INTEGRATIONS => [
                'label' => 'Google Drive & Microsoft SharePoint add-ons',
                'description' => 'Connect Google Drive and Microsoft SharePoint to import, sync, and save matter documents.',
                'group' => self::GROUP_CAPABILITY,
            ],
            self::SUPPORT_24_7 => [
                'label' => '24/7 support',
                'description' => 'Reach us outside office hours, including weekends.',
                'group' => self::GROUP_SERVICE,
            ],
            self::GUIDED_SETUP => [
                'label' => 'Guided setup',
                'description' => 'We configure the account and import your existing matters with you.',
                'group' => self::GROUP_SERVICE,
            ],
            self::TEAM_TRAINING => [
                'label' => 'Team training',
                'description' => 'A live session training your team on the product.',
                'group' => self::GROUP_SERVICE,
            ],
        ];
    }

    /**
     * The keys that some code path actually refuses a request for.
     *
     * Used by the test that holds this class to its own rule, and by the
     * pricing table to decide which rows belong in the capability ladder.
     *
     * @return list<string>
     */
    public static function capabilities(): array
    {
        return array_keys(array_filter(
            self::catalogue(),
            fn (array $entry): bool => $entry['group'] === self::GROUP_CAPABILITY,
        ));
    }

    /**
     * Whether the user's plan carries a feature.
     *
     * Admins carry everything, matching {@see PlanLimits::hasActiveAccess()} —
     * an account that bypasses the allowance checks would otherwise still be
     * stopped by a capability check, which is a confusing half-bypass.
     */
    public static function has(User $user, string $feature): bool
    {
        if ($user->is_admin) {
            return true;
        }

        $features = $user->subscription?->plan?->features;

        return is_array($features) && in_array($feature, $features, true);
    }

    /**
     * Abort with a 402 when the user's plan does not carry the feature.
     *
     * Access is checked first so someone with no subscription at all is told
     * that, rather than being told to upgrade a plan they do not have.
     */
    public static function ensureHas(User $user, string $feature): void
    {
        PlanLimits::ensureActiveAccess($user);

        if (self::has($user, $feature)) {
            return;
        }

        abort(UpgradeResponse::make(self::unavailableMessage($feature)));
    }

    /**
     * Why the request was refused, named after the thing they were trying to
     * do rather than after the feature key behind it.
     */
    protected static function unavailableMessage(string $feature): string
    {
        $label = self::catalogue()[$feature]['label'] ?? str_replace('_', ' ', $feature);

        return "{$label} is not included in your plan. Upgrade to use it.";
    }
}
