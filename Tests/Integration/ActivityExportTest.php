<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Tests\Integration;

use MauticPlugin\MauticStatusiBundle\DTO\Export\ExportRequest;
use MauticPlugin\MauticStatusiBundle\Export\ActivityExporter;
use MauticPlugin\MauticStatusiBundle\Export\ActivityExportRepository;
use MauticPlugin\MauticStatusiBundle\Export\ExportCursor;
use Symfony\Component\HttpFoundation\Request;

final class ActivityExportTest extends EmailActivityTestCase
{
    private ActivityExporter $exporter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exporter = new ActivityExporter(new ActivityExportRepository(self::$connection, MAUTIC_TABLE_PREFIX));

        $this->email(1, 'Welcome');
        $this->insert('emails', [
            'id' => 2, 'name' => 'Newsletter', 'subject' => 'October news', 'email_type' => 'list', 'is_published' => 1,
            'date_added' => '2026-01-01 00:00:00', 'publish_up' => '2026-10-13 07:00:00',
        ]);
        $this->insert('lead_lists', ['id' => 9, 'name' => 'All', 'alias' => 'all', 'is_published' => 1, 'filters' => 'a:0:{}', 'is_global' => 1]);
        $this->insert('lead_lists', ['id' => 7, 'name' => 'VIP', 'alias' => 'vip', 'is_published' => 1, 'filters' => 'a:0:{}', 'is_global' => 1]);
        $this->insert('email_list_xref', ['email_id' => 2, 'leadlist_id' => 9]);
        $this->insert('email_list_xref', ['email_id' => 2, 'leadlist_id' => 7]);
        $this->contact(10, 'ana@gmail.com');
        $this->contact(11, 'bo@yahoo.com');
        $this->ip(5, '203.0.113.5', ['country' => 'India', 'city' => 'Pune']);
        $this->device(3, 10, 'Chrome', 'Windows');
        $this->redirect(4, 'abc', 'https://example.com/offer');

        $this->send(1, 1, 10, '2026-10-13 08:00:00');
        $this->send(2, 1, 11, '2026-10-13 08:00:00', null, true);
        $this->send(3, 2, 10, '2026-10-13 07:00:00');
        $this->send(4, 1, 10, '2026-10-12 23:59:59'); // outside the range
        $this->open(1, 1, '2026-10-13 09:00:00', 5, 3);
        $this->click(1, 1, 10, '2026-10-14 08:30:00', 'https://example.com/offer?utm=x', null, 5, 4, 'India');
        $this->dnc(1, 11, 2, 1, '2026-10-13 10:00:00', 'Complaint: marked as spam');
        $this->dnc(2, 10, null, 2, '2026-10-13 11:00:00', '550 user unknown');
        $this->dnc(3, 10, null, 3, '2026-10-13 12:00:00', 'Manually added'); // not tracked by Statusi
    }

    public function testRowsAreInStatusiTerms(): void
    {
        $export = $this->exporter->export($this->request(['dateFrom' => '2026-10-13', 'dateTo' => '2026-10-14']));

        self::assertSame(['send-3', 'send-1', 'send-2', 'open-1', 'dnc-1', 'dnc-2', 'click-1'], array_column($export['data'], 'id'));
        self::assertSame(['send', 'send', 'soft_bounce', 'open', 'complaint', 'hard_bounce', 'click'], array_column($export['data'], 'activity_type'));
        self::assertFalse($export['pagination']['hasMore']);
        self::assertNull($export['pagination']['nextCursor']);

        $rows = array_column($export['data'], null, 'id');
        self::assertSame([
            'id' => 'send-3', 'activity_type' => 'send', 'activity_date_time' => '2026-10-13 07:00:00', 'email' => 'ana@gmail.com',
            'contact_id' => 10, 'ip' => null, 'country_code' => null, 'os' => null, 'browser' => null, 'days_passed' => null,
            'campaign_id' => 2, 'campaign_name' => 'Newsletter', 'mailing_type' => 'list', 'mailing_schedule' => '2026-10-13 07:00:00',
            'email_message_id' => 2, 'email_message_subject' => 'October news', 'data_value' => 'October news', 'link_id' => null,
            'segment_id' => 7, 'segment_name' => 'VIP, All',
        ], $rows['send-3']);

        self::assertSame(['203.0.113.5', 'IN', 'Windows', 'Chrome', 0, 'Welcome subject'],
            [$rows['open-1']['ip'], $rows['open-1']['country_code'], $rows['open-1']['os'], $rows['open-1']['browser'], $rows['open-1']['days_passed'], $rows['open-1']['data_value']]);
        self::assertSame(['https://example.com/offer?utm=x', 4, 1, 'IN', 'template'],
            [$rows['click-1']['data_value'], $rows['click-1']['link_id'], $rows['click-1']['days_passed'], $rows['click-1']['country_code'], $rows['click-1']['mailing_type']]);
        self::assertSame([2, null], [$rows['dnc-1']['campaign_id'], $rows['dnc-1']['days_passed']], 'complaint about the newsletter, never sent to this contact');
        self::assertSame([null, null, 'bo@yahoo.com'], [$rows['dnc-2']['campaign_id'], $rows['dnc-2']['segment_id'], $rows['dnc-1']['email']]);
    }

    public function testCursorPagingReturnsEveryRowOnceAcrossEqualTimestamps(): void
    {
        $ids    = [];
        $cursor = null;
        $pages  = 0;
        do {
            $query = ['dateFrom' => '2026-10-13', 'dateTo' => '2026-10-14', 'limit' => '2'] + (null !== $cursor ? ['cursor' => $cursor] : []);
            $page  = $this->exporter->export($this->request($query));
            $ids   = array_merge($ids, array_column($page['data'], 'id'));
            $cursor = $page['pagination']['nextCursor'];
            ++$pages;
        } while (null !== $cursor && $pages < 10);

        self::assertSame(['send-3', 'send-1', 'send-2', 'open-1', 'dnc-1', 'dnc-2', 'click-1'], $ids);
        self::assertSame(4, $pages);
    }

    public function testCursorFromAnEarlierSourceAtTheSameTimestamp(): void
    {
        // Cursor on a dnc row: a click at the same second must still come next (sources sort send < open < click < dnc).
        $this->click(2, 1, 11, '2026-10-13 10:00:00', 'https://example.com/a');
        $export = $this->exporter->export($this->request([
            'dateFrom' => '2026-10-13', 'dateTo' => '2026-10-14',
            'cursor'   => ExportCursor::encode(['2026-10-13 10:00:00', ActivityExportRepository::SOURCE_OPEN, 999]),
        ]));

        self::assertSame(['click-2', 'dnc-1', 'dnc-2', 'click-1'], array_column($export['data'], 'id'));
    }

    /**
     * @param array<string, string> $query
     */
    private function request(array $query): ExportRequest
    {
        return ExportRequest::fromRequest(Request::create('/api/custom/email-activity-export', 'GET', $query));
    }
}
