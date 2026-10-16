<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Export;

use Doctrine\DBAL\Connection;

/**
 * Raw email activity rows for the Statusi export, ordered by (timestamp, source, row id) and paged
 * with a keyset cursor, so rows added while a client is paging never shift or repeat pages.
 *
 * Sources (one UNION ALL branch each, filtered on its own indexed timestamp column):
 *   1 send / failed send -> email_stats.date_sent
 *   2 open               -> email_stats_devices.date_opened
 *   3 click              -> page_hits.date_hit (email clicks only)
 *   4 unsubscribe/bounce -> lead_donotcontact.date_added (email channel, reasons 1 and 2)
 *
 * Manual do-not-contact entries (reason 3) are not exported: Statusi doesn't track them as activity.
 */
class ActivityExportRepository
{
    public const SOURCE_SEND  = 1;
    public const SOURCE_OPEN  = 2;
    public const SOURCE_CLICK = 3;
    public const SOURCE_DNC   = 4;

    private string $prefix;

    public function __construct(private Connection $connection, ?string $tablePrefix = null)
    {
        $this->prefix = $tablePrefix ?? (defined('MAUTIC_TABLE_PREFIX') ? (string) MAUTIC_TABLE_PREFIX : '');
    }

    /**
     * Up to $limit + 1 rows after the cursor (the extra row tells whether there is a next page).
     *
     * @param array<int, mixed>|null $cursor [timestamp, source, row id] of the last row of the previous page
     *
     * @return array<int, array<string, mixed>>
     */
    public function page(string $dateFrom, string $dateTo, ?array $cursor, int $limit): array
    {
        $params = ['dateFrom' => $dateFrom, 'dateTo' => $dateTo];
        if (null !== $cursor) {
            $params['cursorTs'] = (string) $cursor[0];
        }

        $take       = $limit + 1;
        $sentBefore = fn (string $email, string $lead, string $ts): string => '(SELECT MAX(ls.date_sent) FROM '.$this->table('email_stats').' ls'
            .' WHERE ls.email_id = '.$email.' AND ls.lead_id = '.$lead.' AND ls.date_sent <= '.$ts.')';

        $branches = [
            [self::SOURCE_SEND, 's.date_sent', 's.id', [
                's.email_id', 's.lead_id', 's.is_failed', 'NULL', 'NULL', 'NULL', 'NULL', 'NULL', 's.date_sent', 'NULL', 'NULL',
            ], $this->table('email_stats').' s', ['s.email_id IS NOT NULL', 's.lead_id IS NOT NULL']],
            [self::SOURCE_OPEN, 'd.date_opened', 'd.id', [
                's.email_id', 's.lead_id', '0', 'd.ip_id', 'd.device_id', 'NULL', 'NULL', 'NULL', 's.date_sent', 'NULL', 'NULL',
            ], $this->table('email_stats_devices').' d INNER JOIN '.$this->table('email_stats').' s ON s.id = d.stat_id',
                ['s.email_id IS NOT NULL', 's.lead_id IS NOT NULL']],
            [self::SOURCE_CLICK, 'h.date_hit', 'h.id', [
                'h.email_id', 'h.lead_id', '0', 'h.ip_id', 'h.device_id', 'h.redirect_id', 'h.url', 'h.country',
                $sentBefore('h.email_id', 'h.lead_id', 'h.date_hit'), 'NULL', 'NULL',
            ], $this->table('page_hits').' h', ['h.email_id IS NOT NULL', 'h.lead_id IS NOT NULL']],
            [self::SOURCE_DNC, 'n.date_added', 'n.id', [
                'n.channel_id', 'n.lead_id', '0', 'NULL', 'NULL', 'NULL', 'NULL', 'NULL',
                'IF(n.channel_id IS NULL, NULL, '.$sentBefore('n.channel_id', 'n.lead_id', 'n.date_added').')', 'n.reason', 'n.comments',
            ], $this->table('lead_donotcontact').' n',
                ['n.channel = \'email\'', 'n.lead_id IS NOT NULL', 'n.reason IN ('.StatusiValues::DNC_UNSUBSCRIBED.', '.StatusiValues::DNC_BOUNCED.')']],
        ];

        $columns = ['email_id', 'lead_id', 'is_failed', 'ip_id', 'device_id', 'redirect_id', 'url', 'country', 'sent_ts', 'dnc_reason', 'comments'];
        $sql     = [];
        foreach ($branches as [$source, $ts, $id, $values, $from, $where]) {
            $where[] = $ts.' BETWEEN :dateFrom AND :dateTo';
            if (null !== $cursor) {
                $where[] = $this->afterCursor($source, $ts, $id, $cursor);
            }

            $select = [$source.' AS source', $id.' AS row_id', $ts.' AS ts'];
            foreach ($columns as $i => $column) {
                $select[] = $values[$i].' AS '.$column;
            }

            $sql[] = '(SELECT '.implode(', ', $select).' FROM '.$from.' WHERE '.implode(' AND ', $where)
                .' ORDER BY '.$ts.', '.$id.' LIMIT '.$take.')';
        }

        return $this->connection->executeQuery(
            'SELECT * FROM ('.implode("\nUNION ALL\n", $sql).') a ORDER BY a.ts, a.source, a.row_id LIMIT '.$take,
            $params
        )->fetchAllAssociative();
    }

    /**
     * @param int[] $ids
     *
     * @return array<int, array<string, mixed>> id => email row
     */
    public function emails(array $ids): array
    {
        return $this->byId('SELECT id, name, subject, email_type, publish_up FROM '.$this->table('emails').' WHERE id IN (%s)', $ids);
    }

    /**
     * Segments a segment (list) email is sent to.
     *
     * @param int[] $emailIds
     *
     * @return array<int, array<int, array{id: int, name: string}>> email id => segments ordered by id
     */
    public function emailSegments(array $emailIds): array
    {
        $segments = [];
        foreach ($this->fetchIn(
            'SELECT x.email_id, l.id, l.name FROM '.$this->table('email_list_xref').' x'
            .' INNER JOIN '.$this->table('lead_lists').' l ON l.id = x.leadlist_id WHERE x.email_id IN (%s) ORDER BY x.email_id, l.id',
            $emailIds
        ) as $row) {
            $segments[(int) $row['email_id']][] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
        }

        return $segments;
    }

    /**
     * @param int[] $ids
     *
     * @return array<int, string> contact id => email address
     */
    public function contactEmails(array $ids): array
    {
        $emails = [];
        foreach ($this->fetchIn('SELECT id, email FROM '.$this->table('leads').' WHERE id IN (%s)', $ids) as $row) {
            $emails[(int) $row['id']] = (string) $row['email'];
        }

        return $emails;
    }

    /**
     * @param int[] $ids
     *
     * @return array<int, array<string, mixed>>
     */
    public function ipAddresses(array $ids): array
    {
        return $this->byId('SELECT id, ip_address, ip_details FROM '.$this->table('ip_addresses').' WHERE id IN (%s)', $ids);
    }

    /**
     * @param int[] $ids
     *
     * @return array<int, array<string, mixed>>
     */
    public function devices(array $ids): array
    {
        return $this->byId('SELECT id, client_info, device_os_name FROM '.$this->table('lead_devices').' WHERE id IN (%s)', $ids);
    }

    /**
     * @param int[] $ids
     *
     * @return array<int, array<string, mixed>>
     */
    public function redirects(array $ids): array
    {
        return $this->byId('SELECT id, url FROM '.$this->table('page_redirects').' WHERE id IN (%s)', $ids);
    }

    /**
     * Keyset condition for one branch: its rows sort by (ts, source, id), and the source is a constant.
     *
     * @param array<int, mixed> $cursor
     */
    private function afterCursor(int $source, string $ts, string $id, array $cursor): string
    {
        $cursorSource = (int) $cursor[1];

        return match (true) {
            $source > $cursorSource => $ts.' >= :cursorTs',
            $source < $cursorSource => $ts.' > :cursorTs',
            default                 => '('.$ts.' > :cursorTs OR ('.$ts.' = :cursorTs AND '.$id.' > '.(int) $cursor[2].'))',
        };
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
    private function byId(string $sql, array $ids): array
    {
        $rows = [];
        foreach ($this->fetchIn($sql, $ids) as $row) {
            $rows[(int) $row['id']] = $row;
        }

        return $rows;
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
