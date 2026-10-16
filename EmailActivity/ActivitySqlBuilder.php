<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\EmailActivity;

use MauticPlugin\MauticStatusiBundle\DTO\EmailActivity\ReportCriteria;

/**
 * Builds the "qualifying activities" set: one UNION ALL branch per activity source, each
 * filtered on its own indexed timestamp column. Branches can return any subset of:
 *
 *   event, row_id, email_id, lead_id, ts, stat_id, ip_id, device_id, redirect_id,
 *   campaign_event_id, campaign_id
 *
 * Queries ask only for the columns they need: carrying fewer columns through the derived
 * table is what keeps the large aggregations fast.
 *
 * Only activities that can be tied to an email are included (email_id / channel_id not null).
 * Integer lists are inlined after an (int) cast; every user-provided value is a bound parameter.
 */
class ActivitySqlBuilder
{
    public const ALL_COLUMNS = [
        'event', 'row_id', 'email_id', 'lead_id', 'ts', 'stat_id', 'ip_id', 'device_id', 'redirect_id',
        'campaign_event_id', 'campaign_id',
    ];

    private string $prefix;

    public function __construct(?string $tablePrefix = null)
    {
        $this->prefix = $tablePrefix ?? (defined('MAUTIC_TABLE_PREFIX') ? (string) MAUTIC_TABLE_PREFIX : '');
    }

    public function table(string $name): string
    {
        return $this->prefix.$name;
    }

    /**
     * @param int[]|null        $emailIds restrict to these emails (page scope)
     * @param array<int[]>|null $pairs    restrict to these [emailId, leadId] pairs (contact scope)
     * @param string[]          $columns  output columns, in this order
     *
     * @return array{0: string, 1: array<string, mixed>} SQL of the UNION ALL and its parameters
     */
    public function build(ReportCriteria $criteria, ?array $emailIds = null, ?array $pairs = null, array $columns = self::ALL_COLUMNS): array
    {
        $types    = array_flip($criteria->eventTypes);
        $branches = [];

        $sendTypes = array_values(array_intersect([ActivityType::SENT, ActivityType::FAILED], $criteria->eventTypes));
        if ($sendTypes) {
            $branches[] = $this->sendsBranch($criteria, $sendTypes, $emailIds, $pairs);
        }
        if (isset($types[ActivityType::READ])) {
            $branches[] = $this->opensBranch($criteria, $emailIds, $pairs);
        }
        if (isset($types[ActivityType::CLICK])) {
            $branches[] = $this->clicksBranch($criteria, $emailIds, $pairs);
        }
        $reasons = [];
        foreach (ActivityType::DNC_REASONS as $reason => $type) {
            if (isset($types[$type])) {
                $reasons[] = $reason;
            }
        }
        // Do-not-contact entries carry no campaign reference, so they cannot match a campaign filter.
        if ($reasons && null === $criteria->campaignId) {
            $branches[] = $this->dncBranch($criteria, $reasons, $emailIds, $pairs);
        }
        if (isset($types[ActivityType::CAMPAIGN_EVENT])) {
            $branches[] = $this->campaignBranch($criteria, $emailIds, $pairs);
        }

        $sql = [];
        foreach ($branches as [$select, $from, $where]) {
            $sql[] = 'SELECT '.$this->selectList($select, $columns).' FROM '.$from.' WHERE '.implode(' AND ', $where);
        }
        if (!$sql) {
            // Valid but empty selection (e.g. only DNC types together with campaignId).
            $sql[] = 'SELECT '.implode(', ', array_map(static fn (string $c): string => 'NULL AS '.$c, $columns)).' FROM DUAL WHERE 1 = 0';
        }

        return [implode("\nUNION ALL\n", $sql), $this->parameters($criteria)];
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(ReportCriteria $criteria): array
    {
        $params = [
            'dateFrom' => $criteria->dateFromSql(),
            'dateTo'   => $criteria->dateToSql(),
        ];
        foreach (['contactId', 'emailId', 'campaignId', 'leadOwnerId', 'emailCreatedBy'] as $name) {
            if (null !== $criteria->{$name}) {
                $params[$name] = $criteria->{$name};
            }
        }
        if (null !== $criteria->search) {
            $escaped                  = addcslashes($criteria->search, '%_\\');
            $params['searchContains'] = '%'.$escaped.'%';
            $params['searchPrefix']   = $escaped.'%';
        }

        return $params;
    }

    /**
     * @param array<string, string> $select  column => expression
     * @param string[]              $columns
     */
    private function selectList(array $select, array $columns): string
    {
        return implode(', ', array_map(static fn (string $column): string => $select[$column].' AS '.$column, $columns));
    }

    /**
     * @param string[]          $sendTypes
     * @param int[]|null        $emailIds
     * @param array<int[]>|null $pairs
     *
     * @return array{0: array<string, string>, 1: string, 2: string[]}
     */
    private function sendsBranch(ReportCriteria $criteria, array $sendTypes, ?array $emailIds, ?array $pairs): array
    {
        $where = $this->commonConditions($criteria, 's.email_id', 's.lead_id', 's.date_sent', $emailIds, $pairs);
        if (1 === count($sendTypes)) {
            $where[] = 's.is_failed = '.(ActivityType::FAILED === $sendTypes[0] ? '1' : '0');
        }
        if (null !== $criteria->campaignId) {
            $where[] = $this->statFromCampaign('s');
        }

        return [[
            'event'             => 'IF(s.is_failed = 1, \''.ActivityType::FAILED.'\', \''.ActivityType::SENT.'\')',
            'row_id'            => 's.id',
            'email_id'          => 's.email_id',
            'lead_id'           => 's.lead_id',
            'ts'                => 's.date_sent',
            'stat_id'           => 's.id',
            'ip_id'             => 'NULL',
            'device_id'         => 'NULL',
            'redirect_id'       => 'NULL',
            'campaign_event_id' => 'IF(s.source = \'campaign.event\', s.source_id, NULL)',
            'campaign_id'       => 'NULL',
        ], $this->table('email_stats').' s', $where];
    }

    /**
     * @param int[]|null        $emailIds
     * @param array<int[]>|null $pairs
     *
     * @return array{0: array<string, string>, 1: string, 2: string[]}
     */
    private function opensBranch(ReportCriteria $criteria, ?array $emailIds, ?array $pairs): array
    {
        $where = $this->commonConditions($criteria, 's.email_id', 's.lead_id', 'd.date_opened', $emailIds, $pairs);
        if (null !== $criteria->campaignId) {
            $where[] = $this->statFromCampaign('s');
        }

        return [[
            'event'             => '\''.ActivityType::READ.'\'',
            'row_id'            => 'd.id',
            'email_id'          => 's.email_id',
            'lead_id'           => 's.lead_id',
            'ts'                => 'd.date_opened',
            'stat_id'           => 's.id',
            'ip_id'             => 'd.ip_id',
            'device_id'         => 'd.device_id',
            'redirect_id'       => 'NULL',
            'campaign_event_id' => 'IF(s.source = \'campaign.event\', s.source_id, NULL)',
            'campaign_id'       => 'NULL',
        ], $this->table('email_stats_devices').' d INNER JOIN '.$this->table('email_stats').' s ON s.id = d.stat_id', $where];
    }

    /**
     * @param int[]|null        $emailIds
     * @param array<int[]>|null $pairs
     *
     * @return array{0: array<string, string>, 1: string, 2: string[]}
     */
    private function clicksBranch(ReportCriteria $criteria, ?array $emailIds, ?array $pairs): array
    {
        $where = $this->commonConditions($criteria, 'h.email_id', 'h.lead_id', 'h.date_hit', $emailIds, $pairs);
        if (null !== $criteria->campaignId) {
            // page_hits has no stat column: a click counts for a campaign when this contact was sent
            // this email by that campaign. The exact send is resolved from the tracked link (ct.stat) later.
            $where[] = 'EXISTS (SELECT 1 FROM '.$this->table('email_stats').' cs'
                .' WHERE cs.email_id = h.email_id AND cs.lead_id = h.lead_id AND '.$this->statFromCampaign('cs').')';
        }

        return [[
            'event'             => '\''.ActivityType::CLICK.'\'',
            'row_id'            => 'h.id',
            'email_id'          => 'h.email_id',
            'lead_id'           => 'h.lead_id',
            'ts'                => 'h.date_hit',
            'stat_id'           => 'NULL',
            'ip_id'             => 'h.ip_id',
            'device_id'         => 'h.device_id',
            'redirect_id'       => 'h.redirect_id',
            'campaign_event_id' => 'NULL',
            'campaign_id'       => 'NULL',
        ], $this->table('page_hits').' h', $where];
    }

    /**
     * @param int[]             $reasons
     * @param int[]|null        $emailIds
     * @param array<int[]>|null $pairs
     *
     * @return array{0: array<string, string>, 1: string, 2: string[]}
     */
    private function dncBranch(ReportCriteria $criteria, array $reasons, ?array $emailIds, ?array $pairs): array
    {
        $where   = $this->commonConditions($criteria, 'n.channel_id', 'n.lead_id', 'n.date_added', $emailIds, $pairs);
        $where[] = 'n.channel = \'email\'';
        $where[] = 'n.reason IN ('.implode(', ', array_map('intval', $reasons)).')';

        $case = 'CASE n.reason';
        foreach (ActivityType::DNC_REASONS as $reason => $type) {
            $case .= ' WHEN '.$reason.' THEN \''.$type.'\'';
        }
        $case .= ' END';

        return [[
            'event'             => $case,
            'row_id'            => 'n.id',
            'email_id'          => 'n.channel_id',
            'lead_id'           => 'n.lead_id',
            'ts'                => 'n.date_added',
            'stat_id'           => 'NULL',
            'ip_id'             => 'NULL',
            'device_id'         => 'NULL',
            'redirect_id'       => 'NULL',
            'campaign_event_id' => 'NULL',
            'campaign_id'       => 'NULL',
        ], $this->table('lead_donotcontact').' n', $where];
    }

    /**
     * @param int[]|null        $emailIds
     * @param array<int[]>|null $pairs
     *
     * @return array{0: array<string, string>, 1: string, 2: string[]}
     */
    private function campaignBranch(ReportCriteria $criteria, ?array $emailIds, ?array $pairs): array
    {
        $where = $this->commonConditions($criteria, 'l.channel_id', 'l.lead_id', 'l.date_triggered', $emailIds, $pairs);
        if (null === $emailIds && null === $pairs && null === $criteria->emailId && null === $criteria->contactId) {
            // Unscoped (paging) query: almost every log row is channel 'email', so an index lookup on
            // (channel, channel_id, lead_id) reads the whole table. Keep the comparison off that index
            // so the date_triggered range is used instead (measured: 1.7 s -> ~0.15 s for 7 days on 700k logs).
            $where[] = 'TRIM(l.channel) = \'email\'';
        } else {
            // Scoped to emails/pairs/a contact: (channel, channel_id, lead_id) is the best index here.
            $where[] = 'l.channel = \'email\'';
        }
        // Only executed steps; scheduled rows describe future work, not activity.
        $where[] = 'l.is_scheduled = 0';
        if (null !== $criteria->campaignId) {
            $where[] = 'l.campaign_id = :campaignId';
        }

        return [[
            'event'             => '\''.ActivityType::CAMPAIGN_EVENT.'\'',
            'row_id'            => 'l.id',
            'email_id'          => 'l.channel_id',
            'lead_id'           => 'l.lead_id',
            'ts'                => 'l.date_triggered',
            'stat_id'           => 'NULL',
            'ip_id'             => 'l.ip_id',
            'device_id'         => 'NULL',
            'redirect_id'       => 'NULL',
            'campaign_event_id' => 'l.event_id',
            'campaign_id'       => 'l.campaign_id',
        ], $this->table('campaign_lead_event_log').' l', $where];
    }

    private function statFromCampaign(string $alias): string
    {
        return $alias.'.source = \'campaign.event\' AND '.$alias.'.source_id IN'
            .' (SELECT ce.id FROM '.$this->table('campaign_events').' ce WHERE ce.campaign_id = :campaignId)';
    }

    /**
     * @param int[]|null        $emailIds
     * @param array<int[]>|null $pairs
     *
     * @return string[]
     */
    private function commonConditions(
        ReportCriteria $criteria,
        string $emailColumn,
        string $leadColumn,
        string $dateColumn,
        ?array $emailIds,
        ?array $pairs,
    ): array {
        $where = [
            $dateColumn.' BETWEEN :dateFrom AND :dateTo',
            $emailColumn.' IS NOT NULL',
            $leadColumn.' IS NOT NULL',
        ];

        if (null !== $criteria->contactId) {
            $where[] = $leadColumn.' = :contactId';
        }
        if (null !== $criteria->emailId) {
            $where[] = $emailColumn.' = :emailId';
        }
        if (null !== $emailIds) {
            $where[] = $emailIds ? $emailColumn.' IN ('.$this->intList($emailIds).')' : '1 = 0';
        }
        if (null !== $pairs) {
            if (!$pairs) {
                $where[] = '1 = 0';
            } else {
                $leadIds = array_unique(array_map(static fn (array $pair): int => (int) $pair[1], $pairs));
                $where[] = $leadColumn.' IN ('.$this->intList($leadIds).')';
                $where[] = '('.$emailColumn.', '.$leadColumn.') IN ('.implode(', ', array_map(
                    static fn (array $pair): string => '('.(int) $pair[0].', '.(int) $pair[1].')',
                    $pairs
                )).')';
            }
        }
        if (null !== $criteria->leadOwnerId) {
            $where[] = $leadColumn.' IN (SELECT ol.id FROM '.$this->table('leads').' ol WHERE ol.owner_id = :leadOwnerId)';
        }
        if (null !== $criteria->emailCreatedBy) {
            $where[] = $emailColumn.' IN (SELECT oe.id FROM '.$this->table('emails').' oe WHERE oe.created_by = :emailCreatedBy)';
        }
        if (null !== $criteria->search) {
            $where[] = '('.$emailColumn.' IN (SELECT se.id FROM '.$this->table('emails').' se'
                .' WHERE se.name LIKE :searchContains OR se.subject LIKE :searchContains)'
                .' OR '.$leadColumn.' IN (SELECT sl.id FROM '.$this->table('leads').' sl WHERE sl.email LIKE :searchPrefix))';
        }

        return $where;
    }

    /**
     * @param int[] $ids
     */
    private function intList(array $ids): string
    {
        return implode(', ', array_map('intval', array_values($ids)));
    }
}
