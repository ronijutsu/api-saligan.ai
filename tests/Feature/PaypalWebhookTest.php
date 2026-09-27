<?php

use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Integrations\IntegrationEligibility;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // The entitlement sync branches on the subscription's organization, so an
    // organization-backed subscription is what these events describe. Without
    // it the controller takes the per-user branch and the org assertions below
    // can never be satisfied.
    $this->organization = Organization::factory()->create();
    $this->user = User::factory()->ownerOf($this->organization)->create();
    $this->plan = Plan::factory()->standard()->create();
    $this->subscription = Subscription::factory()->for($this->user)->create([
        'organization_id' => $this->organization->id,
        'plan_id' => $this->plan->id,
        'gateway' => Subscription::GATEWAY_PAYPAL,
        'paypal_subscription_id' => 'I-SUB-123',
        'status' => Subscription::STATUS_INCOMPLETE,
    ]);

    config([
        'paypal.base_url' => 'https://api-m.sandbox.paypal.com',
        'paypal.oauth_url' => 'https://api-m.sandbox.paypal.com/v1/oauth2/token',
        'paypal.client_id' => 'client-id',
        'paypal.client_secret' => 'client-secret',
        'paypal.webhook_id' => 'webhook-id',
    ]);

    Cache::flush();
});

function paypalWebhookHeaders(): array
{
    return [
        'Paypal-Auth-Algo' => 'SHA256withRSA',
        'Paypal-Cert-Url' => 'https://api.paypal.com/cert.pem',
        'Paypal-Transmission-Id' => 'transmission-id',
        'Paypal-Transmission-Sig' => 'transmission-signature',
        'Paypal-Transmission-Time' => '2026-08-26T12:00:00Z',
    ];
}

function fakePaypalWebhookVerification(bool $verified = true): void
{
    Http::fake([
        'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'access_token' => 'access-token',
            'expires_in' => 3600,
        ]),
        'https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response([
            'verification_status' => $verified ? 'SUCCESS' : 'FAILURE',
        ]),
    ]);
}

function paypalSubscriptionEvent(
    string $eventType,
    array $resource = [],
    string $createdAt = '2026-08-26T12:00:00Z',
    ?string $eventId = null,
): array {
    return [
        'id' => $eventId ?? 'WH-'.str_replace('.', '-', $eventType),
        'event_type' => $eventType,
        'create_time' => $createdAt,
        'resource' => ['id' => 'I-SUB-123', ...$resource],
    ];
}

it('activates a PayPal subscription from a verified webhook', function () {
    fakePaypalWebhookVerification();

    $payload = paypalSubscriptionEvent('BILLING.SUBSCRIPTION.ACTIVATED', [
        'status' => 'ACTIVE',
        'start_time' => '2026-08-26T12:00:00Z',
        'billing_info' => ['next_billing_time' => '2026-09-26T12:00:00Z'],
    ]);

    $this->postJson('/api/paypal/webhook', $payload, paypalWebhookHeaders())->assertOk();

    $subscription = $this->subscription->fresh();

    expect($subscription->status)->toBe(Subscription::STATUS_ACTIVE)
        ->and($subscription->current_period_start?->toDateString())->toBe('2026-08-26')
        ->and($subscription->current_period_end?->toDateString())->toBe('2026-09-26')
        ->and($subscription->cancelled_at)->toBeNull();
});

it('maps PayPal suspension and cancellation events to local statuses', function () {
    fakePaypalWebhookVerification();

    $this->postJson(
        '/api/paypal/webhook',
        paypalSubscriptionEvent('BILLING.SUBSCRIPTION.SUSPENDED', ['status' => 'SUSPENDED']),
        paypalWebhookHeaders(),
    )->assertOk();

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_PAUSED);

    $this->postJson(
        '/api/paypal/webhook',
        paypalSubscriptionEvent('BILLING.SUBSCRIPTION.CANCELLED', ['status' => 'CANCELLED']),
        paypalWebhookHeaders(),
    )->assertOk();

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_CANCELLED)
        ->and($this->subscription->fresh()->cancelled_at)->not->toBeNull();
});

it('reactivates the local subscription after a PayPal renewal sale', function () {
    fakePaypalWebhookVerification();

    $this->subscription->update(['status' => Subscription::STATUS_PAST_DUE]);

    $payload = paypalSubscriptionEvent('PAYMENT.SALE.COMPLETED', [
        'billing_agreement_id' => 'I-SUB-123',
    ]);

    $this->postJson('/api/paypal/webhook', $payload, paypalWebhookHeaders())->assertOk();

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_ACTIVE);
});

it('applies the revised PayPal plan only after the webhook arrives', function () {
    $pro = Plan::factory()->pro()->create();
    config(['paypal.plans.pro.monthly' => 'P-PRO-MONTHLY']);
    $this->subscription->update(['pending_plan_id' => $pro->id]);
    fakePaypalWebhookVerification();

    $payload = paypalSubscriptionEvent('BILLING.SUBSCRIPTION.UPDATED', [
        'status' => 'ACTIVE',
        'plan_id' => 'P-PRO-MONTHLY',
    ]);

    $this->postJson('/api/paypal/webhook', $payload, paypalWebhookHeaders())->assertOk();

    expect($this->subscription->fresh()->plan_id)->toBe($pro->id)
        ->and($this->subscription->fresh()->interval)->toBe('monthly');
});

it('applies an annual PayPal revision and clears its pending interval', function () {
    $pro = Plan::factory()->pro()->create();
    config(['paypal.plans.pro.annual' => 'P-PRO-ANNUAL']);
    $this->subscription->update([
        'status' => Subscription::STATUS_ACTIVE,
        'pending_plan_id' => $pro->id,
        'pending_plan_interval' => Plan::INTERVAL_ANNUAL,
    ]);
    fakePaypalWebhookVerification();

    $payload = paypalSubscriptionEvent('BILLING.SUBSCRIPTION.UPDATED', [
        'status' => 'ACTIVE',
        'plan_id' => 'P-PRO-ANNUAL',
    ]);

    $this->postJson('/api/paypal/webhook', $payload, paypalWebhookHeaders())->assertOk();

    expect($this->subscription->fresh()->plan_id)->toBe($pro->id)
        ->and($this->subscription->fresh()->interval)->toBe(Plan::INTERVAL_ANNUAL)
        ->and($this->subscription->fresh()->pending_plan_id)->toBeNull()
        ->and($this->subscription->fresh()->pending_plan_interval)->toBeNull();
});

it('does not clear an annual same-plan revision for a stale monthly webhook', function () {
    config([
        'paypal.plans.standard.monthly' => 'P-STANDARD-MONTHLY',
        'paypal.plans.standard.annual' => 'P-STANDARD-ANNUAL',
    ]);
    $this->subscription->update([
        'status' => Subscription::STATUS_ACTIVE,
        'pending_plan_id' => $this->plan->id,
        'pending_plan_interval' => Plan::INTERVAL_ANNUAL,
    ]);
    fakePaypalWebhookVerification();

    $payload = paypalSubscriptionEvent('BILLING.SUBSCRIPTION.UPDATED', [
        'status' => 'ACTIVE',
        'plan_id' => 'P-STANDARD-MONTHLY',
    ]);

    $this->postJson('/api/paypal/webhook', $payload, paypalWebhookHeaders())->assertOk();

    expect($this->subscription->fresh()->plan_id)->toBe($this->plan->id)
        ->and($this->subscription->fresh()->interval)->toBe(Plan::INTERVAL_MONTHLY)
        ->and($this->subscription->fresh()->pending_plan_id)->toBe($this->plan->id)
        ->and($this->subscription->fresh()->pending_plan_interval)->toBe(Plan::INTERVAL_ANNUAL);
});

it('ignores an older PayPal webhook after a newer event was processed', function () {
    $this->subscription->update([
        'status' => Subscription::STATUS_ACTIVE,
        'paypal_last_event_at' => '2026-08-26 13:00:00',
    ]);
    fakePaypalWebhookVerification();

    $payload = paypalSubscriptionEvent('BILLING.SUBSCRIPTION.UPDATED', [
        'status' => 'SUSPENDED',
    ], '2026-08-26T12:00:00Z');

    $this->postJson('/api/paypal/webhook', $payload, paypalWebhookHeaders())->assertOk();

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_ACTIVE);
});

it('syncs entitlements for the subscription organization', function () {
    $subscriptionOrganization = $this->subscription->organization;
    $differentOrganization = Organization::factory()->create();
    $this->user->update(['organization_id' => $differentOrganization->id]);
    $pro = Plan::factory()->pro()->create();
    config(['paypal.plans.pro.monthly' => 'P-PRO-MONTHLY']);
    $this->subscription->update(['pending_plan_id' => $pro->id]);

    $this->mock(IntegrationEligibility::class, function ($mock) use ($subscriptionOrganization) {
        $mock->shouldReceive('syncOrganization')->once()->with(
            Mockery::on(fn (Organization $organization): bool => $organization->getKey() === $subscriptionOrganization->getKey()),
        )->andReturn([
            'paused' => 0,
            'resumed' => 0,
        ]);
        $mock->shouldReceive('syncUser')->never();
    });

    fakePaypalWebhookVerification();

    $payload = paypalSubscriptionEvent('BILLING.SUBSCRIPTION.UPDATED', [
        'status' => 'ACTIVE',
        'plan_id' => 'P-PRO-MONTHLY',
    ]);

    $this->postJson('/api/paypal/webhook', $payload, paypalWebhookHeaders())->assertOk();

    expect($this->subscription->fresh()->plan_id)->toBe($pro->id);
});

it('retries a PayPal webhook when entitlement reconciliation fails', function () {
    $pro = Plan::factory()->pro()->create();
    config(['paypal.plans.pro.monthly' => 'P-PRO-MONTHLY']);
    $this->subscription->update(['pending_plan_id' => $pro->id]);
    $attempts = 0;

    $this->mock(IntegrationEligibility::class, function ($mock) use (&$attempts) {
        $mock->shouldReceive('syncOrganization')->twice()->andReturnUsing(function () use (&$attempts) {
            $attempts++;

            if ($attempts === 1) {
                throw new RuntimeException('eligibility service unavailable');
            }

            return ['paused' => 0, 'resumed' => 0];
        });
        $mock->shouldReceive('syncUser')->never();
    });

    fakePaypalWebhookVerification();

    $payload = paypalSubscriptionEvent('BILLING.SUBSCRIPTION.UPDATED', [
        'status' => 'ACTIVE',
        'plan_id' => 'P-PRO-MONTHLY',
    ]);

    $this->postJson('/api/paypal/webhook', $payload, paypalWebhookHeaders())->assertStatus(500);
    $this->postJson('/api/paypal/webhook', $payload, paypalWebhookHeaders())->assertOk();

    expect($attempts)->toBe(2)
        ->and($this->subscription->fresh()->plan_id)->toBe($pro->id);
});

it('accepts repeated PayPal webhook delivery without changing the result', function () {
    fakePaypalWebhookVerification();

    $payload = paypalSubscriptionEvent('BILLING.SUBSCRIPTION.ACTIVATED', [
        'status' => 'ACTIVE',
    ]);

    $this->postJson('/api/paypal/webhook', $payload, paypalWebhookHeaders())->assertOk();
    $firstUpdatedAt = $this->subscription->fresh()->updated_at;

    $this->postJson('/api/paypal/webhook', $payload, paypalWebhookHeaders())->assertOk();

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_ACTIVE)
        ->and($this->subscription->fresh()->updated_at->greaterThanOrEqualTo($firstUpdatedAt))->toBeTrue();
});

it('rejects a PayPal webhook when PayPal cannot verify it', function () {
    fakePaypalWebhookVerification(false);

    $this->postJson(
        '/api/paypal/webhook',
        paypalSubscriptionEvent('BILLING.SUBSCRIPTION.ACTIVATED'),
        paypalWebhookHeaders(),
    )->assertStatus(400);

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_INCOMPLETE);
});
