<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Tests\Unit;

use MauticPlugin\MauticStatusiBundle\Service\IntegrationList;
use PHPUnit\Framework\TestCase;

final class IntegrationListTest extends TestCase
{
    public function testRowsFollowStatusiListingRules(): void
    {
        $rows = (new IntegrationList())->rows([
            $this->row(1, 12, 'Mautic', null, 1, 1),
            $this->row(2, 1, 'ongage', '/integration/create/ongage/contact-activity', 0, 1),
            $this->row(3, 1, 'ongage', '/integration/create/ongage/contact-manager', 1, 1),
            $this->row(4, 6, 'Google Ads', '/integration/create/google-ads-accounts', 0, 0),
            $this->row(5, 11, 'ChatGPT Ads', '/integration/create/chatgpt', 0, 0),
            $this->row(6, 10, 'Click Go', '/integration/create/clickgo', 0, 1),
            $this->row(7, 4, 'Mailchimp', null, 0, 1),
            $this->row(8, 4, 'Mailchimp', null, 1, 1),
            $this->row(9, 7, 'Google AdSense', '/integration/create/google-adsense-accounts', 0, 1),
            $this->row(10, 8, 'Google Analytics', '/integration/create/google-analytics-accounts', 0, 1),
            $this->row(11, 9, 'Meta Ads', '/integration/create/meta-ads-accounts', 0, 1),
            $this->row(12, 13, 'inboxgenie', '/integration/create/inbox-genie', 0, 1),
            ['integration_config_id' => null, 'platform_id' => 3, 'platform_name' => 'no config yet'],
        ]);

        $byId = array_column($rows, null, 'id');
        self::assertCount(12, $rows, 'rows without integration_config are skipped');

        $expected = [
            1  => ['mautic', false, null],
            2  => ['ongage', true, null],                       // Contact Activity has no settings
            3  => ['ongage', true, ['disabled' => false]],      // Contact Manager: field mapping
            4  => ['google_ads', true, ['disabled' => true]],   // inactive: edit after activating
            5  => ['chatgpt', false, ['disabled' => false]],    // status follows its ad accounts
            6  => ['clickgo', true, null],                      // no edit page, like in Statusi
            7  => ['mailchimp', true, ['disabled' => false]],
            8  => ['mailchimp', true, null],                    // platform 4 Contact Manager: no edit page
            9  => ['google_adsense', true, ['disabled' => false]],
            10 => ['google_analytics', true, ['disabled' => false]],
            11 => ['meta_ads', true, ['disabled' => false]],
            12 => ['unsupported', true, null],
        ];
        foreach ($expected as $id => [$kind, $canToggle, $edit]) {
            self::assertSame([$kind, $canToggle, $edit], [$byId[$id]['kind'], $byId[$id]['can_toggle'], $byId[$id]['edit']], 'row '.$id);
        }
        self::assertSame('contact_manager', $byId[3]['task']);
        self::assertFalse($byId[4]['active']);
    }

    public function testPlatformPickerOffersEverySupportedPlatform(): void
    {
        $platforms = (new IntegrationList())->platforms([
            ['id' => 1, 'name' => 'ongage', 'path' => '/integration/create/ongage', 'image_url' => ' https://cdn/ongage.png '],
            ['id' => 4, 'name' => 'Mailchimp', 'path' => '/integration/create/mailchimp'],
            ['id' => 5, 'name' => 'Google AdSense', 'path' => '/integration/create/google-adsense'],
            ['id' => 6, 'name' => 'Google Ads', 'path' => '/integration/create/google-ads'],
            ['id' => 8, 'name' => 'Google Analytics', 'path' => '/integration/create/google-analytics'],
            ['id' => 9, 'name' => 'Meta Ads', 'path' => '/integration/create/meta-ads'],
            ['id' => 10, 'name' => 'Click Go', 'path' => '/integration/create/clickgo'],
            ['id' => 11, 'name' => 'ChatGPT Ads', 'path' => '/integration/create/chatgpt'],
            ['id' => 12, 'name' => 'Mautic', 'path' => null],
            ['id' => 14, 'name' => 'SendPost', 'path' => '/integration/create/sendpost'],
            ['id' => 0, 'name' => 'broken'],
        ]);

        self::assertSame(
            ['ongage', 'mailchimp', 'google_adsense', 'google_ads', 'google_analytics', 'meta_ads', 'clickgo', 'chatgpt'],
            array_column($platforms, 'kind'),
            'Mautic (connected by the plugin) and platforms without a setup page are not offered'
        );
        self::assertSame('https://cdn/ongage.png', $platforms[0]['image_url']);
    }

    public function testOAuthReturnKeysMatchStatusi(): void
    {
        // statusi_backend: the callbacks send the browser to /s/statusi/integrations/oauth/<key>
        self::assertSame(
            ['google-ads', 'google-adsense', 'google-analytics', 'meta-ads', 'mailchimp'],
            array_column(IntegrationList::OAUTH, 'key')
        );
        self::assertSame('google_analytics', IntegrationList::kindForOAuthKey('google-analytics'));
        self::assertNull(IntegrationList::kindForOAuthKey('ongage'));
        self::assertTrue(IntegrationList::isOAuth('meta_ads'));
        self::assertFalse(IntegrationList::isOAuth('chatgpt'));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id, int $platformId, string $name, ?string $path, int $contactManager, int $status): array
    {
        return [
            'integration_config_id' => $id,
            'platform_id'           => $platformId,
            'platform_name'         => $name,
            'navigation_path'       => $path,
            'contact_manager'       => $contactManager,
            'status'                => $status,
        ];
    }
}
