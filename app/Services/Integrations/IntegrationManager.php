<?php

namespace App\Services\Integrations;

use App\Enums\IntegrationProvider;
use App\Models\Integration;
use App\Models\IntegrationAuditLog;
use App\Models\User;
use App\Services\Integrations\Providers\ProviderOAuthClients;

/**
 * The orchestrator behind the add-ons page: starting and finishing OAuth
 * round-trips, toggling capabilities, and disconnecting.
 *
 * Every mutating entry point runs the plan check first — the locked cards a
 * lower-tier user sees are an upsell, and this class is the gate they never
 * get past.
 */
class IntegrationManager
{
    public function __construct(
        protected readonly ProviderOAuthClients $clients,
        protected readonly OAuthStateStore $states,
        protected readonly IntegrationAuditLogger $audit,
        protected readonly IntegrationEligibility $eligibility,
        protected readonly WebhookRegistrar $webhooks,
    ) {
        //
    }

    /**
     * The URL the provider returns the user to after consent. Defaults to the
     * API's own origin, but routes through the Nuxt callback when one is
     * configured so the frontend can proxy the round-trip.
     */
    public function redirectUri(): string
    {
        $base = (string) config('integrations.callback_base_url');

        if ($base === '') {
            $base = (string) config('app.url');
        }

        return rtrim($base, '/').config('integrations.redirect_path');
    }

    /**
     * Everything the add-ons page renders: the provider catalogue, the user's
     * connections, and — for an organization manager — the firm controls.
     *
     * Deliberately ungated: the page is a discovery surface, so a user on any
     * plan can read it and see exactly what they are missing.
     *
     * @return array<string, mixed>
     */
    public function indexPayload(User $user): array
    {
        $eligible = $this->eligibility->isEligible($user);

        $providers = array_map(
            fn (array $definition): array => $this->providerPayload($user, $definition),
            IntegrationCatalogue::all(),
        );

        return [
            'eligible' => $eligible,
            'upgrade_message' => $eligible
                ? null
                : 'Add-ons are available on the Pro and Firm plans.',
            'providers' => $providers,
            'admin' => $this->adminPayload($user),
        ];
    }

    /**
     * Start a connection: plan-check, work out whether this is a personal or
     * firm-wide connection, and hand back the consent URL plus the data
     * disclosure shown before the user leaves.
     *
     * @return array{authorize_url: string, privacy_summary: list<array{key: string, label: string, data_access: string}>}
     */
    public function beginConnection(User $user, IntegrationProvider $provider): array
    {
        $this->eligibility->ensureEligible($user);
        $this->assertCanConnect($user, $provider);

        $state = $this->states->issue($user, $provider, OAuthStateStore::PURPOSE_CONNECT);

        return [
            'authorize_url' => $this->clients->for($provider)->authorizationUrl(
                IntegrationCatalogue::baseScopes($provider),
                $state,
                $this->redirectUri(),
                $this->states->codeChallenge($state),
            ),
            'privacy_summary' => $this->privacySummary($provider),
        ];
    }

    /**
     * Start the consent round-trip that adds one capability's scopes to an
     * existing connection.
     *
     * @return array{authorize_url: string, privacy_summary: list<array{key: string, label: string, data_access: string}>}
     */
    public function beginCapabilityConsent(User $user, Integration $integration, string $capability): array
    {
        $this->eligibility->ensureEligible($user);
        $this->assertCanManage($user, $integration);

        $definition = IntegrationCatalogue::capability($integration->provider, $capability);
        abort_if($definition === null, 422, 'Unknown capability.');

        $state = $this->states->issue(
            $user,
            $integration->provider,
            OAuthStateStore::PURPOSE_ENABLE_CAPABILITY,
            $capability,
        );

        // The consent asks for everything the connection should hold once the
        // capability is on: what it already has plus what the capability adds.
        $scopes = array_values(array_unique([
            ...($integration->granted_scopes ?? []),
            ...$definition['scopes'],
        ]));

        return [
            'authorize_url' => $this->clients->for($integration->provider)->authorizationUrl(
                $scopes,
                $state,
                $this->redirectUri(),
                $this->states->codeChallenge($state),
            ),
            'privacy_summary' => $this->privacySummary($integration->provider, [$capability]),
        ];
    }

    /**
     * Start the consent round-trip that heals a connection whose token the
     * provider no longer accepts. The scopes asked for are the ones the
     * connection's enabled capabilities need — reauthorizing never widens a
     * grant.
     *
     * @return array{authorize_url: string}
     */
    public function beginReauthorization(User $user, Integration $integration): array
    {
        $this->eligibility->ensureEligible($user);
        $this->assertCanManage($user, $integration);

        $state = $this->states->issue($user, $integration->provider, OAuthStateStore::PURPOSE_REAUTHORIZE);

        $scopes = IntegrationCatalogue::scopesFor(
            $integration->provider,
            $integration->enabledCapabilities(),
        );

        return [
            'authorize_url' => $this->clients->for($integration->provider)->authorizationUrl(
                $scopes,
                $state,
                $this->redirectUri(),
                $this->states->codeChallenge($state),
            ),
        ];
    }

    /**
     * Finish a consent round-trip: exchange the code, store the tokens
     * encrypted, and apply whatever the round-trip was for.
     */
    public function completeAuthorization(string $state, string $code): Integration
    {
        $payload = $this->states->consume($state);

        abort_if($payload === null, 400, 'This sign-in link has expired or is invalid. Start the connection again.');

        $user = User::findOrFail($payload['user_id']);

        // Re-check at completion, not only at the start: a plan can lapse
        // while the user is on the consent screen.
        $this->eligibility->ensureEligible($user);

        $provider = $payload['provider'];
        $tokens = $this->clients->for($provider)->exchangeCode($code, $this->redirectUri(), $payload['code_verifier'] ?? null);
        $account = $this->clients->for($provider)->accountInfo($tokens['access_token']);

        $integration = $this->upsertConnection($user, $provider, $tokens, $account);

        $isReconnect = $integration->wasRecentlyCreated === false
            && $integration->getOriginal('status') !== Integration::STATUS_CONNECTED;

        $this->audit->logFromRequest(
            request(),
            $provider,
            $isReconnect ? IntegrationAuditLog::ACTION_REAUTHORIZED : IntegrationAuditLog::ACTION_CONNECTED,
            $integration,
            ['scopes' => $integration->granted_scopes ?? [], 'account' => $integration->account_email],
        );

        if ($payload['purpose'] === OAuthStateStore::PURPOSE_ENABLE_CAPABILITY && $payload['capability'] !== null) {
            // Google's granular consent lets the user untick individual
            // scopes, so a completed round-trip doesn't prove the capability's
            // scopes were granted. Only switch it on when they were.
            $definition = IntegrationCatalogue::capability($provider, $payload['capability']);
            $missing = array_values(array_diff($definition['scopes'] ?? [], $integration->granted_scopes ?? []));

            if ($missing !== []) {
                $integration->updateCapabilityState($payload['capability'], [
                    'enabled' => false,
                    'last_error' => 'The permission this feature needs was not granted. Turn it on again and allow access.',
                ]);

                return $integration;
            }

            $this->enableCapability($integration, $payload['capability']);

            $this->audit->logFromRequest(
                request(),
                $provider,
                IntegrationAuditLog::ACTION_CAPABILITY_ENABLED,
                $integration,
                ['capability' => $payload['capability'], 'via' => 'incremental_consent'],
            );
        }

        return $integration;
    }

    /**
     * Switch a capability on or off.
     *
     * Enabling a capability whose scopes the connection does not hold yet
     * answers with the consent URL that asks for exactly those scopes —
     * incremental authorization, not an all-or-nothing reconnect. Disabling
     * one answers the same way when dropping it leaves scopes behind that
     * nothing else uses, so the grant narrows rather than staying wide.
     *
     * @return array{enabled: bool, authorization_required: bool, authorize_url: string|null}
     */
    public function setCapabilityEnabled(User $user, IntegrationProvider $provider, string $capability, bool $enabled): array
    {
        $this->eligibility->ensureEligible($user);

        $definition = IntegrationCatalogue::capability($provider, $capability);
        abort_if($definition === null, 422, 'Unknown capability.');

        $integration = $this->resolveOwnedConnection($user, $provider);
        abort_if($integration === null, 404, 'This integration is not connected.');
        abort_if($integration->isPaused(), 422, 'This integration is paused. Upgrade your plan to use it.');
        abort_if($integration->needsReauthorization(), 422, 'This integration needs reauthorization before its capabilities can change.');

        $this->assertCanManage($user, $integration);
        $this->assertPolicyAllows($integration, $capability, $enabled);

        if ($enabled) {
            return $this->enableWithConsent($user, $integration, $capability, $definition['scopes']);
        }

        return $this->disableWithConsent($user, $integration, $capability, $definition['scopes']);
    }

    /**
     * Disconnect: revoke what can be revoked, delete the credentials, and
     * keep the audit row. Available to whoever owns the connection — cutting
     * a cord is never something a plan should hold hostage.
     */
    public function disconnect(User $user, IntegrationProvider $provider): void
    {
        $integration = $this->resolveOwnedConnection($user, $provider);
        abort_if($integration === null, 404, 'This integration is not connected.');

        $this->assertCanManage($user, $integration);

        $client = $this->clients->for($provider);

        // Stop push channels first, while the token still works; otherwise
        // the provider keeps notifying an endpoint that will ignore it.
        $this->webhooks->unsubscribeAll($integration);

        // Best-effort: a provider that refuses revocation must not trap the
        // user in a connection they are trying to leave.
        try {
            $client->revokeToken($integration->refresh_token ?? (string) $integration->access_token);
        } catch (\Throwable) {
            // Deliberately swallowed; the credentials are deleted below.
        }

        $this->audit->logFromRequest(
            request(),
            $provider,
            IntegrationAuditLog::ACTION_DISCONNECTED,
            $integration,
            ['scopes' => $integration->granted_scopes ?? [], 'capabilities' => $integration->enabledCapabilities()],
        );

        $integration->delete();
    }

    /**
     * The connection a user acts on for a provider: their own, or the firm's
     * when the organization connects once for everyone.
     */
    public function resolveOwnedConnection(User $user, IntegrationProvider $provider): ?Integration
    {
        $own = $user->integrations()->where('provider', $provider)->first();

        if ($own !== null) {
            return $own;
        }

        $organization = $user->organization;

        if ($organization === null || ! $organization->usesFirmWideIntegrations()) {
            return null;
        }

        return $organization->integrations()->where('provider', $provider)->first();
    }

    /**
     * Whether a capability should actually run for a connection, once the
     * organization's forced-on and forced-off policies are applied.
     */
    public function isEffectivelyEnabled(Integration $integration, string $capability): bool
    {
        $organization = $integration->organization ?? $integration->user?->organization;

        if ($organization?->capabilityForcedOff($capability) === true) {
            return false;
        }

        if ($organization?->capabilityForcedOn($capability) === true) {
            return true;
        }

        return $integration->capabilityEnabled($capability);
    }

    /**
     * Refuse a connection attempt the organization's mode does not allow.
     */
    protected function assertCanConnect(User $user, IntegrationProvider $provider): void
    {
        $organization = $user->organization;

        if ($organization?->usesFirmWideIntegrations() === true) {
            abort_unless(
                $user->canManageOrganization(),
                403,
                'Your firm connects this add-on once for everyone. Ask a firm admin to set it up.',
            );

            $existing = $organization->integrations()->where('provider', $provider)->first();

            abort_if(
                $existing !== null && ! $existing->isPaused(),
                422,
                'This add-on is already connected for your firm.',
            );

            return;
        }

        $existing = $user->integrations()->where('provider', $provider)->first();

        abort_if(
            $existing !== null && ($existing->isConnected() || $existing->needsReauthorization()),
            422,
            'This add-on is already connected. Manage it from the card instead.',
        );
    }

    /**
     * Refuse a change the user has no standing to make: a firm-wide
     * connection answers to firm admins only.
     */
    protected function assertCanManage(User $user, Integration $integration): void
    {
        if (! $integration->isFirmWide()) {
            return;
        }

        abort_unless(
            $user->canManageOrganization() && $user->organization_id === $integration->organization_id,
            403,
            'Only firm admins can manage a firm-wide connection.',
        );
    }

    /**
     * Apply the organization's capability policy to a toggle before it
     * happens.
     */
    protected function assertPolicyAllows(Integration $integration, string $capability, bool $enabled): void
    {
        $organization = $integration->organization ?? $integration->user?->organization;

        if ($organization === null) {
            return;
        }

        if ($organization->capabilityForcedOff($capability) && $enabled) {
            abort(422, 'Your organization has disabled this capability for everyone.');
        }

        if ($organization->capabilityForcedOn($capability) && ! $enabled) {
            abort(422, 'Your organization requires this capability to stay enabled.');
        }
    }

    /**
     * Enable a capability, asking for consent first when its scopes are not
     * granted yet.
     *
     * @param  list<string>  $scopes
     * @return array{enabled: bool, authorization_required: bool, authorize_url: string|null}
     */
    protected function enableWithConsent(User $user, Integration $integration, string $capability, array $scopes): array
    {
        $granted = $integration->granted_scopes ?? [];
        $missing = array_values(array_diff($scopes, $granted));

        if ($missing !== []) {
            $consent = $this->beginCapabilityConsent($user, $integration, $capability);

            return [
                'enabled' => false,
                'authorization_required' => true,
                'authorize_url' => $consent['authorize_url'],
            ];
        }

        $this->enableCapability($integration, $capability);

        $this->audit->logFromRequest(
            request(),
            $integration->provider,
            IntegrationAuditLog::ACTION_CAPABILITY_ENABLED,
            $integration,
            ['capability' => $capability],
        );

        return ['enabled' => true, 'authorization_required' => false, 'authorize_url' => null];
    }

    /**
     * Disable a capability, and when its scopes are no longer used by
     * anything else, answer with the consent URL that narrows the grant.
     *
     * Neither Google nor Microsoft can strip a single scope from an existing
     * grant on demand; the narrowing happens by consenting again to the
     * reduced set. When the user skips that step the capability is still off
     * and its scopes are never used — the consent only makes the provider
     * side match.
     *
     * @param  list<string>  $scopes
     * @return array{enabled: bool, authorization_required: bool, authorize_url: string|null}
     */
    protected function disableWithConsent(User $user, Integration $integration, string $capability, array $scopes): array
    {
        $this->disableCapability($integration, $capability);

        $this->audit->logFromRequest(
            request(),
            $integration->provider,
            IntegrationAuditLog::ACTION_CAPABILITY_DISABLED,
            $integration,
            ['capability' => $capability],
        );

        $remaining = IntegrationCatalogue::scopesFor(
            $integration->provider,
            $integration->enabledCapabilities(),
        );

        $granted = $integration->granted_scopes ?? [];
        $orphaned = array_values(array_diff($granted, $remaining));

        if ($orphaned === []) {
            return ['enabled' => false, 'authorization_required' => false, 'authorize_url' => null];
        }

        $state = $this->states->issue($user, $integration->provider, OAuthStateStore::PURPOSE_REAUTHORIZE);

        $authorizeUrl = $this->clients->for($integration->provider)->authorizationUrl(
            $remaining,
            $state,
            $this->redirectUri(),
            $this->states->codeChallenge($state),
        );

        $this->audit->logFromRequest(
            request(),
            $integration->provider,
            IntegrationAuditLog::ACTION_SCOPES_REVOKED,
            $integration,
            ['scopes' => $orphaned, 'capability' => $capability],
        );

        return ['enabled' => false, 'authorization_required' => true, 'authorize_url' => $authorizeUrl];
    }

    /**
     * Mark a capability on.
     */
    protected function enableCapability(Integration $integration, string $capability): void
    {
        $integration->updateCapabilityState($capability, [
            'enabled' => true,
            'enabled_at' => now()->toIso8601String(),
            'sync_status' => 'idle',
            'last_error' => null,
        ]);
    }

    /**
     * Mark a capability off, keeping its sync history so a re-enable shows
     * where things stood.
     */
    protected function disableCapability(Integration $integration, string $capability): void
    {
        $this->webhooks->unsubscribe($integration, $capability);

        $integration->updateCapabilityState($capability, [
            'enabled' => false,
            'sync_status' => 'idle',
            'last_error' => null,
        ]);
    }

    /**
     * Create or revive the connection row from a completed consent.
     *
     * @param  array{access_token: string, refresh_token: string|null, expires_in: int|null, scope: string|null}  $tokens
     * @param  array{id: string|null, email: string|null, name: string|null}  $account
     */
    protected function upsertConnection(User $user, IntegrationProvider $provider, array $tokens, array $account): Integration
    {
        $organization = $user->organization;
        $firmWide = $organization?->usesFirmWideIntegrations() === true && $user->canManageOrganization();

        $integration = $firmWide
            ? ($organization->integrations()->where('provider', $provider)->first()
                ?? $user->integrations()->where('provider', $provider)->first())
            : $user->integrations()->where('provider', $provider)->first();

        $attributes = [
            'user_id' => $user->id,
            'organization_id' => $firmWide ? $organization->id : $user->organization_id,
            'connection_scope' => $firmWide ? Integration::SCOPE_FIRM_WIDE : Integration::SCOPE_PERSONAL,
            'status' => Integration::STATUS_CONNECTED,
            'provider_account_id' => $account['id'],
            'account_email' => $account['email'],
            'account_name' => $account['name'],
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?? $integration?->refresh_token,
            'token_expires_at' => $tokens['expires_in'] !== null ? now()->addSeconds($tokens['expires_in']) : null,
            'granted_scopes' => $tokens['scope'] !== null ? explode(' ', $tokens['scope']) : [],
            'paused_at' => null,
            'paused_reason' => null,
            'connected_at' => $integration?->connected_at ?? now(),
        ];

        if ($integration === null) {
            return Integration::create(['provider' => $provider] + $attributes);
        }

        $integration->forceFill($attributes)->save();

        return $integration;
    }

    /**
     * One provider's card for the add-ons page: catalogue copy plus whatever
     * connection state the user can see.
     *
     * @param  array{provider: string, name: string, description: string, capabilities: array<string, array<string, mixed>>}  $definition
     * @return array<string, mixed>
     */
    protected function providerPayload(User $user, array $definition): array
    {
        $provider = IntegrationProvider::from($definition['provider']);
        $integration = $user->integrationFor($provider);
        $organization = $user->organization;

        $capabilities = [];

        foreach ($definition['capabilities'] as $key => $capability) {
            $state = $integration?->capabilityState($key) ?? [
                'enabled' => false,
                'enabled_at' => null,
                'last_synced_at' => null,
                'sync_status' => 'idle',
                'last_error' => null,
            ];

            $capabilities[] = $capability + [
                'state' => $state + [
                    'effectively_enabled' => $integration !== null && $this->isEffectivelyEnabled($integration, $key),
                    'policy' => $organization?->capabilityPolicy($key),
                ],
            ];
        }

        return [
            'provider' => $definition['provider'],
            'name' => $definition['name'],
            'description' => $definition['description'],
            'capabilities' => $capabilities,
            'connection' => $integration === null ? null : [
                'id' => $integration->id,
                'status' => $integration->status,
                'account_email' => $integration->account_email,
                'account_name' => $integration->account_name,
                'connection_scope' => $integration->connection_scope,
                'connected_at' => $integration->connected_at?->toIso8601String(),
                'last_synced_at' => $integration->last_synced_at?->toIso8601String(),
                'paused_reason' => $integration->paused_reason,
                'can_manage' => $integration->isFirmWide()
                    ? $user->canManageOrganization() && $user->organization_id === $integration->organization_id
                    : $integration->user_id === $user->id,
            ],
        ];
    }

    /**
     * The firm controls block, present only for someone who can manage the
     * organization — the endpoints behind it enforce the same rule.
     *
     * @return array<string, mixed>|null
     */
    protected function adminPayload(User $user): ?array
    {
        $organization = $user->organization;

        if ($organization === null || ! $user->canManageOrganization()) {
            return null;
        }

        return [
            'is_manager' => true,
            'connection_mode' => $organization->integrations_connection_mode ?? 'per_seat',
            'policies' => $organization->integration_capability_policies ?? (object) [],
        ];
    }

    /**
     * The data-access disclosure shown before the user consents.
     *
     * @param  list<string>|null  $onlyCapabilities
     * @return list<array{key: string, label: string, data_access: string}>
     */
    protected function privacySummary(IntegrationProvider $provider, ?array $onlyCapabilities = null): array
    {
        $summary = [];

        foreach (IntegrationCatalogue::capabilities($provider) as $key => $capability) {
            if ($onlyCapabilities !== null && ! in_array($key, $onlyCapabilities, true)) {
                continue;
            }

            $summary[] = [
                'key' => $key,
                'label' => $capability['label'],
                'data_access' => $capability['data_access'],
            ];
        }

        return $summary;
    }
}
