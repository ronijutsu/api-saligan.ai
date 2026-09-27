<?php

/*
 * Pins every Google and Microsoft Graph call to the provider's documented
 * contract (verified September 2026): exact endpoint paths, required query
 * parameters, and request bodies. The broader IntegrationAddonsTest fakes
 * whole URL prefixes, which is how wrong paths slipped through before.
 */

use App\Enums\IntegrationProvider;
use App\Jobs\SyncIntegrationCapability;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Integrations\OAuthStateStore;
use App\Services\Integrations\Providers\GoogleOAuthClient;
use App\Services\Integrations\Providers\GoogleSyncGateway;
use App\Services\Integrations\Providers\MicrosoftOAuthClient;
use App\Services\Integrations\Providers\MicrosoftSyncGateway;
use App\Services\Integrations\WebhookRegistrar;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Notification::fake();

    config([
        'app.url' => 'https://api.batayan.co',
        'integrations.google.client_id' => 'google-client-id',
        'integrations.microsoft.client_id' => 'ms-client-id',
        'integrations.microsoft.tenant' => 'common',
    ]);

    $this->organization = Organization::factory()->create();
    $this->owner = User::factory()->ownerOf($this->organization)->create();
    Subscription::factory()->for($this->organization)->for($this->owner)->create([
        'plan_id' => Plan::factory()->pro()->create()->id,
    ]);
});

function queryOf(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $query;
}

// ---------------------------------------------------------------------------
// OAuth
// ---------------------------------------------------------------------------

it('sends Google the incremental-auth and PKCE parameters, and the verifier on exchange', function () {
    $states = app(OAuthStateStore::class);
    $state = $states->issue($this->owner, IntegrationProvider::GoogleWorkspace, OAuthStateStore::PURPOSE_CONNECT);

    $url = app(GoogleOAuthClient::class)->authorizationUrl(['openid'], $state, 'https://app/cb', $states->codeChallenge($state));
    $query = queryOf($url);

    expect($url)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth?')
        ->and($query['access_type'])->toBe('offline')
        ->and($query['include_granted_scopes'])->toBe('true')
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($query['code_challenge'])->toMatch('/^[A-Za-z0-9_-]{43}$/');

    Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'a', 'expires_in' => 3600])]);

    $verifier = $states->consume($state)['code_verifier'];
    app(GoogleOAuthClient::class)->exchangeCode('the-code', 'https://app/cb', $verifier);

    Http::assertSent(function (Request $request) use ($query) {
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $request['code_verifier'], true)), '+/', '-_'), '=');

        return $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['grant_type'] === 'authorization_code'
            && $challenge === $query['code_challenge'];
    });
});

it('sends Microsoft PKCE parameters and normalizes resource-qualified Graph scopes', function () {
    $states = app(OAuthStateStore::class);
    $state = $states->issue($this->owner, IntegrationProvider::SharePoint, OAuthStateStore::PURPOSE_CONNECT);

    $url = app(MicrosoftOAuthClient::class)->authorizationUrl(['offline_access'], $state, 'https://app/cb', $states->codeChallenge($state));
    $query = queryOf($url);

    expect($url)->toStartWith('https://login.microsoftonline.com/common/oauth2/v2.0/authorize?')
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($query['response_mode'])->toBe('query');

    Http::fake(['login.microsoftonline.com/*' => Http::response([
        'access_token' => 'a',
        'expires_in' => 3600,
        'scope' => 'openid profile https://graph.microsoft.com/User.Read https://graph.microsoft.com/Sites.Read.All',
    ])]);

    $tokens = app(MicrosoftOAuthClient::class)->exchangeCode('code', 'https://app/cb', 'v');

    expect($tokens['scope'])->toBe('openid profile User.Read Sites.Read.All');
});

it('does not call the Microsoft logout endpoint as if it revoked tokens', function () {
    Http::fake();

    expect(app(MicrosoftOAuthClient::class)->revokeToken('token'))->toBeFalse();

    Http::assertNothingSent();
});

it('does not enable a capability whose scope the user unticked on the consent screen', function () {
    $integration = Integration::factory()->for($this->owner)->create([
        'granted_scopes' => ['openid', 'https://www.googleapis.com/auth/userinfo.email', 'https://www.googleapis.com/auth/userinfo.profile'],
    ]);

    // Granular consent: the token comes back without drive.readonly.
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3600,
            'scope' => 'openid https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/userinfo.profile',
        ]),
        'openidconnect.googleapis.com/*' => Http::response(['sub' => $integration->provider_account_id, 'email' => 'x@example.com']),
    ]);

    $state = app(OAuthStateStore::class)->issue($this->owner, IntegrationProvider::GoogleWorkspace, OAuthStateStore::PURPOSE_ENABLE_CAPABILITY, 'drive_import');

    $this->get('/api/integrations/callback?'.http_build_query(['state' => $state, 'code' => 'c']));

    $fresh = $integration->fresh();
    expect($fresh->capabilityEnabled('drive_import'))->toBeFalse()
        ->and($fresh->capabilityState('drive_import')['last_error'])->toContain('not granted');
});

// ---------------------------------------------------------------------------
// Google sync
// ---------------------------------------------------------------------------

it('lists Calendar events from the primary calendar with an RFC 3339 updatedMin', function () {
    Http::fake(['www.googleapis.com/calendar/v3/*' => Http::response(['items' => []])]);

    $integration = Integration::factory()->for($this->owner)->create([
        'capabilities' => ['calendar_sync' => ['enabled' => true, 'last_synced_at' => '2026-09-20T01:02:03+08:00']],
    ]);

    app(GoogleSyncGateway::class)->sync($integration, 'calendar_sync', 'token');

    Http::assertSent(function (Request $request) {
        $query = queryOf($request->url());

        return str_starts_with($request->url(), 'https://www.googleapis.com/calendar/v3/calendars/primary/events?')
            && $query['updatedMin'] === '2026-09-19T17:02:03+00:00'
            && $query['singleEvents'] === 'true';
    });
});

it('reads Drive changes across shared drives', function () {
    Http::fake(['www.googleapis.com/drive/v3/changes*' => Http::response(['changes' => [['fileId' => 'f1']], 'newStartPageToken' => 'p2'])]);

    $integration = Integration::factory()->for($this->owner)->create([
        'capabilities' => ['drive_import' => ['enabled' => true, 'sync_cursor' => 'p1']],
    ]);

    $result = app(GoogleSyncGateway::class)->sync($integration, 'drive_import', 'token');

    expect($result['changed'])->toBe(1)
        ->and($integration->fresh()->capabilityState('drive_import')['sync_cursor'])->toBe('p2');

    Http::assertSent(function (Request $request) {
        $query = queryOf($request->url());

        return str_starts_with($request->url(), 'https://www.googleapis.com/drive/v3/changes?')
            && $query['pageToken'] === 'p1'
            && $query['supportsAllDrives'] === 'true'
            && $query['includeItemsFromAllDrives'] === 'true';
    });
});

it('queries Gmail from the exact moment of the last sync', function () {
    Http::fake(['gmail.googleapis.com/*' => Http::response(['messages' => []])]);

    $integration = Integration::factory()->for($this->owner)->create([
        'capabilities' => ['gmail' => ['enabled' => true, 'last_synced_at' => '2026-09-20T00:00:00+00:00']],
    ]);

    app(GoogleSyncGateway::class)->sync($integration, 'gmail', 'token');

    Http::assertSent(fn (Request $request) => queryOf($request->url())['q'] === 'after:1789862400');
});

// ---------------------------------------------------------------------------
// Microsoft sync
// ---------------------------------------------------------------------------

it('searches all reachable SharePoint sites rather than a keyword', function () {
    Http::fake(['graph.microsoft.com/*' => Http::response(['value' => [['id' => 's1'], ['id' => 's2']]])]);

    $integration = Integration::factory()->for($this->owner)->sharepoint()->create();

    $result = app(MicrosoftSyncGateway::class)->sync($integration, 'sharepoint_import', 'token');

    expect($result['changed'])->toBe(2);
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://graph.microsoft.com/v1.0/sites?')
        && queryOf($request->url())['search'] === '*');
});

it('keeps the OneDrive delta cursor when a pass ends mid-way', function () {
    Http::fake(['graph.microsoft.com/*' => Http::response([
        'value' => [['id' => 'i1']],
        '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/drive/root/delta?token=next',
    ])]);

    $integration = Integration::factory()->for($this->owner)->sharepoint()->create();

    app(MicrosoftSyncGateway::class)->sync($integration, 'onedrive_access', 'token');

    expect($integration->fresh()->capabilityState('onedrive_access')['sync_cursor'])
        ->toBe('https://graph.microsoft.com/v1.0/me/drive/root/delta?token=next');
});

// ---------------------------------------------------------------------------
// Push registration
// ---------------------------------------------------------------------------

it('registers a Drive changes channel with a page token and a valid channel id', function () {
    Http::fake([
        'www.googleapis.com/drive/v3/changes/startPageToken*' => Http::response(['startPageToken' => 'start-1']),
        'www.googleapis.com/drive/v3/changes/watch*' => Http::response(['resourceId' => 'res-1']),
    ]);

    $integration = Integration::factory()->for($this->owner)->withCapabilities(['drive_import'])->create();

    app(WebhookRegistrar::class)->ensureSubscribed($integration, 'drive_import');

    Http::assertSent(function (Request $request) {
        if (! str_starts_with($request->url(), 'https://www.googleapis.com/drive/v3/changes/watch?')) {
            return false;
        }

        $query = queryOf($request->url());

        return $query['pageToken'] === 'start-1'
            && $query['supportsAllDrives'] === 'true'
            && strlen($request['id']) <= 64
            && preg_match('#^[A-Za-z0-9\-_+/=]+$#', $request['id']) === 1
            && $request['type'] === 'web_hook'
            && $request['address'] === 'https://api.batayan.co/api/integrations/webhooks/google'
            && is_string($request['token']) && $request['token'] !== ''
            && $request['expiration'] <= now()->addDays(7)->getTimestampMs();
    });

    $state = $integration->fresh()->capabilityState('drive_import');
    expect($state['webhook_resource_id'])->toBe('res-1')
        ->and($state['webhook_token'])->not->toBeNull()
        ->and($state['sync_cursor'])->toBe('start-1');
});

it('registers a Calendar channel on the primary calendar', function () {
    Http::fake(['www.googleapis.com/calendar/v3/*' => Http::response(['resourceId' => 'res-cal'])]);

    $integration = Integration::factory()->for($this->owner)->withCapabilities(['calendar_sync'])->create();

    app(WebhookRegistrar::class)->ensureSubscribed($integration, 'calendar_sync');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://www.googleapis.com/calendar/v3/calendars/primary/events/watch');
});

it('subscribes to the OneDrive root with the only changeType Graph allows for it', function () {
    Http::fake(['graph.microsoft.com/v1.0/subscriptions' => Http::response(['id' => 'sub-1'], 201)]);

    $integration = Integration::factory()->for($this->owner)->sharepoint()->withCapabilities(['onedrive_access'])->create();

    app(WebhookRegistrar::class)->ensureSubscribed($integration, 'onedrive_access');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://graph.microsoft.com/v1.0/subscriptions'
        && $request['changeType'] === 'updated'
        && $request['resource'] === '/me/drive/root'
        && strlen($request['clientState']) <= 128
        && str_ends_with($request['expirationDateTime'], 'Z'));

    expect($integration->fresh()->capabilityState('onedrive_access')['webhook_subscription_id'])->toBe('sub-1');
});

it('subscribes SharePoint imports to a specific drive root, never the lists collection', function () {
    Http::fake([
        'graph.microsoft.com/v1.0/sites/root/drive*' => Http::response(['id' => 'drive-abc']),
        'graph.microsoft.com/v1.0/subscriptions' => Http::response(['id' => 'sub-2'], 201),
    ]);

    $integration = Integration::factory()->for($this->owner)->sharepoint()->withCapabilities(['sharepoint_import'])->create();

    app(WebhookRegistrar::class)->ensureSubscribed($integration, 'sharepoint_import');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://graph.microsoft.com/v1.0/subscriptions'
        && $request['resource'] === '/drives/drive-abc/root'
        && $request['changeType'] === 'updated');
});

// ---------------------------------------------------------------------------
// Webhook handlers
// ---------------------------------------------------------------------------

it('ignores the Google sync handshake and a forged token, and syncs on a genuine change', function () {
    Queue::fake();

    $integration = Integration::factory()->for($this->owner)->create([
        'capabilities' => ['drive_import' => ['enabled' => true, 'webhook_channel_id' => 'chan-1', 'webhook_token' => 'secret-token']],
    ]);

    $headers = ['X-Goog-Channel-ID' => 'chan-1', 'X-Goog-Channel-Token' => 'secret-token'];

    $this->post('/api/integrations/webhooks/google', [], $headers + ['X-Goog-Resource-State' => 'sync'])->assertOk();
    Queue::assertNothingPushed();

    $this->post('/api/integrations/webhooks/google', [], ['X-Goog-Channel-ID' => 'chan-1', 'X-Goog-Channel-Token' => 'guess', 'X-Goog-Resource-State' => 'change'])->assertOk();
    Queue::assertNothingPushed();

    $this->post('/api/integrations/webhooks/google', [], $headers + ['X-Goog-Resource-State' => 'change'])->assertOk();
    Queue::assertPushed(SyncIntegrationCapability::class, 1);
});

it('echoes the Graph validation token as plain text', function () {
    $this->post('/api/integrations/webhooks/microsoft?validationToken='.rawurlencode('Validation: Token 123'))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertSeeText('Validation: Token 123', false);
});

it('finds the Graph subscription on Postgres, requires clientState, and answers 202', function () {
    Queue::fake();

    Integration::factory()->for($this->owner)->sharepoint()->create([
        'capabilities' => ['onedrive_access' => ['enabled' => true, 'webhook_subscription_id' => 'sub-9', 'webhook_client_state' => 'cs-secret']],
    ]);

    $this->postJson('/api/integrations/webhooks/microsoft', ['value' => [['subscriptionId' => 'sub-9']]])->assertStatus(202);
    Queue::assertNothingPushed();

    $this->postJson('/api/integrations/webhooks/microsoft', ['value' => [['subscriptionId' => 'sub-9', 'clientState' => 'cs-secret']]])->assertStatus(202);
    Queue::assertPushed(SyncIntegrationCapability::class, 1);
});

it('stops Google channels and deletes Graph subscriptions when disconnecting', function () {
    Http::fake();

    Integration::factory()->for($this->owner)->create([
        'capabilities' => ['drive_import' => ['enabled' => true, 'webhook_channel_id' => 'chan-7', 'webhook_resource_id' => 'res-7']],
    ]);

    $this->signInAs($this->owner)->deleteJson('/api/integrations/google_workspace')->assertOk();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://www.googleapis.com/drive/v3/channels/stop'
        && $request['id'] === 'chan-7' && $request['resourceId'] === 'res-7');

    Integration::factory()->for($this->owner)->sharepoint()->create([
        'capabilities' => ['onedrive_access' => ['enabled' => true, 'webhook_subscription_id' => 'sub-7']],
    ]);

    $this->signInAs($this->owner)->deleteJson('/api/integrations/sharepoint')->assertOk();

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && $request->url() === 'https://graph.microsoft.com/v1.0/subscriptions/sub-7');
});
