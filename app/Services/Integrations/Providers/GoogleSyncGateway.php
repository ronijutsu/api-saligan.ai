<?php

namespace App\Services\Integrations\Providers;

use App\Models\Integration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Change detection against the Google Workspace APIs.
 *
 * Each capability uses the lightest call that answers "what changed": Drive's
 * changes API with a stored page token for imports, an events listing bounded
 * by the last sync for Calendar, and a bounded message listing for Gmail.
 * Export-style capabilities have nothing to poll and say so.
 */
class GoogleSyncGateway implements ProviderSyncGateway
{
    public function sync(Integration $integration, string $capability, string $accessToken): array
    {
        return match ($capability) {
            'drive_import' => $this->syncDrive($integration, $capability, $accessToken),
            'calendar_sync' => $this->syncCalendar($integration, $capability, $accessToken),
            'gmail' => $this->syncGmail($integration, $capability, $accessToken),
            'docs_import' => $this->syncDocs($integration, $capability, $accessToken),
            default => ['summary' => 'Nothing to sync.', 'changed' => 0],
        };
    }

    public function isSyncable(string $capability): bool
    {
        return in_array($capability, ['drive_import', 'calendar_sync', 'gmail', 'docs_import'], true);
    }

    /**
     * Drive changes since the stored page token. The first run takes a start
     * token and reports a baseline rather than a change count.
     *
     * @return array{summary: string, changed: int}
     */
    protected function syncDrive(Integration $integration, string $capability, string $accessToken): array
    {
        $state = $integration->capabilityState($capability);
        $pageToken = $state['sync_cursor'] ?? null;

        if ($pageToken === null) {
            $start = Http::withToken($accessToken)
                ->get('https://www.googleapis.com/drive/v3/changes/startPageToken', ['supportsAllDrives' => 'true'])
                ->throw()
                ->json('startPageToken');

            $integration->updateCapabilityState($capability, ['sync_cursor' => $start]);

            return ['summary' => 'Drive connected; watching for changes.', 'changed' => 0];
        }

        $response = Http::withToken($accessToken)
            ->get('https://www.googleapis.com/drive/v3/changes', [
                'pageToken' => $pageToken,
                'pageSize' => 100,
                // Without these, files in shared drives never appear in the
                // feed (Drive defaults both to false).
                'supportsAllDrives' => 'true',
                'includeItemsFromAllDrives' => 'true',
                'fields' => 'newStartPageToken,nextPageToken,changes(fileId,removed,time)',
            ])
            ->throw();

        $changed = count($response->json('changes', []));
        $nextToken = $response->json('newStartPageToken') ?? $response->json('nextPageToken');

        if ($nextToken !== null) {
            $integration->updateCapabilityState($capability, ['sync_cursor' => $nextToken]);
        }

        return [
            'summary' => $changed === 0 ? 'No new Drive changes.' : "{$changed} Drive change(s) found.",
            'changed' => $changed,
        ];
    }

    /**
     * Calendar events updated since the last sync.
     *
     * @return array{summary: string, changed: int}
     */
    protected function syncCalendar(Integration $integration, string $capability, string $accessToken): array
    {
        $state = $integration->capabilityState($capability);

        $params = ['maxResults' => 250, 'singleEvents' => 'true', 'showDeleted' => 'true'];

        if ($state['last_synced_at'] !== null) {
            // RFC 3339, as events.list requires.
            $params['updatedMin'] = Carbon::parse($state['last_synced_at'])->utc()->toRfc3339String();
        }

        // `primary` is the signed-in user's main calendar; there is no
        // user-level events collection in Calendar v3.
        $response = Http::withToken($accessToken)
            ->get('https://www.googleapis.com/calendar/v3/calendars/primary/events', $params)
            ->throw();

        $changed = count($response->json('items', []));

        return [
            'summary' => $changed === 0 ? 'Calendar is up to date.' : "{$changed} calendar event(s) checked.",
            'changed' => $changed,
        ];
    }

    /**
     * Gmail messages since the last sync, bounded to a day back on the first
     * run so a mailbox with years of history does not arrive at once.
     *
     * @return array{summary: string, changed: int}
     */
    protected function syncGmail(Integration $integration, string $capability, string $accessToken): array
    {
        $state = $integration->capabilityState($capability);

        // Gmail's `after:` accepts a Unix timestamp, so a repeat sync picks up
        // exactly where the last one ended instead of a fixed day window.
        $query = $state['last_synced_at'] !== null
            ? 'after:'.Carbon::parse($state['last_synced_at'])->getTimestamp()
            : 'newer_than:1d';

        $response = Http::withToken($accessToken)
            ->get('https://gmail.googleapis.com/gmail/v1/users/me/messages', [
                'q' => $query,
                'maxResults' => 100,
            ])
            ->throw();

        $changed = count($response->json('messages', []));

        return [
            'summary' => $changed === 0 ? 'No new Gmail activity.' : "{$changed} Gmail message(s) found.",
            'changed' => $changed,
        ];
    }

    /**
     * Docs have no listing endpoint under the narrow documents scope, so the
     * sync proves the grant still works; document-level pulls happen when a
     * user opens a doc.
     *
     * @return array{summary: string, changed: int}
     */
    protected function syncDocs(Integration $integration, string $capability, string $accessToken): array
    {
        Http::withToken($accessToken)
            ->get(config('integrations.google.userinfo_url'))
            ->throw();

        return ['summary' => 'Google Docs access verified.', 'changed' => 0];
    }
}
