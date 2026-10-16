<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\EmailActivity;

/**
 * Lookup data loaded in batches for one report page.
 *
 * @internal
 */
final class ReportContext
{
    /** @var array<int, array<int, array<int, array<string, mixed>>>> email id => lead id => activity rows */
    public array $activities = [];

    /** @var array<int, array<string, mixed>> stat id => email_stats row */
    public array $stats = [];

    /** @var array<string, array<string, mixed>> tracking hash => email_stats row */
    public array $statsByHash = [];

    /** @var array<int, array<int, array<int, array<string, mixed>>>> email id => lead id => stat rows */
    public array $statsByPair = [];

    /** @var array<int, array<string, mixed>> page_hits id => row */
    public array $clicks = [];

    /** @var array<int, array{stat: ?array<string, mixed>, method: ?string}> page_hits id => resolved send */
    public array $clickStats = [];

    /** @var array<int, array<string, mixed>> lead_donotcontact id => row */
    public array $doNotContacts = [];

    /** @var array<int, array<string, mixed>> campaign_events id => row (with campaign) */
    public array $campaignEvents = [];

    /** @var array<int, array<string, mixed>> campaign_lead_event_log id => row */
    public array $campaignLogsById = [];

    /** @var array<int, array<int, array<int, array<string, mixed>>>> lead id => event id => log rows */
    public array $campaignLogsByLeadEvent = [];

    /** @var array<int, array<string, mixed>> */
    public array $ipAddresses = [];

    /** @var array<int, array<string, mixed>> */
    public array $devices = [];

    /** @var array<int, array<string, mixed>> */
    public array $redirects = [];

    /**
     * @param array<string, mixed> $stat
     */
    public function addStat(array $stat): void
    {
        $id                                                                     = (int) $stat['id'];
        $this->stats[$id]                                                       = $stat;
        $this->statsByPair[(int) $stat['email_id']][(int) $stat['lead_id']][] = $stat;
        if (!empty($stat['tracking_hash'])) {
            $this->statsByHash[(string) $stat['tracking_hash']] = $stat;
        }
    }

    /**
     * @param array<string, mixed> $log
     */
    public function addCampaignLog(array $log): void
    {
        $this->campaignLogsById[(int) $log['id']]                                       = $log;
        $this->campaignLogsByLeadEvent[(int) $log['lead_id']][(int) $log['event_id']][] = $log;
    }

    /**
     * Campaign steps referenced by loaded activities and sends.
     *
     * @return int[]
     */
    public function campaignEventIds(): array
    {
        $ids = [];
        foreach ($this->activities as $byLead) {
            foreach ($byLead as $rows) {
                foreach ($rows as $row) {
                    if (null !== $row['campaign_event_id']) {
                        $ids[] = (int) $row['campaign_event_id'];
                    }
                }
            }
        }
        foreach ($this->stats as $stat) {
            if ('campaign.event' === ($stat['source'] ?? null) && !empty($stat['source_id'])) {
                $ids[] = (int) $stat['source_id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * The step log that triggered a send. A step is logged just before the email is sent, so the
     * log closest to the send time that is not more than 5 minutes after it wins; when every log
     * is later than that, the closest one is used.
     *
     * @return array<string, mixed>|null
     */
    public function campaignLogFor(int $leadId, int $eventId, ?string $sentAt): ?array
    {
        $logs = $this->campaignLogsByLeadEvent[$leadId][$eventId] ?? [];
        if (!$logs) {
            return null;
        }
        if (null === $sentAt) {
            usort($logs, static fn (array $a, array $b): int => strcmp((string) $b['date_triggered'], (string) $a['date_triggered']));

            return $logs[0];
        }

        $sent      = strtotime($sentAt.' UTC');
        $best      = null;
        $bestScore = PHP_INT_MAX;
        foreach ($logs as $log) {
            if (empty($log['date_triggered'])) {
                continue;
            }
            $diff  = $sent - strtotime($log['date_triggered'].' UTC');
            $score = $diff >= -300 ? abs($diff) : PHP_INT_MAX - 1 - abs($diff);
            if ($score < $bestScore) {
                $best      = $log;
                $bestScore = $score;
            }
        }

        return $best ?? $logs[0];
    }
}
