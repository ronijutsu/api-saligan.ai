<?php

namespace App\Http\Controllers\Api;

use App\Enums\IntegrationProvider;
use App\Http\Controllers\Controller;
use App\Http\Resources\IntegrationAuditLogResource;
use App\Jobs\SyncIntegrationCapability;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\User;
use App\Services\Integrations\IntegrationAdminService;
use App\Services\Integrations\IntegrationCatalogue;
use App\Services\Integrations\IntegrationEligibility;
use App\Services\Integrations\IntegrationManager;
use App\Services\Integrations\IntegrationSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The add-ons surface: the catalogue every user can see, and the connect,
 * toggle, sync, and disconnect flows only an eligible plan can drive.
 */
class IntegrationController extends Controller
{
    public function __construct(
        protected readonly IntegrationManager $manager,
        protected readonly IntegrationSyncService $sync,
        protected readonly IntegrationAdminService $admin,
    ) {
        //
    }

    /**
     * The add-ons page payload. Deliberately reachable on every plan: the
     * cards are a discovery surface, and a locked card still has to render.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->manager->indexPayload($request->user()),
        ]);
    }

    /**
     * Start a connection. Answers with the provider consent URL and the data
     * disclosure to show before the user leaves.
     */
    public function connect(Request $request, string $provider): JsonResponse
    {
        $provider = $this->resolveProvider($provider);

        $result = $this->manager->beginConnection($request->user(), $provider);

        return response()->json(['data' => $result]);
    }

    /**
     * The OAuth landing: the provider returns the user here with a code and
     * the state that names who started the flow and why. No bearer token
     * rides along, so the state is the only authentication — it is encrypted
     * and expiry-stamped.
     *
     * The browser is then sent back to the add-ons page with the outcome in
     * the query string. When the request carries the proxy header (the Nuxt
     * app owns the callback and forwards here), the outcome is returned as
     * JSON so the proxy can issue its own redirect without following ours.
     */
    public function callback(Request $request)
    {
        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
        $proxied = $request->header('X-Integrations-Proxy') === '1';

        $target = function (string $status, ?string $provider = null) use ($frontendUrl): string {
            $query = http_build_query(array_filter([
                'integration_status' => $status,
                'provider' => $provider,
            ]));

            return "{$frontendUrl}/settings/addons?{$query}";
        };

        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');

        if ($request->filled('error') || $code === '' || $state === '') {
            $reason = $request->query('error') === 'access_denied' ? 'denied' : 'error';
            $url = $target($reason);

            return $proxied ? response()->json(['redirect' => $url]) : redirect($url);
        }

        try {
            $integration = $this->manager->completeAuthorization($state, $code);
        } catch (\Throwable $e) {
            report($e);

            $url = $target('error');

            return $proxied ? response()->json(['redirect' => $url]) : redirect($url);
        }

        $url = $target('success', $integration->provider->value);

        return $proxied ? response()->json(['redirect' => $url]) : redirect($url);
    }

    /**
     * Switch one capability on or off. Enabling can answer with a consent URL
     * when the capability's scopes are not granted yet; disabling can do the
     * same when it leaves orphaned scopes to narrow away.
     */
    public function toggleCapability(Request $request, string $provider, string $capability): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $provider = $this->resolveProvider($provider);

        $result = $this->manager->setCapabilityEnabled(
            $request->user(),
            $provider,
            $capability,
            (bool) $validated['enabled'],
        );

        return response()->json(['data' => $result]);
    }

    /**
     * Run a sync now — every enabled capability, or one named capability.
     */
    public function sync(Request $request, string $provider): JsonResponse
    {
        $validated = $request->validate([
            'capability' => ['sometimes', 'string'],
        ]);

        $provider = $this->resolveProvider($provider);

        // A sync spends the plan's add-on entitlement, so gate it like the
        // other mutating calls even though the connection already exists.
        app(IntegrationEligibility::class)->ensureEligible($request->user());

        $integration = $this->manager->resolveOwnedConnection($request->user(), $provider);
        abort_if($integration === null, 404, 'This integration is not connected.');

        $results = isset($validated['capability'])
            ? [$validated['capability'] => $this->sync->syncCapability($integration, $validated['capability'])]
            : $this->sync->syncAll($integration);

        return response()->json([
            'data' => [
                'results' => $results,
                'last_synced_at' => $integration->fresh()->last_synced_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Heal a connection whose token the provider no longer accepts. Answers
     * with the consent URL that re-grants exactly the enabled capabilities.
     */
    public function reauthorize(Request $request, string $provider): JsonResponse
    {
        $provider = $this->resolveProvider($provider);

        $integration = $this->manager->resolveOwnedConnection($request->user(), $provider);
        abort_if($integration === null, 404, 'This integration is not connected.');

        $result = $this->manager->beginReauthorization($request->user(), $integration);

        return response()->json(['data' => $result]);
    }

    /**
     * Disconnect: revoke with the provider where possible and delete the
     * stored credentials.
     */
    public function disconnect(Request $request, string $provider): JsonResponse
    {
        $provider = $this->resolveProvider($provider);

        $this->manager->disconnect($request->user(), $provider);

        return response()->json(['data' => ['disconnected' => true]]);
    }

    /**
     * The firm management view: which members have connected what, the
     * org-wide policies, and the audit trail. Organization managers only.
     */
    public function admin(Request $request): JsonResponse
    {
        $organization = $this->requireOrgManager($request->user());

        return response()->json([
            'data' => [
                'connection_mode' => $organization->integrations_connection_mode ?? 'per_seat',
                'policies' => $organization->integration_capability_policies ?? (object) [],
                'connections' => $this->admin->memberConnections($organization),
            ],
        ]);
    }

    /**
     * Update the org-wide capability policies.
     */
    public function updatePolicies(Request $request): JsonResponse
    {
        $organization = $this->requireOrgManager($request->user());

        $validated = $request->validate([
            'policies' => ['present', 'array'],
            'policies.*' => ['nullable', 'string', 'in:forced_on,forced_off'],
        ]);

        $organization = $this->admin->setCapabilityPolicies(
            $request->user(),
            $organization,
            $validated['policies'],
        );

        return response()->json([
            'data' => ['policies' => $organization->integration_capability_policies ?? (object) []],
        ]);
    }

    /**
     * Choose between per-seat and firm-wide connections.
     */
    public function updateConnectionMode(Request $request): JsonResponse
    {
        $organization = $this->requireOrgManager($request->user());

        $validated = $request->validate([
            'mode' => ['required', 'string', 'in:per_seat,firm_wide'],
        ]);

        $organization = $this->admin->setConnectionMode(
            $request->user(),
            $organization,
            $validated['mode'],
        );

        return response()->json([
            'data' => ['connection_mode' => $organization->integrations_connection_mode],
        ]);
    }

    /**
     * The integration audit trail for the organization.
     */
    public function auditLogs(Request $request): JsonResponse
    {
        $organization = $this->requireOrgManager($request->user());

        $logs = $this->admin->auditLogs($organization, 50);

        return response()->json([
            'data' => IntegrationAuditLogResource::collection($logs)->resolve(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    /**
     * A Google push notification. The channel id the registration minted
     * names the integration and capability it belongs to; anything that does
     * not parse, or that names a channel no longer stored, is dropped.
     */
    public function googleWebhook(Request $request): JsonResponse
    {
        // Google push notifications have an empty body; everything is in the
        // X-Goog-* headers.
        $channelId = (string) $request->header('X-Goog-Channel-ID', '');
        $resourceState = (string) $request->header('X-Goog-Resource-State', '');

        // `sync` is the handshake sent right after a channel is created, and
        // `not_exists` means the watched resource is gone; neither is a change.
        if ($channelId === '' || in_array($resourceState, ['sync', 'not_exists'], true)) {
            return response()->json(['status' => 'ok']);
        }

        $integration = $this->findByCapabilityState('webhook_channel_id', $channelId);

        if ($integration === null || ! $integration->isConnected()) {
            return response()->json(['status' => 'ignored'], 200);
        }

        foreach (array_keys(IntegrationCatalogue::capabilities($integration->provider)) as $capability) {
            $state = $integration->capabilityState($capability);

            if (($state['webhook_channel_id'] ?? null) !== $channelId) {
                continue;
            }

            // The token set at registration comes back on every genuine
            // notification; a caller that only guessed the channel id lacks it.
            if (! hash_equals((string) ($state['webhook_token'] ?? ''), (string) $request->header('X-Goog-Channel-Token', ''))) {
                return response()->json(['status' => 'ignored'], 200);
            }

            SyncIntegrationCapability::dispatch($integration->id, $capability);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * A Microsoft Graph notification. Graph validates a new subscription by
     * POSTing a validationToken that must be echoed back; real notifications
     * carry the subscription id and the client state the registration chose.
     */
    public function microsoftWebhook(Request $request): mixed
    {
        if ($request->has('validationToken')) {
            return response($request->query('validationToken'))
                ->header('Content-Type', 'text/plain');
        }

        foreach ((array) $request->json('value', []) as $notification) {
            $subscriptionId = $notification['subscriptionId'] ?? null;
            $clientState = $notification['clientState'] ?? null;

            if ($subscriptionId === null) {
                continue;
            }

            $integration = $this->findByCapabilityState('webhook_subscription_id', $subscriptionId);

            if ($integration === null || ! $integration->isConnected()) {
                continue;
            }

            foreach (array_keys(IntegrationCatalogue::capabilities($integration->provider)) as $capability) {
                $state = $integration->capabilityState($capability);

                if (($state['webhook_subscription_id'] ?? null) !== $subscriptionId) {
                    continue;
                }

                // Graph always echoes the clientState set at registration; a
                // missing or different one means the call didn't come from Graph.
                if (! hash_equals((string) ($state['webhook_client_state'] ?? ''), (string) $clientState)) {
                    continue;
                }

                SyncIntegrationCapability::dispatch($integration->id, $capability);
            }
        }

        // 202: the work is queued, not done — what Graph recommends when
        // processing happens after the response.
        return response()->json(['status' => 'accepted'], 202);
    }

    /**
     * The integration whose capability state holds `$key => $value` — a Graph
     * subscription id or a Google channel id. `capabilities` is a json
     * column keyed by capability, so each entry is checked with json_each
     * (a LIKE over a json column is not valid on Postgres).
     */
    protected function findByCapabilityState(string $key, string $value): ?Integration
    {
        return Integration::query()
            ->whereRaw(
                'exists (select 1 from json_each(integrations.capabilities) as c(key, value) where c.value->>? = ?)',
                [$key, $value],
            )
            ->first();
    }

    /**
     * Turn a route segment into a provider, or a 404 when it names none.
     */
    protected function resolveProvider(string $provider): IntegrationProvider
    {
        $resolved = IntegrationProvider::tryFrom($provider);

        abort_if($resolved === null, 404, 'Unknown integration provider.');

        return $resolved;
    }

    /**
     * The caller's organization, when they manage one — a 403 otherwise.
     */
    protected function requireOrgManager(User $user): Organization
    {
        $organization = $user->organization;

        abort_if(
            $organization === null || ! $user->canManageOrganization(),
            403,
            'Only firm admins can manage integrations for the organization.',
        );

        return $organization;
    }
}
