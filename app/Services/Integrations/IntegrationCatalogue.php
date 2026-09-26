<?php

namespace App\Services\Integrations;

use App\Enums\IntegrationProvider;

/**
 * The single source of truth for what each integration is and what each of its
 * capabilities needs.
 *
 * Both the API response that builds the add-ons page and the server-side
 * enforcement that decides which OAuth scopes a toggle may ask for read from
 * this catalogue, so the copy shown to a user and the permission actually
 * requested can never drift apart. Adding a capability means adding it here;
 * nothing else should carry a hard-coded scope string.
 */
class IntegrationCatalogue
{
    /**
     * The OAuth scopes every connection asks for just to identify the account,
     * before any capability is switched on. Kept to identity only so a plain
     * connection grants no data access at all.
     *
     * @return list<string>
     */
    public static function baseScopes(IntegrationProvider $provider): array
    {
        return match ($provider) {
            // Google's canonical forms, not the `email`/`profile` aliases.
            // Google accepts the aliases on the way out but always answers
            // with these URLs, and the alias form never matched what came
            // back — so every requested-vs-granted comparison saw the
            // identity scopes as missing, or as orphaned on the way down.
            IntegrationProvider::GoogleWorkspace => [
                'openid',
                'https://www.googleapis.com/auth/userinfo.email',
                'https://www.googleapis.com/auth/userinfo.profile',
            ],
            IntegrationProvider::SharePoint => ['openid', 'email', 'profile', 'offline_access', 'User.Read'],
        };
    }

    /**
     * The full definition of a provider for the add-ons page.
     *
     * @return array{
     *     provider: string,
     *     name: string,
     *     description: string,
     *     capabilities: array<string, array{key: string, label: string, description: string, data_access: string, scopes: list<string>, sync_mode: string}>
     * }
     */
    public static function provider(IntegrationProvider $provider): array
    {
        $capabilities = self::capabilities($provider);

        return match ($provider) {
            IntegrationProvider::GoogleWorkspace => [
                'provider' => $provider->value,
                'name' => 'Google Workspace',
                'description' => 'Bring in documents from Drive, sync deadlines to Calendar, and deliver work by Gmail.',
                'capabilities' => $capabilities,
            ],
            IntegrationProvider::SharePoint => [
                'provider' => $provider->value,
                'name' => 'Microsoft SharePoint',
                'description' => 'Import and save documents in SharePoint libraries, track matters in Lists, and reach OneDrive files.',
                'capabilities' => $capabilities,
            ],
        };
    }

    /**
     * Every provider definition, in the order the add-ons page lists them.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            self::provider(IntegrationProvider::GoogleWorkspace),
            self::provider(IntegrationProvider::SharePoint),
        ];
    }

    /**
     * The capability definitions for a provider, keyed by capability key.
     *
     * @return array<string, array{key: string, label: string, description: string, data_access: string, scopes: list<string>, sync_mode: string}>
     */
    public static function capabilities(IntegrationProvider $provider): array
    {
        $rows = match ($provider) {
            IntegrationProvider::GoogleWorkspace => self::googleCapabilities(),
            IntegrationProvider::SharePoint => self::sharePointCapabilities(),
        };

        $keyed = [];

        foreach ($rows as $row) {
            $keyed[$row['key']] = $row;
        }

        return $keyed;
    }

    /**
     * Whether a provider carries the given capability key.
     */
    public static function hasCapability(IntegrationProvider $provider, string $capability): bool
    {
        return array_key_exists($capability, self::capabilities($provider));
    }

    /**
     * The definition of one capability, or null when the provider has no such
     * capability.
     *
     * @return array{key: string, label: string, description: string, data_access: string, scopes: list<string>, sync_mode: string}|null
     */
    public static function capability(IntegrationProvider $provider, string $capability): ?array
    {
        return self::capabilities($provider)[$capability] ?? null;
    }

    /**
     * The union of the OAuth scopes a set of enabled capabilities requires.
     *
     * This is what makes toggles least-privilege: the scope list sent to the
     * provider is computed from exactly the capabilities that are on, never
     * from a superset. Order is stable so the same set always yields the same
     * string, which the incremental-consent comparison relies on.
     *
     * @param  list<string>  $capabilities
     * @return list<string>
     */
    public static function scopesFor(IntegrationProvider $provider, array $capabilities): array
    {
        $scopes = self::baseScopes($provider);

        foreach ($capabilities as $capability) {
            $definition = self::capability($provider, $capability);

            if ($definition === null) {
                continue;
            }

            $scopes = [...$scopes, ...$definition['scopes']];
        }

        return array_values(array_unique($scopes));
    }

    /**
     * Google Workspace capabilities. Scopes are the narrowest Google publishes
     * for each action — a capability never asks for a broader one when a
     * narrower one covers it.
     *
     * @return list<array{key: string, label: string, description: string, data_access: string, scopes: list<string>, sync_mode: string}>
     */
    protected static function googleCapabilities(): array
    {
        return [
            [
                'key' => 'drive_import',
                'label' => 'Import documents from Google Drive',
                'description' => 'Pull files from your Drive into your Batayan case files.',
                'data_access' => 'Reads the files you pick in Google Drive.',
                'scopes' => ['https://www.googleapis.com/auth/drive.readonly'],
                'sync_mode' => 'webhook',
            ],
            [
                'key' => 'drive_export',
                'label' => 'Export / save documents to Google Drive',
                'description' => 'Save drafts and generated documents back to a Drive folder you choose.',
                'data_access' => 'Creates and writes only the files Batayan saves.',
                'scopes' => ['https://www.googleapis.com/auth/drive.file'],
                'sync_mode' => 'on_demand',
            ],
            [
                'key' => 'calendar_sync',
                'label' => 'Sync Google Calendar',
                'description' => 'Surface deadlines and notarization appointments alongside your matters.',
                'data_access' => 'Reads your calendar events.',
                'scopes' => ['https://www.googleapis.com/auth/calendar.readonly'],
                'sync_mode' => 'webhook',
            ],
            [
                'key' => 'gmail',
                'label' => 'Send / receive via Gmail',
                'description' => 'Deliver documents and notifications by email from your own address.',
                'data_access' => 'Sends email as you and reads messages it sends or receives.',
                'scopes' => [
                    'https://www.googleapis.com/auth/gmail.send',
                    'https://www.googleapis.com/auth/gmail.readonly',
                ],
                'sync_mode' => 'scheduled',
            ],
            [
                'key' => 'docs_import',
                'label' => 'Google Docs co-editing & import',
                'description' => 'Import native Google Docs content and keep shared edits in step.',
                'data_access' => 'Reads and edits the Google Docs you open in Batayan.',
                'scopes' => ['https://www.googleapis.com/auth/documents'],
                'sync_mode' => 'scheduled',
            ],
        ];
    }

    /**
     * Microsoft SharePoint capabilities. Scopes are Microsoft Graph delegated
     * permissions, narrowed to what each capability uses.
     *
     * @return list<array{key: string, label: string, description: string, data_access: string, scopes: list<string>, sync_mode: string}>
     */
    protected static function sharePointCapabilities(): array
    {
        return [
            [
                'key' => 'sharepoint_import',
                'label' => 'Import documents from SharePoint libraries',
                'description' => 'Pull documents from your SharePoint document libraries into case files.',
                'data_access' => 'Reads sites and document libraries you have access to.',
                'scopes' => ['Sites.Read.All'],
                'sync_mode' => 'webhook',
            ],
            [
                'key' => 'sharepoint_export',
                'label' => 'Export / save documents to SharePoint',
                'description' => 'Save drafts and generated documents into a SharePoint library.',
                'data_access' => 'Writes the files Batayan saves to your libraries.',
                'scopes' => ['Sites.ReadWrite.All'],
                'sync_mode' => 'on_demand',
            ],
            [
                'key' => 'sharepoint_lists',
                'label' => 'Sync with SharePoint Lists',
                'description' => 'Track matters and cases against a SharePoint list.',
                'data_access' => 'Reads the SharePoint lists you connect.',
                'scopes' => ['Sites.Read.All'],
                // Graph pushes changes only for one specific list
                // (/sites/{site-id}/lists/{list-id}), never a site's whole
                // list collection, so this polls until a list is bound.
                'sync_mode' => 'scheduled',
            ],
            [
                'key' => 'onedrive_access',
                'label' => 'OneDrive for Business file access',
                'description' => 'Reach your OneDrive for Business files from Batayan.',
                'data_access' => 'Reads the files in your OneDrive.',
                'scopes' => ['Files.Read.All'],
                'sync_mode' => 'webhook',
            ],
        ];
    }
}
