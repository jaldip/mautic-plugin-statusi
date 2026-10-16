<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Tests\Integration;

use MauticPlugin\MauticStatusiBundle\Exception\InvalidReportCriteriaException;

final class ContactAttributesReportTest extends ContactChangesTestCase
{
    private const RANGE = ['dateFrom' => '2026-10-01 00:00:00', 'dateTo' => '2026-10-09 23:59:59'];

    // 1. Contact created inside the range
    public function testContactCreatedInRange(): void
    {
        $this->contact(1, '2026-10-05 09:00:00', '2026-10-05 09:00:00'); // Mautic sets date_modified = date_added on create

        $row = $this->report(self::RANGE)['data'][0];

        self::assertSame(1, $row['contact']['id']);
        self::assertSame('created', $row['changeInfo']['changeType']);
        self::assertTrue($row['changeInfo']['createdInRange']);
        self::assertFalse($row['changeInfo']['updatedInRange'], 'date_modified equal to date_added is the creation stamp, not an update');
        self::assertSame('2026-10-05T09:00:00+00:00', $row['changeInfo']['createdAt']);
        self::assertSame('2026-10-05T09:00:00+00:00', $row['contact']['dateAdded']);
    }

    // 2. Contact created before the range but modified inside it
    public function testContactCreatedBeforeAndUpdatedInRange(): void
    {
        $this->contact(1, '2026-09-01 10:00:00', '2026-09-01 10:00:00');
        $this->audit(10, 1, 'update', '2026-10-08 11:30:00', ['firstname' => ['Old', 'New'], 'fields' => ['firstname' => ['Old', 'New']]]);
        $this->contact(2, '2026-09-01 10:00:00', '2026-10-07 08:00:00'); // timestamp-only evidence (e.g. import with dateModified)

        $report = $this->report(self::RANGE);

        $audited = $this->row($report, 1)['changeInfo'];
        self::assertSame('updated', $audited['changeType']);
        self::assertFalse($audited['createdInRange']);
        self::assertSame('2026-10-08T11:30:00+00:00', $audited['lastUpdatedAt']);
        self::assertSame(['auditLog'], $audited['updateEvidence']);

        $stamped = $this->row($report, 2)['changeInfo'];
        self::assertSame('updated', $stamped['changeType']);
        self::assertSame(['dateModified'], $stamped['updateEvidence']);
        self::assertSame('2026-10-07T08:00:00+00:00', $stamped['matchedTimestamp']);
    }

    // 3. Created and modified inside the range: returned once, both conditions shown
    public function testCreatedAndUpdatedContactIsReturnedOnce(): void
    {
        $this->contact(1, '2026-10-02 09:00:00', '2026-10-06 12:00:00');
        $this->audit(10, 1, 'create', '2026-10-02 09:00:00', ['email' => [null, 'contact1@example.com']]);
        $this->audit(11, 1, 'update', '2026-10-04 12:00:00', ['city' => [null, 'Pune']]);
        $this->audit(12, 1, 'update', '2026-10-05 12:00:00', ['city' => ['Pune', 'Mumbai']]);

        $report = $this->report(self::RANGE);

        self::assertSame([1], $this->ids($report));
        self::assertSame(1, $report['pagination']['totalContacts']);
        $info = $report['data'][0]['changeInfo'];
        self::assertSame('created_and_updated', $info['changeType']);
        self::assertTrue($info['createdInRange']);
        self::assertTrue($info['updatedInRange']);
        self::assertSame(['auditLog', 'dateModified'], $info['updateEvidence']);
        self::assertSame('2026-10-06T12:00:00+00:00', $info['lastUpdatedAt'], 'latest of the update signals');
        self::assertSame('2026-10-06T12:00:00+00:00', $info['matchedTimestamp']);
    }

    // 4. Contact outside the range is excluded
    public function testContactOutsideRangeIsExcluded(): void
    {
        $this->contact(1, '2026-09-01 10:00:00', '2026-09-01 10:00:00');
        $this->audit(10, 1, 'update', '2026-09-15 10:00:00', ['city' => [null, 'Pune']]);
        $this->audit(11, 1, 'identified', '2026-10-05 10:00:00', []); // not an attribute update
        $this->contact(2, '2026-10-10 00:00:00', '2026-10-10 00:00:00');

        self::assertSame([], $this->report(self::RANGE)['data']);
    }

    // 5-7. changeType created / updated / all
    public function testChangeTypeFilters(): void
    {
        $this->contact(1, '2026-10-02 09:00:00', '2026-10-02 09:00:00');        // created only
        $this->contact(2, '2026-09-01 09:00:00', '2026-09-01 09:00:00');        // updated only
        $this->audit(20, 2, 'update', '2026-10-03 09:00:00', ['city' => [null, 'Pune']]);
        $this->contact(3, '2026-10-04 09:00:00', '2026-10-04 09:00:00');        // both
        $this->audit(30, 3, 'update', '2026-10-05 09:00:00', ['city' => [null, 'Pune']]);

        $created = $this->ids($this->report(self::RANGE + ['changeType' => 'created', 'sortBy' => 'id', 'order' => 'ASC']));
        $updated = $this->ids($this->report(self::RANGE + ['changeType' => 'updated', 'sortBy' => 'id', 'order' => 'ASC']));
        $all     = $this->report(self::RANGE + ['changeType' => 'all', 'sortBy' => 'id', 'order' => 'ASC']);

        self::assertSame([1, 3], $created);
        self::assertSame([2, 3], $updated);
        self::assertSame([1, 2, 3], $this->ids($all));
        self::assertSame(['created', 'updated', 'created_and_updated'], array_map(static fn (array $r): string => $r['changeInfo']['changeType'], $all['data']));

        // With changeType=created, only the creation signal is evaluated for that contact.
        $createdOnly = $this->row($this->report(self::RANGE + ['changeType' => 'created']), 3)['changeInfo'];
        self::assertSame('created', $createdOnly['changeType']);
    }

    // 8. Date boundaries (inclusive) and timezone conversion
    public function testBoundariesAndTimezone(): void
    {
        $this->contact(1, '2026-09-30 23:59:59', '2026-09-30 23:59:59');
        $this->contact(2, '2026-10-01 00:00:00', '2026-10-01 00:00:00');
        $this->contact(3, '2026-10-09 23:59:59', '2026-10-09 23:59:59');
        $this->contact(4, '2026-10-10 00:00:00', '2026-10-10 00:00:00');

        self::assertSame([2, 3], $this->ids($this->report(['dateFrom' => '2026-10-01', 'dateTo' => '2026-10-09', 'sortBy' => 'id', 'order' => 'ASC'])));

        // 2026-10-01 05:30 India (+05:30) = 2026-10-01 00:00 UTC
        $india = $this->report(['dateFrom' => '2026-10-01T05:29:59+05:30', 'dateTo' => '2026-10-01T05:30:00+05:30', 'sortBy' => 'id', 'order' => 'ASC']);
        self::assertSame('2026-09-30T23:59:59+00:00', $india['filters']['dateFrom']);
        self::assertSame([1, 2], $this->ids($india));
    }

    // 9-10. Pagination across pages with stable ordering (many identical timestamps)
    public function testPaginationIsCompleteAndStable(): void
    {
        for ($id = 1; $id <= 23; ++$id) {
            $this->contact($id, '2026-10-0'.(1 + $id % 3).' 10:00:00', '2026-10-0'.(1 + $id % 3).' 10:00:00');
        }

        $seen     = [];
        $previous = null;
        for ($page = 1; $page <= 3; ++$page) {
            $report = $this->report(self::RANGE + ['limit' => '10', 'page' => (string) $page]);
            self::assertSame(23, $report['pagination']['totalContacts']);
            self::assertSame(3, $report['pagination']['totalPages']);
            self::assertSame($page < 3, $report['pagination']['hasNextPage']);
            self::assertSame($page > 1, $report['pagination']['hasPreviousPage']);
            self::assertSame($this->ids($report), $this->ids($this->report(self::RANGE + ['limit' => '10', 'page' => (string) $page])), 'same page twice');

            foreach ($report['data'] as $row) {
                $key = [$row['changeInfo']['matchedTimestamp'], $row['contact']['id']];
                if (null !== $previous) {
                    self::assertTrue($previous > $key, 'strictly descending by timestamp, then id');
                }
                $previous = $key;
                $seen[]   = $row['contact']['id'];
            }
        }
        sort($seen);
        self::assertSame(range(1, 23), $seen, 'every contact exactly once');

        $beyond = $this->report(self::RANGE + ['limit' => '10', 'page' => '7']);
        self::assertSame([], $beyond['data']);
        self::assertSame(23, $beyond['pagination']['totalContacts']);
    }

    public function testSortByDateAddedAndDateModified(): void
    {
        $this->contact(1, '2026-10-03 10:00:00', '2026-10-08 10:00:00');
        $this->contact(2, '2026-10-05 10:00:00', '2026-10-06 10:00:00');
        $this->contact(3, '2026-10-02 10:00:00', null);

        self::assertSame([3, 1, 2], $this->ids($this->report(self::RANGE + ['sortBy' => 'dateAdded', 'order' => 'ASC'])));
        self::assertSame([1, 2, 3], $this->ids($this->report(self::RANGE + ['sortBy' => 'dateModified', 'order' => 'DESC'])), 'NULL sorts last in DESC');
        self::assertSame([3, 2, 1], $this->ids($this->report(self::RANGE + ['sortBy' => 'dateModified', 'order' => 'ASC'])), 'NULL sorts first in ASC');
    }

    // 11. Search and contact ID
    public function testSearchAndContactIdFilters(): void
    {
        $this->contact(1, '2026-10-02 10:00:00', '2026-10-02 10:00:00', ['email' => 'jane@acme.test', 'company' => 'Acme']);
        $this->contact(2, '2026-10-03 10:00:00', '2026-10-03 10:00:00', ['email' => 'raj@other.test', 'firstname' => 'Raj']);
        $this->contact(3, '2026-10-04 10:00:00', '2026-10-04 10:00:00', ['email' => '100%real@x.test']);

        self::assertSame([1], $this->ids($this->report(self::RANGE + ['search' => 'jane'])));
        self::assertSame([1], $this->ids($this->report(self::RANGE + ['search' => 'Acme'])));
        self::assertSame([2], $this->ids($this->report(self::RANGE + ['search' => 'Raj'])));
        self::assertSame([3], $this->ids($this->report(self::RANGE + ['search' => '100%'])), '% is literal');
        self::assertSame([], $this->ids($this->report(self::RANGE + ['search' => '%'])));
        self::assertSame([2], $this->ids($this->report(self::RANGE + ['contactId' => '2'])));
        self::assertSame([], $this->ids($this->report(['dateFrom' => '2026-11-01', 'dateTo' => '2026-11-02', 'contactId' => '2'])), 'contact must qualify');
    }

    // 12. Custom contact fields, allowlist and ?fields=
    public function testCustomFieldsAndFieldSelection(): void
    {
        $this->contact(1, '2026-10-02 10:00:00', '2026-10-02 10:00:00', ['loyalty_tier' => 'gold', 'internal_score' => 99, 'points' => 12]);

        $full = $this->report(self::RANGE)['data'][0]['contact'];
        self::assertSame('gold', $full['fields']['loyalty_tier']);
        self::assertSame(12, $full['fields']['points'], 'number fields are typed');
        self::assertArrayNotHasKey('internal_score', $full['fields'], 'unpublished field is never exposed');

        $selected = $this->report(self::RANGE + ['fields' => 'loyalty_tier,city'])['data'][0]['contact'];
        self::assertSame(['loyalty_tier', 'city'], array_keys($selected['fields']));
        self::assertSame('contact1@example.com', $selected['email'], 'core attributes are always present');

        try {
            $this->report(self::RANGE + ['fields' => 'internal_score']);
            self::fail('Unpublished field must be rejected.');
        } catch (InvalidReportCriteriaException $e) {
            self::assertArrayHasKey('fields', $e->getErrors());
        }
        $this->expectException(InvalidReportCriteriaException::class);
        $this->report(self::RANGE + ['fields' => 'id; DROP TABLE x']);
    }

    // 13. Missing / null timestamps
    public function testNullTimestamps(): void
    {
        $this->contact(1, null, '2026-10-03 10:00:00');                 // no creation date
        $this->contact(2, '2026-10-04 10:00:00', null);                 // tracking-created contact: no date_modified
        $this->contact(3, null, null);                                  // no dates at all
        $this->audit(30, 3, 'update', '2026-10-05 10:00:00', ['city' => [null, 'Pune']]);

        $report = $this->report(self::RANGE + ['sortBy' => 'id', 'order' => 'ASC']);

        self::assertSame([1, 2, 3], $this->ids($report));
        self::assertSame('updated', $this->row($report, 1)['changeInfo']['changeType']);
        self::assertNull($this->row($report, 1)['contact']['dateAdded']);
        self::assertSame('created', $this->row($report, 2)['changeInfo']['changeType']);
        self::assertNull($this->row($report, 2)['contact']['dateModified']);
        self::assertSame(['auditLog'], $this->row($report, 3)['changeInfo']['updateEvidence']);
    }

    public function testHistoryIsVerifiedAuditDataOnly(): void
    {
        $this->contact(1, '2026-09-01 10:00:00', '2026-09-01 10:00:00', ['city' => 'Mumbai', 'loyalty_tier' => 'gold']);
        $this->audit(10, 1, 'update', '2026-10-03 10:00:00', [
            'city'           => ['Pune', 'Mumbai'],
            'loyalty_tier'   => ['silver', 'gold'],
            'internal_score' => [1, 99],                                    // unpublished field: hidden
            'ipAddresses'    => ['', '203.0.113.7'],                         // not an attribute: hidden
            'dateIdentified' => [null, new \DateTime('2026-10-03 10:00:00', new \DateTimeZone('UTC'))],
            'fields'         => ['city' => ['Pune', 'Mumbai']],
            'manipulated_by' => 'form',
            'manipulator_key' => 'form:42',
        ]);
        $this->audit(11, 1, 'update', '2026-09-20 10:00:00', ['city' => ['Delhi', 'Pune']]); // before the range
        for ($i = 0; $i < 3; ++$i) {
            $this->audit(20 + $i, 1, 'update', '2026-10-04 1'.$i.':00:00', ['points' => [$i, $i + 1]], null, 'System');
        }

        $history = $this->report(self::RANGE + ['includeHistory' => 'true'])['data'][0]['history'];

        self::assertSame(4, $history['totalEntries'], 'only entries inside the range');
        self::assertSame(4, $history['entriesReturned']);
        $first = $history['entries'][3]; // newest first: the form entry is the oldest in range
        self::assertSame('update', $first['action']);
        self::assertSame('2026-10-03T10:00:00+00:00', $first['timestamp']);
        self::assertSame(['userId' => 1, 'userName' => 'Admin User'], $first['actor']);
        self::assertSame(['manipulatedBy' => 'form', 'manipulatorKey' => 'form:42'], $first['source']);
        self::assertSame(
            [
                ['field' => 'city', 'oldValue' => 'Pune', 'newValue' => 'Mumbai'],
                ['field' => 'loyalty_tier', 'oldValue' => 'silver', 'newValue' => 'gold'],
                ['field' => 'dateIdentified', 'oldValue' => null, 'newValue' => '2026-10-03T10:00:00+00:00'],
            ],
            $first['changes']
        );
        self::assertSame(['userId' => null, 'userName' => 'System'], $history['entries'][0]['actor']);

        $limited = $this->report(self::RANGE + ['includeHistory' => '1', 'historyLimit' => '2'])['data'][0]['history'];
        self::assertSame(2, $limited['entriesReturned']);
        self::assertTrue($limited['truncated']);
        self::assertSame(4, $limited['totalEntries']);

        self::assertArrayNotHasKey('history', $this->report(self::RANGE)['data'][0], 'history only on request');

        // Mautic stores "no date" as '' for dateIdentified (seen on a real install): returned as null.
        $this->audit(40, 1, 'update', '2026-10-05 10:00:00', ['dateIdentified' => ['', new \DateTime('2026-10-05 10:00:00', new \DateTimeZone('UTC'))]]);
        $identified = $this->report(self::RANGE + ['includeHistory' => 'true'])['data'][0]['history']['entries'];
        $match      = array_values(array_filter($identified, static fn (array $e): bool => 40 === $e['id']))[0];
        self::assertSame([['field' => 'dateIdentified', 'oldValue' => null, 'newValue' => '2026-10-05T10:00:00+00:00']], $match['changes']);
    }

    public function testDeletedContactsAndOwnerRestriction(): void
    {
        $this->audit(10, 999, 'update', '2026-10-03 10:00:00', ['city' => [null, 'Pune']]); // contact no longer exists
        $this->contact(1, '2026-10-02 10:00:00', '2026-10-02 10:00:00', ['owner_id' => 5]);
        $this->contact(2, '2026-10-02 10:00:00', '2026-10-02 10:00:00', ['owner_id' => 6]);
        self::$db->insert(MAUTIC_TABLE_PREFIX.'users', ['id' => 5, 'first_name' => 'Asha', 'last_name' => 'Patel', 'username' => 'asha', 'email' => 'asha@x.test', 'password' => 'x', 'is_published' => 1, 'role_id' => 1]);

        self::assertSame([1, 2], $this->ids($this->report(self::RANGE + ['sortBy' => 'id', 'order' => 'ASC'])), 'audit rows of deleted contacts are ignored');

        $own = $this->builder->build($this->criteria(self::RANGE)->withOwner(5));
        self::assertSame([1], $this->ids($own));
        self::assertSame(['id' => 5, 'name' => 'Asha Patel'], $own['data'][0]['contact']['owner']);
    }

    public function testQueryCountIsConstant(): void
    {
        $this->contact(1, '2026-10-02 10:00:00', '2026-10-02 10:00:00');
        $small = $this->report(self::RANGE + ['includeHistory' => 'true'])['meta']['queryCount'];

        for ($id = 2; $id <= 150; ++$id) {
            $this->contact($id, '2026-10-02 10:00:00', '2026-10-02 10:00:00', ['owner_id' => 5, 'stage_id' => 3]);
            $this->audit(1000 + $id, $id, 'update', '2026-10-03 10:00:00', ['city' => [null, 'Pune']]);
        }
        $large = $this->report(self::RANGE + ['includeHistory' => 'true', 'limit' => '150'])['meta']['queryCount'];

        self::assertLessThanOrEqual(5, $large, 'page, contacts, owners, stages, history');
        self::assertLessThanOrEqual($small + 2, $large, 'only the owner and stage lookups appear when such ids exist');
    }
}
