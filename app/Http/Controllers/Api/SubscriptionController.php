<?php

namespace App\Http\Controllers\Api;

use App\Enums\BillingGateway;
use App\Exceptions\AutomaticTrialException;
use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\AutomaticTrialProvisioner;
use App\Services\Billing\BillingGatewayManager;
use App\Services\Billing\LemonSqueezyClient;
use App\Services\Billing\PaymongoClient;
use App\Services\Billing\SeatBillingService;
use App\Services\Integrations\IntegrationEligibility;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class SubscriptionController extends Controller
{
    public function __construct(
        private readonly PaymongoClient $paymongo,
        private readonly LemonSqueezyClient $lemonsqueezy,
        private readonly BillingGatewayManager $gateways,
        private readonly SeatBillingService $seats,
        private readonly AutomaticTrialProvisioner $trialProvisioner,
    ) {
        //
    }

    /**
     * Start a subscription for the current user. Returns the gateway's hosted
     * checkout URL the client redirects the user to for the first payment.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'uuid', 'exists:plans,id'],
            'billing_interval' => ['sometimes', 'in:monthly,annual'],
        ]);

        $billingInterval = $validated['billing_interval'] ?? Plan::INTERVAL_MONTHLY;

        $plan = Plan::findOrFail($validated['plan_id']);
        abort_unless($plan->is_active, 422);
        $this->assertIntervalAvailable($plan, $billingInterval);
        $this->assertSelfServe($plan);

        $user = $request->user();
        $this->assertCanStartCheckout($user);

        // Serialize checkout creation per billing owner so two concurrent
        // requests cannot create a subscription (and a provider charge).
        $lockKey = $user->organization_id !== null
            ? "subscription.checkout.organization.{$user->organization_id}"
            : "subscription.checkout.{$user->id}";
        $lock = Cache::lock($lockKey, 30);

        if (! $lock->get()) {
            abort(response()->json([
                'message' => 'A checkout is already being created. Please try again.',
            ], 409));
        }

        try {
            $current = $user->fresh()->subscription;

            if ($current?->isActive() === true && ! $current->onTrial()) {
                abort(response()->json([
                    'message' => 'You already have an active subscription. Change your plan instead.',
                ], 422));
            }

            if ($current !== null && $current->status !== Subscription::STATUS_CANCELLED) {
                abort(response()->json([
                    'message' => 'A subscription is already pending. Cancel it before starting a new checkout.',
                ], 422));
            }

            $frontendUrl = rtrim((string) config('app.frontend_url'), '/');

            $gateway = $this->gateways->resolve($plan, $billingInterval);

            $result = $gateway->initiateCheckout(
                user: $user,
                plan: $plan,
                interval: $billingInterval,
                // A completed checkout lands on the welcome screen, not in
                // billing settings: the user just paid, so the return is a
                // thank-you and a way into the app, not an invoice to read.
                // That screen waits out the webhook before it says anything.
                successUrl: "{$frontendUrl}/welcome?{$gateway->name()->value}=return",
                cancelUrl: "{$frontendUrl}/pricing",
            );

            return response()->json([
                'data' => (new SubscriptionResource($result['subscription']))->resolve(),
                'checkout' => $result['checkout'],
            ], 201);
        } catch (HttpResponseException|HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return $this->providerUnavailableResponse();
        } finally {
            $lock->release();
        }
    }

    /**
     * Start the signed-in account's no-card trial from the plan selector.
     */
    public function startTrial(Request $request): JsonResponse
    {
        try {
            $subscription = $this->trialProvisioner->provision($request->user());
        } catch (AutomaticTrialException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        if ($subscription === null) {
            return response()->json([
                'message' => 'The free trial is not available right now. Please try again shortly.',
            ], 503);
        }

        return response()->json([
            'data' => new SubscriptionResource($subscription->load('plan')),
        ]);
    }

    /**
     * Refuse a plan that is not available at the requested billing interval.
     */
    protected function assertIntervalAvailable(Plan $plan, string $interval): void
    {
        abort_unless(
            $plan->supportsInterval($interval),
            422,
            "The {$plan->name} plan is only available with annual billing.",
        );
    }

    /**
     * Refuse a contact-sales plan at checkout. Such a plan has no list price
     * and no gateway plan behind it, so letting one through would either charge
     * nothing or fail at the gateway.
     */
    protected function assertSelfServe(Plan $plan): void
    {
        if ($plan->isSelfServe()) {
            return;
        }

        abort(response()->json([
            'message' => "The {$plan->name} plan is priced per organization. Contact sales to get set up.",
            'contact_sales' => true,
        ], 422));
    }

    /**
     * Show the current user's subscription, plan, and usage.
     */
    public function show(Request $request): JsonResponse
    {
        $subscription = $request->user()->subscription?->load('plan');

        return response()->json([
            'data' => $subscription ? (new SubscriptionResource($subscription))->resolve() : null,
        ]);
    }

    /**
     * Change the current subscription's plan.
     */
    public function changePlan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'uuid', 'exists:plans,id'],
            'billing_interval' => ['sometimes', 'in:monthly,annual'],
        ]);

        $subscription = $request->user()->subscription;
        abort_unless($subscription !== null && $subscription->status !== Subscription::STATUS_CANCELLED, 422);
        $this->assertCanManageBilling($request->user(), $subscription);

        $billingInterval = $validated['billing_interval'] ?? ($subscription->interval ?? Plan::INTERVAL_MONTHLY);

        $plan = Plan::findOrFail($validated['plan_id']);
        abort_unless($plan->is_active, 422);
        $this->assertIntervalAvailable($plan, $billingInterval);
        $this->assertSelfServe($plan);
        $this->assertProviderSubscription($subscription);

        $lock = Cache::lock("subscription.plan-change.{$subscription->id}", 30);

        if (! $lock->get()) {
            abort(response()->json([
                'message' => 'A plan change is already being created. Please try again.',
            ], 409));
        }

        try {
            $subscription = $subscription->fresh();
            abort_unless($subscription->status !== Subscription::STATUS_CANCELLED, 422);

            if ($subscription->pending_plan_id !== null) {
                $pendingInterval = $subscription->pending_plan_interval
                    ?? ($subscription->interval ?? Plan::INTERVAL_MONTHLY);

                if ($subscription->pending_plan_id === $plan->id
                    && $pendingInterval === $billingInterval
                    && is_string($subscription->pending_plan_checkout_url)
                    && $subscription->pending_plan_checkout_url !== '') {
                    return $this->planChangeResponse($subscription, [
                        'checkout_url' => $subscription->pending_plan_checkout_url,
                        'payment_intent_id' => null,
                        'public_key' => null,
                    ]);
                }

                abort(response()->json([
                    'message' => 'A plan change is already awaiting approval. Complete or cancel it before choosing another plan.',
                ], 409));
            }

            $gateway = $this->gateways->for($subscription);
            $paypalRevision = $gateway->name() === BillingGateway::Paypal;
            $frontendUrl = rtrim((string) config('app.frontend_url'), '/');

            if ($paypalRevision) {
                // Mark the intent before calling PayPal so a fast webhook can
                // distinguish this revision from an ordinary subscription update.
                $subscription->update([
                    'pending_plan_id' => $plan->id,
                    'pending_plan_interval' => $billingInterval,
                    'pending_plan_checkout_url' => null,
                ]);
            }

            try {
                $checkout = $gateway->changePlan(
                    subscription: $subscription,
                    plan: $plan,
                    interval: $billingInterval,
                    successUrl: $paypalRevision
                        ? "{$frontendUrl}/settings/billing?paypal=plan-change-return&plan={$plan->id}"
                        : '',
                    cancelUrl: $paypalRevision
                        ? "{$frontendUrl}/settings/billing?paypal=plan-change-cancelled"
                        : '',
                );

                // A PayPal revision is pending buyer approval. Its webhook applies
                // the plan locally; changing the row before approval would grant
                // unpaid access. Do not restore the marker if that webhook already
                // completed the revision while the provider request was in flight.
                if ($checkout !== null) {
                    $latest = $subscription->fresh();

                    if ($latest->pending_plan_id === $plan->id) {
                        $latest->update([
                            'pending_plan_checkout_url' => $checkout['checkout_url'],
                        ]);
                    } elseif ($latest->plan_id === $plan->id) {
                        $checkout = null;
                    }

                    $subscription = $latest->fresh();
                } else {
                    $this->applyPlanChange($subscription, $plan, $billingInterval);
                    $this->syncSubscriptionEligibility($subscription->fresh());
                }

                return $this->planChangeResponse($subscription, $checkout);
            } catch (Throwable $exception) {
                if ($paypalRevision && $subscription->fresh()?->pending_plan_id === $plan->id) {
                    $subscription->update([
                        'pending_plan_id' => null,
                        'pending_plan_interval' => null,
                        'pending_plan_checkout_url' => null,
                    ]);
                }

                if (! $exception instanceof HttpResponseException
                    && ! $exception instanceof HttpExceptionInterface) {
                    report($exception);

                    return $this->providerUnavailableResponse();
                }

                throw $exception;
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Clear a PayPal plan revision after the buyer cancels its approval flow.
     */
    public function cancelPlanChange(Request $request): JsonResponse
    {
        $subscription = $request->user()->subscription;

        if ($subscription === null) {
            return $this->planChangeResponse(null, null);
        }

        $this->assertCanManageBilling($request->user(), $subscription);

        $lock = Cache::lock("subscription.plan-change.{$subscription->id}", 30);

        if (! $lock->get()) {
            abort(response()->json([
                'message' => 'A plan change is already being created. Please try again.',
            ], 409));
        }

        try {
            $subscription = $subscription->fresh();

            if ($subscription->pending_plan_id !== null) {
                $subscription->update([
                    'pending_plan_id' => null,
                    'pending_plan_interval' => null,
                    'pending_plan_checkout_url' => null,
                ]);
            }

            return $this->planChangeResponse($subscription->fresh(), null);
        } finally {
            $lock->release();
        }
    }

    /**
     * Apply the local portion of a plan change after the provider accepts it.
     */
    protected function applyPlanChange(Subscription $subscription, Plan $plan, string $interval): void
    {
        $subscription->update([
            'plan_id' => $plan->id,
            'interval' => $interval,
            'price_per_seat' => $plan->seat_price ?? $plan->price,
            'seats_purchased' => max($subscription->seats_purchased, $plan->included_seats ?? 1),
        ]);
    }

    /**
     * Return a plan-change response for both immediate and hosted flows.
     */
    protected function planChangeResponse(?Subscription $subscription, ?array $checkout): JsonResponse
    {
        return response()->json([
            'data' => $subscription === null
                ? null
                : (new SubscriptionResource($subscription->load('plan')))->resolve(),
            'checkout' => $checkout,
        ]);
    }

    /**
     * Cancel the current subscription immediately.
     */
    public function cancel(Request $request): JsonResponse
    {
        $subscription = $request->user()->subscription;
        abort_unless($subscription !== null && $subscription->status !== Subscription::STATUS_CANCELLED, 422);
        $this->assertCanManageBilling($request->user(), $subscription);

        $lock = Cache::lock("subscription.plan-change.{$subscription->id}", 30);

        if (! $lock->get()) {
            abort(response()->json([
                'message' => 'A plan change is already being created. Please try again.',
            ], 409));
        }

        try {
            $subscription = $subscription->fresh();
            abort_unless($subscription->status !== Subscription::STATUS_CANCELLED, 422);

            try {
                $this->gateways->for($subscription)->cancel($subscription);
            } catch (Throwable $exception) {
                report($exception);

                return $this->providerUnavailableResponse();
            }

            $subscription->update([
                'status' => Subscription::STATUS_CANCELLED,
                'pending_plan_id' => null,
                'pending_plan_interval' => null,
                'pending_plan_checkout_url' => null,
                'cancelled_at' => now(),
                // Stamp the local decision with a provider-event timestamp so
                // the webhook ordering guard can drop a stale ACTIVE event.
                // Without it a delayed BILLING.SUBSCRIPTION.UPDATED could
                // resurrect a subscription the user just cancelled.
                'paypal_last_event_at' => now(),
            ]);

            // Cancelling drops the account below the tiers that carry add-ons, so
            // pause its integrations now rather than at the next sweep. Settings
            // are kept; reconnecting is one upgrade away.
            $this->syncSubscriptionEligibility($subscription->fresh());

            return response()->json([
                'data' => (new SubscriptionResource($subscription->fresh()->load('plan')))->resolve(),
            ]);
        } finally {
            $lock->release();
        }
    }

    /**
     * Ensure only an organization manager can start shared billing.
     */
    protected function assertCanStartCheckout(User $user): void
    {
        if ($user->organization_id === null) {
            return;
        }

        abort_unless(
            $user->hasActiveMembership() && $user->canManageOrganization(),
            403,
            'Only organization admins can manage billing.',
        );
    }

    /**
     * Ensure shared subscriptions can only be managed by organization admins.
     */
    protected function assertCanManageBilling(User $user, Subscription $subscription): void
    {
        if ($subscription->organization_id === null) {
            abort_unless($subscription->user_id === $user->id, 403, 'Only the subscription owner can manage billing.');

            return;
        }

        abort_unless(
            $subscription->organization_id === $user->organization_id
                && $user->hasActiveMembership()
                && $user->canManageOrganization(),
            403,
            'Only organization admins can manage billing.',
        );
    }

    /**
     * Reject local or incomplete rows before dispatching to a provider client.
     */
    protected function assertProviderSubscription(Subscription $subscription): void
    {
        $providerSubscriptionId = match ($subscription->gateway) {
            BillingGateway::Paypal->value => $subscription->paypal_subscription_id,
            BillingGateway::Paymongo->value => $subscription->paymongo_subscription_id,
            BillingGateway::LemonSqueezy->value => $subscription->lemonsqueezy_subscription_id,
            default => null,
        };

        abort_unless(
            is_string($providerSubscriptionId) && $providerSubscriptionId !== '',
            422,
            'This subscription is not managed by a payment provider yet.',
        );
    }

    /**
     * Reconcile integrations against the subscription's billing owner.
     */
    protected function syncSubscriptionEligibility(Subscription $subscription): void
    {
        $eligibility = app(IntegrationEligibility::class);

        if ($subscription->organization_id !== null && $subscription->organization !== null) {
            $eligibility->syncOrganization($subscription->organization);

            return;
        }

        if ($subscription->user !== null) {
            $eligibility->syncUser($subscription->user);
        }
    }

    /**
     * Keep provider outages from surfacing as framework 500 pages to the SPA.
     */
    protected function providerUnavailableResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'The payment provider is temporarily unavailable. Please try again.',
        ], 502);
    }

    /**
     * Purchase additional seats on the organization's subscription. Admins
     * only; logged as a billing event so the next invoice reflects the change.
     */
    public function addSeats(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $organization = $user->organization;

        abort_unless(
            $organization !== null,
            422,
            'Seat management is only available for organization subscriptions.',
        );
        abort_unless($user->hasActiveMembership(), 403);

        $subscription = $this->seats->addSeats($organization, $user, (int) $validated['quantity']);

        return response()->json([
            'data' => (new SubscriptionResource($subscription->load('plan')))->resolve(),
        ]);
    }

    /**
     * Remove purchased seats from the organization's subscription. Admins
     * only; blocked when the reduction would drop below the active member
     * count. Logged as a billing event.
     */
    public function removeSeats(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $organization = $user->organization;

        abort_unless(
            $organization !== null,
            422,
            'Seat management is only available for organization subscriptions.',
        );
        abort_unless($user->hasActiveMembership(), 403);

        $subscription = $this->seats->removeSeats($organization, $user, (int) $validated['quantity']);

        return response()->json([
            'data' => (new SubscriptionResource($subscription->load('plan')))->resolve(),
        ]);
    }

    /**
     * Handle a PayMongo webhook event (no auth; verified by signature).
     */
    public function webhook(Request $request): JsonResponse
    {
        $raw = $request->getContent();

        if (! $this->paymongo->verifyWebhookSignature($request->headers->all(), $raw)) {
            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        $attributes = $request->json('data.attributes', []);
        $eventType = $attributes['type'] ?? null;
        $eventData = $attributes['data'] ?? [];

        match ($eventType) {
            'subscription.invoice.paid' => $this->markInvoicePaid($eventData),
            'subscription.updated', 'subscription.past_due', 'subscription.unpaid' => $this->syncStatus($eventData),
            default => null,
        };

        return response()->json(['status' => 'ok'], 200);
    }

    /**
     * Handle a LemonSqueezy webhook event (no auth; verified by signature).
     */
    public function lemonsqueezyWebhook(Request $request): JsonResponse
    {
        $raw = $request->getContent();

        if (! $this->lemonsqueezy->verifyWebhookSignature($request->headers->all(), $raw)) {
            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        $eventName = $request->json('meta.event_name');
        $payload = $request->json('data.attributes', []);

        $resourceId = $request->json('data.id');

        if ($resourceId !== null && ! isset($payload['id'])) {
            $payload['id'] = $resourceId;
        }

        // The checkout's custom fields ride on `meta`, not on the subscription
        // attributes; they carry the id of the pending row we created when the
        // checkout started, which is how the webhook finds it again.
        $customData = $request->json('meta.custom_data');

        if (is_array($customData) && $customData !== []) {
            $payload['custom_data'] = $customData;
        }

        match ($eventName) {
            'subscription_created' => $this->syncLemonSqueezySubscription($payload),
            'subscription_updated' => $this->syncLemonSqueezySubscription($payload),
            'subscription_cancelled' => $this->syncLemonSqueezySubscription($payload),
            'subscription_expired' => $this->syncLemonSqueezySubscription($payload),
            'subscription_paused', 'subscription_resumed' => $this->syncLemonSqueezySubscription($payload),
            'subscription_activated' => $this->activateLemonSqueezySubscription($payload),
            'subscription_payment_success' => $this->activateLemonSqueezySubscription($payload),
            default => null,
        };

        return response()->json(['status' => 'ok'], 200);
    }

    /**
     * Mirror a LemonSqueezy subscription status change, creating a local
     * subscription row when one doesn't exist yet.
     */
    protected function syncLemonSqueezySubscription(array $payload): void
    {
        $lsSubscriptionId = $payload['id'] ?? null;
        $status = $payload['status'] ?? null;

        if ($lsSubscriptionId === null) {
            return;
        }

        $subscription = Subscription::query()
            ->where('lemonsqueezy_subscription_id', $lsSubscriptionId)
            ->first();
        $created = false;

        if ($subscription === null) {
            $subscription = $this->createLemonSqueezySubscriptionFromWebhook($payload);

            if ($subscription === null) {
                return;
            }

            $created = true;
        }

        $localStatus = $this->mapLemonSqueezyStatus($status);

        if ($localStatus !== null) {
            $statusChanged = $subscription->status !== $localStatus;
            $subscription->update([
                'status' => $localStatus,
                'current_period_start' => $payload['created_at'] ?? $subscription->current_period_start,
                'current_period_end' => $payload['renews_at'] ?? $subscription->current_period_end,
                'cancelled_at' => $payload['cancelled_at'] ?? ($localStatus === Subscription::STATUS_CANCELLED ? now() : $subscription->cancelled_at),
            ]);

            if ($created || $statusChanged) {
                $this->syncSubscriptionEligibility($subscription->fresh());
            }
        }
    }

    /**
     * Activate a LemonSqueezy subscription after its first (or subsequent)
     * successful payment.
     */
    protected function activateLemonSqueezySubscription(array $payload): void
    {
        $lsSubscriptionId = $payload['id'] ?? null;

        if ($lsSubscriptionId === null) {
            return;
        }

        $subscription = Subscription::query()
            ->where('lemonsqueezy_subscription_id', $lsSubscriptionId)
            ->first();
        $created = false;

        if ($subscription === null) {
            $subscription = $this->createLemonSqueezySubscriptionFromWebhook($payload);

            if ($subscription === null) {
                return;
            }

            $created = true;
        }

        $statusChanged = $subscription->status !== Subscription::STATUS_ACTIVE;
        $subscription->update([
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => $payload['created_at'] ?? now(),
            'current_period_end' => $payload['renews_at'] ?? $this->periodEnd($subscription),
            'cancelled_at' => null,
        ]);

        if ($created || $statusChanged) {
            $this->syncSubscriptionEligibility($subscription->fresh());
        }
    }

    /**
     * Attach a LemonSqueezy webhook payload to a local subscription row,
     * matching the variant back to a plan and the email to a user.
     *
     * Checkout already created a pending row for this purchase, so the first
     * thing to try is claiming that one: it holds the seats the plan bundles
     * and the seat price, which a row invented here would not.
     */
    protected function createLemonSqueezySubscriptionFromWebhook(array $payload): ?Subscription
    {
        $variantId = data_get($payload, 'variant_id');
        $userEmail = $payload['user_email'] ?? null;
        $lsSubscriptionId = $payload['id'] ?? null;

        $user = $userEmail !== null
            ? User::where('email', $userEmail)->first()
            : null;

        if ($user === null || $variantId === null || $lsSubscriptionId === null) {
            return null;
        }

        $plan = Plan::query()
            ->where('lemonsqueezy_variant_id', $variantId)
            ->orWhere('lemonsqueezy_variant_id_annual', $variantId)
            ->first();

        if ($plan === null) {
            return null;
        }

        $interval = $plan->lemonsqueezy_variant_id_annual === $variantId
            ? Plan::INTERVAL_ANNUAL
            : Plan::INTERVAL_MONTHLY;

        $pending = $this->pendingLemonSqueezySubscription($user, $payload);

        if ($pending !== null) {
            $pending->update([
                'plan_id' => $plan->id,
                'interval' => $interval,
                'lemonsqueezy_subscription_id' => $lsSubscriptionId,
                'lemonsqueezy_customer_id' => $payload['customer_id'] ?? $pending->lemonsqueezy_customer_id,
                // The pending row was priced for the plan the checkout started
                // on; honour whatever the buyer actually paid for.
                'price_per_seat' => $plan->seat_price ?? $plan->price,
                'seats_purchased' => max($pending->seats_purchased, $plan->included_seats ?? 1),
            ]);

            return $pending->fresh();
        }

        return Subscription::create([
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'plan_id' => $plan->id,
            'interval' => $interval,
            'gateway' => Subscription::GATEWAY_LEMONSQUEEZY,
            'lemonsqueezy_subscription_id' => $lsSubscriptionId,
            'lemonsqueezy_customer_id' => $payload['customer_id'] ?? null,
            'status' => $this->mapLemonSqueezyStatus($payload['status'] ?? null) ?? Subscription::STATUS_INCOMPLETE,
            'current_period_start' => $payload['created_at'] ?? null,
            'current_period_end' => $payload['renews_at'] ?? null,
            // A subscription that reaches us only through the webhook still has
            // to hand over the seats its plan bundles.
            'seats_purchased' => $plan->included_seats ?? 1,
            'price_per_seat' => $plan->seat_price ?? $plan->price,
        ]);
    }

    /**
     * The row `store()` created for this checkout, before LemonSqueezy told us
     * which subscription it became. Preferred by id from the checkout's custom
     * data; otherwise the user's newest LemonSqueezy row that never got linked.
     */
    protected function pendingLemonSqueezySubscription(User $user, array $payload): ?Subscription
    {
        $unlinked = Subscription::query()
            ->where('user_id', $user->id)
            ->where('gateway', Subscription::GATEWAY_LEMONSQUEEZY)
            ->whereNull('lemonsqueezy_subscription_id');

        $pendingId = data_get($payload, 'custom_data.subscription_id');

        if (is_string($pendingId) && $pendingId !== '') {
            $claimed = (clone $unlinked)->whereKey($pendingId)->first();

            if ($claimed !== null) {
                return $claimed;
            }
        }

        return $unlinked
            ->where('status', Subscription::STATUS_INCOMPLETE)
            ->latest('created_at')
            ->first();
    }

    /**
     * Map a LemonSqueezy subscription status to our local status values.
     */
    protected function mapLemonSqueezyStatus(?string $status): ?string
    {
        return match ($status) {
            'active' => Subscription::STATUS_ACTIVE,
            'cancelled', 'expired' => Subscription::STATUS_CANCELLED,
            'past_due', 'unpaid' => Subscription::STATUS_PAST_DUE,
            'paused' => Subscription::STATUS_PAUSED,
            default => null,
        };
    }

    /**
     * The end of the current billing period.
     */
    protected function periodEnd(Subscription $subscription): Carbon
    {
        return $subscription->interval === Plan::INTERVAL_ANNUAL
            ? now()->addYear()->endOfMonth()
            : now()->addMonth()->endOfMonth();
    }

    /**
     * Mark a subscription active after a successful invoice payment.
     */
    protected function markInvoicePaid(array $eventData): void
    {
        $subscriptionId = data_get($eventData, 'attributes.resource_id');

        $subscription = $subscriptionId !== null
            ? Subscription::query()->where('paymongo_subscription_id', $subscriptionId)->first()
            : null;

        if ($subscription === null) {
            return;
        }

        $periodEnd = $subscription->interval === Plan::INTERVAL_ANNUAL
            ? now()->addYear()->endOfMonth()
            : now()->addMonth()->endOfMonth();

        $statusChanged = $subscription->status !== Subscription::STATUS_ACTIVE;
        $subscription->update([
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => now()->startOfDay(),
            'current_period_end' => $periodEnd,
            'cancelled_at' => null,
        ]);

        if ($statusChanged) {
            $this->syncSubscriptionEligibility($subscription->fresh());
        }
    }

    /**
     * Mirror a subscription status change sent by PayMongo.
     */
    protected function syncStatus(array $eventData): void
    {
        $subscriptionId = $eventData['id'] ?? null;
        $status = data_get($eventData, 'attributes.status');

        $subscription = $subscriptionId !== null
            ? Subscription::query()->where('paymongo_subscription_id', $subscriptionId)->first()
            : null;

        if ($subscription === null || $status === null) {
            return;
        }

        $allowed = [
            Subscription::STATUS_ACTIVE,
            Subscription::STATUS_PAST_DUE,
            Subscription::STATUS_UNPAID,
            Subscription::STATUS_CANCELLED,
            Subscription::STATUS_INCOMPLETE,
            Subscription::STATUS_INCOMPLETE_CANCELLED,
        ];

        if (in_array($status, $allowed, true)) {
            $statusChanged = $subscription->status !== $status;
            $subscription->update(['status' => $status]);

            if ($statusChanged) {
                $this->syncSubscriptionEligibility($subscription->fresh());
            }
        }
    }
}
