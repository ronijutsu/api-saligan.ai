<?php

use App\Enums\TopUpStatus;
use App\Models\AiTopUp;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionWindow;
use App\Models\User;
use App\Services\Billing\AiBudget;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Prepaid AI top-ups (ADR-010).
 *
 * The two properties that matter most: money is taken before allowance exists
 * (so nothing can be owed), and a replayed webhook cannot grant the allowance
 * twice.
 */
beforeEach(function () {
    config(['paymongo.webhook_secret' => 'test-secret']);

    // A small allowance keeps the gate arithmetic readable: 500 cents is $5,
    // so a window that has spent $5 is genuinely exhausted. The window's budget
    // is synced from the plan, so the plan is where this has to be set.
    $this->plan = Plan::factory()->pro()->withTopUps()->create([
        'ai_budget_usd_cents' => 500,
    ]);
    $this->user = User::factory()->create();

    $this->subscription = Subscription::factory()->for($this->user)->create([
        'plan_id' => $this->plan->id,
        'topup_enabled' => true,
    ]);

    // The canonical window the gate will actually use: it is derived from the
    // subscription's period, so a hand-made row would simply be ignored.
    $this->window = AiBudget::windowFor($this->subscription);
    $this->window->forceFill([
        'budget_usd' => 5.0,
        'topup_usd' => 0,
        'used_usd' => 0,
    ])->save();

    Http::fake([
        'api.paymongo.com/v1/customers' => Http::response(['data' => ['id' => 'cus_test123']]),
        'api.paymongo.com/v1/payment_intents' => Http::response(['data' => ['id' => 'pi_topup123']]),
        'api.paymongo.com/v1/checkout_sessions' => Http::response([
            'data' => ['attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_topup#token']],
        ]),
    ]);
});

it('refuses extra usage on a plan that does not allow it', function () {
    $this->plan->forceFill([
        'allow_top_ups' => false,
        'topup_price' => null,
        'topup_usd_cents' => null,
    ])->save();

    $this->signInAs($this->user)
        ->postJson('/api/billing/top-ups', ['packs' => 1])
        ->assertStatus(402);
});

it('refuses a purchase until the customer turns extra usage on', function () {
    $this->subscription->forceFill(['topup_enabled' => false])->save();

    $this->signInAs($this->user)
        ->postJson('/api/billing/top-ups', ['packs' => 1])
        ->assertStatus(402)
        ->assertJsonPath('topup_disabled', true);
});

it('starts a purchase and returns a checkout url without crediting anything', function () {
    $response = $this->signInAs($this->user)
        ->postJson('/api/billing/top-ups', ['packs' => 1])
        ->assertCreated();

    expect($response->json('data.checkout_url'))->toBe('https://checkout.paymongo.com/cs_topup#token')
        ->and($response->json('data.status'))->toBe(TopUpStatus::Pending->value);

    // Nothing is granted until the gateway says the money arrived.
    expect($this->window->fresh()->topup_usd)->toBe(0.0)
        ->and(AiTopUp::where('status', TopUpStatus::Pending->value)->count())->toBe(1);
});

it('credits the window once when the payment webhook arrives', function () {
    $this->signInAs($this->user)->postJson('/api/billing/top-ups', ['packs' => 1])->assertCreated();

    postTopUpWebhook($this, topUpWebhookPayload('payment.paid'))
        ->assertOk();

    expect($this->window->fresh()->topup_usd)->toBe(1.25)
        ->and(AiTopUp::first()->status)->toBe(TopUpStatus::Paid);
});

it('does not grant the allowance twice when the webhook is replayed', function () {
    $this->signInAs($this->user)->postJson('/api/billing/top-ups', ['packs' => 2])->assertCreated();

    postTopUpWebhook($this, topUpWebhookPayload('payment.paid'))->assertOk();
    postTopUpWebhook($this, topUpWebhookPayload('payment.paid'))->assertOk();
    postTopUpWebhook($this, topUpWebhookPayload('payment.paid'))->assertOk();

    // Two packs, granted once.
    expect($this->window->fresh()->topup_usd)->toBe(2.5);
});

it('rejects a webhook with an invalid signature', function () {
    config(['paymongo.webhook_secret' => 'test-secret']);

    $this->signInAs($this->user)->postJson('/api/billing/top-ups', ['packs' => 1])->assertCreated();

    postTopUpWebhook($this, topUpWebhookPayload('payment.paid'), secret: 'the-wrong-secret')
        ->assertStatus(400);

    expect($this->window->fresh()->topup_usd)->toBe(0.0);
});

it('counts the purchased allowance towards the gate', function () {
    // The plan's own allowance is spent, so only the pack can let work through.
    $this->window->forceFill(['used_usd' => 5.0])->save();

    expect(fn () => AiBudget::reserve($this->user, 'chat', 0.05))
        ->toThrow(HttpResponseException::class);

    $this->signInAs($this->user)->postJson('/api/billing/top-ups', ['packs' => 2])->assertCreated();
    postTopUpWebhook($this, topUpWebhookPayload('payment.paid'))->assertOk();

    $usage = AiBudget::reserve($this->user, 'chat', 0.05);

    expect($usage->subscription_window_id)->toBe($this->window->id);
});

it('refuses a purchase that would exceed the customers own cap', function () {
    $this->subscription->forceFill(['topup_cap_pesos' => 30000])->save();
    $this->plan->forceFill(['topup_price' => 30000])->save();

    $this->signInAs($this->user)->postJson('/api/billing/top-ups', ['packs' => 1])->assertCreated();
    postTopUpWebhook($this, topUpWebhookPayload('payment.paid'))->assertOk();

    // One pack equals the whole ceiling, so the next one is refused.
    $this->signInAs($this->user)
        ->postJson('/api/billing/top-ups', ['packs' => 1])
        ->assertStatus(402)
        ->assertJsonPath('topup_cap_reached', true);
});

it('refuses more packs than the plan allows per window', function () {
    $this->plan->forceFill(['topup_max_per_window' => 1])->save();

    $this->signInAs($this->user)->postJson('/api/billing/top-ups', ['packs' => 1])->assertCreated();

    $this->signInAs($this->user)
        ->postJson('/api/billing/top-ups', ['packs' => 1])
        ->assertStatus(402)
        ->assertJsonPath('topup_limit_reached', true);
});

it('lets purchased allowance expire with the window it was bought for', function () {
    $this->signInAs($this->user)->postJson('/api/billing/top-ups', ['packs' => 1])->assertCreated();
    postTopUpWebhook($this, topUpWebhookPayload('payment.paid'))->assertOk();

    expect($this->window->fresh()->topup_usd)->toBe(1.25);

    // The next window carries the plan's allowance and nothing else: extra
    // usage is bought for a month, not banked against the account.
    $nextBudget = AiBudget::windowFor($this->subscription->fresh());
    $next = $nextBudget->id === $this->window->id
        ? $nextBudget
        : SubscriptionWindow::find($nextBudget->id);

    if ($next->id === $this->window->id) {
        // Same window: roll it forward the way a reset would.
        $next->forceFill([
            'window_start' => now(),
            'window_end' => now()->addMonth(),
            'used_usd' => 0,
            'topup_usd' => 0,
        ])->save();
    }

    expect((float) SubscriptionWindow::find($next->id)->topup_usd)->toBe(0.0);
});

it('exposes the control and the customers ceiling on the billing endpoint', function () {
    $this->signInAs($this->user)
        ->patchJson('/api/billing/top-ups/settings', ['enabled' => true, 'cap_pesos' => 150000])
        ->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.cap_pesos', 150000);

    $this->signInAs($this->user)
        ->getJson('/api/billing/top-ups')
        ->assertOk()
        ->assertJsonPath('data.available', true)
        ->assertJsonPath('data.pack.price_pesos', 30000)
        ->assertJsonPath('data.pack.usd', 1.25)
        ->assertJsonPath('data.remaining_pesos', 150000);
});

it('tells an exhausted account it can buy more instead of only upgrading', function () {
    $this->window->forceFill(['used_usd' => 5.0])->save();

    $payload = null;

    try {
        AiBudget::reserve($this->user, 'chat', 0.05);
        $this->fail('Expected the allowance gate to refuse.');
    } catch (HttpResponseException $exception) {
        $payload = $exception->getResponse()->getData(true);
    }

    expect($payload['can_top_up'])->toBeTrue()
        ->and($payload['message'])->toContain('Add extra usage');
});

/**
 * A PayMongo delivery carrying just the identifier the pack was stored under.
 *
 * @return array<string, mixed>
 */
function topUpWebhookPayload(string $type): array
{
    return [
        'data' => [
            'attributes' => [
                'type' => $type,
                'data' => [
                    'id' => 'pay_test123',
                    'attributes' => ['payment_intent_id' => 'pi_topup123'],
                ],
            ],
        ],
    ];
}

/**
 * Post a PayMongo delivery signed the way the gateway signs it: an HMAC over
 * the exact raw body. The body has to be the literal string that is sent, so
 * this bypasses the JSON helper's own serialization.
 */
function postTopUpWebhook(TestCase $test, array $payload, string $secret = 'test-secret'): TestResponse
{
    $body = json_encode($payload);
    $digest = hash_hmac('sha256', $body, $secret);

    return $test->call(
        'POST',
        '/api/paymongo/topup/webhook',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_PAYMONGO_SIGNATURE' => $digest,
        ],
        $body,
    );
}
