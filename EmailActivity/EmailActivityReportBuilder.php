<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\EmailActivity;

use MauticPlugin\MauticStatusiBundle\DTO\EmailActivity\ReportCriteria;

/**
 * Builds the email-wise activity report:
 *
 *   1. page the distinct emails that have qualifying activities (database side)
 *   2. per email on the page: summary counts, campaigns, and one page of contacts
 *   3. activities of those contacts, enriched with batched lookups (no N+1)
 *
 * Only period activities (timestamps inside dateFrom..dateTo) are listed and counted.
 * Earlier facts needed to explain them (e.g. when an opened email was sent) are attached
 * to the activity as context fields and never counted.
 */
class EmailActivityReportBuilder
{
    public function __construct(private EmailActivityRepository $repository)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(ReportCriteria $criteria): array
    {
        $queriesBefore = $this->repository->getQueryCount();
        $timeBefore    = $this->repository->getQueryTime();

        $groups      = $this->repository->findEmailGroups($criteria);
        $totalEmails = $groups
            ? (int) $groups[0]['total_groups']
            : ($criteria->page > 1 ? $this->repository->countEmailGroups($criteria) : 0);

        $data = [];
        if ($groups) {
            $data = $this->buildGroups($criteria, $groups);
        }

        $totalPages = (int) ceil($totalEmails / $criteria->limit);

        return [
            'data'       => $data,
            'pagination' => [
                'page'            => $criteria->page,
                'limit'           => $criteria->limit,
                'totalEmails'     => $totalEmails,
                'totalPages'      => $totalPages,
                'hasNextPage'     => $criteria->page < $totalPages,
                'hasPreviousPage' => $criteria->page > 1,
            ],
            'filters' => [
                'dateFrom'          => $criteria->dateFrom->format(\DATE_ATOM),
                'dateTo'            => $criteria->dateTo->format(\DATE_ATOM),
                'includeEvents'     => $criteria->eventTypes,
                'contactId'         => $criteria->contactId,
                'emailId'           => $criteria->emailId,
                'campaignId'        => $criteria->campaignId,
                'search'            => $criteria->search,
                'order'             => $criteria->order,
                'sortBy'            => $criteria->sortBy,
                'contactPage'       => $criteria->contactPage,
                'contactLimit'      => $criteria->contactLimit,
                'activityLimit'     => $criteria->activityLimit,
                'includeContacts'   => $criteria->includeContacts,
                'includeActivities' => $criteria->includeActivities,
            ],
            'unassignedActivities' => $this->repository->unassignedCounts($criteria) + [
                'note' => 'Activities in the period that cannot be tied to an email are not part of the email groups.',
            ],
            'meta' => [
                'timezone'    => 'UTC',
                'generatedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM),
                'queryCount'  => $this->repository->getQueryCount() - $queriesBefore,
                'dbTimeMs'    => (int) round(($this->repository->getQueryTime() - $timeBefore) * 1000),
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $groups
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildGroups(ReportCriteria $criteria, array $groups): array
    {
        $emailIds = array_map(static fn (array $g): int => (int) $g['email_id'], $groups);

        $summaries    = [];
        $steps        = [];
        foreach ($this->repository->pageSummary($criteria, $emailIds) as $row) {
            if (null === $row['email_id']) {
                continue; // grand total
            }
            $emailId               = (int) $row['email_id'];
            $summaries[$emailId] ??= $this->emptySummary();
            if (null === $row['event']) {
                $summaries[$emailId]['totalActivities'] = (int) $row['cnt'];
                $summaries[$emailId]['totalContacts']   = (int) $row['contacts'];
            } elseif (null === $row['step']) {
                $key = ActivityType::SUMMARY_KEYS[$row['event']] ?? null;
                if (null !== $key) {
                    $summaries[$emailId][$key] = (int) $row['cnt'];
                }
            } elseif ((int) $row['step'] > 0) {
                $steps[$emailId][(int) $row['step']] = ($steps[$emailId][(int) $row['step']] ?? 0) + (int) $row['cnt'];
            }
        }
        $campaignRows = [];
        foreach ($steps as $emailId => $byStep) {
            foreach ($byStep as $stepId => $count) {
                $campaignRows[] = ['email_id' => $emailId, 'event_id' => $stepId, 'cnt' => $count];
            }
        }

        $contactPages = [];
        $leadIds      = [];
        if ($criteria->includeContacts) {
            foreach ($this->repository->contactPage($criteria, $emailIds) as $row) {
                $contactPages[(int) $row['email_id']][] = ['lead_id' => (int) $row['lead_id'], 'last_ts' => $row['last_ts'], 'cnt' => (int) $row['cnt']];
                $leadIds[]                              = (int) $row['lead_id'];
            }
        }
        $leadIds = array_values(array_unique($leadIds));
        $emails  = $this->repository->emails($emailIds);

        // Activities and everything needed to enrich them, fetched in batches.
        $context = new ReportContext();
        if ($criteria->includeContacts && $criteria->includeActivities && $contactPages) {
            $pairs = [];
            foreach ($contactPages as $emailId => $rows) {
                foreach ($rows as $row) {
                    $pairs[] = [$emailId, $row['lead_id']];
                }
            }
            $this->loadActivities($criteria, $context, $pairs, $emailIds, $leadIds);
        }

        // Campaign steps referenced by the email-level campaign list.
        $missing = array_diff(
            array_map(static fn (array $r): int => (int) $r['event_id'], $campaignRows),
            array_keys($context->campaignEvents)
        );
        if ($missing) {
            $context->campaignEvents += $this->repository->campaignEvents($missing);
        }

        $campaignsByEmail = $this->campaignsByEmail($campaignRows, $context);

        $contacts    = $leadIds ? $this->repository->contacts($leadIds) : [];
        $memberships = [];
        if ($leadIds) {
            $campaignIds = [];
            foreach ($campaignsByEmail as $list) {
                foreach ($list as $campaign) {
                    $campaignIds[] = $campaign['id'];
                }
            }
            foreach ($this->repository->campaignMemberships($leadIds, array_unique($campaignIds)) as $row) {
                $memberships[(int) $row['lead_id']][(int) $row['campaign_id']] = $row;
            }
        }

        $data = [];
        foreach ($groups as $group) {
            $emailId       = (int) $group['email_id'];
            $summary       = $summaries[$emailId] ?? $this->emptySummary();
            $campaignList  = $campaignsByEmail[$emailId] ?? [];
            $totalContacts = $summary['totalContacts'];

            $entry = [
                'email'            => $this->formatEmail($emailId, $emails[$emailId] ?? null),
                'latestActivityAt' => $this->iso($group['last_ts']),
                'campaigns'        => $campaignList,
                'activitySummary'  => $summary,
            ];

            if ($criteria->includeContacts) {
                $entry['contactsPagination'] = [
                    'page'          => $criteria->contactPage,
                    'limit'         => $criteria->contactLimit,
                    'totalContacts' => $totalContacts,
                    'totalPages'    => (int) ceil($totalContacts / $criteria->contactLimit),
                    'hasNextPage'   => $criteria->contactPage * $criteria->contactLimit < $totalContacts,
                ];
                $entry['contacts'] = [];
                $campaignIdsOfEmail = array_column($campaignList, 'name', 'id');

                foreach ($contactPages[$emailId] ?? [] as $row) {
                    $leadId     = $row['lead_id'];
                    $activities = $context->activities[$emailId][$leadId] ?? [];
                    $total      = $row['cnt'];

                    $contact = [
                        'contact'             => $this->formatContact($leadId, $contacts[$leadId] ?? null),
                        'latestActivityAt'    => $this->iso($row['last_ts']),
                        'activityCount'       => $total,
                        'campaignMemberships' => $this->formatMemberships($memberships[$leadId] ?? [], $campaignIdsOfEmail),
                    ];
                    if ($criteria->includeActivities) {
                        $contact['activitiesReturned']  = count($activities);
                        $contact['activitiesTruncated'] = count($activities) < $total;
                        $contact['activities']          = array_map(
                            fn (array $activity): array => $this->formatActivity($activity, $context, $emails),
                            $activities
                        );
                    }
                    $entry['contacts'][] = $contact;
                }
            }

            $data[] = $entry;
        }

        return $data;
    }

    /**
     * @param array<int[]> $pairs
     * @param int[]        $emailIds
     * @param int[]        $leadIds
     */
    private function loadActivities(ReportCriteria $criteria, ReportContext $context, array $pairs, array $emailIds, array $leadIds): void
    {
        $rows = $this->repository->activities($criteria, $pairs);

        $ids        = ['click' => [], 'dnc' => [], 'ip' => [], 'device' => [], 'redirect' => []];
        $clickPairs = [];
        foreach ($rows as $row) {
            $context->activities[(int) $row['email_id']][(int) $row['lead_id']][] = $row;
            if (ActivityType::CLICK === $row['event']) {
                $clickPairs[(int) $row['row_id']] = [(int) $row['email_id'], (int) $row['lead_id']];
            }
            match ($row['event']) {
                ActivityType::CLICK => $ids['click'][] = (int) $row['row_id'],
                ActivityType::UNSUBSCRIBE, ActivityType::BOUNCE, ActivityType::DNC_MANUAL => $ids['dnc'][] = (int) $row['row_id'],
                default => null,
            };
            foreach (['ip_id' => 'ip', 'device_id' => 'device', 'redirect_id' => 'redirect'] as $column => $key) {
                if (null !== $row[$column]) {
                    $ids[$key][] = (int) $row[$column];
                }
            }
        }

        foreach ($this->repository->stats($emailIds, $leadIds) as $stat) {
            $context->addStat($stat);
        }
        $context->clicks        = $this->repository->clicks($ids['click']);
        $context->doNotContacts = $this->repository->doNotContacts($ids['dnc']);

        // Resolve the send behind each click: the tracked link carries the send's tracking hash.
        foreach ($context->clicks as $clickId => $click) {
            [$emailId, $leadId]            = $clickPairs[$clickId] ?? [0, 0];
            $context->clickStats[$clickId] = $this->resolveClickStat($click, $context, $emailId, $leadId);
        }

        $context->campaignEvents = $this->repository->campaignEvents($context->campaignEventIds());
        foreach ($this->repository->campaignLogs($leadIds, array_keys($context->campaignEvents)) as $log) {
            $context->addCampaignLog($log);
        }

        $context->ipAddresses = $this->repository->ipAddresses($ids['ip']);
        $context->devices     = $this->repository->devices($ids['device']);
        $context->redirects   = $this->repository->redirects($ids['redirect']);
    }

    /**
     * Which send a click came from: the tracked link's ct.stat (exact), otherwise the contact's
     * only send of this email. With several sends and no tracking hash the click stays unattributed.
     *
     * @param array<string, mixed> $click
     *
     * @return array{stat: ?array<string, mixed>, method: ?string}
     */
    private function resolveClickStat(array $click, ReportContext $context, int $emailId, int $leadId): array
    {
        $query = ValueParser::unserializeArray($click['query'] ?? null);
        $hash  = is_array($query['ct'] ?? null) ? ($query['ct']['stat'] ?? null) : null;
        if (is_string($hash) && isset($context->statsByHash[$hash])) {
            $stat = $context->statsByHash[$hash];
            if ((int) $stat['email_id'] === $emailId && (int) $stat['lead_id'] === $leadId) {
                return ['stat' => $stat, 'method' => 'trackedLink'];
            }
        }

        $candidates = $context->statsByPair[$emailId][$leadId] ?? [];
        if (1 === count($candidates)) {
            return ['stat' => reset($candidates), 'method' => 'onlySendToContact'];
        }

        return ['stat' => null, 'method' => null];
    }

    /**
     * @return array<string, int>
     */
    private function emptySummary(): array
    {
        $summary = ['totalActivities' => 0, 'totalContacts' => 0];
        foreach (ActivityType::SUMMARY_KEYS as $key) {
            $summary[$key] = 0;
        }

        return $summary;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function campaignsByEmail(array $rows, ReportContext $context): array
    {
        $result = [];
        foreach ($rows as $row) {
            $emailId = (int) $row['email_id'];
            $event   = $context->campaignEvents[(int) $row['event_id']] ?? null;
            if (null === $event || empty($event['campaign_id'])) {
                continue; // step deleted since: no campaign to report it under
            }
            $campaignId = (int) $event['campaign_id'];

            $result[$emailId][$campaignId] ??= [
                'id'          => $campaignId,
                'name'        => $event['campaign_name'] ?? null,
                'isPublished' => isset($event['campaign_is_published']) ? (bool) $event['campaign_is_published'] : null,
                'createdAt'   => $this->iso($event['campaign_date_added'] ?? null),
                'steps'       => [],
            ];
            $result[$emailId][$campaignId]['steps'][] = [
                'id'                 => (int) $row['event_id'],
                'name'               => $event['name'] ?? null,
                'type'               => $event['type'] ?? null,
                'stepType'           => $event['event_type'] ?? null,
                'periodActivities'   => (int) $row['cnt'],
            ];
        }

        return array_map('array_values', $result);
    }

    /**
     * @param array<string, mixed>|null $email
     *
     * @return array<string, mixed>
     */
    private function formatEmail(int $id, ?array $email): array
    {
        return [
            'id'                => $id,
            'exists'            => null !== $email,
            'name'              => $email['name'] ?? null,
            'subject'           => $email['subject'] ?? null,
            'description'       => $email['description'] ?? null,
            'emailType'         => $email['email_type'] ?? null,
            'language'          => $email['lang'] ?? null,
            'isPublished'       => isset($email['is_published']) ? (bool) $email['is_published'] : null,
            'publishUp'         => $this->iso($email['publish_up'] ?? null),
            'publishDown'       => $this->iso($email['publish_down'] ?? null),
            'createdAt'         => $this->iso($email['date_added'] ?? null),
            'modifiedAt'        => $this->iso($email['date_modified'] ?? null),
            'fromAddress'       => $email['from_address'] ?? null,
            'fromName'          => $email['from_name'] ?? null,
            'lifetimeSentCount' => isset($email['sent_count']) ? (int) $email['sent_count'] : null,
            'lifetimeReadCount' => isset($email['read_count']) ? (int) $email['read_count'] : null,
        ];
    }

    /**
     * @param array<string, mixed>|null $contact
     *
     * @return array<string, mixed>
     */
    private function formatContact(int $id, ?array $contact): array
    {
        return [
            'id'        => $id,
            'exists'    => null !== $contact,
            'email'     => $contact['email'] ?? null,
            'firstName' => $contact['firstname'] ?? null,
            'lastName'  => $contact['lastname'] ?? null,
            'company'   => $contact['company'] ?? null,
            'country'   => $contact['country'] ?? null,
            'region'    => $contact['state'] ?? null,
            'city'      => $contact['city'] ?? null,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $memberships      campaign id => campaign_leads row
     * @param array<int, string|null>          $campaignsOfEmail campaign id => name
     *
     * @return array<int, array<string, mixed>>
     */
    private function formatMemberships(array $memberships, array $campaignsOfEmail): array
    {
        $result = [];
        foreach ($campaignsOfEmail as $campaignId => $name) {
            $row      = $memberships[(int) $campaignId] ?? null;
            $result[] = [
                'campaignId'      => (int) $campaignId,
                'campaignName'    => $name,
                'isMember'        => null !== $row,
                'enteredAt'       => $this->iso($row['date_added'] ?? null),
                'manuallyAdded'   => isset($row['manually_added']) ? (bool) $row['manually_added'] : null,
                'manuallyRemoved' => isset($row['manually_removed']) ? (bool) $row['manually_removed'] : null,
                'exitedAt'        => $this->iso($row['date_last_exited'] ?? null),
                'rotation'        => isset($row['rotation']) ? (int) $row['rotation'] : null,
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed>             $row
     * @param array<int, array<string, mixed>> $emails
     *
     * @return array<string, mixed>
     */
    private function formatActivity(array $row, ReportContext $context, array $emails): array
    {
        $type    = (string) $row['event'];
        $rowId   = (int) $row['row_id'];
        $emailId = (int) $row['email_id'];
        $leadId  = (int) $row['lead_id'];

        $activity = [
            'event'                     => $type,
            'eventId'                   => $type.$rowId,
            'eventType'                 => ActivityType::LABELS[$type] ?? $type,
            'activityType'              => ActivityType::ACTIVITY[$type] ?? null,
            'timestamp'                 => $this->iso($row['ts']),
            'messageId'                 => $emailId,
            'subject'                   => $emails[$emailId]['subject'] ?? null,
            'statId'                    => null,
            'campaignId'                => null,
            'campaignName'              => null,
            'campaignActionId'          => null,
            'campaignActionName'        => null,
            'campaignActionTriggeredAt' => null,
            'campaignAttribution'       => null,
            'sentAt'                    => null,
            'openedAt'                  => null,
            'clickedAt'                 => null,
            'clickedUrl'                => null,
            'trackedLinkId'             => null,
            'sendOpenCountTotal'        => null,
            'ipAddress'                 => null,
            'country'                   => null,
            'region'                    => null,
            'city'                      => null,
            'isp'                       => null,
            'browser'                   => null,
            'operatingSystem'           => null,
            'device'                    => null,
            'deviceBrand'               => null,
            'deviceModel'               => null,
            'userAgent'                 => null,
            'referer'                   => null,
            'source'                    => null,
            'failureReason'             => null,
            'dncReason'                 => null,
            'dncComments'               => null,
            'rawDetails'                => ['table' => null, 'id' => $rowId],
        ];

        $stat = null;
        switch ($type) {
            case ActivityType::SENT:
            case ActivityType::FAILED:
                $stat                           = $context->stats[(int) $row['stat_id']] ?? null;
                $activity['rawDetails']['table'] = 'email_stats';
                break;

            case ActivityType::READ:
                $stat                            = $context->stats[(int) $row['stat_id']] ?? null;
                $activity['openedAt']            = $activity['timestamp'];
                $activity['rawDetails']['table'] = 'email_stats_devices';
                break;

            case ActivityType::CLICK:
                $click                           = $context->clicks[$rowId] ?? [];
                $resolved                        = $context->clickStats[$rowId] ?? ['stat' => null, 'method' => null];
                $stat                            = $resolved['stat'];
                $redirect                        = $context->redirects[(int) $row['redirect_id']] ?? null;
                $activity['clickedAt']           = $activity['timestamp'];
                $activity['clickedUrl']          = $click['url'] ?? ($redirect['url'] ?? null);
                $activity['trackedLinkId']       = $redirect['redirect_id'] ?? null;
                $activity['userAgent']           = $click['user_agent'] ?? null;
                $activity['referer']             = $click['referer'] ?? null;
                $activity['country']             = $this->nonEmpty($click['country'] ?? null);
                $activity['region']              = $this->nonEmpty($click['region'] ?? null);
                $activity['city']                = $this->nonEmpty($click['city'] ?? null);
                $activity['isp']                 = $this->nonEmpty($click['isp'] ?? null);
                $activity['source']              = $click['source'] ?? null;
                $activity['campaignAttribution'] = $resolved['method'];
                $activity['rawDetails']['table'] = 'page_hits';
                break;

            case ActivityType::UNSUBSCRIBE:
            case ActivityType::BOUNCE:
            case ActivityType::DNC_MANUAL:
                $dnc                             = $context->doNotContacts[$rowId] ?? [];
                $activity['dncReason']           = ActivityType::ACTIVITY[$type];
                $activity['dncComments']         = $this->nonEmpty($dnc['comments'] ?? null);
                $activity['rawDetails']['table'] = 'lead_donotcontact';
                break;

            case ActivityType::CAMPAIGN_EVENT:
                $event                                 = $context->campaignEvents[(int) $row['campaign_event_id']] ?? null;
                $log                                   = $context->campaignLogsById[$rowId] ?? null;
                $activity['campaignId']                = (int) $row['campaign_id'];
                $activity['campaignName']              = $event['campaign_name'] ?? null;
                $activity['campaignActionId']          = (int) $row['campaign_event_id'];
                $activity['campaignActionName']        = $event['name'] ?? null;
                $activity['campaignActionTriggeredAt'] = $activity['timestamp'];
                $activity['campaignAttribution']       = 'campaignLog';
                $activity['source']                    = $event['event_type'] ?? null;
                $activity['failureReason']             = $this->nonEmpty($log['fail_reason'] ?? null);
                $activity['rawDetails']['table']       = 'campaign_lead_event_log';
                break;
        }

        if ($stat) {
            $activity['statId']             = (int) $stat['id'];
            $activity['sentAt']             = $this->iso($stat['date_sent'] ?? null);
            $activity['sendOpenCountTotal'] = isset($stat['open_count']) ? (int) $stat['open_count'] : null;
            $activity['source'] ??= $stat['source'] ?? null;
            $this->applyStatCampaign($activity, $stat, $leadId, $context);
            if (ActivityType::FAILED === $type && null !== $activity['campaignActionId']) {
                $log                       = $context->campaignLogFor($leadId, (int) $activity['campaignActionId'], $stat['date_sent'] ?? null);
                $activity['failureReason'] = $this->nonEmpty($log['fail_reason'] ?? null);
            }
        }

        $this->applyIp($activity, $context->ipAddresses[(int) $row['ip_id']] ?? null);
        $this->applyDevice($activity, $context->devices[(int) $row['device_id']] ?? null);

        return $activity;
    }

    /**
     * Sends from a campaign store source = campaign.event and source_id = the campaign step.
     *
     * @param array<string, mixed> $activity
     * @param array<string, mixed> $stat
     */
    private function applyStatCampaign(array &$activity, array $stat, int $leadId, ReportContext $context): void
    {
        if ('campaign.event' !== ($stat['source'] ?? null) || empty($stat['source_id'])) {
            return;
        }

        $eventId = (int) $stat['source_id'];
        $event   = $context->campaignEvents[$eventId] ?? null;
        $log     = $context->campaignLogFor($leadId, $eventId, $stat['date_sent'] ?? null);

        $activity['campaignId']                = isset($event['campaign_id']) ? (int) $event['campaign_id'] : null;
        $activity['campaignName']              = $event['campaign_name'] ?? null;
        $activity['campaignActionId']          = $eventId;
        $activity['campaignActionName']        = $event['name'] ?? null;
        $activity['campaignActionTriggeredAt'] = $this->iso($log['date_triggered'] ?? null);
        $activity['campaignAttribution'] ??= 'sendSource';
    }

    /**
     * @param array<string, mixed>      $activity
     * @param array<string, mixed>|null $ip
     */
    private function applyIp(array &$activity, ?array $ip): void
    {
        if (!$ip) {
            return;
        }

        $details               = ValueParser::unserializeArray($ip['ip_details'] ?? null);
        $activity['ipAddress'] = $ip['ip_address'] ?? null;
        foreach (['country' => 'country', 'region' => 'region', 'city' => 'city', 'isp' => 'isp'] as $field => $key) {
            $activity[$field] ??= $this->nonEmpty($details[$key] ?? null);
        }
    }

    /**
     * @param array<string, mixed>      $activity
     * @param array<string, mixed>|null $device
     */
    private function applyDevice(array &$activity, ?array $device): void
    {
        if (!$device) {
            return;
        }

        $client                      = ValueParser::unserializeArray($device['client_info'] ?? null);
        $browser                     = $this->nonEmpty($client['name'] ?? null);
        $version                     = $this->nonEmpty($client['version'] ?? null);
        $activity['browser']         = $browser && $version ? $browser.' '.$version : $browser;
        $osName                      = $this->nonEmpty($device['device_os_name'] ?? null);
        $osVersion                   = $this->nonEmpty($device['device_os_version'] ?? null);
        $activity['operatingSystem'] = $osName && $osVersion ? $osName.' '.$osVersion : $osName;
        $activity['device']          = $this->nonEmpty($device['device'] ?? null);
        $activity['deviceBrand']     = $this->nonEmpty($device['device_brand'] ?? null);
        $activity['deviceModel']     = $this->nonEmpty($device['device_model'] ?? null);
    }

    private function nonEmpty(mixed $value): ?string
    {
        return (null === $value || '' === $value) ? null : (string) $value;
    }

    private function iso(mixed $value): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        try {
            return (new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC')))->format(\DATE_ATOM);
        } catch (\Exception) {
            return null;
        }
    }
}
