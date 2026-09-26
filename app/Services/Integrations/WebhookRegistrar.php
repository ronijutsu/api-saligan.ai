<?php

namespace App\Services\Integrations;

use App\Enums\IntegrationProvider;
use App\Models\Integration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Subscribes webhook-capable capabilities to their provider's push channel,
 * best-effort.
 *
 * Push beats polling where the provider supports it — Google Drive and
 * Calendar, and Microsoft Graph, all publish change notifications — but a
 * registration needs a publicly reachable HTTPS address, which a dev box does
 * not have. Registration failures are therefore swallowed: the scheduled sync
 * stays as the fallback and the capability keeps working, just on a delay.
 *
 * Provider contracts this follows (verified September 2026):
 * - Google: channel ids are at most 64 characters of [A-Za-z0-9-_+/=]; Drive
 *   `changes.watch` requires a `pageToken`; a changes channel lives at most
 *   one week; Calendar watches `calendars/{calendarId}/events/watch`.
 * - Graph: drive-root and list subscriptions accept only the `updated`
 *   changeType, on a specific drive root (`/drives/{id}/root`, `/me/drive/root`)
 *   or a specific list; both live at most 42,300 minutes.
 */
class WebhookRegistrar
{
    /**
     * How long a push channel is requested for before the sweep renews it.
     * Six days keeps Google's one-week ceiling for changes channels out of
     * reach of clock skew; Graph allows far longer but gains nothing here.
     */
    public const CHANNEL_TTL_DAYS = 6;

    public function __construct(
        protected readonly TokenRefresher $refresher,
    ) {
        //
    }

    /**
     * Ensure a webhook-capable capability has a live push channel. No-op when
     * one is still good; best-effort otherwise.
     */
    public function ensureSubscribed(Integration $integration, string $capability): void
    {
        $definition = IntegrationCatalogue::capability($integration->provider, $capability);

        if (($definition['sync_mode'] ?? null) !== 'webhook') {
            return;
        }

        $state = $integration->capabilityState($capability);
        $expiresAt = $state['webhook_expires_at'] ?? null;

        if ($expiresAt !== null && now()->lt($expiresAt)) {
            return;
        }

        $accessToken = $integration->freshAccessToken($this->refresher);

        if ($accessToken === null) {
            return;
        }

        try {
            match (true) {
                $integration->provider === IntegrationProvider::GoogleWorkspace => $this->subscribeGoogle($integration, $capability, $accessToken),
                default => $this->subscribeMicrosoft($integration, $capability, $accessToken),
            };
        } catch (\Throwable) {
            // No public callback URL, an unverified domain, or a missing
            // permission all land here. Polling carries the capability until
            // push becomes reachable.
        }
    }

    /**
     * Stop a capability's push channel, if it has one. Best-effort: a channel
     * the provider already dropped, or a token that no longer works, must not
     * block disabling the capability.
     */
    public function unsubscribe(Integration $integration, string $capability): void
    {
        $state = $integration->capabilityState($capability);
        $hasGoogle = ($state['webhook_channel_id'] ?? null) !== null;
        $hasGraph = ($state['webhook_subscription_id'] ?? null) !== null;

        if (! $hasGoogle && ! $hasGraph) {
            return;
        }

        try {
            $accessToken = $integration->freshAccessToken($this->refresher);

            if ($accessToken !== null && $hasGoogle) {
                // channels.stop is per API: a Calendar channel must be stopped
                // through the Calendar API, a Drive channel through Drive.
                $stopUrl = $capability === 'calendar_sync'
                    ? 'https://www.googleapis.com/calendar/v3/channels/stop'
                    : 'https://www.googleapis.com/drive/v3/channels/stop';

                Http::withToken($accessToken)->post($stopUrl, [
                    'id' => $state['webhook_channel_id'],
                    'resourceId' => $state['webhook_resource_id'] ?? null,
                ]);
            }

            if ($accessToken !== null && $hasGraph) {
                Http::withToken($accessToken)
                    ->baseUrl(config('integrations.microsoft.graph_url'))
                    ->delete('/subscriptions/'.rawurlencode((string) $state['webhook_subscription_id']));
            }
        } catch (\Throwable) {
            // The channel expires on its own within CHANNEL_TTL_DAYS anyway.
        }

        $integration->updateCapabilityState($capability, [
            'webhook_channel_id' => null,
            'webhook_resource_id' => null,
            'webhook_token' => null,
            'webhook_subscription_id' => null,
            'webhook_client_state' => null,
            'webhook_expires_at' => null,
        ]);
    }

    /**
     * Stop every push channel on a connection (used on disconnect).
     */
    public function unsubscribeAll(Integration $integration): void
    {
        foreach (array_keys(IntegrationCatalogue::capabilities($integration->provider)) as $capability) {
            $this->unsubscribe($integration, $capability);
        }
    }

    /**
     * Register a Google push channel for the capability.
     */
    protected function subscribeGoogle(Integration $integration, string $capability, string $accessToken): void
    {
        [$endpoint, $query] = match ($capability) {
            'drive_import' => [
                'https://www.googleapis.com/drive/v3/changes/watch',
                [
                    'pageToken' => $this->drivePageToken($integration, $capability, $accessToken),
                    'supportsAllDrives' => 'true',
                    'includeItemsFromAllDrives' => 'true',
                ],
            ],
            'calendar_sync' => ['https://www.googleapis.com/calendar/v3/calendars/primary/events/watch', []],
            default => [null, []],
        };

        if ($endpoint === null) {
            return;
        }

        // A UUID is 36 characters of [0-9a-f-]: inside Google's 64-character
        // channel-id limit and alphabet. The webhook handler finds the
        // integration by looking this id up, so it needs to encode nothing.
        $channelId = (string) Str::uuid();
        // Echoed back as X-Goog-Channel-Token on every notification, so the
        // handler can reject forged calls that guess a channel id.
        $token = Str::random(40);
        $expiresAt = now()->addDays(self::CHANNEL_TTL_DAYS);

        $response = Http::withToken($accessToken)
            ->withQueryParameters($query)
            ->post($endpoint, [
                'id' => $channelId,
                'type' => 'web_hook',
                'address' => $this->callbackUrl('google'),
                'token' => $token,
                'expiration' => $expiresAt->getTimestampMs(),
            ]);

        if ($response->successful()) {
            $integration->updateCapabilityState($capability, [
                'webhook_channel_id' => $response->json('id', $channelId),
                'webhook_resource_id' => $response->json('resourceId'),
                'webhook_token' => $token,
                'webhook_expires_at' => $expiresAt->toIso8601String(),
            ]);
        }
    }

    /**
     * The Drive page token a changes channel starts from: the sync cursor if
     * the capability has one, otherwise a fresh start token (stored so the
     * next sync continues from the same point).
     */
    protected function drivePageToken(Integration $integration, string $capability, string $accessToken): string
    {
        $cursor = $integration->capabilityState($capability)['sync_cursor'] ?? null;

        if (is_string($cursor) && $cursor !== '') {
            return $cursor;
        }

        $start = (string) Http::withToken($accessToken)
            ->get('https://www.googleapis.com/drive/v3/changes/startPageToken', ['supportsAllDrives' => 'true'])
            ->throw()
            ->json('startPageToken');

        $integration->updateCapabilityState($capability, ['sync_cursor' => $start]);

        return $start;
    }

    /**
     * Register a Microsoft Graph subscription for the capability.
     */
    protected function subscribeMicrosoft(Integration $integration, string $capability, string $accessToken): void
    {
        $graph = Http::withToken($accessToken)
            ->baseUrl(config('integrations.microsoft.graph_url'))
            ->acceptJson();

        $resource = match ($capability) {
            'onedrive_access' => '/me/drive/root',
            // The tenant root site's default document library. Graph only
            // subscribes to a specific drive's root, so resolve its id first.
            'sharepoint_import' => '/drives/'.$graph->get('/sites/root/drive', ['$select' => 'id'])->throw()->json('id').'/root',
            default => null,
        };

        if ($resource === null) {
            return;
        }

        $clientState = Str::random(64); // Graph allows up to 128 characters.
        $expiresAt = now()->addDays(self::CHANNEL_TTL_DAYS);

        $response = $graph->post('/subscriptions', [
            // Drive-root and list subscriptions support only `updated`.
            'changeType' => 'updated',
            'notificationUrl' => $this->callbackUrl('microsoft'),
            'resource' => $resource,
            'expirationDateTime' => $expiresAt->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'clientState' => $clientState,
        ]);

        if ($response->successful()) {
            $integration->updateCapabilityState($capability, [
                'webhook_subscription_id' => $response->json('id'),
                'webhook_client_state' => $clientState,
                'webhook_expires_at' => $expiresAt->toIso8601String(),
            ]);
        }
    }

    /**
     * The public URL push notifications arrive on.
     */
    protected function callbackUrl(string $provider): string
    {
        return rtrim((string) config('app.url'), '/')."/api/integrations/webhooks/{$provider}";
    }
}
