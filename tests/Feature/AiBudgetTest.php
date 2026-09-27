<?php

use App\Models\AiUsage;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\UsageBudgetWarning;
use App\Services\Billing\AiBudget;
use App\Services\Billing\AiCosting;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->plan = Plan::factory()->pro()->create(['ai_budget_usd_cents' => 1000]);
    $this->user = User::factory()->create();
    $this->subscription = Subscription::factory()->for($this->user)->create([
        'plan_id' => $this->plan->id,
        'status' => Subscription::STATUS_ACTIVE,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);
});

function budgetUser(): User
{
    return test()->user->fresh();
}

it('reserves and settles a turn against the anniversary window', function () {
    $reservation = AiBudget::reserve(budgetUser(), AiUsage::OPERATION_CHAT);

    expect($reservation->status)->toBe(AiUsage::STATUS_RESERVED)
        ->and($reservation->cost_usd)->toBeGreaterThan(0);

    AiBudget::settle($reservation, [
        'provider' => 'anthropic',
        'model' => 'claude-haiku-4-5',
        'input_tokens' => 100_000,
        'output_tokens' => 10_000,
    ]);

    $reservation->refresh();

    // 100000 * $1 + 10000 * $5 per million = $0.15.
    expect($reservation->status)->toBe(AiUsage::STATUS_SETTLED)
        ->and($reservation->cost_usd)->toBe(0.15)
        ->and($reservation->rate_version)->toBe(AiCosting::RATE_VERSION);

    $snapshot = AiBudget::snapshot(budgetUser());

    expect($snapshot['budget_usd'])->toBe(10.0)
        ->and($snapshot['used_usd'])->toBe(0.15)
        ->and($snapshot['percent'])->toBe(1.5)
        ->and($snapshot['warning'])->toBeFalse()
        ->and($snapshot['exhausted'])->toBeFalse();
});

it('refuses new work at 100 percent with a reset date', function () {
    $user = budgetUser();

    AiBudget::reserve($user, AiUsage::OPERATION_CHAT, 9.99);
    $second = AiBudget::reserve($user, AiUsage::OPERATION_CHAT, 0.005);
    AiBudget::settle($second, ['cost_usd' => 0.005]);

    // $9.99 hold + $0.005 settled of $10: the next estimate no longer fits.
    expect(fn () => AiBudget::reserve($user, AiUsage::OPERATION_CHAT, 0.02))
        ->toThrow(HttpResponseException::class);

    try {
        AiBudget::reserve($user, AiUsage::OPERATION_CHAT, 0.02);
        $this->fail('Expected a 402.');
    } catch (HttpResponseException $e) {
        $payload = $e->getResponse()->getData(true);

        expect($payload['upgrade_required'])->toBeTrue()
            ->and($payload['usage_reset_at'])->not->toBeNull()
            ->and($payload['usage_percent'])->toBeGreaterThanOrEqual(99.0);
    }
});

it('releases the hold when nothing billable ran', function () {
    $user = budgetUser();

    $reservation = AiBudget::reserve($user, AiUsage::OPERATION_CHAT, 0.05);
    AiBudget::release($reservation, failed: true);

    expect($reservation->fresh()->status)->toBe(AiUsage::STATUS_FAILED)
        ->and(AiBudget::snapshot($user)['used_usd'])->toBe(0.0);
});

it('settles twice only once', function () {
    $reservation = AiBudget::reserve(budgetUser(), AiUsage::OPERATION_CHAT, 0.05);

    AiBudget::settle($reservation, ['cost_usd' => 0.02]);
    AiBudget::settle($reservation, ['cost_usd' => 0.02]);

    expect($reservation->fresh()->cost_usd)->toBe(0.02);
    expect(AiBudget::snapshot(budgetUser())['used_usd'])->toBe(0.02);
});

it('settling under the estimate hands spend back', function () {
    $user = budgetUser();

    $reservation = AiBudget::reserve($user, AiUsage::OPERATION_CHAT, 0.08);
    AiBudget::settle($reservation, ['cost_usd' => 0.01]);

    // Used is the measured $0.01, not the $0.08 hold.
    expect(AiBudget::snapshot($user)['used_usd'])->toBe(0.01);
});

it('reuses the idempotency key instead of double-charging', function () {
    $user = budgetUser();

    $first = AiBudget::reserve($user, AiUsage::OPERATION_CHAT, 0.05, 'turn:abc');
    $second = AiBudget::reserve($user, AiUsage::OPERATION_CHAT, 0.05, 'turn:abc');

    expect($second->id)->toBe($first->id)
        ->and(AiUsage::query()->where('idempotency_key', 'turn:abc')->count())->toBe(1);
});

it('warns once at eighty percent', function () {
    $user = budgetUser();

    Notification::fake();

    $reservation = AiBudget::reserve($user, AiUsage::OPERATION_CHAT, 0.05);
    AiBudget::settle($reservation, ['cost_usd' => 8.5]);

    $snapshot = AiBudget::snapshot($user);

    expect($snapshot['warning'])->toBeTrue()
        ->and($snapshot['warned_80_at'])->not->toBeNull();

    Notification::assertSentTo($user, UsageBudgetWarning::class);

    // Crossing again sends nothing new.
    $another = AiBudget::reserve($user, AiUsage::OPERATION_CHAT, 0.05);
    AiBudget::settle($another, ['cost_usd' => 0.5]);

    Notification::assertSentToTimes($user, UsageBudgetWarning::class, 1);
});

it('pools firm spend across the workspace', function () {
    $firm = Plan::factory()->firm()->create(['ai_budget_usd_cents' => 10300]);
    $org = Organization::factory()->create();
    $owner = User::factory()->memberOf($org, User::ORG_ROLE_OWNER)->create();
    $member = User::factory()->memberOf($org)->create();
    Subscription::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $owner->id,
        'plan_id' => $firm->id,
        'status' => Subscription::STATUS_ACTIVE,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    $reservation = AiBudget::reserve($member->fresh(), AiUsage::OPERATION_CHAT, 0.05);
    AiBudget::settle($reservation, ['cost_usd' => 1.0]);

    // One shared window: the member's spend shows on the owner's meter.
    expect(AiBudget::snapshot($owner->fresh())['used_usd'])->toBe(1.0)
        ->and(AiBudget::snapshot($owner->fresh())['budget_usd'])->toBe(103.0);
});

it('uses the configured usage multipliers in the AI endpoint meter', function () {
    $standard = Plan::factory()->standard()->create();
    $firm = Plan::factory()->firm()->create();
    $this->plan->update(['ai_budget_usd_cents' => 1500]);
    $pro = $this->plan->fresh();

    $standardUser = User::factory()->create();
    Subscription::factory()->for($standardUser)->create([
        'plan_id' => $standard->id,
        'status' => Subscription::STATUS_ACTIVE,
    ]);

    $firmUser = User::factory()->create();
    Subscription::factory()->for($firmUser)->create([
        'plan_id' => $firm->id,
        'status' => Subscription::STATUS_ACTIVE,
    ]);

    expect($standard->aiUsageMultiplier())->toBe(1)
        ->and($pro->aiUsageMultiplier())->toBe(3)
        ->and($firm->aiUsageMultiplier())->toBe(10)
        ->and(AiBudget::snapshot($standardUser)['budget_usd'])->toBe(5.15)
        ->and(AiBudget::snapshot(budgetUser())['budget_usd'])->toBe(15.0)
        ->and(AiBudget::snapshot($firmUser)['budget_usd'])->toBe(51.5);
});

it('ends the trial when its budget runs out', function () {
    $trialPlan = Plan::factory()->trial()->create(['ai_budget_usd_cents' => 200]);
    $user = User::factory()->create();
    $subscription = Subscription::factory()->for($user)->create([
        'plan_id' => $trialPlan->id,
        'status' => Subscription::STATUS_TRIALING,
        'trial_ends_at' => now()->addDays(10),
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    $reservation = AiBudget::reserve($user->fresh(), AiUsage::OPERATION_CHAT, 0.05);
    AiBudget::settle($reservation, ['cost_usd' => 2.0]);

    expect($subscription->fresh()->onTrial())->toBeFalse();
});

it('slices annual subscriptions into monthly windows', function () {
    $start = now()->startOfMonth();
    $this->subscription->forceFill([
        'interval' => 'annual',
        'current_period_start' => $start,
        'current_period_end' => $start->copy()->addYear(),
    ])->save();

    $window = AiBudget::windowFor($this->subscription->fresh());

    expect($window->window_start->toDateString())->toBe($start->toDateString())
        ->and($window->window_end->toDateString())->toBe($start->copy()->addMonth()->toDateString());
});

it('releases crashed reservations instead of holding budget hostage', function () {
    config(['billing.reservation_ttl_minutes' => 15]);

    $user = budgetUser();
    $stale = AiBudget::reserve($user, AiUsage::OPERATION_CHAT, 5.0);
    $stale->forceFill(['updated_at' => now()->subMinutes(30)])->save();

    // A fresh reserve prunes the stale hold first, so the full budget is free.
    AiBudget::reserve($user, AiUsage::OPERATION_CHAT, 9.0);

    expect($stale->fresh()->status)->toBe(AiUsage::STATUS_RELEASED);
});

it('costs local inference at zero', function () {
    expect(AiCosting::callCostUsd('ollama', 'qwen3.6:latest', 37774, 1000))->toBe(0.0)
        ->and(AiCosting::turnCostUsd([
            'provider' => 'ollama',
            'model' => 'qwen3.6:latest',
            'input_tokens' => 37774,
            'output_tokens' => 1000,
        ]))->toBe(0.0);
});

it('bills unknown cloud models at the frontier rate, never zero', function () {
    expect(AiCosting::callCostUsd('anthropic', 'claude-next-9', 1000, 100))->toBeGreaterThan(0);
});
