<?php

namespace App\Services\Integrations\Providers;

use App\Models\Integration;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Change detection against Microsoft Graph.
 *
 * OneDrive uses the delta feed, SharePoint a site search bounded by the last
 * sync, and Lists the root site's list collection. Export-style capabilities
 * have nothing to poll and say so.
 */
class MicrosoftSyncGateway implements ProviderSyncGateway
{
    public function sync(Integration $integration, string $capability, string $accessToken): array
    {
        return match ($capability) {
            'sharepoint_import' => $this->syncSharePoint($integration, $capability, $accessToken),
            'sharepoint_lists' => $this->syncLists($integration, $capability, $accessToken),
            'onedrive_access' => $this->syncOneDrive($integration, $capability, $accessToken),
            default => ['summary' => 'Nothing to sync.', 'changed' => 0],
        };
    }

    public function isSyncable(string $capability): bool
    {
        return in_array($capability, ['sharepoint_import', 'sharepoint_lists', 'onedrive_access'], true);
    }

    /**
     * SharePoint sites reachable to the account, as a stand-in for library
     * change detection until a library is bound to the connection.
     *
     * @return array{summary: string, changed: int}
     */
    protected function syncSharePoint(Integration $integration, string $capability, string $accessToken): array
    {
        // `search=*` lists every site the account can reach; a keyword would
        // only match sites whose name or description contains it.
        $response = $this->graph($accessToken)
            ->get('/sites', ['search' => '*', '$select' => 'id,name,webUrl'])
            ->throw();

        $changed = count($response->json('value', []));

        return [
            'summary' => $changed === 0 ? 'No SharePoint sites found.' : "{$changed} SharePoint site(s) reachable.",
            'changed' => $changed,
        ];
    }

    /**
     * The lists on the tenant's root site.
     *
     * @return array{summary: string, changed: int}
     */
    protected function syncLists(Integration $integration, string $capability, string $accessToken): array
    {
        $response = $this->graph($accessToken)
            ->get('/sites/root/lists')
            ->throw();

        $changed = count($response->json('value', []));

        return [
            'summary' => $changed === 0 ? 'No SharePoint lists found.' : "{$changed} SharePoint list(s) reachable.",
            'changed' => $changed,
        ];
    }

    /**
     * OneDrive items changed since the stored delta link. The first run
     * captures the baseline.
     *
     * @return array{summary: string, changed: int}
     */
    protected function syncOneDrive(Integration $integration, string $capability, string $accessToken): array
    {
        $state = $integration->capabilityState($capability);
        $deltaLink = $state['sync_cursor'] ?? null;

        $request = $this->graph($accessToken);

        $response = $deltaLink === null
            ? $request->get('/me/drive/root/delta')->throw()
            : $request->get($deltaLink)->throw();

        $changed = count($response->json('value', []));
        // A page with more to come carries @odata.nextLink; the last page
        // carries @odata.deltaLink for the next round. Keeping whichever is
        // present means an interrupted pass resumes rather than restarts.
        // Read the raw array: json('@odata.deltaLink') would treat the dot as
        // nesting and look up ['@odata']['deltaLink'], which never exists.
        $body = (array) $response->json();
        $nextDelta = $body['@odata.deltaLink'] ?? $body['@odata.nextLink'] ?? null;

        if (is_string($nextDelta) && $nextDelta !== '') {
            $integration->updateCapabilityState($capability, ['sync_cursor' => $nextDelta]);
        }

        return [
            'summary' => $changed === 0 ? 'OneDrive is up to date.' : "{$changed} OneDrive item(s) changed.",
            'changed' => $changed,
        ];
    }

    /**
     * The authenticated Graph client.
     */
    protected function graph(string $accessToken): PendingRequest
    {
        return Http::withToken($accessToken)
            ->baseUrl(config('integrations.microsoft.graph_url'))
            ->acceptJson();
    }
}
