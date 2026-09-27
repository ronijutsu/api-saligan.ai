<?php

use App\Models\Conversation;
use App\Models\LegalCase;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\Auth\SupabaseJwtService;
use App\Services\Integrations\IntegrationEligibility;
use App\Support\PlanLimits;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['billing.default_gateway' => 'paymongo']);

    $this->user = User::factory()->create();
    $this->standard = Plan::factory()->standard()->create();
    $this->pro = Plan::factory()->pro()->create();
});

it('lists the active plans for a guest', function () {
    $response = $this->getJson('/api/plans')->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('data.0.slug'))->toBe('standard')
        ->and($response->json('data.0.price_label'))->toBe('₱1,500')
        ->and($response->json('data.1.slug'))->toBe('pro');
});

it('includes the inactive free trial only when requested', function () {
    $trial = Plan::factory()->trial()->create();

    $this->getJson('/api/plans')
        ->assertOk()
        ->assertJsonMissing(['slug' => $trial->slug]);

    $this->getJson('/api/plans?include_trial=1')
        ->assertOk()
        ->assertJsonPath('data.0.slug', Plan::SLUG_TRIAL)
        ->assertJsonPath('data.0.contact_sales', false);
});

it('starts the automatic trial idempotently from the plan selector', function () {
    Plan::factory()->trial()->create();
    $user = User::factory()->create();

    $this->signInAs($user)
        ->postJson('/api/subscription/trial')
        ->assertOk()
        ->assertJsonPath('data.status', Subscription::STATUS_TRIALING)
        ->assertJsonPath('data.plan.slug', Plan::SLUG_TRIAL)
        ->assertJsonPath('data.trial.on_trial', true);

    $this->postJson('/api/subscription/trial')
        ->assertOk()
        ->assertJsonPath('data.id', Subscription::where('user_id', $user->id)->value('id'));

    expect(Subscription::where('user_id', $user->id)->count())->toBe(1);
});

it('provisions a first-time Supabase user without any subscription', function () {
    // There is no registration endpoint any more: the account is created in
    // Supabase and imported here the first time its token reaches the API.
    $token = app(SupabaseJwtService::class)->mintToken(
        (string) Str::uuid(),
        'new@example.com',
        ['full_name' => 'New User'],
    );

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/user')
        ->assertSuccessful()
        ->assertJsonPath('data.email', 'new@example.com');

    $user = User::where('email', 'new@example.com')->firstOrFail();

    expect($user->name)->toBe('New User')
        ->and($user->supabase_uid)->not->toBeNull()
        ->and($user->subscription)->toBeNull();
});

it('starts a subscription and returns the hosted checkout url', function () {
    Http::fake([
        'api.paymongo.com/v1/customers*' => Http::sequence()
            ->push(['data' => []], 200)
            ->push([
                'data' => ['id' => 'cus_test123', 'type' => 'customer', 'attributes' => []],
            ], 200),
        'api.paymongo.com/v1/subscriptions/plans' => Http::response([
            'data' => ['id' => 'plan_test123', 'type' => 'plan', 'attributes' => []],
        ]),
        'api.paymongo.com/v1/subscriptions' => Http::response([
            'data' => [
                'id' => 'subs_test123',
                'type' => 'subscription',
                'attributes' => [
                    'latest_invoice' => [
                        'payment_intent' => [
                            'id' => 'pi_test123',
                            'attributes' => [
                                'client_key' => 'pi_test123_ck_test',
                            ],
                        ],
                    ],
                ],
            ],
        ]),
        'api.paymongo.com/v1/checkout_sessions' => Http::response([
            'data' => [
                'id' => 'cs_test123',
                'type' => 'checkout_session',
                'attributes' => [
                    'checkout_url' => 'https://checkout.paymongo.com/cs_test123#token',
                ],
            ],
        ]),
    ]);

    $response = $this->signInAs($this->user)
        ->postJson('/api/subscription', ['plan_id' => $this->pro->id])
        ->assertCreated();

    expect($response->json('checkout.checkout_url'))->toBe('https://checkout.paymongo.com/cs_test123#token')
        ->and($response->json('checkout.payment_intent_id'))->toBe('pi_test123')
        ->and($response->json('data.status'))->toBe(Subscription::STATUS_INCOMPLETE);

    $this->assertDatabaseHas('subscriptions', [
        'user_id' => $this->user->id,
        'plan_id' => $this->pro->id,
        'paymongo_subscription_id' => 'subs_test123',
        'paymongo_customer_id' => 'cus_test123',
    ]);

    Http::assertSentCount(5);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/v1/checkout_sessions')
            && data_get($request->data(), 'data.attributes.payment_intent_id') === 'pi_test123'
            && data_get($request->data(), 'data.attributes.customer_id') === 'cus_test123'
            && data_get($request->data(), 'data.attributes.line_items.0.amount') === $this->pro->price;
    });

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/v1/customers')
            && data_get($request->data(), 'data.attributes.email') === $this->user->email
            && data_get($request->data(), 'data.attributes.default_device') === 'email'
            && data_get($request->data(), 'data.attributes.first_name') !== null
            && data_get($request->data(), 'data.attributes.last_name') !== null;
    });
});

it('reuses an existing PayMongo customer by email instead of creating one', function () {
    Http::fake([
        'api.paymongo.com/v1/customers*' => Http::response([
            'data' => [
                ['id' => 'cus_existing', 'type' => 'customer', 'attributes' => ['email' => $this->user->email]],
            ],
        ]),
        'api.paymongo.com/v1/subscriptions/plans' => Http::response([
            'data' => ['id' => 'plan_test123', 'type' => 'plan', 'attributes' => []],
        ]),
        'api.paymongo.com/v1/subscriptions' => Http::response([
            'data' => [
                'id' => 'subs_test123',
                'type' => 'subscription',
                'attributes' => [
                    'latest_invoice' => [
                        'payment_intent' => [
                            'id' => 'pi_test123',
                            'attributes' => ['client_key' => 'pi_test123_ck_test'],
                        ],
                    ],
                ],
            ],
        ]),
        'api.paymongo.com/v1/checkout_sessions' => Http::response([
            'data' => [
                'id' => 'cs_test123',
                'type' => 'checkout_session',
                'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_test123#token'],
            ],
        ]),
    ]);

    $this->signInAs($this->user)
        ->postJson('/api/subscription', ['plan_id' => $this->pro->id])
        ->assertCreated();

    $this->assertDatabaseHas('subscriptions', [
        'user_id' => $this->user->id,
        'paymongo_customer_id' => 'cus_existing',
    ]);

    Http::assertSent(function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_contains($request->url(), '/v1/customers')
            && $request->method() === 'GET'
            && ($query['email'] ?? null) === $this->user->email;
    });

    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), '/v1/customers')
            && $request->method() === 'POST';
    });
});

it('removes a PayMongo checkout row when the first payment cannot be initialized', function () {
    Http::fake([
        'api.paymongo.com/v1/customers*' => Http::response([
            'data' => ['id' => 'cus_failed', 'type' => 'customer', 'attributes' => []],
        ]),
        'api.paymongo.com/v1/subscriptions/plans' => Http::response([
            'data' => ['id' => 'plan_failed', 'type' => 'plan', 'attributes' => []],
        ]),
        'api.paymongo.com/v1/subscriptions' => Http::response([
            'data' => [
                'id' => 'subs_failed',
                'type' => 'subscription',
                'attributes' => ['latest_invoice' => ['payment_intent' => null]],
            ],
        ]),
        'api.paymongo.com/v1/subscriptions/subs_failed/cancel' => Http::response([
            'data' => ['id' => 'subs_failed'],
        ]),
    ]);

    $this->signInAs($this->user)
        ->postJson('/api/subscription', ['plan_id' => $this->pro->id])
        ->assertStatus(422)
        ->assertJsonPath('message', 'The subscription payment could not be initialized. Please try again.');

    $this->assertDatabaseCount('subscriptions', 0);
    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/v1/subscriptions/subs_failed/cancel'));
});

it('rejects subscribing while an active subscription exists', function () {
    Subscription::factory()->for($this->user)->create(['plan_id' => $this->pro->id]);

    $this->signInAs($this->user)
        ->postJson('/api/subscription', ['plan_id' => $this->pro->id])
        ->assertStatus(422);
});

it('lists only the three paid tiers', function () {
    $firm = Plan::factory()->firm()->create();

    $response = $this->getJson('/api/plans')->assertOk();

    expect(collect($response->json('data'))->pluck('slug')->all())
        ->toBe([Plan::SLUG_STANDARD, Plan::SLUG_PRO, Plan::SLUG_FIRM])
        ->and($response->json('data.2.annual_only'))->toBeTrue()
        ->and($firm->isSelfServe())->toBeTrue();
});

it('refuses to check out Firm monthly', function () {
    $firm = Plan::factory()->firm()->create();

    $this->signInAs($this->user)
        ->postJson('/api/subscription', [
            'plan_id' => $firm->id,
            'billing_interval' => Plan::INTERVAL_MONTHLY,
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'The Firm plan is only available with annual billing.');

    $this->assertDatabaseCount('subscriptions', 0);
});

it('refuses to switch an existing subscription onto Firm monthly', function () {
    $firm = Plan::factory()->firm()->create();
    Subscription::factory()->for($this->user)->create(['plan_id' => $this->pro->id]);

    $this->signInAs($this->user)
        ->postJson('/api/subscription/change-plan', [
            'plan_id' => $firm->id,
            'billing_interval' => Plan::INTERVAL_MONTHLY,
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'The Firm plan is only available with annual billing.');

    expect($this->user->subscription->plan_id)->toBe($this->pro->id);
});

it('rejects starting a checkout while a payment is already pending', function () {
    Subscription::factory()->for($this->user)->create([
        'plan_id' => $this->pro->id,
        'status' => Subscription::STATUS_INCOMPLETE,
    ]);

    $this->signInAs($this->user)
        ->postJson('/api/subscription', ['plan_id' => $this->pro->id])
        ->assertStatus(422)
        ->assertJsonPath('message', 'A subscription is already pending. Cancel it before starting a new checkout.');
});

it('rejects a checkout while another checkout is being created', function () {
    Cache::lock("subscription.checkout.{$this->user->id}", 30)->get();

    $this->signInAs($this->user)
        ->postJson('/api/subscription', ['plan_id' => $this->pro->id])
        ->assertStatus(409);

    Cache::lock("subscription.checkout.{$this->user->id}", 30)->forceRelease();
});

it('shows the current subscription with usage', function () {
    Subscription::factory()->for($this->user)->create(['plan_id' => $this->pro->id]);
    UsageCounter::factory()->for($this->user)->create([
        'messages_used' => 42,
        'documents_uploaded' => 3,
    ]);

    $response = $this->signInAs($this->user)->getJson('/api/subscription')->assertOk();

    expect($response->json('data.plan.slug'))->toBe('pro')
        ->and($response->json('data.usage.messages.used'))->toBe(42)
        ->and($response->json('data.usage.messages.limit'))->toBe(300)
        ->and($response->json('data.usage.messages.overage'))->toBe(0)
        ->and($response->json('data.usage.documents.limit'))->toBe(200);
});

it('returns null subscription for users without one', function () {
    $this->signInAs($this->user)->getJson('/api/subscription')
        ->assertOk()
        ->assertJson(['data' => null]);
});

it('returns PHP-only pricing and usage fields from subscription endpoints', function () {
    Subscription::factory()->for($this->user)->create(['plan_id' => $this->pro->id]);

    $response = $this->signInAs($this->user)->getJson('/api/subscription')->assertOk();

    $response->assertJsonPath('data.plan.ai_budget_label', '3x usage')
        ->assertJsonPath('data.plan.ai_usage_multiplier', 3)
        ->assertJsonMissingPath('data.plan.ai_budget_pesos')
        ->assertJsonMissingPath('data.plan.ai_budget_usd_cents')
        ->assertJsonMissingPath('data.plan.ai_budget_usd')
        ->assertJsonMissingPath('data.usage.ai_usage.used_usd')
        ->assertJsonMissingPath('data.usage.ai_usage.budget_usd');
});

it('rejects seat endpoints for a personal subscription with a useful error', function () {
    Subscription::factory()->for($this->user)->create(['plan_id' => $this->pro->id]);

    $this->signInAs($this->user)
        ->postJson('/api/subscription/seats', ['quantity' => 1])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Seat management is only available for organization subscriptions.');

    $this->signInAs($this->user)
        ->deleteJson('/api/subscription/seats', ['quantity' => 1])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Seat management is only available for organization subscriptions.');
});

it('changes the subscription plan', function () {
    $this->standard->update(['paymongo_plan_id' => 'plan_standard']);
    $this->pro->update(['paymongo_plan_id' => 'plan_pro']);

    Subscription::factory()->for($this->user)->create([
        'plan_id' => $this->standard->id,
        'paymongo_subscription_id' => 'subs_test123',
    ]);

    Http::fake(['api.paymongo.com/*' => Http::response(['data' => []])]);

    $response = $this->signInAs($this->user)
        ->postJson('/api/subscription/change-plan', ['plan_id' => $this->pro->id])
        ->assertOk();

    expect($response->json('data.plan.slug'))->toBe('pro')
        ->and(Subscription::firstWhere('user_id', $this->user->id)->plan_id)->toBe($this->pro->id);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/subscriptions/subs_test123/plan'));
});

it('changes a PayMongo subscription to annual billing', function () {
    $this->standard->update(['paymongo_plan_id' => 'plan_standard']);
    $this->pro->update([
        'paymongo_plan_id' => 'plan_pro',
        'paymongo_plan_id_annual' => 'plan_pro_annual',
    ]);

    $subscription = Subscription::factory()->for($this->user)->create([
        'plan_id' => $this->standard->id,
        'interval' => Plan::INTERVAL_MONTHLY,
        'paymongo_subscription_id' => 'subs_test123',
    ]);

    Http::fake(['api.paymongo.com/*' => Http::response(['data' => []])]);

    $response = $this->signInAs($this->user)
        ->postJson('/api/subscription/change-plan', [
            'plan_id' => $this->pro->id,
            'billing_interval' => Plan::INTERVAL_ANNUAL,
        ])
        ->assertOk();

    expect($response->json('data.plan.slug'))->toBe('pro')
        ->and($response->json('data.interval'))->toBe(Plan::INTERVAL_ANNUAL)
        ->and($subscription->fresh()->interval)->toBe(Plan::INTERVAL_ANNUAL);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/subscriptions/subs_test123/plan')
        && data_get($request->data(), 'data.attributes.plan_id') === 'plan_pro_annual');
});

it('hands over the new plan\'s included seats when upgrading', function () {
    $firm = Plan::factory()->firm()->create(['paymongo_plan_id_annual' => 'plan_firm_annual']);
    $this->standard->update(['paymongo_plan_id' => 'plan_standard']);

    Subscription::factory()->for($this->user)->create([
        'plan_id' => $this->standard->id,
        'seats_purchased' => 1,
        'paymongo_subscription_id' => 'subs_test123',
    ]);

    Http::fake(['api.paymongo.com/*' => Http::response(['data' => []])]);

    $this->signInAs($this->user)
        ->postJson('/api/subscription/change-plan', [
            'plan_id' => $firm->id,
            'billing_interval' => Plan::INTERVAL_ANNUAL,
        ])
        ->assertOk()
        ->assertJsonPath('data.seats.purchased', 3);
});

it('keeps seats bought on top of the old plan when downgrading', function () {
    $firm = Plan::factory()->firm()->create(['paymongo_plan_id' => 'plan_firm']);
    $this->pro->update(['paymongo_plan_id' => 'plan_pro']);

    Subscription::factory()->for($this->user)->create([
        'plan_id' => $firm->id,
        'seats_purchased' => 5,
        'paymongo_subscription_id' => 'subs_test123',
    ]);

    Http::fake(['api.paymongo.com/*' => Http::response(['data' => []])]);

    // Pro includes one seat, but the five already paid for are still holding
    // members — dropping them here would lock those people out.
    $this->signInAs($this->user)
        ->postJson('/api/subscription/change-plan', ['plan_id' => $this->pro->id])
        ->assertOk()
        ->assertJsonPath('data.seats.purchased', 5);
});

it('cancels the subscription', function () {
    Subscription::factory()->for($this->user)->create([
        'plan_id' => $this->pro->id,
        'paymongo_subscription_id' => 'subs_test123',
    ]);

    Http::fake(['api.paymongo.com/*' => Http::response(['data' => []])]);

    $this->signInAs($this->user)->postJson('/api/subscription/cancel')->assertOk();

    $subscription = Subscription::firstWhere('user_id', $this->user->id);

    expect($subscription->status)->toBe(Subscription::STATUS_CANCELLED)
        ->and($subscription->cancelled_at)->not->toBeNull();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/subscriptions/subs_test123/cancel'));
});

it('activates a subscription on invoice paid webhook', function () {
    Subscription::factory()->for($this->user)->create([
        'plan_id' => $this->standard->id,
        'paymongo_subscription_id' => 'subs_test123',
        'status' => Subscription::STATUS_INCOMPLETE,
    ]);

    config(['paymongo.webhook_secret' => 'test-secret']);

    $payload = [
        'data' => [
            'type' => 'event',
            'attributes' => [
                'type' => 'subscription.invoice.paid',
                'data' => [
                    'id' => 'inv_test123',
                    'type' => 'invoice',
                    'attributes' => ['resource_id' => 'subs_test123'],
                ],
            ],
        ],
    ];

    $signature = 'paymongo_'.hash_hmac('sha256', json_encode($payload), 'test-secret');

    $this->postJson('/api/subscriptions/webhook', $payload, [
        'Paymongo-Signature' => $signature,
    ])->assertOk();

    $subscription = Subscription::firstWhere('paymongo_subscription_id', 'subs_test123');

    expect($subscription->status)->toBe(Subscription::STATUS_ACTIVE)
        ->and($subscription->current_period_end)->not->toBeNull();
});

it('mirrors a subscription status update webhook', function () {
    Subscription::factory()->for($this->user)->create([
        'plan_id' => $this->standard->id,
        'paymongo_subscription_id' => 'subs_test123',
    ]);

    config(['paymongo.webhook_secret' => 'test-secret']);

    $payload = [
        'data' => [
            'type' => 'event',
            'attributes' => [
                'type' => 'subscription.updated',
                'data' => [
                    'id' => 'subs_test123',
                    'type' => 'subscription',
                    'attributes' => ['status' => 'past_due'],
                ],
            ],
        ],
    ];

    $signature = 'paymongo_'.hash_hmac('sha256', json_encode($payload), 'test-secret');

    $this->postJson('/api/subscriptions/webhook', $payload, [
        'Paymongo-Signature' => $signature,
    ])->assertOk();

    expect(Subscription::firstWhere('paymongo_subscription_id', 'subs_test123')->status)
        ->toBe(Subscription::STATUS_PAST_DUE);
});

it('syncs organization integrations after a PayMongo status webhook', function () {
    $organization = Organization::factory()->create();
    $this->user->update([
        'organization_id' => $organization->id,
        'org_role' => User::ORG_ROLE_OWNER,
        'org_status' => User::ORG_STATUS_ACTIVE,
    ]);
    $subscription = Subscription::factory()->for($this->user)->create([
        'organization_id' => $organization->id,
        'plan_id' => $this->pro->id,
        'paymongo_subscription_id' => 'subs_test123',
        'status' => Subscription::STATUS_ACTIVE,
    ]);

    $this->mock(IntegrationEligibility::class, function ($mock) use ($organization) {
        $mock->shouldReceive('syncOrganization')->once()->with(
            Mockery::on(fn (Organization $actual): bool => $actual->getKey() === $organization->getKey()),
        )->andReturn(['paused' => 0, 'resumed' => 0]);
        $mock->shouldReceive('syncUser')->never();
    });

    config(['paymongo.webhook_secret' => 'test-secret']);

    $payload = [
        'data' => [
            'type' => 'event',
            'attributes' => [
                'type' => 'subscription.updated',
                'data' => [
                    'id' => 'subs_test123',
                    'type' => 'subscription',
                    'attributes' => ['status' => 'cancelled'],
                ],
            ],
        ],
    ];

    $signature = 'paymongo_'.hash_hmac('sha256', json_encode($payload), 'test-secret');

    $this->postJson('/api/subscriptions/webhook', $payload, [
        'Paymongo-Signature' => $signature,
    ])->assertOk();

    expect($subscription->fresh()->status)->toBe(Subscription::STATUS_CANCELLED);
});

it('rejects webhooks with an invalid signature', function () {
    config(['paymongo.webhook_secret' => 'test-secret']);

    $this->postJson('/api/subscriptions/webhook', [
        'data' => ['attributes' => ['type' => 'subscription.updated']],
    ], [
        'Paymongo-Signature' => 'not-a-real-signature',
    ])->assertStatus(400);
});

it('blocks a message when the monthly message limit is reached', function () {
    Subscription::factory()->for($this->user)->create(['plan_id' => $this->standard->id]);
    // Read off the plan rather than hardcoded, so a change to the allowance
    // cannot leave this asserting a cap the plan no longer has.
    UsageCounter::factory()->for($this->user)->create([
        'messages_used' => $this->standard->limits['messages_used'],
        'documents_uploaded' => 0,
    ]);

    $conversation = Conversation::factory()->for($this->user)->create();

    $response = $this->signInAs($this->user)
        ->postJson("/api/conversations/{$conversation->id}/messages", ['message' => 'Hello'])
        ->assertStatus(402);

    expect($response->json('upgrade_required'))->toBeTrue();
});

it('blocks messages for users without a subscription', function () {
    $conversation = Conversation::factory()->for($this->user)->create();

    $response = $this->signInAs($this->user)
        ->postJson("/api/conversations/{$conversation->id}/messages", ['message' => 'Hello'])
        ->assertStatus(402);

    expect($response->json('upgrade_required'))->toBeTrue();
});

it('blocks all product endpoints for users without a subscription', function () {
    $response = $this->signInAs($this->user)->getJson('/api/documents')->assertStatus(402);

    expect($response->json('upgrade_required'))->toBeTrue();
});

it('blocks document upload when the monthly upload limit is reached', function () {
    Subscription::factory()->for($this->user)->create(['plan_id' => $this->standard->id]);
    UsageCounter::factory()->for($this->user)->create([
        'messages_used' => 0,
        'documents_uploaded' => $this->standard->limits['documents_uploaded'],
    ]);
    Queue::fake();
    Storage::fake('local');

    $response = $this->signInAs($this->user)->postJson('/api/documents', [
        'file' => UploadedFile::fake()->createWithContent('memo.txt', 'x'),
    ])->assertStatus(402);

    expect($response->json('upgrade_required'))->toBeTrue();
});

it('increments document upload usage', function () {
    Subscription::factory()->for($this->user)->create(['plan_id' => $this->pro->id]);
    Queue::fake();
    Storage::fake('local');

    $this->signInAs($this->user)->postJson('/api/documents', [
        'file' => UploadedFile::fake()->createWithContent('memo.txt', 'x'),
    ])->assertCreated();

    expect(UsageCounter::firstWhere('user_id', $this->user->id)->documents_uploaded)->toBe(1);
});

it('blocks creating a case over the active case limit', function () {
    // The gate is exercised with an explicitly capped plan: the shipped tiers
    // leave matters unlimited, so the test must not depend on their numbers.
    $capped = Plan::factory()->create([
        'limits' => ['active_cases' => 1, 'documents_uploaded' => null, 'messages_used' => null],
    ]);
    Subscription::factory()->for($this->user)->create(['plan_id' => $capped->id]);
    LegalCase::factory()->for($this->user)->state(['status' => 'open'])->create();

    $response = $this->signInAs($this->user)->postJson('/api/cases', [
        'title' => 'Another case',
        'case_type' => 'legal',
        'status' => 'open',
        'priority' => 'medium',
    ])->assertStatus(402);

    expect($response->json('upgrade_required'))->toBeTrue();
});

it('blocks over-cap messages instead of accruing overage', function () {
    // Allowances are prepaid: an exhausted cap refuses the turn rather than
    // growing the bill, even when the plan row still carries a legacy
    // overage price from before metered billing was removed.
    $this->pro->update(['overage_price' => 900]);
    Subscription::factory()->for($this->user)->create(['plan_id' => $this->pro->id]);
    UsageCounter::factory()->for($this->user)->create([
        'messages_used' => $this->pro->limits['messages_used'],
        'messages_overage' => 2,
        'documents_uploaded' => 0,
    ]);

    expect(fn () => PlanLimits::consumeMessage($this->user))
        ->toThrow(HttpResponseException::class);

    $counter = UsageCounter::firstWhere('user_id', $this->user->id);

    expect($counter->messages_used)->toBe($this->pro->limits['messages_used'])
        ->and($counter->messages_overage)->toBe(2);
});

it('refunds the pre-charge when a turn delivers nothing', function () {
    Subscription::factory()->for($this->user)->create(['plan_id' => $this->pro->id]);
    PlanLimits::consumeMessage($this->user);

    expect(PlanLimits::used($this->user, 'messages_used'))->toBe(1);

    PlanLimits::refundMessage($this->user);

    expect(PlanLimits::used($this->user, 'messages_used'))->toBe(0);

    // Refunding an empty counter never drives it negative.
    PlanLimits::refundMessage($this->user);

    expect(PlanLimits::used($this->user, 'messages_used'))->toBe(0);
});

it('blocks over-cap messages when the plan has no overage price', function () {
    Subscription::factory()->for($this->user)->create(['plan_id' => $this->standard->id]);
    UsageCounter::factory()->for($this->user)->create([
        'messages_used' => $this->standard->limits['messages_used'],
        'documents_uploaded' => 0,
    ]);

    expect(fn () => PlanLimits::consumeMessage($this->user))
        ->toThrow(HttpResponseException::class)
        ->and(UsageCounter::firstWhere('user_id', $this->user->id)->messages_overage)->toBe(0);
});

it('exposes the overage balance in the subscription payload', function () {
    // Historical balances from before metered billing was removed stay
    // visible; no new overage accrues.
    $this->pro->update(['overage_price' => 900]);
    Subscription::factory()->for($this->user)->create(['plan_id' => $this->pro->id]);
    UsageCounter::factory()->for($this->user)->create([
        'messages_used' => $this->pro->limits['messages_used'] + 10,
        'messages_overage' => 10,
        'documents_uploaded' => 0,
    ]);

    $response = $this->signInAs($this->user)->getJson('/api/subscription')->assertOk();

    expect($response->json('data.usage.messages.overage'))->toBe(10)
        ->and($response->json('data.usage.messages.overage_rate'))->toBe(900)
        ->and($response->json('data.usage.messages.overage_due_cents'))->toBe(9000)
        ->and($response->json('data.usage.messages.overage_due_pesos'))->toBe(90);
});

it('starts an annual subscription with a yearly PayMongo plan', function () {
    $this->pro->update(['paymongo_plan_id' => 'plan_pro_monthly']);

    Http::fake([
        'api.paymongo.com/v1/customers*' => Http::response([
            'data' => ['id' => 'cus_annual', 'type' => 'customer', 'attributes' => []],
        ]),
        'api.paymongo.com/v1/subscriptions/plans' => Http::response([
            'data' => ['id' => 'plan_pro_annual', 'type' => 'plan', 'attributes' => []],
        ]),
        'api.paymongo.com/v1/subscriptions' => Http::response([
            'data' => [
                'id' => 'subs_annual',
                'type' => 'subscription',
                'attributes' => [
                    'latest_invoice' => [
                        'payment_intent' => [
                            'id' => 'pi_annual',
                            'attributes' => ['client_key' => 'pi_annual_ck'],
                        ],
                    ],
                ],
            ],
        ]),
        'api.paymongo.com/v1/checkout_sessions' => Http::response([
            'data' => [
                'id' => 'cs_annual',
                'type' => 'checkout_session',
                'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_annual#token'],
            ],
        ]),
    ]);

    $this->signInAs($this->user)
        ->postJson('/api/subscription', ['plan_id' => $this->pro->id, 'billing_interval' => 'annual'])
        ->assertCreated();

    $this->assertDatabaseHas('subscriptions', [
        'user_id' => $this->user->id,
        'plan_id' => $this->pro->id,
        'interval' => 'annual',
        'paymongo_customer_id' => 'cus_annual',
    ]);

    $this->assertDatabaseHas('plans', [
        'id' => $this->pro->id,
        'paymongo_plan_id_annual' => 'plan_pro_annual',
    ]);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/v1/subscriptions/plans')
            && data_get($request->data(), 'data.attributes.interval') === 'yearly'
            && data_get($request->data(), 'data.attributes.amount') === 3500000;
    });

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/v1/checkout_sessions')
            && data_get($request->data(), 'data.attributes.line_items.0.amount') === 3500000;
    });
});

it('extends the current period to a year for annual invoice payments', function () {
    Subscription::factory()->for($this->user)->create([
        'plan_id' => $this->standard->id,
        'interval' => 'annual',
        'paymongo_subscription_id' => 'subs_annual',
        'status' => Subscription::STATUS_INCOMPLETE,
    ]);

    config(['paymongo.webhook_secret' => 'test-secret']);

    $payload = [
        'data' => [
            'type' => 'event',
            'attributes' => [
                'type' => 'subscription.invoice.paid',
                'data' => [
                    'id' => 'inv_annual',
                    'type' => 'invoice',
                    'attributes' => ['resource_id' => 'subs_annual'],
                ],
            ],
        ],
    ];

    $signature = 'paymongo_'.hash_hmac('sha256', json_encode($payload), 'test-secret');

    $this->postJson('/api/subscriptions/webhook', $payload, [
        'Paymongo-Signature' => $signature,
    ])->assertOk();

    $subscription = Subscription::firstWhere('paymongo_subscription_id', 'subs_annual');

    expect($subscription->status)->toBe(Subscription::STATUS_ACTIVE)
        ->and($subscription->current_period_end->year)->toBe(now()->addYear()->year);
});
