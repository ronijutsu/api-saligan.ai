<?php

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\PlanFeatures;

it('backfills PDF access onto every paid plan', function (): void {
    foreach ([Plan::SLUG_STANDARD, Plan::SLUG_PRO, Plan::SLUG_FIRM] as $slug) {
        Plan::factory()->create([
            'slug' => $slug,
            'features' => [PlanFeatures::DRAFTING, PlanFeatures::EXPORTS, PlanFeatures::WEB_SEARCH],
        ]);
    }

    $migration = require base_path('database/migrations/2026_09_13_000001_add_pdf_feature_to_paid_plans.php');

    $migration->up();
    $migration->up();

    foreach (Plan::whereIn('slug', [Plan::SLUG_STANDARD, Plan::SLUG_PRO, Plan::SLUG_FIRM])->get() as $plan) {
        expect($plan->fresh()->features)->toContain(PlanFeatures::PDF_DOCUMENTS);
    }
});

it('backfills CRM access onto every paid plan and leaves the trial untouched', function (): void {
    foreach ([Plan::SLUG_TRIAL, Plan::SLUG_STANDARD, Plan::SLUG_PRO, Plan::SLUG_FIRM] as $slug) {
        Plan::factory()->create([
            'slug' => $slug,
            'features' => [PlanFeatures::DRAFTING, PlanFeatures::EXPORTS, PlanFeatures::WEB_SEARCH],
            'is_active' => $slug !== Plan::SLUG_TRIAL,
        ]);
    }

    $migration = require base_path('database/migrations/2026_09_15_000001_add_crm_feature_to_paid_plans.php');

    $migration->up();
    $migration->up();

    foreach ([Plan::SLUG_STANDARD, Plan::SLUG_PRO, Plan::SLUG_FIRM] as $slug) {
        expect(Plan::where('slug', $slug)->firstOrFail()->features)->toContain(PlanFeatures::CRM);
    }

    expect(Plan::where('slug', Plan::SLUG_TRIAL)->firstOrFail()->features)
        ->not->toContain(PlanFeatures::CRM);

    $migration->down();

    foreach ([Plan::SLUG_STANDARD, Plan::SLUG_PRO, Plan::SLUG_FIRM] as $slug) {
        expect(Plan::where('slug', $slug)->firstOrFail()->features)->not->toContain(PlanFeatures::CRM);
    }
});

it('moves legacy Business subscribers onto the annual-only Firm tier', function (): void {
    $firm = Plan::factory()->firm()->create([
        'annual_only' => false,
        'features' => [PlanFeatures::TEAMS],
    ]);
    $business = Plan::factory()->create([
        'slug' => Plan::SLUG_BUSINESS,
        'name' => 'Business',
        'features' => [PlanFeatures::GUIDED_SETUP, PlanFeatures::TEAM_TRAINING],
        'is_active' => true,
    ]);
    $user = User::factory()->create();
    $subscription = Subscription::factory()->for($user)->create([
        'plan_id' => $business->id,
    ]);

    $migration = require base_path('database/migrations/2026_09_13_000003_consolidate_business_plan_into_annual_firm.php');

    $migration->up();

    expect($firm->fresh()->annual_only)->toBeTrue()
        ->and($firm->fresh()->features)->toContain(
            PlanFeatures::TEAMS,
            PlanFeatures::GUIDED_SETUP,
            PlanFeatures::TEAM_TRAINING,
        )
        ->and($subscription->fresh()->plan_id)->toBe($firm->id)
        ->and($business->fresh()->is_active)->toBeFalse();
});
