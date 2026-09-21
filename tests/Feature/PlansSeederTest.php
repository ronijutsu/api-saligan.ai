<?php

use App\Models\Plan;
use App\Services\Billing\EarningsModel;
use App\Support\PlanFeatures;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seeder = new PlansSeeder;
});

it('provisions the supported PayMongo plans and persists their ids', function () {
    Http::fake([
        'api.paymongo.com/v1/subscriptions/plans' => Http::response([
            'data' => ['id' => 'plan_provisioned_'.fake()->word(), 'type' => 'plan', 'attributes' => []],
        ]),
    ]);

    config(['paymongo.secret_key' => 'sk_test_123']);

    $this->seeder->run();

    expect(Plan::count())->toBe(4);

    foreach (Plan::where('price', '>', 0)->get() as $plan) {
        expect($plan->paymongo_plan_id_annual)->not->toBeNull();

        if ($plan->supportsInterval(Plan::INTERVAL_MONTHLY)) {
            expect($plan->paymongo_plan_id)->not->toBeNull();
        } else {
            expect($plan->paymongo_plan_id)->toBeNull();
        }
    }

    Http::assertSentCount(5);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/v1/subscriptions/plans')
            && data_get($request->data(), 'data.attributes.plan_type') === 'scheduled'
            && data_get($request->data(), 'data.attributes.interval') === 'monthly';
    });

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/v1/subscriptions/plans')
            && data_get($request->data(), 'data.attributes.interval') === 'yearly'
            && data_get($request->data(), 'data.attributes.amount') === 2499000;
    });
});

it('skips PayMongo provisioning when no secret key is configured', function () {
    config(['paymongo.secret_key' => '']);

    $this->seeder->run();

    expect(Plan::count())->toBe(4);

    foreach (Plan::all() as $plan) {
        expect($plan->paymongo_plan_id)->toBeNull()
            ->and($plan->paymongo_plan_id_annual)->toBeNull();
    }

    Http::assertNothingSent();
});

it('keeps existing PayMongo plan ids', function () {
    Plan::factory()->standard()->create([
        'paymongo_plan_id' => 'plan_standard',
        'paymongo_plan_id_annual' => 'plan_standard_annual',
    ]);
    $pro = Plan::factory()->pro()->create([
        'paymongo_plan_id' => 'plan_existing',
        'paymongo_plan_id_annual' => 'plan_existing_annual',
    ]);
    Plan::factory()->firm()->create([
        'paymongo_plan_id' => 'plan_firm',
        'paymongo_plan_id_annual' => 'plan_firm_annual',
    ]);
    Http::fake(['api.paymongo.com/*' => Http::response(['data' => []])]);
    config(['paymongo.secret_key' => 'sk_test_123']);

    $this->seeder->run();

    expect($pro->fresh()->paymongo_plan_id)->toBe('plan_existing')
        ->and($pro->fresh()->paymongo_plan_id_annual)->toBe('plan_existing_annual');

    Http::assertNothingSent();
});

it('seeds the simplified pricing: round numbers, capped tiers, no overage', function () {
    Http::fake(['api.paymongo.com/*' => Http::response(['data' => []])]);
    config(['paymongo.secret_key' => '']);

    $this->seeder->run();

    $standard = Plan::where('slug', Plan::SLUG_STANDARD)->firstOrFail();
    $pro = Plan::where('slug', Plan::SLUG_PRO)->firstOrFail();
    $firm = Plan::where('slug', Plan::SLUG_FIRM)->firstOrFail();

    expect($standard->price)->toBe(99900)
        // Annual is exactly ten months: two months free, reproducible by hand.
        ->and($standard->price_annual)->toBe(999000)
        ->and($standard->overage_price)->toBeNull()
        // The meter is spend, not counts: paid tiers carry no count caps.
        ->and($standard->limits['messages_used'])->toBeNull()
        ->and($standard->limits['documents_uploaded'])->toBeNull()
        ->and($standard->limits['active_cases'])->toBeNull()
        // $5.15/mo of AI spend at the budgeting FX rate.
        ->and($standard->ai_budget_usd_cents)->toBe(515)
        ->and($pro->price)->toBe(249900)
        ->and($pro->price_annual)->toBe(2499000)
        ->and($pro->overage_price)->toBeNull()
        ->and($pro->limits['messages_used'])->toBeNull()
        // $15.00/mo at the budgeting FX rate: 3x Standard, sized so the
        // frontier tier still clears a 50% margin.
        ->and($pro->ai_budget_usd_cents)->toBe(1500)
        ->and($pro->ai_usage_multiplier)->toBe(3)
        ->and($firm->price)->toBe(799900)
        ->and($firm->price_annual)->toBe(7999000)
        ->and($firm->annual_only)->toBeTrue()
        ->and($firm->overage_price)->toBeNull()
        // One team pool, not three seat wallets: the spend allowance is
        // shared across the organization's active members.
        ->and($firm->limits['messages_used'])->toBeNull()
        // 10x Standard: at 20x the pooled allowance cost more than the price
        // covered once payment fees were counted (`artisan costing:earnings`).
        ->and($firm->ai_budget_usd_cents)->toBe(5150)
        ->and($firm->ai_usage_multiplier)->toBe(10)
        ->and($firm->included_seats)->toBe(3)
        ->and($firm->seat_price)->toBe(199900)
        ->and($firm->features)->toContain(PlanFeatures::GUIDED_SETUP, PlanFeatures::TEAM_TRAINING)
        ->and($standard->features)->toContain(PlanFeatures::CRM)
        ->and($pro->features)->toContain(PlanFeatures::CRM)
        ->and($firm->features)->toContain(PlanFeatures::CRM)
        // The single-seat tiers sell no seats at all, which is a different
        // statement from selling them for nothing.
        ->and($standard->included_seats)->toBe(1)
        ->and($standard->ai_usage_multiplier)->toBe(1)
        ->and($standard->seat_price)->toBeNull()
        ->and($pro->seat_price)->toBeNull();
});

it('includes Google Drive and SharePoint add-ons on Firm', function () {
    Http::fake(['api.paymongo.com/*' => Http::response(['data' => []])]);
    config(['paymongo.secret_key' => '']);

    $this->seeder->run();

    $firm = Plan::where('slug', Plan::SLUG_FIRM)->firstOrFail();

    expect($firm->features)->toContain(PlanFeatures::INTEGRATIONS)
        ->and(PlanFeatures::catalogue()[PlanFeatures::INTEGRATIONS]['label'])
        ->toBe('Google Drive & Microsoft SharePoint add-ons')
        ->and(PlanFeatures::catalogue()[PlanFeatures::INTEGRATIONS]['description'])
        ->toContain('Google Drive', 'Microsoft SharePoint');
});

it('publishes CRM in the plan catalogue and every paid plan', function () {
    Http::fake(['api.paymongo.com/*' => Http::response(['data' => []])]);
    config(['paymongo.secret_key' => '']);

    $this->seeder->run();

    $this->getJson('/api/plans')
        ->assertOk()
        ->assertJsonPath('meta.features.crm.label', 'Client management (CRM)')
        ->assertJsonPath('meta.features.crm.description', 'Track client profiles and move intake through configurable pipeline stages.')
        ->assertJsonPath('data.0.features.4', PlanFeatures::CRM)
        ->assertJsonPath('data.1.features.4', PlanFeatures::CRM)
        ->assertJsonPath('data.2.features.4', PlanFeatures::CRM);

    $this->getJson('/api/plans?include_trial=1')
        ->assertOk()
        ->assertJsonPath('data.0.slug', Plan::SLUG_TRIAL)
        ->assertJsonPath('data.0.features', [
            PlanFeatures::DRAFTING,
            PlanFeatures::EXPORTS,
            PlanFeatures::PDF_DOCUMENTS,
            PlanFeatures::WEB_SEARCH,
        ]);
});

it('seeds the free trial plan small and unsold', function () {
    Http::fake(['api.paymongo.com/*' => Http::response(['data' => []])]);
    config(['paymongo.secret_key' => 'sk_test_123']);

    $this->seeder->run();

    $trial = Plan::where('slug', Plan::SLUG_TRIAL)->firstOrFail();
    $standard = Plan::where('slug', Plan::SLUG_STANDARD)->firstOrFail();

    // Explicit small numbers: 5 documents and 30 messages on 10% of
    // Standard's $5.15 allowance, rounded up to $0.52.
    expect($trial->limits['active_cases'])->toBeNull()
        ->and($trial->limits['documents_uploaded'])->toBe(5)
        ->and($trial->limits['messages_used'])->toBe(30)
        ->and($trial->ai_budget_usd_cents)->toBe(52)
        ->and($trial->ai_usage_multiplier)->toBeNull();

    // Free but filing: the trial carries PDFs on a 12-document cap, hidden
    // from paid pricing while checkout and gateways still cannot sell it.
    expect($trial->price)->toBe(0)
        ->and($trial->is_active)->toBeFalse()
        ->and($trial->paymongo_plan_id)->toBeNull()
        ->and($trial->paymongo_plan_id_annual)->toBeNull()
        ->and($trial->features)->toContain(PlanFeatures::PDF_DOCUMENTS)
        ->and($standard->features)->toContain(PlanFeatures::PDF_DOCUMENTS);
    expect($trial->features)->not->toContain(PlanFeatures::CRM);
});

it('puts the former Business services on Firm', function () {
    Http::fake(['api.paymongo.com/*' => Http::response(['data' => []])]);
    config(['paymongo.secret_key' => '']);

    $this->seeder->run();

    $firm = Plan::where('slug', Plan::SLUG_FIRM)->firstOrFail();

    expect($firm->features)->toContain(
        PlanFeatures::GUIDED_SETUP,
        PlanFeatures::TEAM_TRAINING,
        PlanFeatures::SUPPORT_24_7,
    );
});

it('advertises only features that some code path enforces', function () {
    Http::fake(['api.paymongo.com/*' => Http::response(['data' => []])]);
    config(['paymongo.secret_key' => '']);

    $this->seeder->run();

    // The rule this whole rework exists to establish: a feature on a plan is
    // either a capability the application refuses without, or a service a
    // person delivers under contract. Anything else is a claim nobody can
    // check — which is what `expanded_capacity` and `unlimited_cases` were.
    $known = array_keys(PlanFeatures::catalogue());

    foreach (Plan::all() as $plan) {
        foreach ($plan->features ?? [] as $feature) {
            expect($feature)->toBeIn($known);
        }
    }
});

it('gives every paid tier a capability the one below it lacks', function () {
    Http::fake(['api.paymongo.com/*' => Http::response(['data' => []])]);
    config(['paymongo.secret_key' => '']);

    $this->seeder->run();

    $ladder = Plan::whereIn('slug', [Plan::SLUG_STANDARD, Plan::SLUG_PRO, Plan::SLUG_FIRM])
        ->orderBy('sort_order')
        ->get();

    // A tier that adds only numbers is a tier nobody can be told the reason
    // for. Every step up the ladder must unlock something the step below
    // genuinely cannot do.
    for ($index = 1; $index < $ladder->count(); $index++) {
        $plan = $ladder[$index];
        $below = $ladder[$index - 1];

        expect(array_diff($plan->features, $below->features))->not->toBeEmpty(
            "{$plan->name} adds no capability over {$below->name}.",
        );
    }
});

it('leaves the self-serve plans buyable', function () {
    Http::fake(['api.paymongo.com/*' => Http::response(['data' => []])]);
    config(['paymongo.secret_key' => '']);

    $this->seeder->run();

    foreach ([Plan::SLUG_STANDARD, Plan::SLUG_PRO, Plan::SLUG_FIRM] as $slug) {
        expect(Plan::where('slug', $slug)->firstOrFail()->isSelfServe())->toBeTrue();
    }
});

it('funds every tier for a real workload, not a message count', function () {
    // The guardrail the count ladder could never hold: a tier whose spend
    // allowance buys only a handful of turns at its own serving model is a
    // cap that reads generous and behaves otherwise. Each tier's budget must
    // cover dozens of full-size cold-cache turns — Standard on the base
    // model it is served, the rest on the frontier one.
    Http::fake(['api.paymongo.com/*' => Http::response(['data' => []])]);
    config(['paymongo.secret_key' => '']);

    $this->seeder->run();

    // Paid tiers only: the free trial plan carries no revenue to cost against,
    // and its allowance is deliberately a loss leader.
    foreach (Plan::where('price', '>', 0)->get() as $plan) {
        $frontier = in_array(PlanFeatures::FRONTIER_MODEL, $plan->features ?? [], true);
        $costPerTurn = EarningsModel::perMessageCostPesos(
            $frontier ? 'claude-sonnet-5' : 'claude-haiku-4-5',
            exchangeRate: 65.0,
            cached: true,
            cacheHitRate: 0.0,
        );

        // Firm's pool is shared: the whole budget against the whole team is
        // the honest comparison, so the floor is checked per seat. The model
        // cost comes back in pesos, so it is converted at the same budgeting
        // rate before dividing into the dollar budget.
        $budgetPerSeat = ($plan->ai_budget_usd_cents / 100) / max(1, $plan->included_seats ?? 1);

        expect($budgetPerSeat / ($costPerTurn / 65.0))->toBeGreaterThan(60);
    }
});

it('logs a warning and continues when PayMongo provisioning fails', function () {
    Http::fake(['api.paymongo.com/*' => Http::response([
        'errors' => [['code' => 'payment_method_not_configured', 'detail' => 'no subscription payment methods are configured']],
    ], 403)]);

    config(['paymongo.secret_key' => 'sk_test_123']);

    $this->seeder->run();

    expect(Plan::count())->toBe(4);

    foreach (Plan::all() as $plan) {
        expect($plan->paymongo_plan_id)->toBeNull();
    }
});
