<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Tests\Integration;

use MauticPlugin\MauticStatusiBundle\DTO\Export\ExportRequest;
use MauticPlugin\MauticStatusiBundle\Export\ContactExporter;
use MauticPlugin\MauticStatusiBundle\Export\ContactExportRepository;
use Symfony\Component\HttpFoundation\Request;

final class ContactExportTest extends ContactChangesTestCase
{
    private ContactExporter $exporter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exporter = new ContactExporter(new ContactExportRepository(self::$db, MAUTIC_TABLE_PREFIX), $this->catalog);

        $earlier = '2026-09-01 00:00:00';
        $this->contact(20, '2026-10-13 05:00:00', '2026-10-13 05:00:00', ['country' => 'India', 'loyalty_tier' => 'gold', 'internal_score' => 99]);
        $this->contact(21, $earlier, $earlier);
        $this->audit(1, 21, 'update', '2026-10-13 09:00:00', ['firstname' => ['Old', 'New']]);
        $this->contact(22, $earlier, $earlier);
        $this->dnc(1, 22, 1, '2026-09-10 00:00:00', 'User unsubscribed');
        $this->dnc(2, 22, 2, '2026-10-13 10:00:00', '550 user unknown');
        $this->contact(23, $earlier, $earlier); // unchanged in the range
        $this->contact(24, $earlier, $earlier);
        $this->dnc(3, 24, 1, '2026-10-13 11:00:00', 'Spam complaint (feedback loop)');

        foreach ([[1, '198.51.100.1'], [2, '198.51.100.2']] as [$id, $address]) {
            self::$db->insert(MAUTIC_TABLE_PREFIX.'ip_addresses', ['id' => $id, 'ip_address' => $address, 'ip_details' => serialize([])]);
            self::$db->insert(MAUTIC_TABLE_PREFIX.'lead_ips_xref', ['lead_id' => 20, 'ip_id' => $id]);
        }
    }

    public function testRowsAreInStatusiTerms(): void
    {
        $export = $this->exporter->export($this->request(['dateFrom' => '2026-10-13', 'dateTo' => '2026-10-13']));
        $rows   = array_column($export['data'], null, 'contact_id');

        self::assertSame([20, 21, 22, 24], array_keys($rows));
        self::assertSame(
            ['contact20@example.com', '2026-10-13 05:00:00', '2026-10-13 05:00:00', 'subscribed', '198.51.100.2', 'India'],
            [$rows[20]['email'], $rows[20]['created_date'], $rows[20]['last_modified_date'], $rows[20]['platform_status'], $rows[20]['ip'], $rows[20]['country']]
        );
        self::assertSame('gold', $rows[20]['fields']['loyalty_tier']);
        self::assertArrayNotHasKey('internal_score', $rows[20]['fields'], 'unpublished fields are never exported');

        self::assertSame(['2026-10-13 09:00:00', 'subscribed'], [$rows[21]['last_modified_date'], $rows[21]['platform_status']]);
        self::assertSame(
            ['cleaned', '2026-09-10 00:00:00', '2026-10-13 10:00:00', null],
            [$rows[22]['platform_status'], $rows[22]['unsubscribe_date'], $rows[22]['bounce_date'], $rows[22]['complaint_date']]
        );
        self::assertSame(
            ['unsubscribed', null, '2026-10-13 11:00:00'],
            [$rows[24]['platform_status'], $rows[24]['unsubscribe_date'], $rows[24]['complaint_date']]
        );
        self::assertContains('loyalty_tier', $export['meta']['fields']);
    }

    public function testCursorPaging(): void
    {
        $first = $this->exporter->export($this->request(['dateFrom' => '2026-10-13', 'dateTo' => '2026-10-13', 'limit' => '2']));
        self::assertSame([20, 21], array_column($first['data'], 'contact_id'));
        self::assertTrue($first['pagination']['hasMore']);

        $second = $this->exporter->export($this->request([
            'dateFrom' => '2026-10-13', 'dateTo' => '2026-10-13', 'limit' => '2', 'cursor' => $first['pagination']['nextCursor'],
        ]));
        self::assertSame([22, 24], array_column($second['data'], 'contact_id'));
        self::assertFalse($second['pagination']['hasMore']);
    }

    private function dnc(int $id, int $leadId, int $reason, string $date, string $comments): void
    {
        self::$db->insert(MAUTIC_TABLE_PREFIX.'lead_donotcontact', [
            'id' => $id, 'lead_id' => $leadId, 'channel' => 'email', 'channel_id' => null, 'reason' => $reason,
            'date_added' => $date, 'comments' => $comments,
        ]);
    }

    /**
     * @param array<string, string> $query
     */
    private function request(array $query): ExportRequest
    {
        return ExportRequest::fromRequest(Request::create('/api/custom/contact-attribute-export', 'GET', $query));
    }
}
