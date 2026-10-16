<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\EmailActivity;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use MauticPlugin\MauticStatusiBundle\DTO\EmailActivity\ReportCriteria;

/**
 * All database access of the email activity report. Every method is one query; lookups are
 * batched by ID lists, so the number of queries per request does not grow with the data.
 */
class EmailActivityRepository
{
    /**
     * In-memory limit for the internal temporary table of the large aggregations (MariaDB only,
     * per statement). With the 16 MB server default a 30-day range spills to disk: measured
     * 14.3 s -> 4.7 s for 2.5M activity rows.
     */
    private const AGGREGATION_TMP_TABLE_BYTES = 67108864;

    private int $queryCount = 0;

    private float $queryTime = 0.0;

    /** @var array<int, array{query: string, ms: float, rows: int}> */
    private array $queryLog = [];

    public function __construct(
        private Connection $connection,
        private ActivitySqlBuilder $sql,
    ) {
    }

    public function getQueryCount(): int
    {
        return $this->queryCount;
    }

    /**
     * Per query timings (repository method name, milliseconds, rows), for profiling.
     *
     * @return array<int, array{query: string, ms: float, rows: int}>
     */
    public function getQueryLog(): array
    {
        return $this->queryLog;
    }

    /**
     * Seconds spent waiting for the database (execution and fetching) so far.
     */
    public function getQueryTime(): float
    {
        return $this->queryTime;
    }

    /**
     * One page of email groups, with the total number of groups (window count).
     *
     * @return array<int, array{email_id: int, last_ts: string, total: int, total_groups: int}>
     */
    public function findEmailGroups(ReportCriteria $criteria): array
    {
        [$union, $params] = $this->sql->build($criteria, null, null, ['email_id', 'ts']);

        $direction = 'ASC' === $criteria->order ? 'ASC' : 'DESC';
        $sortExpr  = match ($criteria->sortBy) {
            ReportCriteria::SORT_TOTAL_ACTIVITIES => 'total',
            ReportCriteria::SORT_EMAIL_ID         => 'g.email_id',
            ReportCriteria::SORT_EMAIL_NAME       => 'e.name',
            default                               => 'last_ts',
        };

        $sql = 'SELECT g.email_id, g.last_ts, g.total, COUNT(*) OVER () AS total_groups'
            .' FROM (SELECT a.email_id, MAX(a.ts) AS last_ts, COUNT(*) AS total FROM ('.$union.') a GROUP BY a.email_id) g'
            .(ReportCriteria::SORT_EMAIL_NAME === $criteria->sortBy ? ' LEFT JOIN '.$this->sql->table('emails').' e ON e.id = g.email_id' : '')
            .' ORDER BY '.$sortExpr.' '.$direction.', g.email_id '.$direction
            .' LIMIT '.(int) $criteria->limit.' OFFSET '.(int) $criteria->offset();

        return $this->fetch($this->largeAggregation($sql), $params);
    }

    public function countEmailGroups(ReportCriteria $criteria): int
    {
        [$union, $params] = $this->sql->build($criteria, null, null, ['email_id']);

        return (int) $this->fetchOne($this->largeAggregation('SELECT COUNT(DISTINCT a.email_id) FROM ('.$union.') a'), $params);
    }

    /**
     * Counts for the page's emails in one pass, using WITH ROLLUP:
     *  - event IS NULL                   -> per email: total activities and distinct contacts
     *  - event set, step IS NULL         -> per email and activity type: count
     *  - event set, step > 0             -> per email, type and campaign step: count
     * Activities without a campaign step use step 0, so they are not confused with rollup rows.
     *
     * @param int[] $emailIds
     *
     * @return array<int, array<string, mixed>>
     */
    public function pageSummary(ReportCriteria $criteria, array $emailIds): array
    {
        [$union, $params] = $this->sql->build($criteria, $emailIds, null, ['event', 'email_id', 'lead_id', 'campaign_event_id']);

        return $this->fetch($this->largeAggregation(
            'SELECT a.email_id, a.event, COALESCE(a.campaign_event_id, 0) AS step, COUNT(*) AS cnt, COUNT(DISTINCT a.lead_id) AS contacts'
            .' FROM ('.$union.') a GROUP BY a.email_id, a.event, COALESCE(a.campaign_event_id, 0) WITH ROLLUP'
        ), $params);
    }

    /**
     * The requested page of contacts inside each email group, ranked by latest activity.
     *
     * @param int[] $emailIds
     *
     * @return array<int, array<string, mixed>>
     */
    public function contactPage(ReportCriteria $criteria, array $emailIds): array
    {
        [$union, $params] = $this->sql->build($criteria, $emailIds, null, ['email_id', 'lead_id', 'ts']);
        $direction        = 'ASC' === $criteria->order ? 'ASC' : 'DESC';
        $first            = ($criteria->contactPage - 1) * $criteria->contactLimit + 1;
        $last             = $first + $criteria->contactLimit - 1;

        return $this->fetch($this->largeAggregation(
            'SELECT x.email_id, x.lead_id, x.last_ts, x.cnt FROM ('
            .'SELECT a.email_id, a.lead_id, MAX(a.ts) AS last_ts, COUNT(*) AS cnt,'
            .' ROW_NUMBER() OVER (PARTITION BY a.email_id ORDER BY MAX(a.ts) '.$direction.', a.lead_id '.$direction.') AS rn'
            .' FROM ('.$union.') a GROUP BY a.email_id, a.lead_id'
            .') x WHERE x.rn BETWEEN '.$first.' AND '.$last
            .' ORDER BY x.email_id, x.rn'
        ), $params);
    }

    /**
     * Activities of the given [emailId, leadId] pairs, at most activityLimit per pair,
     * with the full count per pair so truncation is visible.
     *
     * @param array<int[]> $pairs
     *
     * @return array<int, array<string, mixed>>
     */
    public function activities(ReportCriteria $criteria, array $pairs): array
    {
        if (!$pairs) {
            return [];
        }

        [$union, $params] = $this->sql->build($criteria, null, $pairs);
        $direction        = 'ASC' === $criteria->order ? 'ASC' : 'DESC';

        return $this->fetch(
            'SELECT y.* FROM ('
            .'SELECT a.*,'
            .' ROW_NUMBER() OVER (PARTITION BY a.email_id, a.lead_id ORDER BY a.ts '.$direction.', a.event, a.row_id '.$direction.') AS rn,'
            .' COUNT(*) OVER (PARTITION BY a.email_id, a.lead_id) AS pair_total'
            .' FROM ('.$union.') a'
            .') y WHERE y.rn <= '.(int) $criteria->activityLimit
            .' ORDER BY y.email_id, y.lead_id, y.rn',
            $params
        );
    }

    /**
     * Activities that happened in the period but cannot be tied to an email (excluded from groups).
     *
     * @return array<string, int>
     */
    public function unassignedCounts(ReportCriteria $criteria): array
    {
        $params     = ['dateFrom' => $criteria->dateFromSql(), 'dateTo' => $criteria->dateToSql()];
        $statLead   = '';
        $dncLead    = '';
        if (null !== $criteria->contactId) {
            $params['contactId'] = $criteria->contactId;
            $statLead            = ' AND s.lead_id = :contactId';
            $dncLead             = ' AND n.lead_id = :contactId';
        }
        if (null !== $criteria->leadOwnerId) {
            $params['leadOwnerId'] = $criteria->leadOwnerId;
            $owned                 = ' IN (SELECT ol.id FROM '.$this->sql->table('leads').' ol WHERE ol.owner_id = :leadOwnerId)';
            $statLead .= ' AND s.lead_id'.$owned;
            $dncLead .= ' AND n.lead_id'.$owned;
        }

        $row = $this->fetch(
            'SELECT'
            .' (SELECT COUNT(*) FROM '.$this->sql->table('email_stats').' s'
            .'   WHERE s.date_sent BETWEEN :dateFrom AND :dateTo AND s.email_id IS NULL'.$statLead.') AS sends_without_email,'
            .' (SELECT COUNT(*) FROM '.$this->sql->table('lead_donotcontact').' n'
            .'   WHERE n.date_added BETWEEN :dateFrom AND :dateTo AND n.channel = \'email\' AND n.channel_id IS NULL'.$dncLead.') AS dnc_without_email',
            $params
        )[0] ?? [];

        return [
            'sendsWithoutEmail' => (int) ($row['sends_without_email'] ?? 0),
            'dncWithoutEmail'   => (int) ($row['dnc_without_email'] ?? 0),
        ];
    }

    // ---- batched lookups -------------------------------------------------------------------

    /**
     * @param int[] $ids
     *
     * @return array<int, array<string, mixed>>
     */
    public function emails(array $ids): array
    {
        return $this->byId(
            'SELECT id, name, subject, description, email_type, lang, is_published, publish_up, publish_down,'
            .' date_added, date_modified, from_address, from_name, sent_count, read_count'
            .' FROM '.$this->sql->table('emails').' WHERE id IN (%s)',
            $ids
        );
    }

    /**
     * @param int[] $ids
     *
     * @return array<int, array<string, mixed>>
     */
    public function contacts(array $ids): array
    {
        return $this->byId(
            'SELECT id, email, firstname, lastname, company, country, state, city, owner_id'
            .' FROM '.$this->sql->table('leads').' WHERE id IN (%s)',
            $ids
        );
    }

    /**
     * Every send of the given emails to the given contacts (used for open/click context and
     * to resolve which send a click came from).
     *
     * @param int[] $emailIds
     * @param int[] $leadIds
     *
     * @return array<int, array<string, mixed>>
     */
    public function stats(array $emailIds, array $leadIds): array
    {
        if (!$emailIds || !$leadIds) {
            return [];
        }

        return $this->fetch(
            'SELECT id, email_id, lead_id, date_sent, is_failed, is_read, date_read, open_count, last_opened,'
            .' source, source_id, tracking_hash, retry_count'
            .' FROM '.$this->sql->table('email_stats')
            .' WHERE email_id IN ('.$this->ints($emailIds).') AND lead_id IN ('.$this->ints($leadIds).')'
        );
    }

    /**
     * @param int[] $ids
     *
     * @return array<int, array<string, mixed>>
     */
    public function clicks(array $ids): array
    {
        return $this->byId(
            'SELECT id, url, url_title, referer, user_agent, country, region, city, isp, organization, source, source_id, query'
            .' FROM '.$this->sql->table('page_hits').' WHERE id IN (%s)',
            $ids
        );
    }

    /**
     * @param int[] $ids
     *
     * @return array<int, array<string, mixed>>
     */
    public function doNotContacts(array $ids): array
    {
        return $this->byId(
            'SELECT id, reason, comments FROM '.$this->sql->table('lead_donotcontact').' WHERE id IN (%s)',
            $ids
        );
    }

    /**
     * Campaign steps with their campaign.
     *
     * @param int[] $ids
     *
     * @return array<int, array<string, mixed>>
     */
    public function campaignEvents(array $ids): array
    {
        return $this->byId(
            'SELECT ce.id, ce.name, ce.type, ce.event_type, ce.campaign_id,'
            .' c.name AS campaign_name, c.date_added AS campaign_date_added, c.is_published AS campaign_is_published'
            .' FROM '.$this->sql->table('campaign_events').' ce'
            .' LEFT JOIN '.$this->sql->table('campaigns').' c ON c.id = ce.campaign_id'
            .' WHERE ce.id IN (%s)',
            $ids
        );
    }

    /**
     * @param int[] $ids
     *
     * @return array<int, array<string, mixed>>
     */
    public function campaigns(array $ids): array
    {
        return $this->byId(
            'SELECT id, name, is_published, date_added FROM '.$this->sql->table('campaigns').' WHERE id IN (%s)',
            $ids
        );
    }

    /**
     * Executed campaign step logs of the given contacts and steps (when a send was triggered),
     * with the failure reason when the step failed.
     *
     * @param int[] $leadIds
     * @param int[] $eventIds
     *
     * @return array<int, array<string, mixed>>
     */
    public function campaignLogs(array $leadIds, array $eventIds): array
    {
        if (!$leadIds || !$eventIds) {
            return [];
        }

        return $this->fetch(
            'SELECT l.id, l.lead_id, l.event_id, l.campaign_id, l.date_triggered, l.rotation, f.reason AS fail_reason'
            .' FROM '.$this->sql->table('campaign_lead_event_log').' l'
            .' LEFT JOIN '.$this->sql->table('campaign_lead_event_failed_log').' f ON f.log_id = l.id'
            .' WHERE l.lead_id IN ('.$this->ints($leadIds).') AND l.event_id IN ('.$this->ints($eventIds).') AND l.is_scheduled = 0'
        );
    }

    /**
     * @param int[] $leadIds
     * @param int[] $campaignIds
     *
     * @return array<int, array<string, mixed>>
     */
    public function campaignMemberships(array $leadIds, array $campaignIds): array
    {
        if (!$leadIds || !$campaignIds) {
            return [];
        }

        return $this->fetch(
            'SELECT campaign_id, lead_id, date_added, manually_added, manually_removed, date_last_exited, rotation'
            .' FROM '.$this->sql->table('campaign_leads')
            .' WHERE lead_id IN ('.$this->ints($leadIds).') AND campaign_id IN ('.$this->ints($campaignIds).')'
        );
    }

    /**
     * @param int[] $ids
     *
     * @return array<int, array<string, mixed>>
     */
    public function ipAddresses(array $ids): array
    {
        return $this->byId('SELECT id, ip_address, ip_details FROM '.$this->sql->table('ip_addresses').' WHERE id IN (%s)', $ids);
    }

    /**
     * @param int[] $ids
     *
     * @return array<int, array<string, mixed>>
     */
    public function devices(array $ids): array
    {
        return $this->byId(
            'SELECT id, client_info, device, device_os_name, device_os_version, device_brand, device_model'
            .' FROM '.$this->sql->table('lead_devices').' WHERE id IN (%s)',
            $ids
        );
    }

    /**
     * @param int[] $ids
     *
     * @return array<int, array<string, mixed>>
     */
    public function redirects(array $ids): array
    {
        return $this->byId('SELECT id, redirect_id, url FROM '.$this->sql->table('page_redirects').' WHERE id IN (%s)', $ids);
    }

    // ---- helpers ---------------------------------------------------------------------------

    /**
     * @param int[] $ids
     *
     * @return array<int, array<string, mixed>> keyed by id
     */
    private function byId(string $sqlWithPlaceholder, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }

        $rows = [];
        foreach ($this->fetch(sprintf($sqlWithPlaceholder, $this->ints($ids))) as $row) {
            $rows[(int) $row['id']] = $row;
        }

        return $rows;
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
        $this->record($start, count($rows));

        return $rows;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function fetchOne(string $sql, array $params = []): mixed
    {
        ++$this->queryCount;
        $start = microtime(true);
        $value = $this->connection->executeQuery($sql, $params)->fetchOne();
        $this->record($start, 1);

        return $value;
    }

    private function largeAggregation(string $sql): string
    {
        if (!$this->connection->getDatabasePlatform() instanceof MariaDBPlatform) {
            return $sql;
        }

        return 'SET STATEMENT tmp_table_size = '.self::AGGREGATION_TMP_TABLE_BYTES
            .', max_heap_table_size = '.self::AGGREGATION_TMP_TABLE_BYTES.' FOR '.$sql;
    }

    private function record(float $start, int $rows): void
    {
        $elapsed = microtime(true) - $start;
        $this->queryTime += $elapsed;

        $caller           = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);
        $method           = 'byId' === ($caller[2]['function'] ?? '') ? ($caller[3]['function'] ?? '?') : ($caller[2]['function'] ?? '?');
        $this->queryLog[] = ['query' => $method, 'ms' => round($elapsed * 1000, 1), 'rows' => $rows];
    }

    /**
     * @param int[] $ids
     */
    private function ints(array $ids): string
    {
        return implode(', ', array_map('intval', array_values(array_unique($ids))));
    }
}
