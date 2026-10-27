<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Tests\Unit;

use MauticPlugin\MauticStatusiBundle\Service\OngageFieldMapping;
use PHPUnit\Framework\TestCase;

final class OngageFieldMappingTest extends TestCase
{
    private const DEFINITION = [
        'fields' => ['contacts.email', 'contact_list_attribute.country', 'contact_list_attribute.ip', 'contact_list_attribute.platform_status'],
        'fixed'  => ['contact_list_attribute.platform_status' => 'ocx_status'],
    ];

    public function testViewShowsSavedMappingsFromIntegrationAndLists(): void
    {
        $requested = [];
        $view      = (new OngageFieldMapping())->view(
            self::DEFINITION,
            [
                ['id' => 10, 'name' => 'Main', 'field_mapping_override' => ['contacts.email' => 'e_mail', 'contact_list_attribute.platform_status' => 'ocx_status']],
                ['id' => 20, 'name' => 'Newsletter', 'default' => true, 'field_mapping_override' => null],
                ['id' => 30, 'name' => 'Other'],
            ],
            // As Statusi stores it: only mapped fields, lower-cased (country was "None")
            ['field_mapping' => ['contacts.email' => 'email', 'contact_list_attribute.platform_status' => 'ocx_status']],
            static function (int $listId) use (&$requested): array {
                $requested[] = $listId;

                return 20 === $listId ? ['Email', 'IP', 'Country'] : ['E_Mail', 'Country'];
            }
        );

        self::assertSame([20, 10], $requested, 'fields are loaded for the default list and the lists with a mapping only');
        self::assertSame(20, $view['default']['list']['id']);
        self::assertSame(['contacts.email', 'contact_list_attribute.country', 'contact_list_attribute.ip'], array_column($view['fields'], 'key'));
        self::assertSame([['key' => 'contact_list_attribute.platform_status', 'label' => 'platform_status', 'value' => 'ocx_status']], $view['fixed']);

        // saved "email" -> title "Email"; fields missing from a saved mapping are "None" (no auto-matching)
        self::assertSame(['contacts.email' => 'Email', 'contact_list_attribute.country' => 'None', 'contact_list_attribute.ip' => 'None'], $view['default']['values']);
        self::assertSame(['contacts.email' => 'E_Mail', 'contact_list_attribute.country' => 'None', 'contact_list_attribute.ip' => 'None'], $view['selected'][10]['values']);
        self::assertSame([true, false, false], array_column($view['lists'], 'selected'));
        self::assertSame('10', $view['previous']);
    }

    public function testNewIntegrationMatchesSameNamedFields(): void
    {
        $view = (new OngageFieldMapping())->view(self::DEFINITION, [['id' => 20, 'name' => 'Newsletter']], [], static fn (): array => ['EMAIL', 'Country', 'phone']);

        self::assertSame(['contacts.email' => 'EMAIL', 'contact_list_attribute.country' => 'Country', 'contact_list_attribute.ip' => 'None'], $view['default']['values']);
        self::assertSame([], $view['selected']);
    }

    public function testPayloadAddsFixedFieldsAndDeletedLists(): void
    {
        $payload = (new OngageFieldMapping())->payload(
            self::DEFINITION,
            [
                'default' => ['contacts.email' => 'Email', 'contact_list_attribute.country' => '', 'contact_list_attribute.platform_status' => 'tampered'],
                '20'      => ['contacts.email' => 'mail'],
                'evil'    => ['contacts.email' => 'x'],
            ],
            ['20', 'evil', '20'],
            ['10', '20'],
            ['20' => 'Newsletter', '10' => 'Main']
        );

        self::assertSame([
            'field_mapping' => [
                'contacts.email'                        => 'Email',
                'contact_list_attribute.country'        => 'None',
                'contact_list_attribute.ip'             => 'None',
                'contact_list_attribute.platform_status' => 'ocx_status',
            ],
            '20' => ['field_mapping' => [
                'contacts.email'                        => 'mail',
                'contact_list_attribute.country'        => 'None',
                'contact_list_attribute.ip'             => 'None',
                'contact_list_attribute.platform_status' => 'ocx_status',
            ]],
        ], $payload['fields_mapping']);
        self::assertSame(['20' => 'Newsletter'], $payload['list_names']);
        self::assertSame(['10'], $payload['deleted_list_ids']);
    }
}
