<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Export;

use MauticPlugin\MauticStatusiBundle\DTO\Export\ExportRequest;
use MauticPlugin\MauticStatusiBundle\EmailActivity\ValueParser;

/**
 * GET /api/custom/email-activity-export: one row per email activity, already in Statusi's
 * contact_activity terms (see StatusiValues), for the Statusi mautic_contact_activity DAG.
 *
 * Statusi "campaign" and "message" are both the Mautic email. Rows are in UTC.
 */
class ActivityExporter
{
    public function __construct(private ActivityExportRepository $repository)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function export(ExportRequest $request): array
    {
        $raw     = $this->repository->page($request->dateFromSql(), $request->dateToSql(), $request->cursor, $request->limit);
        $hasMore = count($raw) > $request->limit;
        $raw     = array_slice($raw, 0, $request->limit);

        $emailIds = array_column($raw, 'email_id');
        $emails   = $this->repository->emails($emailIds);
        $segments = $this->repository->emailSegments(array_keys(array_filter($emails, static fn (array $e): bool => 'list' === $e['email_type'])));
        $contacts = $this->repository->contactEmails(array_column($raw, 'lead_id'));
        $ips      = $this->repository->ipAddresses(array_column($raw, 'ip_id'));
        $devices  = $this->repository->devices(array_column($raw, 'device_id'));
        $links    = $this->repository->redirects(array_column($raw, 'redirect_id'));

        $rows = [];
        foreach ($raw as $activity) {
            $type = $this->activityType($activity);
            if (null === $type) {
                continue;
            }

            $emailId  = null !== $activity['email_id'] ? (int) $activity['email_id'] : null;
            $email    = null !== $emailId ? ($emails[$emailId] ?? null) : null;
            $ip       = $ips[(int) $activity['ip_id']] ?? null;
            $details  = ValueParser::unserializeArray($ip['ip_details'] ?? null);
            $device   = $devices[(int) $activity['device_id']] ?? null;
            $client   = ValueParser::unserializeArray($device['client_info'] ?? null);
            $link     = $links[(int) $activity['redirect_id']] ?? null;
            $segment  = null !== $emailId ? ($segments[$emailId] ?? []) : [];
            $isSend   = ActivityExportRepository::SOURCE_SEND === (int) $activity['source'];
            $timestamp = (string) $activity['ts'];

            $rows[] = [
                'id'                    => $this->rowId((int) $activity['source'], (int) $activity['row_id']),
                'activity_type'         => $type,
                'activity_date_time'    => $timestamp,
                'email'                 => $contacts[(int) $activity['lead_id']] ?? null,
                'contact_id'            => (int) $activity['lead_id'],
                'ip'                    => $this->text($ip['ip_address'] ?? null),
                'country_code'          => StatusiValues::countryCode($this->text($activity['country'] ?? null) ?? $this->text($details['country'] ?? null)),
                'os'                    => $this->text($device['device_os_name'] ?? null),
                'browser'               => $this->text($client['name'] ?? null),
                'days_passed'           => $isSend ? null : StatusiValues::daysPassed($this->text($activity['sent_ts']), $timestamp),
                'campaign_id'           => $emailId,
                'campaign_name'         => $this->text($email['name'] ?? null),
                'mailing_type'          => $this->text($email['email_type'] ?? null),
                'mailing_schedule'      => $this->text($email['publish_up'] ?? null),
                'email_message_id'      => $emailId,
                'email_message_subject' => $this->text($email['subject'] ?? null),
                // Ongage's "data" column: the clicked URL for clicks, otherwise the subject
                'data_value'            => ActivityExportRepository::SOURCE_CLICK === (int) $activity['source']
                    ? ($this->text($activity['url']) ?? $this->text($link['url'] ?? null))
                    : $this->text($email['subject'] ?? null),
                'link_id'               => null !== $link ? (int) $link['id'] : null,
                'segment_id'            => $segment[0]['id'] ?? null,
                'segment_name'          => $segment ? implode(', ', array_column($segment, 'name')) : null,
            ];
        }

        $last = $raw ? $raw[array_key_last($raw)] : null;

        return [
            'data'       => $rows,
            'pagination' => [
                'limit'      => $request->limit,
                'returned'   => count($rows),
                'hasMore'    => $hasMore,
                'nextCursor' => $hasMore && null !== $last
                    ? ExportCursor::encode([(string) $last['ts'], (int) $last['source'], (int) $last['row_id']])
                    : null,
            ],
            'filters'    => [
                'dateFrom' => $request->dateFrom->format(DATE_ATOM),
                'dateTo'   => $request->dateTo->format(DATE_ATOM),
            ],
            'meta'       => [
                'timezone'    => 'UTC',
                'generatedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $activity
     */
    private function activityType(array $activity): ?string
    {
        return match ((int) $activity['source']) {
            ActivityExportRepository::SOURCE_SEND  => 1 === (int) $activity['is_failed'] ? StatusiValues::SOFT_BOUNCE : StatusiValues::SEND,
            ActivityExportRepository::SOURCE_OPEN  => StatusiValues::OPEN,
            ActivityExportRepository::SOURCE_CLICK => StatusiValues::CLICK,
            default                                => StatusiValues::dncActivityType((int) $activity['dnc_reason'], $this->text($activity['comments'])),
        };
    }

    /**
     * Stable unique row id: the source table and its row id.
     */
    private function rowId(int $source, int $rowId): string
    {
        return match ($source) {
            ActivityExportRepository::SOURCE_SEND  => 'send',
            ActivityExportRepository::SOURCE_OPEN  => 'open',
            ActivityExportRepository::SOURCE_CLICK => 'click',
            default                                => 'dnc',
        }.'-'.$rowId;
    }

    private function text(mixed $value): ?string
    {
        if (null === $value || is_array($value)) {
            return null;
        }
        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }
}
