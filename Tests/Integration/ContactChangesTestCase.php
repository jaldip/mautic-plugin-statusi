<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use MauticPlugin\MauticStatusiBundle\ContactChanges\ContactChangeCriteriaFactory;
use MauticPlugin\MauticStatusiBundle\ContactChanges\ContactChangeReportBuilder;
use MauticPlugin\MauticStatusiBundle\ContactChanges\ContactChangeRepository;
use MauticPlugin\MauticStatusiBundle\ContactChanges\ContactFieldCatalog;
use MauticPlugin\MauticStatusiBundle\DTO\ContactChanges\ContactChangeCriteria;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Runs the contact attribute report against the plugin test database. The field definitions
 * (lead_fields) are copied from the Mautic database, plus two test custom fields:
 * "loyalty_tier" (published) and "internal_score" (unpublished, must never be exposed).
 */
abstract class ContactChangesTestCase extends TestCase
{
    private const CLONED = ['leads', 'lead_fields', 'audit_log', 'users', 'stages', 'lead_donotcontact', 'lead_ips_xref', 'ip_addresses'];

    protected static ?Connection $db = null;

    protected ContactChangeRepository $repository;
    protected ContactChangeReportBuilder $builder;
    protected ContactFieldCatalog $catalog;

    public static function setUpBeforeClass(): void
    {
        if (null !== self::$db) {
            return;
        }

        $config = $GLOBALS['STATUSI_TEST_DB_CONFIG'];
        $source = $GLOBALS['STATUSI_TEST_SOURCE_DB'];
        $target = $GLOBALS['STATUSI_TEST_DB'];
        $p      = MAUTIC_TABLE_PREFIX;

        try {
            $server = DriverManager::getConnection($config);
            $server->executeStatement('CREATE DATABASE IF NOT EXISTS `'.$target.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            foreach (self::CLONED as $table) {
                $server->executeStatement('DROP TABLE IF EXISTS `'.$target.'`.`'.$p.$table.'`');
                $server->executeStatement('CREATE TABLE `'.$target.'`.`'.$p.$table.'` LIKE `'.$source.'`.`'.$p.$table.'`');
            }
            $server->executeStatement('INSERT INTO `'.$target.'`.`'.$p.'lead_fields` SELECT * FROM `'.$source.'`.`'.$p.'lead_fields`');
            $server->close();
        } catch (\Throwable $e) {
            self::markTestSkipped('Test database is not available: '.$e->getMessage());
        }

        self::$db = DriverManager::getConnection($config + ['dbname' => $target]);

        // Two custom fields, created the way Mautic does: a column on leads + a lead_fields row.
        self::$db->executeStatement('ALTER TABLE `'.$p.'leads` ADD COLUMN `loyalty_tier` VARCHAR(191) NULL, ADD COLUMN `internal_score` INT NULL');
        $nextId = (int) self::$db->fetchOne('SELECT COALESCE(MAX(id), 0) + 1 FROM `'.$p.'lead_fields`');
        foreach ([['loyalty_tier', 'Loyalty tier', 'text', 1], ['internal_score', 'Internal score', 'number', 0]] as $i => [$alias, $label, $type, $published]) {
            self::$db->insert($p.'lead_fields', [
                'id' => $nextId + $i, 'is_published' => $published, 'label' => $label, 'alias' => $alias, 'type' => $type,
                'field_group' => 'core', 'is_required' => 0, 'is_fixed' => 0, 'is_visible' => 1, 'is_short_visible' => 0,
                'is_listable' => 1, 'is_publicly_updatable' => 0, 'is_unique_identifer' => 0, 'is_index' => 0,
                'field_order' => 100 + $i, 'object' => 'lead', 'properties' => 'a:0:{}', 'column_is_not_created' => 0,
                'column_is_not_removed' => 0, 'char_length_limit' => 191,
            ]);
        }
    }

    protected function setUp(): void
    {
        foreach (['leads', 'audit_log', 'users', 'stages', 'lead_donotcontact', 'lead_ips_xref', 'ip_addresses'] as $table) {
            self::$db->executeStatement('TRUNCATE TABLE `'.MAUTIC_TABLE_PREFIX.$table.'`');
        }
        $this->catalog    = new ContactFieldCatalog(self::$db, MAUTIC_TABLE_PREFIX);
        $this->repository = new ContactChangeRepository(self::$db, MAUTIC_TABLE_PREFIX);
        $this->builder    = new ContactChangeReportBuilder($this->repository, $this->catalog);
    }

    /**
     * @param array<string, mixed> $query
     */
    protected function criteria(array $query): ContactChangeCriteria
    {
        return (new ContactChangeCriteriaFactory($this->catalog))->fromRequest(Request::create('/api/custom/contact-attributes', 'GET', $query));
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    protected function report(array $query): array
    {
        return $this->builder->build($this->criteria($query));
    }

    /**
     * @param array<string, mixed> $values
     */
    protected function contact(int $id, ?string $added, ?string $modified, array $values = []): void
    {
        self::$db->insert(MAUTIC_TABLE_PREFIX.'leads', $values + [
            'id' => $id, 'is_published' => 1, 'points' => 0, 'date_added' => $added, 'date_modified' => $modified,
            'email' => 'contact'.$id.'@example.com', 'firstname' => 'First'.$id, 'lastname' => 'Last'.$id,
        ]);
    }

    /**
     * @param array<string, mixed> $details audit details as Mautic writes them (key => [old, new])
     */
    protected function audit(int $id, int $contactId, string $action, string $date, array $details, ?int $userId = 1, string $userName = 'Admin User'): void
    {
        self::$db->insert(MAUTIC_TABLE_PREFIX.'audit_log', [
            'id' => $id, 'user_id' => $userId ?? 0, 'user_name' => $userName, 'bundle' => 'lead', 'object' => 'lead',
            'object_id' => $contactId, 'action' => $action, 'details' => serialize($details), 'date_added' => $date, 'ip_address' => '127.0.0.1',
        ]);
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return int[]
     */
    protected function ids(array $report): array
    {
        return array_map(static fn (array $row): int => $row['contact']['id'], $report['data']);
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return array<string, mixed>
     */
    protected function row(array $report, int $id): array
    {
        foreach ($report['data'] as $row) {
            if ($row['contact']['id'] === $id) {
                return $row;
            }
        }
        self::fail('Contact '.$id.' is not in the report.');
    }
}
