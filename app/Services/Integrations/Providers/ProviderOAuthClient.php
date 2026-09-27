<?php

namespace App\Services\Integrations\Providers;

/**
 * The OAuth surface each add-on provider must offer. Implementations wrap the
 * provider's consent and token endpoints; everything else in the integrations
 * domain speaks only this interface, so a new provider is a new class rather
 * than a new flow.
 */
interface ProviderOAuthClient
{
    /**
     * The URL the user is redirected to for consent.
     *
     * `$codeChallenge` is the PKCE S256 challenge (RFC 7636). Both providers
     * recommend PKCE for confidential web apps too, not only public clients.
     *
     * @param  list<string>  $scopes
     */
    public function authorizationUrl(array $scopes, string $state, string $redirectUri, ?string $codeChallenge = null): string;

    /**
     * Exchange the consent-screen code for tokens.
     *
     * @return array{access_token: string, refresh_token: string|null, expires_in: int|null, scope: string|null}
     */
    public function exchangeCode(string $code, string $redirectUri, ?string $codeVerifier = null): array;

    /**
     * Trade the refresh token for a fresh access token.
     *
     * @return array{access_token: string, refresh_token: string|null, expires_in: int|null, scope: string|null}
     */
    public function refreshToken(string $refreshToken): array;

    /**
     * Ask the provider to revoke the granted tokens. Best-effort: a provider
     * that refuses (or has no endpoint) must not break a disconnect.
     */
    public function revokeToken(string $token): bool;

    /**
     * The account behind an access token, so the UI can show which account is
     * connected.
     *
     * @return array{id: string|null, email: string|null, name: string|null}
     */
    public function accountInfo(string $accessToken): array;
}
