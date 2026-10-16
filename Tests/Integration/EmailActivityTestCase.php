<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use MauticPlugin\MauticStatusiBundle\EmailActivity\ActivitySqlBuilder;
use MauticPlugin\MauticStatusiBundle\EmailActivity\EmailActivityReportBuilder;
use MauticPlugin\MauticStatusiBundle\EmailActivity\EmailActivityRepository;
use MauticPlugin\MauticStatusiBundle\EmailActivity\ReportCriteriaFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Runs the report against a dedicated test database whose tables are structure copies of the
 * Mautic database (see Tests/bootstrap.php). Every test starts with empty tables.
 */
abstract class EmailActivityTestCase extends TestCase
{
    protected const TABLES = [
        'email_stats', 'email_stats_devices', 'page_hits', 'page_redirects', 'lead_donotcontact',
        'campaign_lead_event_log', 'campaign_lead_event_failed_log', 'campaign_events', 'campaigns',
        'campaign_leads', 'emails', 'leads', 'ip_addresses', 'lead_devices', 'email_list_xref', 'lead_lists',
    ];

    protected static ?Connection $connection = null;

    /** @var array<string, array<string, array{type: string, nullable: bool, default: bool}>> */
    private static array $columns = [];

    protected EmailActivityRepository $repository;
    protected EmailActivityReportBuilder $builder;

    public static function setUpBeforeClass(): void
    {
        if (null !== self::$connection) {
            return;
        }

        $config = $GLOBALS['STATUSI_TEST_DB_CONFIG'];
        $source = $GLOBALS['STATUSI_TEST_SOURCE_DB'];
        $target = $GLOBALS['STATUSI_TEST_DB'];

        try {
            $server = DriverManager::getConnection($config);
            $server->executeStatement('CREATE DATABASE IF NOT EXISTS `'.$target.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            foreach (self::TABLES as $table) {
                $name = MAUTIC_TABLE_PREFIX.$table;
                $server->executeStatement('DROP TABLE IF EXISTS `'.$target.'`.`'.$name.'`');
                $server->executeStatement('CREATE TABLE `'.$target.'`.`'.$name.'` LIKE `'.$source.'`.`'.$name.'`');
            }
            $server->close();
        } catch (\Throwable $e) {
            self::markTestSkipped('Test database is not available: '.$e->getMessage());
        }

        self::$connection = DriverManager::getConnection($config + ['dbname' => $target]);
    }

    protected function setUp(): void
    {
        foreach (self::TABLES as $table) {
            self::$connection->executeStatement('TRUNCATE TABLE `'.MAUTIC_TABLE_PREFIX.$table.'`');
        }

        $this->repository = new EmailActivityRepository(self::$connection, new ActivitySqlBuilder(MAUTIC_TABLE_PREFIX));
        $this->builder    = new EmailActivityReportBuilder($this->repository);
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    protected function report(array $query): array
    {
        $criteria = (new ReportCriteriaFactory())->fromRequest(Request::create('/api/custom/email-activity-report', 'GET', $query));

        return $this->builder->build($criteria);
    }

    // ---- fixtures ------------------------------------------------------------------------

    protected function email(int $id, string $name, string $dateAdded = '2026-01-01 00:00:00', ?int $createdBy = null): void
    {
        $this->insert('emails', [
            'id' => $id, 'name' => $name, 'subject' => $name.' subject', 'email_type' => 'template',
            'is_published' => 1, 'date_added' => $dateAdded, 'created_by' => $createdBy,
        ]);
    }

    protected function contact(int $id, ?string $email = null, ?int $ownerId = null): void
    {
        $this->insert('leads', [
            'id' => $id, 'email' => $email ?? 'contact'.$id.'@example.com', 'firstname' => 'First'.$id,
            'lastname' => 'Last'.$id, 'owner_id' => $ownerId, 'date_added' => '2026-01-01 00:00:00',
        ]);
    }

    protected function campaign(int $id, string $name): void
    {
        $this->insert('campaigns', ['id' => $id, 'name' => $name, 'is_published' => 1, 'date_added' => '2026-02-01 00:00:00']);
    }

    protected function campaignStep(int $id, int $campaignId, int $emailId, string $name = 'Send email', string $eventType = 'action'): void
    {
        $this->insert('campaign_events', [
            'id' => $id, 'campaign_id' => $campaignId, 'name' => $name, 'type' => 'email.send', 'event_type' => $eventType,
            'event_order' => 1, 'properties' => 'a:0:{}', 'trigger_mode' => 'immediate', 'channel' => 'email', 'channel_id' => $emailId,
        ]);
    }

    protected function send(int $id, ?int $emailId, int $leadId, string $dateSent, ?int $campaignStepId = null, bool $failed = false, ?string $hash = null): void
    {
        $this->insert('email_stats', [
            'id' => $id, 'email_id' => $emailId, 'lead_id' => $leadId, 'email_address' => 'contact'.$leadId.'@example.com',
            'date_sent' => $dateSent, 'is_read' => 0, 'is_failed' => $failed ? 1 : 0, 'viewed_in_browser' => 0,
            'tracking_hash' => $hash ?? 'hash'.$id, 'retry_count' => 0, 'open_count' => 0,
            'source' => null !== $campaignStepId ? 'campaign.event' : null, 'source_id' => $campaignStepId,
        ]);
    }

    protected function open(int $id, int $statId, string $date, ?int $ipId = null, ?int $deviceId = null): void
    {
        $this->insert('email_stats_devices', ['id' => $id, 'stat_id' => $statId, 'date_opened' => $date, 'ip_id' => $ipId, 'device_id' => $deviceId]);
    }

    protected function click(int $id, int $emailId, int $leadId, string $date, string $url, ?string $statHash = null, ?int $ipId = null, ?int $redirectId = null, ?string $country = null): void
    {
        $query = null !== $statHash ? ['ct' => ['email' => $emailId, 'stat' => $statHash, 'lead' => $leadId, 'source' => ['email', $emailId]]] : [];
        $this->insert('page_hits', [
            'id' => $id, 'email_id' => $emailId, 'lead_id' => $leadId, 'date_hit' => $date, 'url' => $url,
            'source' => 'email', 'source_id' => $emailId, 'ip_id' => $ipId, 'redirect_id' => $redirectId, 'code' => 200,
            'query' => serialize($query), 'country' => $country, 'user_agent' => 'Mozilla/5.0 Test',
        ]);
    }

    protected function dnc(int $id, int $leadId, ?int $emailId, int $reason, string $date, ?string $comments = null): void
    {
        $this->insert('lead_donotcontact', [
            'id' => $id, 'lead_id' => $leadId, 'channel' => 'email', 'channel_id' => $emailId, 'reason' => $reason,
            'date_added' => $date, 'comments' => $comments,
        ]);
    }

    protected function campaignLog(int $id, int $campaignId, int $stepId, int $leadId, int $emailId, string $date, ?string $failReason = null): void
    {
        $this->insert('campaign_lead_event_log', [
            'id' => $id, 'campaign_id' => $campaignId, 'event_id' => $stepId, 'lead_id' => $leadId, 'date_triggered' => $date,
            'is_scheduled' => 0, 'system_triggered' => 1, 'channel' => 'email', 'channel_id' => $emailId, 'rotation' => 1,
            'non_action_path_taken' => 0,
        ]);
        if (null !== $failReason) {
            $this->insert('campaign_lead_event_failed_log', ['log_id' => $id, 'date_added' => $date, 'reason' => $failReason]);
        }
    }

    protected function membership(int $campaignId, int $leadId, string $date): void
    {
        $this->insert('campaign_leads', ['campaign_id' => $campaignId, 'lead_id' => $leadId, 'date_added' => $date, 'manually_removed' => 0, 'manually_added' => 0, 'rotation' => 1]);
    }

    protected function ip(int $id, string $address, array $details): void
    {
        $this->insert('ip_addresses', ['id' => $id, 'ip_address' => $address, 'ip_details' => serialize($details)]);
    }

    protected function device(int $id, int $leadId, string $browser, string $os, string $type = 'desktop'): void
    {
        $this->insert('lead_devices', [
            'id' => $id, 'lead_id' => $leadId, 'date_added' => '2026-01-01 00:00:00', 'device' => $type,
            'device_os_name' => $os, 'client_info' => serialize(['type' => 'browser', 'name' => $browser, 'version' => '129.0']),
        ]);
    }

    protected function redirect(int $id, string $hash, string $url): void
    {
        $this->insert('page_redirects', ['id' => $id, 'redirect_id' => $hash, 'url' => $url, 'hits' => 0, 'unique_hits' => 0, 'is_published' => 1]);
    }

    /**
     * Inserts a row, filling NOT NULL columns without a default so fixtures only name what matters.
     *
     * @param array<string, mixed> $values
     */
    protected function insert(string $table, array $values): void
    {
        $name = MAUTIC_TABLE_PREFIX.$table;
        foreach ($this->columns($name) as $column => $meta) {
            if (!array_key_exists($column, $values) && !$meta['nullable'] && !$meta['default']) {
                $values[$column] = match (true) {
                    str_contains($meta['type'], 'int'), str_contains($meta['type'], 'decimal'), str_contains($meta['type'], 'double') => 0,
                    str_contains($meta['type'], 'datetime'), str_contains($meta['type'], 'timestamp') => '2026-01-01 00:00:00',
                    'date' === $meta['type'] => '2026-01-01',
                    // Mautic's JSON columns are longtext with a json_valid() check.
                    str_contains($meta['type'], 'text') => '[]',
                    default => '',
                };
            }
        }
        self::$connection->insert($name, $values);
    }

    /**
     * @return array<string, array{type: string, nullable: bool, default: bool}>
     */
    private function columns(string $table): array
    {
        if (!isset(self::$columns[$table])) {
            $rows = self::$connection->fetchAllAssociative(
                'SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS'
                .' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$table]
            );
            foreach ($rows as $row) {
                self::$columns[$table][$row['COLUMN_NAME']] = [
                    'type'     => strtolower((string) $row['DATA_TYPE']),
                    'nullable' => 'YES' === $row['IS_NULLABLE'],
                    'default'  => null !== $row['COLUMN_DEFAULT'] || str_contains((string) $row['EXTRA'], 'auto_increment'),
                ];
            }
        }

        return self::$columns[$table];
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return array<string, mixed>
     */
    protected function group(array $report, int $emailId): array
    {
        foreach ($report['data'] as $group) {
            if ($group['email']['id'] === $emailId) {
                return $group;
            }
        }
        self::fail('Email '.$emailId.' is not in the report.');
    }

    /**
     * @param array<string, mixed> $group
     *
     * @return array<string, mixed>
     */
    protected function contactIn(array $group, int $leadId): array
    {
        foreach ($group['contacts'] as $contact) {
            if ($contact['contact']['id'] === $leadId) {
                return $contact;
            }
        }
        self::fail('Contact '.$leadId.' is not in the group.');
    }

    /**
     * @param array<string, mixed> $contact
     *
     * @return string[]
     */
    protected function events(array $contact): array
    {
        $events = array_column($contact['activities'], 'event');
        sort($events);

        return $events;
    }
}
