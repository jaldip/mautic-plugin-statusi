<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Export;

use MauticPlugin\MauticStatusiBundle\ContactChanges\ContactFieldCatalog;
use MauticPlugin\MauticStatusiBundle\DTO\Export\ExportRequest;

/**
 * GET /api/custom/contact-attribute-export: one row per contact created or changed in the range,
 * in Statusi's contact_list_attribute terms, plus every published contact field under "fields"
 * (Statusi maps custom fields through the integration's fields_mapping). Times are UTC.
 */
class ContactExporter
{
    public function __construct(
        private ContactExportRepository $repository,
        private ContactFieldCatalog $fieldCatalog,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function export(ExportRequest $request): array
    {
        $afterId = null !== $request->cursor ? (int) ($request->cursor[0] ?? 0) : 0;
        $page    = $this->repository->page($request->dateFromSql(), $request->dateToSql(), $afterId, $request->limit);
        $hasMore = count($page) > $request->limit;
        $page    = array_slice($page, 0, $request->limit);

        $ids      = array_map(static fn (array $row): int => (int) $row['id'], $page);
        $aliases  = $this->fieldCatalog->aliases();
        $contacts = $this->repository->contacts($ids, $aliases);
        $dnc      = $this->repository->doNotContacts($ids);
        $ips      = $this->repository->lastIpAddresses($ids);

        $rows = [];
        foreach ($page as $match) {
            $id      = (int) $match['id'];
            $contact = $contacts[$id] ?? null;
            if (null === $contact) {
                continue;
            }

            $entries = $dnc[$id] ?? [];
            $dates   = ['unsubscribe' => null, 'complaint' => null, 'bounce' => null];
            foreach ($entries as $entry) {
                $key = match (true) {
                    StatusiValues::DNC_BOUNCED === $entry['reason']                                                   => 'bounce',
                    StatusiValues::DNC_UNSUBSCRIBED === $entry['reason'] && StatusiValues::isComplaint($entry['comments']) => 'complaint',
                    default                                                                                       => 'unsubscribe',
                };
                $dates[$key] ??= $entry['date_added'];
            }

            $fields = [];
            foreach ($aliases as $alias) {
                $fields[$alias] = $contact[$alias] ?? null;
            }

            $rows[] = [
                'contact_id'         => $id,
                'email'              => $this->text($contact['email'] ?? null),
                'created_date'       => $this->text($contact['date_added'] ?? null),
                'last_modified_date' => (string) $match['changed_ts'],
                'platform_status'    => StatusiValues::platformStatus(array_column($entries, 'reason')),
                'unsubscribe_date'   => $dates['unsubscribe'],
                'complaint_date'     => $dates['complaint'],
                'bounce_date'        => $dates['bounce'],
                'ip'                 => $ips[$id] ?? null,
                'country'            => $this->text($contact['country'] ?? null),
                'language'           => $this->text($contact['preferred_locale'] ?? null),
                'fields'             => $fields,
            ];
        }

        return [
            'data'       => $rows,
            'pagination' => [
                'limit'      => $request->limit,
                'returned'   => count($rows),
                'hasMore'    => $hasMore,
                'nextCursor' => $hasMore && $ids ? ExportCursor::encode([$ids[array_key_last($ids)]]) : null,
            ],
            'filters'    => [
                'dateFrom' => $request->dateFrom->format(DATE_ATOM),
                'dateTo'   => $request->dateTo->format(DATE_ATOM),
            ],
            'meta'       => [
                'timezone'    => 'UTC',
                'fields'      => $aliases,
                'generatedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
            ],
        ];
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
