<?php

namespace App\Services\Integrations\Providers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * OAuth 2.0 against the Microsoft Identity Platform, used for SharePoint and
 * the rest of Microsoft 365 through Microsoft Graph.
 *
 * Microsoft rotates refresh tokens on every use, so a refresh must always
 * store the newest one the answer carries. It also publishes no endpoint that
 * revokes one app's delegated token on demand — disconnecting deletes the
 * stored credentials, and a tenant admin can remove the grant in Entra if a
 * hard revocation is needed.
 */
class MicrosoftOAuthClient implements ProviderOAuthClient
{
    public function authorizationUrl(array $scopes, string $state, string $redirectUri, ?string $codeChallenge = null): string
    {
        return $this->endpoint('authorize').'?'.http_build_query(array_filter([
            'client_id' => config('integrations.microsoft.client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $scopes),
            'state' => $state,
            // Ask again even for a known user, so an incremental round-trip can
            // add scopes to a connection that already consented once.
            'prompt' => 'consent',
            'response_mode' => 'query',
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $codeChallenge !== null ? 'S256' : null,
        ], fn ($value) => $value !== null));
    }

    public function exchangeCode(string $code, string $redirectUri, ?string $codeVerifier = null): array
    {
        $response = $this->tokenRequest(array_filter([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $codeVerifier,
        ], fn ($value) => $value !== null));

        return $this->tokenPayload($response);
    }

    public function refreshToken(string $refreshToken): array
    {
        $response = $this->tokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        return $this->tokenPayload($response);
    }

    /**
     * Microsoft has no endpoint that revokes a single app's delegated tokens.
     * `/oauth2/v2.0/logout` is a browser sign-out redirect, not a token API,
     * and Graph's `revokeSignInSessions` would sign the user out of every app
     * they use — far beyond disconnecting Batayan. So nothing is sent; the
     * caller deletes the stored credentials, which is what severs the link.
     */
    public function revokeToken(string $token): bool
    {
        return false;
    }

    public function accountInfo(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->get(config('integrations.microsoft.graph_url').'/me');

        if (! $response->successful()) {
            return ['id' => null, 'email' => null, 'name' => null];
        }

        return [
            'id' => $response->json('id'),
            'email' => $response->json('mail') ?? $response->json('userPrincipalName'),
            'name' => $response->json('displayName'),
        ];
    }

    /**
     * A v2.0 endpoint under the configured tenant.
     */
    protected function endpoint(string $name): string
    {
        $tenant = config('integrations.microsoft.tenant');

        return "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/{$name}";
    }

    /**
     * The authenticated token-endpoint call.
     *
     * @param  array<string, string>  $payload
     * @return array<string, mixed>
     */
    protected function tokenRequest(array $payload): array
    {
        $response = $this->client()->post($this->endpoint('token'), $payload + [
            'client_id' => config('integrations.microsoft.client_id'),
            'client_secret' => config('integrations.microsoft.client_secret'),
        ]);

        $response->throw();

        return $response->json();
    }

    protected function client(): PendingRequest
    {
        return Http::asForm()->acceptJson();
    }

    /**
     * Normalize a token-endpoint answer into the shape both grant types share.
     *
     * @param  array<string, mixed>  $json
     * @return array{access_token: string, refresh_token: string|null, expires_in: int|null, scope: string|null}
     */
    protected function tokenPayload(array $json): array
    {
        return [
            'access_token' => (string) $json['access_token'],
            'refresh_token' => $json['refresh_token'] ?? null,
            'expires_in' => isset($json['expires_in']) ? (int) $json['expires_in'] : null,
            'scope' => isset($json['scope']) ? $this->normalizeScopes((string) $json['scope']) : null,
        ];
    }

    /**
     * Graph scopes can come back resource-qualified
     * (`https://graph.microsoft.com/Sites.Read.All`) while the catalogue uses
     * the short form; strip the prefix so granted-scope checks compare like
     * with like.
     */
    protected function normalizeScopes(string $scope): string
    {
        return implode(' ', array_map(
            fn (string $s) => preg_replace('#^https://graph\.microsoft\.com/#i', '', $s),
            preg_split('/\s+/', trim($scope)) ?: [],
        ));
    }
}
