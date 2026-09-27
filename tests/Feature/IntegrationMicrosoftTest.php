<?php

use App\Enums\IntegrationProvider;
use App\Jobs\SyncIntegrationCapability;
use App\Models\Integration;
use App\Models\IntegrationAuditLog;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\IntegrationReauthorizationRequired;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/**
 * Microsoft SharePoint / Graph coverage.
 *
 * The Google add-on is exercised end to end in IntegrationAddonsTest; this
 * file gives the Microsoft path the same treatment. It matters more here
 * because there is no live tenant to click through by hand — every faked
 * response below mirrors the real Microsoft Graph / Entra contract:
 *
 *   token    login.microsoftonline.com/{tenant}/oauth2/v2.0/token
 *   account  graph.microsoft.com/v1.0/me   ({ id, mail|userPrincipalName, displayName })
 *   sites    graph.microsoft.com/v1.0/sites?search=…   ({ value: [] })
 *   lists    graph.microsoft.com/v1.0/sites/root/lists  ({ value: [] })
 *   onedrive graph.microsoft.com/v1.0/me/drive/root/delta ({ value, @odata.deltaLink })
 *   webhook  a validationToken echo, then { value: [{ subscriptionId, clientState }] }
 */
function microsoftTokenFake(array $overrides = []): void
{
    Http::fake([
        'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response($overrides + [
            'access_token' => 'ms-access-token',
            'refresh_token' => 'ms-refresh-token',
            'expires_in' => 3600,
            'scope' => 'openid email profile offline_access User.Read',
        ]),
        'login.microsoftonline.com/*/oauth2/v2.0/logout' => Http::response([], 200),
        'graph.microsoft.com/v1.0/me' => Http::response([
            'id' => 'ms-account-123',
            'mail' => 'lawyer@contoso.com',
            'userPrincipalName' => 'lawyer@contoso.onmicrosoft.com',
            'displayName' => 'Juan Lawyer',
        ]),
    ]);
}

function microsoftStateFrom(string $authorizeUrl): string
{
    parse_str((string) parse_url($authorizeUrl, PHP_URL_QUERY), $query);

    return (string) $query['state'];
}

/** The SharePoint base scopes a bare connection holds before any capability. */
function sharePointBaseScopes(): array
{
    return ['openid', 'email', 'profile', 'offline_access', 'User.Read'];
}

beforeEach(function () {
    Notification::fake();

    config()->set('integrations.microsoft.client_id', 'ms-client-id');
    config()->set('integrations.microsoft.client_secret', 'ms-client-secret');

    $this->organization = Organization::factory()->create();
    $this->owner = User::factory()->ownerOf($this->organization)->create();

    $this->pro = Plan::factory()->pro()->create();

    Subscription::factory()->for($this->organization)->for($this->owner)->create([
        'plan_id' => $this->pro->id,
    ]);
});

// ---------------------------------------------------------------------------
// Connection flow
// ---------------------------------------------------------------------------

it('builds a Microsoft consent url asking for identity scopes only', function () {
    Http::fake();

    $response = $this->signInAs($this->owner)
        ->postJson('/api/integrations/sharepoint/connect')
        ->assertOk();

    $authorizeUrl = $response->json('data.authorize_url');

    expect($authorizeUrl)->toContain('login.microsoftonline.com')
        ->and($authorizeUrl)->toContain('client_id=ms-client-id')
        ->and($authorizeUrl)->toContain('User.Read')
        ->and($authorizeUrl)->toContain('prompt=consent')
        // A bare connection asks for identity only — no data scopes yet.
        ->and($authorizeUrl)->not->toContain('Sites.Read.All')
        ->and($response->json('data.privacy_summary'))->not->toBeEmpty();
});

it('completes the Microsoft OAuth round-trip and stores encrypted tokens', function () {
    microsoftTokenFake();

    $connect = $this->signInAs($this->owner)
        ->postJson('/api/integrations/sharepoint/connect')
        ->assertOk();

    $state = microsoftStateFrom($connect->json('data.authorize_url'));

    $this->get('/api/integrations/callback?code=consent-code&state='.urlencode($state))
        ->assertRedirectContains('integration_status=success');

    $integration = Integration::query()->where('user_id', $this->owner->id)->firstOrFail();

    expect($integration->provider)->toBe(IntegrationProvider::SharePoint)
        ->and($integration->status)->toBe(Integration::STATUS_CONNECTED)
        // Graph returns `mail`; the client prefers it over userPrincipalName.
        ->and($integration->account_email)->toBe('lawyer@contoso.com')
        ->and($integration->access_token)->toBe('ms-access-token')
        ->and($integration->refresh_token)->toBe('ms-refresh-token');

    // Encrypted at rest: the raw column must not hold the plain token.
    $raw = Integration::query()->whereKey($integration->id)->toBase()->first();

    expect($raw->access_token)->not->toContain('ms-access-token');

    expect(IntegrationAuditLog::query()
        ->where('integration_id', $integration->id)
        ->where('action', IntegrationAuditLog::ACTION_CONNECTED)
        ->exists())->toBeTrue();
});

it('falls back to userPrincipalName when Graph returns no mail', function () {
    Http::fake([
        'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
            'access_token' => 'ms-access-token',
            'refresh_token' => 'ms-refresh-token',
            'expires_in' => 3600,
            'scope' => 'openid email profile offline_access User.Read',
        ]),
        'graph.microsoft.com/v1.0/me' => Http::response([
            'id' => 'ms-account-123',
            'mail' => null,
            'userPrincipalName' => 'lawyer@contoso.onmicrosoft.com',
            'displayName' => 'Juan Lawyer',
        ]),
    ]);

    $connect = $this->signInAs($this->owner)
        ->postJson('/api/integrations/sharepoint/connect')
        ->assertOk();

    $state = microsoftStateFrom($connect->json('data.authorize_url'));

    $this->get('/api/integrations/callback?code=consent-code&state='.urlencode($state))
        ->assertRedirectContains('integration_status=success');

    expect(Integration::query()->where('user_id', $this->owner->id)->firstOrFail()->account_email)
        ->toBe('lawyer@contoso.onmicrosoft.com');
});

// ---------------------------------------------------------------------------
// Refresh-token rotation (Microsoft's key difference from Google)
// ---------------------------------------------------------------------------

it('stores the rotated refresh token Microsoft hands back on refresh', function () {
    // Microsoft rotates the refresh token on every use; the new one must win.
    Http::fake([
        'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
            'access_token' => 'rotated-access-token',
            'refresh_token' => 'rotated-refresh-token',
            'expires_in' => 3600,
            'scope' => 'openid email profile offline_access User.Read Sites.Read.All',
        ]),
        'graph.microsoft.com/v1.0/sites*' => Http::response(['value' => []]),
    ]);

    $integration = Integration::factory()->for($this->owner)->sharepoint()
        ->withCapabilities(['sharepoint_import'])
        ->create([
            'refresh_token' => 'original-refresh-token',
            // Expired so the sync forces a refresh before the Graph call.
            'token_expires_at' => now()->subMinute(),
        ]);

    $this->signInAs($this->owner)
        ->postJson('/api/integrations/sharepoint/sync')
        ->assertOk();

    $fresh = $integration->fresh();

    expect($fresh->refresh_token)->toBe('rotated-refresh-token')
        ->and($fresh->access_token)->toBe('rotated-access-token');
});

// ---------------------------------------------------------------------------
// Incremental consent
// ---------------------------------------------------------------------------

it('asks for incremental consent when a SharePoint capability needs new scopes', function () {
    Integration::factory()->for($this->owner)->sharepoint()->create([
        'granted_scopes' => sharePointBaseScopes(),
    ]);

    $response = $this->signInAs($this->owner)
        ->postJson('/api/integrations/sharepoint/capabilities/sharepoint_import', ['enabled' => true])
        ->assertOk();

    expect($response->json('data.authorization_required'))->toBeTrue()
        ->and($response->json('data.enabled'))->toBeFalse()
        ->and($response->json('data.authorize_url'))->toContain('Sites.Read.All');
});

it('enables a SharePoint capability whose scopes are already granted', function () {
    $integration = Integration::factory()->for($this->owner)->sharepoint()->create([
        'granted_scopes' => [...sharePointBaseScopes(), 'Sites.Read.All'],
    ]);

    $this->signInAs($this->owner)
        ->postJson('/api/integrations/sharepoint/capabilities/sharepoint_import', ['enabled' => true])
        ->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.authorization_required', false);

    expect($integration->fresh()->capabilityEnabled('sharepoint_import'))->toBeTrue();
});

it('enables the capability when the incremental consent round-trip completes', function () {
    microsoftTokenFake([
        'scope' => 'openid email profile offline_access User.Read Sites.Read.All',
    ]);

    Integration::factory()->for($this->owner)->sharepoint()->create([
        'granted_scopes' => sharePointBaseScopes(),
    ]);

    $toggle = $this->signInAs($this->owner)
        ->postJson('/api/integrations/sharepoint/capabilities/sharepoint_import', ['enabled' => true])
        ->assertOk();

    $state = microsoftStateFrom($toggle->json('data.authorize_url'));

    $this->get('/api/integrations/callback?code=consent-code&state='.urlencode($state))
        ->assertRedirectContains('integration_status=success');

    $integration = Integration::query()->where('user_id', $this->owner->id)->firstOrFail();

    expect($integration->capabilityEnabled('sharepoint_import'))->toBeTrue()
        ->and($integration->granted_scopes)->toContain('Sites.Read.All');
});

// ---------------------------------------------------------------------------
// Sync — one test per Graph endpoint the gateway drives
// ---------------------------------------------------------------------------

it('syncs every SharePoint capability against the right Graph endpoints', function () {
    Http::fake([
        'graph.microsoft.com/v1.0/sites/root/lists' => Http::response(['value' => [['id' => 'list-1']]]),
        'graph.microsoft.com/v1.0/sites*' => Http::response(['value' => [['id' => 'site-1'], ['id' => 'site-2']]]),
        'graph.microsoft.com/v1.0/me/drive/root/delta' => Http::response([
            'value' => [['id' => 'file-1']],
            '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/drive/root/delta?token=next',
        ]),
    ]);

    $integration = Integration::factory()->for($this->owner)->sharepoint()
        ->withCapabilities(['sharepoint_import', 'sharepoint_lists', 'onedrive_access'])
        ->create(['token_expires_at' => now()->addHour()]);

    $response = $this->signInAs($this->owner)
        ->postJson('/api/integrations/sharepoint/sync')
        ->assertOk();

    expect($response->json('data.results.sharepoint_import.ok'))->toBeTrue()
        ->and($response->json('data.results.sharepoint_lists.ok'))->toBeTrue()
        ->and($response->json('data.results.onedrive_access.ok'))->toBeTrue();

    // The delta feed's cursor is captured for the next incremental run.
    expect($integration->fresh()->capabilityState('onedrive_access')['sync_cursor'])
        ->toBe('https://graph.microsoft.com/v1.0/me/drive/root/delta?token=next');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.microsoft.com/v1.0/sites'));
    Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.microsoft.com/v1.0/sites/root/lists'));
    Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.microsoft.com/v1.0/me/drive/root/delta'));
});

it('follows the stored delta link on the next OneDrive sync', function () {
    $deltaLink = 'https://graph.microsoft.com/v1.0/me/drive/root/delta?token=stored';

    Http::fake([
        // Only the stored delta link answers — a base /delta call would 404,
        // proving the second sync resumes from the cursor, not the root.
        $deltaLink => Http::response([
            'value' => [['id' => 'file-2']],
            '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/drive/root/delta?token=fresh',
        ]),
    ]);

    $integration = Integration::factory()->for($this->owner)->sharepoint()
        ->withCapabilities(['onedrive_access'])
        ->create(['token_expires_at' => now()->addHour()]);

    $integration->updateCapabilityState('onedrive_access', ['sync_cursor' => $deltaLink]);

    $this->signInAs($this->owner)
        ->postJson('/api/integrations/sharepoint/sync', ['capability' => 'onedrive_access'])
        ->assertOk()
        ->assertJsonPath('data.results.onedrive_access.ok', true);

    Http::assertSent(fn ($request) => $request->url() === $deltaLink);

    expect($integration->fresh()->capabilityState('onedrive_access')['sync_cursor'])
        ->toBe('https://graph.microsoft.com/v1.0/me/drive/root/delta?token=fresh');
});

it('treats an export capability as nothing to poll', function () {
    Http::fake();

    $integration = Integration::factory()->for($this->owner)->sharepoint()
        ->withCapabilities(['sharepoint_export'])
        ->create(['token_expires_at' => now()->addHour()]);

    $this->signInAs($this->owner)
        ->postJson('/api/integrations/sharepoint/sync', ['capability' => 'sharepoint_export'])
        ->assertOk()
        ->assertJsonPath('data.results.sharepoint_export.ok', true);

    // An on-demand capability never reaches Graph during a sync.
    Http::assertNothingSent();
});

it('surfaces a revoked Microsoft grant as an actionable sync error', function () {
    Http::fake([
        'graph.microsoft.com/v1.0/sites*' => Http::response(['error' => ['code' => 'InvalidAuthenticationToken']], 401),
        'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    $integration = Integration::factory()->for($this->owner)->sharepoint()
        ->withCapabilities(['sharepoint_import'])
        ->create(['token_expires_at' => now()->addHour()]);

    $this->signInAs($this->owner)
        ->postJson('/api/integrations/sharepoint/sync')
        ->assertOk()
        ->assertJsonPath('data.results.sharepoint_import.ok', false);

    $fresh = $integration->fresh();

    expect($fresh->capabilityState('sharepoint_import')['sync_status'])->toBe('error')
        ->and($fresh->capabilityState('sharepoint_import')['last_error'])->toContain('reauthorize')
        ->and($fresh->status)->toBe(Integration::STATUS_NEEDS_REAUTHORIZATION);

    Notification::assertSentTo($this->owner, IntegrationReauthorizationRequired::class);
});

// ---------------------------------------------------------------------------
// Disconnect
// ---------------------------------------------------------------------------

it('disconnects a Microsoft connection and keeps the audit row', function () {
    microsoftTokenFake();

    $integration = Integration::factory()->for($this->owner)->sharepoint()->create();

    $this->signInAs($this->owner)
        ->deleteJson('/api/integrations/sharepoint')
        ->assertOk();

    expect(Integration::query()->count())->toBe(0)
        ->and(IntegrationAuditLog::query()
            ->where('action', IntegrationAuditLog::ACTION_DISCONNECTED)
            ->where('integration_id', $integration->id)
            ->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Reauthorization
// ---------------------------------------------------------------------------

it('offers a Microsoft reauthorization url scoped to the enabled capabilities', function () {
    $integration = Integration::factory()->for($this->owner)->sharepoint()
        ->needsReauthorization()
        ->withCapabilities(['onedrive_access'])
        ->create();

    $response = $this->signInAs($this->owner)
        ->postJson('/api/integrations/sharepoint/reauthorize')
        ->assertOk();

    expect($response->json('data.authorize_url'))->toContain('Files.Read.All')
        ->and($response->json('data.authorize_url'))->not->toContain('Sites.ReadWrite.All');
});

// ---------------------------------------------------------------------------
// Webhooks (Microsoft Graph subscriptions)
// ---------------------------------------------------------------------------

it('echoes the Graph subscription validation token as plain text', function () {
    // Graph validates a new subscription by POSTing a validationToken it
    // expects echoed back verbatim.
    $this->post('/api/integrations/webhooks/microsoft?validationToken=abc-123')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertSeeText('abc-123');
});

it('queues a sync when a Graph notification matches a stored subscription', function () {
    Bus::fake([SyncIntegrationCapability::class]);

    $integration = Integration::factory()->for($this->owner)->sharepoint()->create();

    $integration->updateCapabilityState('onedrive_access', [
        'enabled' => true,
        'webhook_subscription_id' => 'graph-sub-1',
        'webhook_client_state' => 'secret-client-state',
    ]);

    $this->postJson('/api/integrations/webhooks/microsoft', [
        'value' => [[
            'subscriptionId' => 'graph-sub-1',
            'clientState' => 'secret-client-state',
            'resource' => '/me/drive/root',
        ]],
    ])->assertOk();

    Bus::assertDispatched(
        SyncIntegrationCapability::class,
        fn (SyncIntegrationCapability $job) => true,
    );
});

it('ignores a Graph notification whose client state does not match', function () {
    Bus::fake([SyncIntegrationCapability::class]);

    $integration = Integration::factory()->for($this->owner)->sharepoint()->create();

    $integration->updateCapabilityState('onedrive_access', [
        'enabled' => true,
        'webhook_subscription_id' => 'graph-sub-1',
        'webhook_client_state' => 'secret-client-state',
    ]);

    $this->postJson('/api/integrations/webhooks/microsoft', [
        'value' => [[
            'subscriptionId' => 'graph-sub-1',
            'clientState' => 'forged-state',
        ]],
    ])->assertOk();

    Bus::assertNotDispatched(SyncIntegrationCapability::class);
});
