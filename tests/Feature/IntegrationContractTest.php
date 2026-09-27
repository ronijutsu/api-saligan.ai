<?php

use App\Enums\IntegrationProvider;
use App\Services\Integrations\IntegrationCatalogue;
use App\Services\Integrations\Providers\GoogleOAuthClient;
use App\Services\Integrations\Providers\GoogleSyncGateway;
use App\Services\Integrations\Providers\MicrosoftOAuthClient;
use App\Services\Integrations\Providers\MicrosoftSyncGateway;
use App\Services\Integrations\Providers\ProviderOAuthClients;
use App\Services\Integrations\Providers\ProviderSyncGateways;

/**
 * Structural invariants that hold without a live provider.
 *
 * These are the checks that make "it will work" defensible for the Microsoft
 * add-on we cannot click through by hand: the container resolves the right
 * clients, the catalogue and the sync gateway agree on what is syncable, and
 * every capability declared for a provider is actually handled somewhere. A
 * refactor that breaks any of these fails here instead of in production.
 */

// ---------------------------------------------------------------------------
// Container wiring
// ---------------------------------------------------------------------------

it('resolves each provider to its own OAuth client', function () {
    $clients = app(ProviderOAuthClients::class);

    expect($clients->for(IntegrationProvider::GoogleWorkspace))->toBeInstanceOf(GoogleOAuthClient::class)
        ->and($clients->for(IntegrationProvider::SharePoint))->toBeInstanceOf(MicrosoftOAuthClient::class);
});

it('resolves each provider to its own sync gateway', function () {
    $gateways = app(ProviderSyncGateways::class);

    expect($gateways->for(IntegrationProvider::GoogleWorkspace))->toBeInstanceOf(GoogleSyncGateway::class)
        ->and($gateways->for(IntegrationProvider::SharePoint))->toBeInstanceOf(MicrosoftSyncGateway::class);
});

// ---------------------------------------------------------------------------
// Catalogue ↔ gateway agreement
// ---------------------------------------------------------------------------

it('marks exactly the webhook-mode SharePoint capabilities as syncable', function () {
    $gateway = new MicrosoftSyncGateway;

    foreach (IntegrationCatalogue::capabilities(IntegrationProvider::SharePoint) as $key => $definition) {
        // A capability that polls (webhook/scheduled) must be syncable; an
        // on-demand one must not — otherwise a sweep either skips real work
        // or churns on a capability with nothing to poll.
        $shouldSync = in_array($definition['sync_mode'], ['webhook', 'scheduled'], true);

        expect($gateway->isSyncable($key))->toBe(
            $shouldSync,
            "sync_mode `{$definition['sync_mode']}` for `{$key}` disagrees with isSyncable()",
        );
    }
});

it('marks exactly the webhook-mode Google capabilities as syncable', function () {
    $gateway = new GoogleSyncGateway;

    foreach (IntegrationCatalogue::capabilities(IntegrationProvider::GoogleWorkspace) as $key => $definition) {
        $shouldSync = in_array($definition['sync_mode'], ['webhook', 'scheduled'], true);

        expect($gateway->isSyncable($key))->toBe(
            $shouldSync,
            "sync_mode `{$definition['sync_mode']}` for `{$key}` disagrees with isSyncable()",
        );
    }
});

// ---------------------------------------------------------------------------
// Least-privilege scope computation
// ---------------------------------------------------------------------------

it('unions SharePoint capability scopes onto the identity base, deduped and stable', function () {
    $base = IntegrationCatalogue::baseScopes(IntegrationProvider::SharePoint);

    $scopes = IntegrationCatalogue::scopesFor(
        IntegrationProvider::SharePoint,
        ['sharepoint_import', 'sharepoint_lists', 'onedrive_access'],
    );

    // Base scopes are always present.
    foreach ($base as $scope) {
        expect($scopes)->toContain($scope);
    }

    // sharepoint_import and sharepoint_lists both need Sites.Read.All — it
    // appears once, not twice.
    expect(array_count_values($scopes)['Sites.Read.All'] ?? 0)->toBe(1)
        ->and($scopes)->toContain('Files.Read.All')
        // A read-only set never leaks the write scope.
        ->and($scopes)->not->toContain('Sites.ReadWrite.All');

    // The same set of capabilities always yields the same set of scopes,
    // whatever order they arrive in — the incremental-consent comparison
    // (array_diff of requested vs. granted) depends on the membership, not the
    // ordering.
    $reordered = IntegrationCatalogue::scopesFor(
        IntegrationProvider::SharePoint,
        ['onedrive_access', 'sharepoint_lists', 'sharepoint_import'],
    );

    sort($reordered);
    $sorted = $scopes;
    sort($sorted);

    expect($reordered)->toBe($sorted);
});

it('asks only for identity scopes before any capability is enabled', function () {
    foreach ([IntegrationProvider::GoogleWorkspace, IntegrationProvider::SharePoint] as $provider) {
        $base = IntegrationCatalogue::scopesFor($provider, []);

        expect($base)->toBe(IntegrationCatalogue::baseScopes($provider));
    }
});

// ---------------------------------------------------------------------------
// Endpoint shape
// ---------------------------------------------------------------------------

it('builds Microsoft endpoints under the configured tenant', function () {
    config()->set('integrations.microsoft.tenant', 'contoso.onmicrosoft.com');
    config()->set('integrations.microsoft.client_id', 'ms-client-id');

    $url = (new MicrosoftOAuthClient)->authorizationUrl(
        IntegrationCatalogue::baseScopes(IntegrationProvider::SharePoint),
        'state-token',
        'https://api.batayan.ai/api/integrations/callback',
    );

    expect($url)->toContain('login.microsoftonline.com/contoso.onmicrosoft.com/oauth2/v2.0/authorize')
        ->and($url)->toContain('response_type=code')
        ->and($url)->toContain('state=state-token');
});
