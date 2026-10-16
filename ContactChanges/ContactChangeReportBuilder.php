<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\ContactChanges;

use MauticPlugin\MauticStatusiBundle\DTO\ContactChanges\ContactChangeCriteria;
use MauticPlugin\MauticStatusiBundle\EmailActivity\ValueParser;

/**
 * Builds the contact attribute change report: current attribute values of contacts created or
 * updated in the range, how each one qualified, and (optionally) the verified field-level history
 * Mautic recorded in its audit log during the range.
 */
class ContactChangeReportBuilder
{
    /**
     * Audit detail keys that are contact properties rather than fields, and may be returned in history.
     */
    private const HISTORY_PROPERTIES = ['owner', 'stage', 'dateIdentified', 'tags', 'utmtags'];

    private const CORE_FIELDS = ['email', 'firstname', 'lastname', 'company', 'phone', 'mobile', 'country', 'state', 'city'];

    public function __construct(
        private ContactChangeRepository $repository,
        private ContactFieldCatalog $catalog,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(ContactChangeCriteria $criteria): array
    {
        $queriesBefore = $this->repository->getQueryCount();
        $timeBefore    = $this->repository->getQueryTime();

        $catalog = $this->catalog->all();
        $aliases = $criteria->fields ?? array_keys($catalog);

        $page  = $this->repository->findPage($criteria);
        $total = $page ? (int) $page[0]['total'] : ($criteria->page > 1 ? $this->repository->countContacts($criteria) : 0);

        $ids      = array_map(static fn (array $row): int => (int) $row['id'], $page);
        // The contact block always carries these core attributes, whatever ?fields= selects.
        $columns  = array_values(array_unique(array_merge(array_intersect(self::CORE_FIELDS, array_keys($catalog)), $aliases)));
        $contacts = $this->repository->contacts($ids, $columns);
        $owners   = $this->repository->userNames(array_filter(array_column($contacts, 'owner_id')));
        $stages   = $this->repository->stageNames(array_filter(array_column($contacts, 'stage_id')));
        $history  = $criteria->includeHistory ? $this->historyByContact($criteria, $ids, $aliases) : [];

        $data = [];
        foreach ($page as $row) {
            $id = (int) $row['id'];
            if (!isset($contacts[$id])) {
                continue; // deleted between the two queries
            }
            $entry = [
                'contact'    => $this->formatContact($contacts[$id], $aliases, $catalog, $owners, $stages),
                'changeInfo' => $this->changeInfo($row),
            ];
            if ($criteria->includeHistory) {
                $entry['history'] = $history[$id] ?? ['totalEntries' => 0, 'entriesReturned' => 0, 'truncated' => false, 'entries' => []];
            }
            $data[] = $entry;
        }

        $totalPages = (int) ceil($total / $criteria->limit);

        return [
            'data'       => $data,
            'pagination' => [
                'page'            => $criteria->page,
                'limit'           => $criteria->limit,
                'totalContacts'   => $total,
                'totalPages'      => $totalPages,
                'hasNextPage'     => $criteria->page < $totalPages,
                'hasPreviousPage' => $criteria->page > 1,
            ],
            'filters' => [
                'dateFrom'       => $criteria->dateFrom->format(\DATE_ATOM),
                'dateTo'         => $criteria->dateTo->format(\DATE_ATOM),
                'changeType'     => $criteria->changeType,
                'contactId'      => $criteria->contactId,
                'search'         => $criteria->search,
                'fields'         => $criteria->fields,
                'order'          => $criteria->order,
                'sortBy'         => $criteria->sortBy,
                'includeHistory' => $criteria->includeHistory,
                'historyLimit'   => $criteria->historyLimit,
            ],
            'meta' => [
                'timezone'    => 'UTC',
                'attributes'  => 'current values',
                'history'     => $criteria->includeHistory ? 'audit log entries (create/update) inside the range' : 'not requested',
                'generatedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM),
                'queryCount'  => $this->repository->getQueryCount() - $queriesBefore,
                'dbTimeMs'    => (int) round(($this->repository->getQueryTime() - $timeBefore) * 1000),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function changeInfo(array $row): array
    {
        $created  = null !== $row['created_ts'];
        $audit    = null !== $row['audit_ts'];
        $modified = null !== $row['modified_ts'];
        $updated  = $audit || $modified;

        $lastUpdate = null;
        if ($updated) {
            $lastUpdate = max(array_filter([$row['audit_ts'], $row['modified_ts']]));
        }

        $evidence = [];
        if ($audit) {
            $evidence[] = 'auditLog';
        }
        if ($modified) {
            $evidence[] = 'dateModified';
        }

        return [
            'changeType'       => $created && $updated ? 'created_and_updated' : ($created ? 'created' : 'updated'),
            'createdInRange'   => $created,
            'updatedInRange'   => $updated,
            'createdAt'        => $created ? $this->iso($row['created_ts']) : null,
            'lastUpdatedAt'    => $this->iso($lastUpdate),
            'matchedTimestamp' => $this->iso($row['matched_ts']),
            'updateEvidence'   => $evidence,
        ];
    }

    /**
     * @param array<string, mixed>                                                            $row
     * @param string[]                                                                        $aliases
     * @param array<string, array{alias: string, label: string, type: string, group: string}> $catalog
     * @param array<int, string>                                                              $owners
     * @param array<int, string>                                                              $stages
     *
     * @return array<string, mixed>
     */
    private function formatContact(array $row, array $aliases, array $catalog, array $owners, array $stages): array
    {
        $value = fn (string $alias): mixed => array_key_exists($alias, $row) && isset($catalog[$alias])
            ? $this->typed($row[$alias], $catalog[$alias]['type'])
            : null;

        $fields = [];
        foreach ($aliases as $alias) {
            $fields[$alias] = $value($alias);
        }

        $ownerId = null !== $row['owner_id'] ? (int) $row['owner_id'] : null;
        $stageId = null !== $row['stage_id'] ? (int) $row['stage_id'] : null;

        return [
            'id'             => (int) $row['id'],
            'email'          => $value('email'),
            'firstName'      => $value('firstname'),
            'lastName'       => $value('lastname'),
            'company'        => $value('company'),
            'phone'          => $value('phone'),
            'mobile'         => $value('mobile'),
            'country'        => $value('country'),
            'region'         => $value('state'),
            'city'           => $value('city'),
            'owner'          => null !== $ownerId ? ['id' => $ownerId, 'name' => $owners[$ownerId] ?? null] : null,
            'stage'          => null !== $stageId ? ['id' => $stageId, 'name' => $stages[$stageId] ?? null] : null,
            'dateAdded'      => $this->iso($row['date_added']),
            'dateModified'   => $this->iso($row['date_modified']),
            'dateIdentified' => $this->iso($row['date_identified']),
            'lastActive'     => $this->iso($row['last_active']),
            'fields'         => $fields,
        ];
    }

    /**
     * @param int[]    $ids
     * @param string[] $aliases
     *
     * @return array<int, array<string, mixed>>
     */
    private function historyByContact(ContactChangeCriteria $criteria, array $ids, array $aliases): array
    {
        $allowed = array_flip(array_merge($aliases, self::HISTORY_PROPERTIES));
        $result  = [];
        foreach ($this->repository->history($criteria, $ids, $criteria->historyLimit) as $row) {
            $id                = (int) $row['object_id'];
            $result[$id] ??= ['totalEntries' => (int) $row['entries'], 'entriesReturned' => 0, 'truncated' => false, 'entries' => []];
            $details           = ValueParser::unserializeArray($row['details']);

            $changes = [];
            foreach ($details as $key => $change) {
                if ('fields' === $key || !isset($allowed[$key]) || !is_array($change) || !array_key_exists(0, $change) || !array_key_exists(1, $change)) {
                    continue;
                }
                $old = $this->plain($change[0]);
                $new = $this->plain($change[1]);
                if ('dateIdentified' === $key) {
                    // Mautic records "no date" as an empty string here, but as null everywhere else.
                    $old = '' === $old ? null : $old;
                    $new = '' === $new ? null : $new;
                }
                $changes[] = ['field' => (string) $key, 'oldValue' => $old, 'newValue' => $new];
            }

            $result[$id]['entries'][] = [
                'id'        => (int) $row['id'],
                'action'    => (string) $row['action'],
                'timestamp' => $this->iso($row['date_added']),
                'actor'     => [
                    'userId'   => null !== $row['user_id'] && (int) $row['user_id'] > 0 ? (int) $row['user_id'] : null,
                    'userName' => $row['user_name'] ?: null,
                ],
                'source'    => [
                    'manipulatedBy'  => isset($details['manipulated_by']) && is_scalar($details['manipulated_by']) ? (string) $details['manipulated_by'] : null,
                    'manipulatorKey' => isset($details['manipulator_key']) && is_scalar($details['manipulator_key']) ? (string) $details['manipulator_key'] : null,
                ],
                'changes'   => $changes,
            ];
        }

        foreach ($result as &$history) {
            $history['entriesReturned'] = count($history['entries']);
            $history['truncated']       = $history['entriesReturned'] < $history['totalEntries'];
        }

        return $result;
    }

    /**
     * Audit values are PHP-serialized; objects (e.g. DateTime) arrive as incomplete classes.
     */
    private function plain(mixed $value): mixed
    {
        if ($value instanceof \__PHP_Incomplete_Class) {
            $props = (array) $value;

            return isset($props['date']) && is_string($props['date']) ? $this->iso(substr($props['date'], 0, 19)) : null;
        }
        if (is_array($value)) {
            return array_map(fn (mixed $v): mixed => $this->plain($v), $value);
        }

        return is_scalar($value) || null === $value ? $value : null;
    }

    private function typed(mixed $value, string $type): mixed
    {
        if (null === $value || '' === $value) {
            return null === $value ? null : '';
        }

        return match ($type) {
            'number'   => is_numeric($value) ? (str_contains((string) $value, '.') ? (float) $value : (int) $value) : $value,
            'boolean'  => (bool) (int) $value,
            'datetime' => $this->iso($value),
            'date'     => substr((string) $value, 0, 10),
            default    => (string) $value,
        };
    }

    private function iso(mixed $value): ?string
    {
        if (null === $value || '' === $value || str_starts_with((string) $value, '1000-01-01')) {
            return null;
        }

        try {
            return (new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC')))->format(\DATE_ATOM);
        } catch (\Exception) {
            return null;
        }
    }
}
