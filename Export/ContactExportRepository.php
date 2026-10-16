<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Export;

use Doctrine\DBAL\Connection;

/**
 * Contacts that changed in a date range, for the Statusi contact attribute export, paged by
 * contact id. A contact qualifies when it was created, updated (audit log or date_modified) or
 * got an email do-not-contact entry (unsubscribe / bounce) inside the range.
 */
class ContactExportRepository
{
    private string $prefix;

    public function __construct(private Connection $connection, ?string $tablePrefix = null)
    {
        $this->prefix = $tablePrefix ?? (defined('MAUTIC_TABLE_PREFIX') ? (string) MAUTIC_TABLE_PREFIX : '');
    }

    /**
     * Up to $limit + 1 qualifying contacts with an id above $afterId, with their latest change time in the range.
     *
     * @return array<int, array{id: int|string, changed_ts: string}>
     */
    public function page(string $dateFrom, string $dateTo, int $afterId, int $limit): array
    {
        $branches = [
            'SELECT c.id, c.date_added AS ts FROM '.$this->table('leads').' c WHERE c.date_added BETWEEN :dateFrom AND :dateTo AND c.id > :afterId',
            'SELECT c.id, c.date_modified AS ts FROM '.$this->table('leads').' c WHERE c.date_modified BETWEEN :dateFrom AND :dateTo AND c.id > :afterId',
            'SELECT a.object_id AS id, a.date_added AS ts FROM '.$this->table('audit_log').' a'
                .' WHERE a.date_added BETWEEN :dateFrom AND :dateTo AND a.bundle = \'lead\' AND a.object = \'lead\''
                .' AND a.action = \'update\' AND a.object_id > :afterId',
            'SELECT n.lead_id AS id, n.date_added AS ts FROM '.$this->table('lead_donotcontact').' n'
                .' WHERE n.date_added BETWEEN :dateFrom AND :dateTo AND n.channel = \'email\' AND n.lead_id > :afterId',
        ];

        return $this->connection->executeQuery(
            'SELECT s.id, MAX(s.ts) AS changed_ts FROM ('.implode("\nUNION ALL\n", $branches).') s'
            // Audit rows of deleted contacts have no contact left to export
            .' INNER JOIN '.$this->table('leads').' l ON l.id = s.id'
            .' GROUP BY s.id ORDER BY s.id LIMIT '.($limit + 1),
            ['dateFrom' => $dateFrom, 'dateTo' => $dateTo, 'afterId' => $afterId]
        )->fetchAllAssociative();
    }

    /**
     * @param int[]    $ids
     * @param string[] $fieldAliases published contact field columns (ContactFieldCatalog)
     *
     * @return array<int, array<string, mixed>> contact id => row
     */
    public function contacts(array $ids, array $fieldAliases): array
    {
        $columns = array_values(array_unique(array_merge(['id', 'email', 'date_added', 'date_modified'], $fieldAliases)));
        $select  = implode(', ', array_map(fn (string $c): string => $this->connection->quoteIdentifier($c), $columns));

        $rows = [];
        foreach ($this->fetchIn('SELECT '.$select.' FROM '.$this->table('leads').' WHERE id IN (%s)', $ids) as $row) {
            $rows[(int) $row['id']] = $row;
        }

        return $rows;
    }

    /**
     * Current email do-not-contact entries (Mautic deletes them when a contact resubscribes).
     *
     * @param int[] $ids
     *
     * @return array<int, array<int, array{reason: int, date_added: string, comments: ?string}>> contact id => entries
     */
    public function doNotContacts(array $ids): array
    {
        $entries = [];
        foreach ($this->fetchIn(
            'SELECT lead_id, reason, date_added, comments FROM '.$this->table('lead_donotcontact')
            .' WHERE channel = \'email\' AND lead_id IN (%s) ORDER BY lead_id, date_added',
            $ids
        ) as $row) {
            $entries[(int) $row['lead_id']][] = [
                'reason'     => (int) $row['reason'],
                'date_added' => (string) $row['date_added'],
                'comments'   => null !== $row['comments'] ? (string) $row['comments'] : null,
            ];
        }

        return $entries;
    }

    /**
     * Most recently recorded IP address of each contact.
     *
     * @param int[] $ids
     *
     * @return array<int, string> contact id => IP address
     */
    public function lastIpAddresses(array $ids): array
    {
        $ips = [];
        foreach ($this->fetchIn(
            'SELECT x.lead_id, i.ip_address FROM ('
            .'SELECT lead_id, MAX(ip_id) AS ip_id FROM '.$this->table('lead_ips_xref').' WHERE lead_id IN (%s) GROUP BY lead_id'
            .') x INNER JOIN '.$this->table('ip_addresses').' i ON i.id = x.ip_id',
            $ids
        ) as $row) {
            $ips[(int) $row['lead_id']] = (string) $row['ip_address'];
        }

        return $ips;
    }

    private function table(string $name): string
    {
        return $this->prefix.$name;
    }

    /**
     * @param int[] $ids
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchIn(string $sql, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }

        return $this->connection->executeQuery(sprintf($sql, implode(', ', $ids)))->fetchAllAssociative();
    }
}
