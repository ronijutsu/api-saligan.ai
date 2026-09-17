<?php

use App\Models\BillingEvent;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;

beforeEach(function () {
    $this->organization = Organization::factory()->create(['name' => 'Acme Law Office']);
    $this->owner = User::factory()->ownerOf($this->organization)->create();
    $this->plan = Plan::factory()->firm()->create();
    $this->subscription = Subscription::factory()->for($this->organization)->for($this->owner)->create([
        'plan_id' => $this->plan->id,
        'seats_purchased' => 2,
        'price_per_seat' => 200000,
    ]);
});

it('purchases additional seats and logs a billing event', function () {
    $this->signInAs($this->owner)
        ->postJson('/api/subscription/seats', ['quantity' => 2])
        ->assertOk()
        // 4 seats on a plan bundling 3: the list price plus one extra seat.
        ->assertJsonPath('data.seats.purchased', 4)
        ->assertJsonPath('data.seats.next_invoice_amount', $this->plan->price + 200000)
        ->assertJsonPath('data.seats.next_invoice_pesos', (int) round(($this->plan->price + 200000) / 100));

    expect($this->subscription->fresh()->seats_purchased)->toBe(4);

    $event = BillingEvent::firstWhere('subscription_id', $this->subscription->id);
    expect($event->event_type)->toBe(BillingEvent::EVENT_SEAT_ADDED)
        ->and($event->seats_before)->toBe(2)
        ->and($event->seats_after)->toBe(4)
        ->and($event->metadata['actor_user_id'])->toBe($this->owner->id);
});

it('removes purchased seats and logs a billing event', function () {
    $this->signInAs($this->owner)
        ->deleteJson('/api/subscription/seats', ['quantity' => 1])
        ->assertOk()
        // Back under the bundled three, so the invoice is the plan price flat.
        ->assertJsonPath('data.seats.purchased', 1)
        ->assertJsonPath('data.seats.next_invoice_amount', $this->plan->price);

    $event = BillingEvent::firstWhere('subscription_id', $this->subscription->id);
    expect($event->event_type)->toBe(BillingEvent::EVENT_SEAT_REMOVED)
        ->and($event->seats_before)->toBe(2)
        ->and($event->seats_after)->toBe(1);
});

it('blocks reducing seats below the active member count', function () {
    User::factory()->memberOf($this->organization)->create();

    // 2 seats purchased, 2 active members.
    $this->signInAs($this->owner)
        ->deleteJson('/api/subscription/seats', ['quantity' => 1])
        ->assertStatus(422)
        ->assertJsonPath('message', 'You cannot reduce seats below the 2 active member(s) of your organization. Remove members first.');

    expect($this->subscription->fresh()->seats_purchased)->toBe(2);
});

it('computes the next invoice as the plan price plus the seats beyond the bundled ones', function () {
    // 2 seats on a plan bundling 3 — nothing extra to charge for.
    expect($this->subscription->nextInvoiceAmount())->toBe($this->plan->price);

    $this->subscription->update(['seats_purchased' => 5]);

    expect($this->subscription->fresh()->nextInvoiceAmount())->toBe($this->plan->price + 2 * 200000);
});

it("falls back to the plan's seat price when no per-seat price is set", function () {
    $this->subscription->update(['price_per_seat' => null, 'seats_purchased' => 5]);

    expect($this->subscription->fresh()->nextInvoiceAmount())
        ->toBe($this->plan->price + 2 * $this->plan->seat_price);
});

it('falls back to the plan price when the plan sells no seats', function () {
    $plan = Plan::factory()->create(['seat_price' => null]);

    $this->subscription->update([
        'plan_id' => $plan->id,
        'price_per_seat' => null,
        'seats_purchased' => 1,
    ]);

    expect($this->subscription->fresh()->nextInvoiceAmount())->toBe($plan->price);
});

it('blocks non-admins from changing seat counts', function () {
    $member = User::factory()->memberOf($this->organization)->create();

    $this->signInAs($member)
        ->postJson('/api/subscription/seats', ['quantity' => 1])
        ->assertStatus(403);

    $this->signInAs($member)
        ->deleteJson('/api/subscription/seats', ['quantity' => 1])
        ->assertStatus(403);
});

it('rejects invalid seat quantities', function () {
    $this->signInAs($this->owner)
        ->postJson('/api/subscription/seats', ['quantity' => 0])
        ->assertUnprocessable();

    $this->signInAs($this->owner)
        ->postJson('/api/subscription/seats', ['quantity' => 101])
        ->assertUnprocessable();
});

/*
 * See the matching note in OrganizationMembershipTest: with the subscription
 * gone there is no plan to carry the teams feature, so the capability check
 * refuses before the "no subscription yet" branch is reached — and does so with
 * the flag that shows an upgrade prompt.
 */
it('requires an organization subscription before changing seats', function () {
    $this->subscription->delete();

    $this->signInAs($this->owner)
        ->postJson('/api/subscription/seats', ['quantity' => 1])
        ->assertStatus(402)
        ->assertJsonPath('upgrade_required', true);
});

it('exposes seat fields on the subscription resource', function () {
    $this->signInAs($this->owner)->getJson('/api/subscription')
        ->assertOk()
        ->assertJsonPath('data.seats.purchased', 2)
        ->assertJsonPath('data.seats.price_per_seat', 200000)
        // 2 seats on a plan bundling 3: still the plan price flat.
        ->assertJsonPath('data.seats.next_invoice_amount', $this->plan->price);
});
