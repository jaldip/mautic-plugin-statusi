<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Service;

/**
 * Turns Statusi's integration rows and platforms into what Statusi integrations shows, with the same
 * rules as Statusi's own listing (statusi_ui/src/pages/Settings/IntegrationListPage.tsx). Every platform
 * is created and edited in Mautic:
 *
 *  - mautic:           read-only, managed by this plugin's Connect / Disconnect
 *  - ongage:           API key form; Contact Manager rows get the list / field mapping page
 *  - chatgpt, clickgo: API key forms (Click Go has no edit page, like in Statusi)
 *  - google_ads, google_adsense, google_analytics, meta_ads, mailchimp: provider sign-in started from
 *    Mautic (Statusi returns the user to Mautic), then account / property / settings pages in Mautic
 *  - unsupported:      platforms Statusi has no integration page for (yet)
 */
final class IntegrationList
{
    public const KIND_MAUTIC           = 'mautic';
    public const KIND_ONGAGE           = 'ongage';
    public const KIND_CHATGPT          = 'chatgpt';
    public const KIND_CLICKGO          = 'clickgo';
    public const KIND_GOOGLE_ADS       = 'google_ads';
    public const KIND_GOOGLE_ADSENSE   = 'google_adsense';
    public const KIND_GOOGLE_ANALYTICS = 'google_analytics';
    public const KIND_META_ADS         = 'meta_ads';
    public const KIND_MAILCHIMP        = 'mailchimp';
    public const KIND_UNSUPPORTED      = 'unsupported';

    public const MAUTIC_PLATFORM_ID = 12;

    /**
     * Sign-in platforms: Statusi endpoints to start the sign-in, read and save the selection, and the key
     * Statusi uses in the Mautic return URL (/s/statusi/integrations/oauth/<key>).
     */
    public const OAUTH = [
        self::KIND_GOOGLE_ADS => [
            'key' => 'google-ads', 'start' => '/integration/google-ads',
            'accounts' => '/integration/get-integrated-accounts', 'save' => '/integration/save-google-ads-config',
        ],
        self::KIND_GOOGLE_ADSENSE => [
            'key' => 'google-adsense', 'start' => '/integration/google-adsense',
            'accounts' => '/integration/get-google-adsense-integrated-accounts', 'save' => '/integration/save-adsense-config',
        ],
        self::KIND_GOOGLE_ANALYTICS => [
            'key' => 'google-analytics', 'start' => '/integration/google-analytics-integration',
            'accounts' => '/integration/get-google-analytics-integrated-accounts', 'save' => '/integration/save-analytics-config',
        ],
        self::KIND_META_ADS => [
            'key' => 'meta-ads', 'start' => '/integration/auth/meta/connect',
            'accounts' => '/integration/get-meta-integrated-accounts', 'save' => '/integration/save-meta-ads-config',
        ],
        self::KIND_MAILCHIMP => [
            'key' => 'mailchimp', 'start' => '/integration/auth/mailchimp/connect',
            'accounts' => '/integration/get-mailchimp-integrated-accounts', 'save' => '/integration/save-mailchimp-config',
        ],
    ];

    // Same as Statusi's listing: platform 4 Contact Manager rows have no edit page.
    private const NO_MANAGER_EDIT_PLATFORM_ID = 4;

    public static function isOAuth(string $kind): bool
    {
        return isset(self::OAUTH[$kind]);
    }

    public static function kindForOAuthKey(string $key): ?string
    {
        foreach (self::OAUTH as $kind => $config) {
            if ($config['key'] === $key) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $rows Statusi /integration/get_lists rows
     *
     * @return array<int, array{id: int, platform_id: int, platform: string, task: string, active: bool, kind: string, can_toggle: bool, edit: array{disabled: bool}|null}>
     */
    public function rows(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            if (empty($row['integration_config_id'])) {
                continue;
            }

            $platformId = (int) ($row['platform_id'] ?? 0);
            $name       = (string) ($row['platform_name'] ?? '');
            $kind       = $this->kind($platformId, $name, (string) ($row['navigation_path'] ?? ''));
            $active     = 1 === (int) ($row['status'] ?? 0);
            $isManager  = 1 === (int) ($row['contact_manager'] ?? 0);

            $result[] = [
                'id'          => (int) $row['integration_config_id'],
                'platform_id' => $platformId,
                'platform'    => $name,
                'task'        => $isManager ? 'contact_manager' : 'contact_activity',
                'active'      => $active,
                'kind'        => $kind,
                'can_toggle'  => !in_array($kind, [self::KIND_MAUTIC, self::KIND_CHATGPT], true),
                'edit'        => $this->edit($kind, $platformId, $active, $isManager),
            ];
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $platforms Statusi /integration/platforms rows
     *
     * @return array<int, array{id: int, name: string, image_url: string, kind: string}>
     */
    public function platforms(array $platforms): array
    {
        $result = [];
        foreach ($platforms as $platform) {
            $id = (int) ($platform['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $name = (string) ($platform['name'] ?? '');
            $kind = $this->kind($id, $name, (string) ($platform['path'] ?? ''));

            // Not offered: Mautic is connected through this plugin, unsupported platforms have no setup page.
            if (in_array($kind, [self::KIND_MAUTIC, self::KIND_UNSUPPORTED], true)) {
                continue;
            }

            $result[] = [
                'id'        => $id,
                'name'      => $name,
                'image_url' => trim((string) ($platform['image_url'] ?? '')),
                'kind'      => $kind,
            ];
        }

        return $result;
    }

    private function kind(int $platformId, string $name, string $path): string
    {
        $path = strtolower($path);
        $name = strtolower($name);

        return match (true) {
            self::MAUTIC_PLATFORM_ID === $platformId || 'mautic' === $name                    => self::KIND_MAUTIC,
            str_contains($path, '/chatgpt') || str_contains($name, 'chatgpt')                  => self::KIND_CHATGPT,
            str_contains($path, '/clickgo') || str_contains($name, 'click go')                 => self::KIND_CLICKGO,
            str_contains($path, 'adsense') || str_contains($name, 'adsense')                   => self::KIND_GOOGLE_ADSENSE,
            str_contains($path, 'analytics') || str_contains($name, 'analytics')               => self::KIND_GOOGLE_ANALYTICS,
            str_contains($path, 'google-ads') || 'google ads' === $name                         => self::KIND_GOOGLE_ADS,
            str_contains($path, '/meta') || str_contains($name, 'meta')                         => self::KIND_META_ADS,
            str_contains($path, 'mailchimp') || 'mailchimp' === $name                           => self::KIND_MAILCHIMP,
            str_contains($path, '/ongage') || 'ongage' === $name                                => self::KIND_ONGAGE,
            default                                                                            => self::KIND_UNSUPPORTED,
        };
    }

    /**
     * @return array{disabled: bool}|null null = no edit action
     */
    private function edit(string $kind, int $platformId, bool $active, bool $isManager): ?array
    {
        return match (true) {
            // Stays editable while inactive, so its ad accounts can be switched on again.
            self::KIND_CHATGPT === $kind => ['disabled' => false],
            // Ongage: only Contact Manager rows have settings (lists and field mapping).
            self::KIND_ONGAGE === $kind => $isManager ? ['disabled' => !$active] : null,
            self::KIND_MAILCHIMP === $kind && self::NO_MANAGER_EDIT_PLATFORM_ID === $platformId && $isManager => null,
            self::isOAuth($kind) => ['disabled' => !$active],
            default => null,
        };
    }
}
