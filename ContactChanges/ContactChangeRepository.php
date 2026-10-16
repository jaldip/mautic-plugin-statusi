<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\ContactChanges;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use MauticPlugin\MauticStatusiBundle\Api\QueryParameters;
use MauticPlugin\MauticStatusiBundle\DTO\ContactChanges\ContactChangeCriteria;

/**
 * Database access of the contact attribute change report. Every method is one query.
 *
 * A contact qualifies through any of these signals inside the range:
 *  - created:              leads.date_added
 *  - updated (audit log):  audit_log rows bundle = lead, object = lead, action = update
 *  - updated (timestamp):  leads.date_modified, only when later than date_added
 *    (Mautic sets date_modified = date_added at creation and normally does not refresh it afterwards)
 */
class ContactChangeRepository
{
    /**
     * In-memory limit for the internal temporary table of the page query (MariaDB only, per statement).
     */
    private const AGGREGATION_TMP_TABLE_BYTES = 67108864;

    private int $queryCount = 0;

    private float $queryTime = 0.0;

    private string $prefix;

    public function __construct(private Connection $connection, ?string $tablePrefix = null)
    {
        $this->prefix = $tablePrefix ?? (defined('MAUTIC_TABLE_PREFIX') ? (string) MAUTIC_TABLE_PREFIX : '');
    }

    public function getQueryCount(): int
    {
        return $this->queryCount;
    }

    public function getQueryTime(): float
    {
        return $this->queryTime;
    }

    /**
     * One page of qualifying contacts (deduplicated), with the exact total as a window count.
     *
     * @return array<int, array<string, mixed>> id, created_ts, audit_ts, modified_ts, matched_ts, total
     */
    public function findPage(ContactChangeCriteria $criteria): array
    {
        [$signals, $params] = $this->signals($criteria);
        $where              = ['1 = 1'];

        if (null !== $criteria->leadOwnerId) {
            $where[]               = 'l.owner_id = :leadOwnerId';
            $params['leadOwnerId'] = $criteria->leadOwnerId;
        }
        if (null !== $criteria->search) {
            $patterns         = QueryParameters::likePatterns($criteria->search);
            $params['prefix'] = $patterns['prefix'];
            $where[]          = '(l.email LIKE :prefix OR l.firstname LIKE :prefix OR l.lastname LIKE :prefix OR l.company LIKE :prefix)';
        }

        $direction = 'ASC' === $criteria->order ? 'ASC' : 'DESC';
        $sort      = match ($criteria->sortBy) {
            ContactChangeCriteria::SORT_DATE_ADDED    => 'date_added',
            ContactChangeCriteria::SORT_DATE_MODIFIED => 'date_modified',
            ContactChangeCriteria::SORT_ID            => 'id',
            default                                   => 'matched_ts',
        };
        // NULL timestamps sort as the oldest value in both directions; the contact ID breaks ties.
        $orderBy = 'id' === $sort
            ? 'g.id '.$direction
            : 'COALESCE(g.'.$sort.', \'1000-01-01 00:00:00\') '.$direction.', g.id '.$direction;

        $sql = 'SELECT g.*, COUNT(*) OVER () AS total FROM ('
            .'SELECT s.id, MAX(s.created_ts) AS created_ts, MAX(s.audit_ts) AS audit_ts, MAX(s.modified_ts) AS modified_ts,'
            .' GREATEST(COALESCE(MAX(s.created_ts), \'1000-01-01 00:00:00\'), COALESCE(MAX(s.audit_ts), \'1000-01-01 00:00:00\'),'
            .' COALESCE(MAX(s.modified_ts), \'1000-01-01 00:00:00\')) AS matched_ts,'
            .' MAX(l.date_added) AS date_added, MAX(l.date_modified) AS date_modified'
            .' FROM ('.$signals.') s'
            .' INNER JOIN '.$this->prefix.'leads l ON l.id = s.id'
            .' WHERE '.implode(' AND ', $where)
            .' GROUP BY s.id'
            .') g ORDER BY '.$orderBy
            .' LIMIT '.(int) $criteria->limit.' OFFSET '.(int) $criteria->offset();

        return $this->fetch($this->largeAggregation($sql), $params);
    }

    /**
     * Exact total when the requested page is past the end (findPage returned no rows).
     */
    public function countContacts(ContactChangeCriteria $criteria): int
    {
        $rows = $this->findPage(new ContactChangeCriteria(...array_merge(get_object_vars($criteria), ['page' => 1, 'limit' => 1])));

        return (int) ($rows[0]['total'] ?? 0);
    }

    /**
     * @param int[]    $ids
     * @param string[] $fieldAliases columns to read (validated against ContactFieldCatalog)
     *
     * @return array<int, array<string, mixed>> keyed by contact id
     */
    public function contacts(array $ids, array $fieldAliases): array
    {
        if (!$ids) {
            return [];
        }

        $columns = ['id', 'owner_id', 'stage_id', 'date_added', 'date_modified', 'date_identified', 'last_active'];
        foreach ($fieldAliases as $alias) {
            if (!in_array($alias, $columns, true)) {
                $columns[] = $alias;
            }
        }
        $select = implode(', ', array_map(fn (string $c): string => $this->connection->quoteIdentifier($c), $columns));

        $rows = [];
        foreach ($this->fetch('SELECT '.$select.' FROM '.$this->prefix.'leads WHERE id IN ('.$this->ints($ids).')') as $row) {
            $rows[(int) $row['id']] = $row;
        }

        return $rows;
    }

    /**
     * @param int[] $ids
     *
     * @return array<int, string> user id => display name
     */
    public function userNames(array $ids): array
    {
        if (!$ids) {
            return [];
        }

        $names = [];
        foreach ($this->fetch('SELECT id, first_name, last_name, username FROM '.$this->prefix.'users WHERE id IN ('.$this->ints($ids).')') as $row) {
            $name                     = trim($row['first_name'].' '.$row['last_name']);
            $names[(int) $row['id']] = '' !== $name ? $name : (string) $row['username'];
        }

        return $names;
    }

    /**
     * @param int[] $ids
     *
     * @return array<int, string> stage id => name
     */
    public function stageNames(array $ids): array
    {
        if (!$ids) {
            return [];
        }

        $names = [];
        foreach ($this->fetch('SELECT id, name FROM '.$this->prefix.'stages WHERE id IN ('.$this->ints($ids).')') as $row) {
            $names[(int) $row['id']] = (string) $row['name'];
        }

        return $names;
    }

    /**
     * Audit log entries (create / update) of the given contacts inside the range, newest first,
     * at most $limit per contact, with the full count per contact.
     *
     * @param int[] $ids
     *
     * @return array<int, array<string, mixed>>
     */
    public function history(ContactChangeCriteria $criteria, array $ids, int $limit): array
    {
        if (!$ids) {
            return [];
        }

        return $this->fetch(
            'SELECT h.* FROM ('
            .'SELECT a.id, a.object_id, a.action, a.date_added, a.user_id, a.user_name, a.details,'
            .' ROW_NUMBER() OVER (PARTITION BY a.object_id ORDER BY a.date_added DESC, a.id DESC) AS rn,'
            .' COUNT(*) OVER (PARTITION BY a.object_id) AS entries'
            .' FROM '.$this->prefix.'audit_log a'
            .' WHERE a.bundle = \'lead\' AND a.object = \'lead\' AND a.action IN (\'create\', \'update\')'
            .' AND a.object_id IN ('.$this->ints($ids).') AND a.date_added BETWEEN :dateFrom AND :dateTo'
            .') h WHERE h.rn <= '.(int) $limit.' ORDER BY h.object_id, h.rn',
            ['dateFrom' => $criteria->dateFromSql(), 'dateTo' => $criteria->dateToSql()]
        );
    }

    /**
     * UNION ALL of the qualifying signals: id, created_ts, audit_ts, modified_ts.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function signals(ContactChangeCriteria $criteria): array
    {
        $params = ['dateFrom' => $criteria->dateFromSql(), 'dateTo' => $criteria->dateToSql()];
        $leadId = '';
        $auditId = '';
        if (null !== $criteria->contactId) {
            $params['contactId'] = $criteria->contactId;
            $leadId              = ' AND c.id = :contactId';
            $auditId             = ' AND a.object_id = :contactId';
        }

        $branches = [];
        if ($criteria->includesCreated()) {
            $branches[] = 'SELECT c.id, c.date_added AS created_ts, NULL AS audit_ts, NULL AS modified_ts'
                .' FROM '.$this->prefix.'leads c WHERE c.date_added BETWEEN :dateFrom AND :dateTo'.$leadId;
        }
        if ($criteria->includesUpdated()) {
            // No GROUP BY in the branch: the outer query groups per contact. Grouping here made MariaDB walk the
            // (object, object_id) index over every contact audit row (1.65M rows, ~4.3 s) instead of the date_added range.
            $branches[] = 'SELECT a.object_id AS id, NULL AS created_ts, a.date_added AS audit_ts, NULL AS modified_ts'
                .' FROM '.$this->prefix.'audit_log a'
                .' WHERE a.date_added BETWEEN :dateFrom AND :dateTo AND a.bundle = \'lead\' AND a.object = \'lead\' AND a.action = \'update\''.$auditId;
            $branches[] = 'SELECT c.id, NULL AS created_ts, NULL AS audit_ts, c.date_modified AS modified_ts'
                .' FROM '.$this->prefix.'leads c WHERE c.date_modified BETWEEN :dateFrom AND :dateTo'
                .' AND (c.date_added IS NULL OR c.date_modified > c.date_added)'.$leadId;
        }

        return [implode("\nUNION ALL\n", $branches), $params];
    }

    protected function largeAggregation(string $sql): string
    {
        if (!$this->connection->getDatabasePlatform() instanceof MariaDBPlatform) {
            return $sql;
        }

        return 'SET STATEMENT tmp_table_size = '.self::AGGREGATION_TMP_TABLE_BYTES
            .', max_heap_table_size = '.self::AGGREGATION_TMP_TABLE_BYTES.' FOR '.$sql;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetch(string $sql, array $params = []): array
    {
        ++$this->queryCount;
        $start = microtime(true);
        $rows  = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();
        $this->queryTime += microtime(true) - $start;

        return $rows;
    }

    /**
     * @param int[] $ids
     */
    private function ints(array $ids): string
    {
        return implode(', ', array_map('intval', array_values(array_unique($ids))));
    }
}
