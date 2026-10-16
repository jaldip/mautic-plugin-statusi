<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Tests\Integration;

final class EmailActivityReportBuilderTest extends EmailActivityTestCase
{
    private const DAY = ['dateFrom' => '2026-10-09 00:00:00', 'dateTo' => '2026-10-09 23:59:59'];

    // 1. Multiple emails with activities in the requested period
    public function testMultipleEmailsAreGroupedSeparately(): void
    {
        $this->email(1, 'Welcome');
        $this->email(2, 'Newsletter');
        $this->contact(10);
        $this->send(100, 1, 10, '2026-10-09 05:00:00');
        $this->send(101, 2, 10, '2026-10-09 06:00:00');

        $report = $this->report(self::DAY);

        self::assertSame([2, 1], array_map(static fn (array $g): int => $g['email']['id'], $report['data']), 'latest activity first');
        self::assertSame(2, $report['pagination']['totalEmails']);
        self::assertSame('Welcome', $this->group($report, 1)['email']['name']);
        self::assertSame(1, $this->group($report, 1)['activitySummary']['sent']);
    }

    // 2. One email used in multiple campaigns
    public function testOneEmailInMultipleCampaignsKeepsEachCampaign(): void
    {
        $this->email(1, 'Welcome');
        $this->contact(10);
        $this->contact(11);
        $this->campaign(7, 'Onboarding');
        $this->campaign(8, 'Reactivation');
        $this->campaignStep(70, 7, 1, 'Send welcome');
        $this->campaignStep(80, 8, 1, 'Send again');
        $this->send(100, 1, 10, '2026-10-09 05:00:00', 70);
        $this->send(101, 1, 11, '2026-10-09 06:00:00', 80);

        $group = $this->group($this->report(self::DAY), 1);

        $campaigns = array_column($group['campaigns'], 'name', 'id');
        ksort($campaigns);
        self::assertSame([7 => 'Onboarding', 8 => 'Reactivation'], $campaigns);
        self::assertSame(7, $this->contactIn($group, 10)['activities'][0]['campaignId']);
        self::assertSame(8, $this->contactIn($group, 11)['activities'][0]['campaignId']);
        self::assertSame('Send again', $this->contactIn($group, 11)['activities'][0]['campaignActionName']);
    }

    // 3. One contact with multiple events for the same email
    public function testContactWithManyEventsKeepsEachEvent(): void
    {
        $this->email(1, 'Welcome');
        $this->contact(10);
        $this->send(100, 1, 10, '2026-10-09 05:00:00');
        $this->open(1, 100, '2026-10-09 05:10:00');
        $this->open(2, 100, '2026-10-09 07:10:00');
        $this->open(3, 100, '2026-10-09 09:10:00');
        $this->click(500, 1, 10, '2026-10-09 05:11:00', 'https://acme.test/a', 'hash100');

        $group   = $this->group($this->report(self::DAY), 1);
        $contact = $this->contactIn($group, 10);

        self::assertSame(['email.click', 'email.read', 'email.read', 'email.read', 'email.sent'], $this->events($contact));
        self::assertSame(1, $group['activitySummary']['sent'], 'three opens are not three sends');
        self::assertSame(3, $group['activitySummary']['opened']);
        self::assertSame(1, $group['activitySummary']['clicked']);
        self::assertSame(5, $group['activitySummary']['totalActivities']);
        self::assertSame(1, $group['activitySummary']['totalContacts']);
    }

    // 4. Multiple contacts associated with the same email
    public function testMultipleContactsUnderOneEmail(): void
    {
        $this->email(1, 'Welcome');
        foreach ([10, 11, 12] as $i => $lead) {
            $this->contact($lead);
            $this->send(100 + $i, 1, $lead, '2026-10-09 0'.($i + 1).':00:00');
        }

        $group = $this->group($this->report(self::DAY), 1);

        self::assertSame([12, 11, 10], array_map(static fn (array $c): int => $c['contact']['id'], $group['contacts']));
        self::assertSame(3, $group['activitySummary']['totalContacts']);
        self::assertSame('contact10@example.com', $this->contactIn($group, 10)['contact']['email']);
    }

    // 5. An email sent before the period but opened during the period
    public function testSendBeforePeriodIsContextNotActivity(): void
    {
        $this->email(1, 'Welcome');
        $this->contact(10);
        $this->send(100, 1, 10, '2026-10-08 22:00:00');
        $this->open(1, 100, '2026-10-09 08:00:00');

        $group    = $this->group($this->report(self::DAY), 1);
        $activity = $this->contactIn($group, 10)['activities'];

        self::assertCount(1, $activity);
        self::assertSame('email.read', $activity[0]['event']);
        self::assertSame('2026-10-08T22:00:00+00:00', $activity[0]['sentAt'], 'earlier send is attached as context');
        self::assertSame(0, $group['activitySummary']['sent']);
        self::assertSame(1, $group['activitySummary']['opened']);
    }

    // 6. An email created before the period but with no qualifying activities
    public function testEmailWithoutPeriodActivityIsNotReported(): void
    {
        $this->email(1, 'Old email', '2025-01-01 00:00:00');
        $this->email(2, 'Active email', '2025-01-01 00:00:00');
        $this->contact(10);
        $this->send(100, 1, 10, '2026-10-01 00:00:00'); // outside the period
        $this->send(101, 2, 10, '2026-10-09 10:00:00');

        $report = $this->report(self::DAY);

        self::assertSame([2], array_map(static fn (array $g): int => $g['email']['id'], $report['data']));
        self::assertSame('2025-01-01T00:00:00+00:00', $report['data'][0]['email']['createdAt']);
    }

    // 7. Click events with multiple destination URLs
    public function testClicksKeepEachDestination(): void
    {
        $this->email(1, 'Welcome');
        $this->contact(10);
        $this->send(100, 1, 10, '2026-10-09 05:00:00');
        $this->redirect(41, 'abc41', 'https://acme.test/pricing');
        $this->click(500, 1, 10, '2026-10-09 05:11:00', 'https://acme.test/pricing', 'hash100', null, 41);
        $this->click(501, 1, 10, '2026-10-09 05:12:00', 'https://acme.test/docs', 'hash100');

        $clicks = array_values(array_filter(
            $this->contactIn($this->group($this->report(self::DAY), 1), 10)['activities'],
            static fn (array $a): bool => 'email.click' === $a['event']
        ));

        $urls = array_column($clicks, 'clickedUrl');
        sort($urls);
        self::assertSame(['https://acme.test/docs', 'https://acme.test/pricing'], $urls);
        $pricing = 'https://acme.test/pricing' === $clicks[0]['clickedUrl'] ? $clicks[0] : $clicks[1];
        self::assertSame('abc41', $pricing['trackedLinkId']);
        self::assertSame(100, $pricing['statId']);
        self::assertSame('trackedLink', $pricing['campaignAttribution']);
    }

    // 8. A failed campaign email action and a DNC event
    public function testFailedCampaignStepAndBounce(): void
    {
        $this->email(1, 'Welcome');
        $this->contact(10);
        $this->campaign(7, 'Onboarding');
        $this->campaignStep(70, 7, 1);
        $this->campaignLog(900, 7, 70, 10, 1, '2026-10-09 04:59:58', 'Email address is invalid');
        $this->send(100, 1, 10, '2026-10-09 05:00:00', 70, true);
        $this->dnc(60, 10, 1, 2, '2026-10-09 05:01:00', '550 5.1.1 User unknown');

        $group      = $this->group($this->report(self::DAY), 1);
        $activities = array_column($this->contactIn($group, 10)['activities'], null, 'event');

        self::assertSame('Email address is invalid', $activities['campaign.event']['failureReason']);
        self::assertSame('Email address is invalid', $activities['email.failed']['failureReason']);
        self::assertSame('2026-10-09T04:59:58+00:00', $activities['email.failed']['campaignActionTriggeredAt']);
        self::assertSame('bounced', $activities['email.bounce']['dncReason']);
        self::assertSame('550 5.1.1 User unknown', $activities['email.bounce']['dncComments']);
        self::assertSame(1, $group['activitySummary']['failed']);
        self::assertSame(1, $group['activitySummary']['bounced']);
        self::assertSame(1, $group['activitySummary']['campaignSteps']);
    }

    // 9. Events with missing email or campaign associations
    public function testUnassociatedEventsAreReportedSeparately(): void
    {
        $this->email(1, 'Welcome');
        $this->contact(10);
        $this->send(100, null, 10, '2026-10-09 05:00:00');   // no email
        $this->dnc(60, 10, null, 1, '2026-10-09 05:01:00');  // unsubscribe without email
        $this->send(101, 1, 10, '2026-10-09 06:00:00');      // not from a campaign

        $report = $this->report(self::DAY);

        self::assertSame([1], array_map(static fn (array $g): int => $g['email']['id'], $report['data']));
        self::assertSame(1, $report['unassignedActivities']['sendsWithoutEmail']);
        self::assertSame(1, $report['unassignedActivities']['dncWithoutEmail']);
        $sent = $this->contactIn($report['data'][0], 10)['activities'][0];
        self::assertNull($sent['campaignId']);
        self::assertSame([], $report['data'][0]['campaigns']);
    }

    // 10. Null IP / geolocation information, and enrichment when present
    public function testMissingAndPresentNetworkDetails(): void
    {
        $this->email(1, 'Welcome');
        $this->contact(10);
        $this->send(100, 1, 10, '2026-10-09 05:00:00');
        $this->ip(5, '203.0.113.7', ['city' => 'Pune', 'region' => 'MH', 'country' => 'India', 'isp' => 'ExampleNet']);
        $this->device(6, 10, 'Chrome', 'Windows');
        $this->open(1, 100, '2026-10-09 05:10:00', 5, 6);
        $this->open(2, 100, '2026-10-09 05:20:00'); // no ip, no device

        $opens = array_values(array_filter(
            $this->contactIn($this->group($this->report(self::DAY + ['order' => 'ASC']), 1), 10)['activities'],
            static fn (array $a): bool => 'email.read' === $a['event']
        ));

        self::assertSame('203.0.113.7', $opens[0]['ipAddress']);
        self::assertSame('Pune', $opens[0]['city']);
        self::assertSame('India', $opens[0]['country']);
        self::assertSame('Chrome 129.0', $opens[0]['browser']);
        self::assertSame('Windows', $opens[0]['operatingSystem']);
        foreach (['ipAddress', 'country', 'region', 'city', 'browser', 'operatingSystem', 'device', 'userAgent'] as $field) {
            self::assertNull($opens[1][$field], $field);
        }
    }

    // 11. Date boundaries (inclusive) and timezone conversion
    public function testDateBoundariesAndTimezone(): void
    {
        $this->email(1, 'Welcome');
        $this->contact(10);
        $this->send(100, 1, 10, '2026-10-08 23:59:59'); // just before
        $this->send(101, 1, 10, '2026-10-09 00:00:00'); // first second
        $this->send(102, 1, 10, '2026-10-09 23:59:59'); // last second
        $this->send(103, 1, 10, '2026-10-10 00:00:00'); // just after

        $group = $this->group($this->report(['dateFrom' => '2026-10-09', 'dateTo' => '2026-10-09']), 1);
        self::assertSame(2, $group['activitySummary']['sent']);

        // 2026-10-09 00:00 to 23:59:59 in India (+05:30) is 2026-10-08 18:30:00 to 2026-10-09 18:29:59 UTC
        $india = $this->report(['dateFrom' => '2026-10-09T00:00:00+05:30', 'dateTo' => '2026-10-09T23:59:59+05:30']);
        self::assertSame('2026-10-08T18:30:00+00:00', $india['filters']['dateFrom']);
        self::assertSame(2, $this->group($india, 1)['activitySummary']['sent'], '23:59:59 on the 8th and 00:00 on the 9th UTC');
    }

    // 12. Event-type filtering (include, exclude, Mautic aliases)
    public function testEventTypeFilters(): void
    {
        $this->email(1, 'Welcome');
        $this->contact(10);
        $this->send(100, 1, 10, '2026-10-09 05:00:00');
        $this->open(1, 100, '2026-10-09 05:10:00');
        $this->click(500, 1, 10, '2026-10-09 05:11:00', 'https://acme.test', 'hash100');

        $onlyOpens = $this->contactIn($this->group($this->report(self::DAY + ['includeEvents' => ['email.read']]), 1), 10);
        self::assertSame(['email.read'], $this->events($onlyOpens));

        $noOpens = $this->contactIn($this->group($this->report(self::DAY + ['excludeEvents' => 'email.read']), 1), 10);
        self::assertSame(['email.click', 'email.sent'], $this->events($noOpens));

        $alias = $this->contactIn($this->group($this->report(self::DAY + ['includeEvents' => ['page.hit']]), 1), 10);
        self::assertSame(['email.click'], $this->events($alias));
    }

    // 13. Email, contact and campaign filtering
    public function testEmailContactAndCampaignFilters(): void
    {
        $this->email(1, 'Welcome');
        $this->email(2, 'Newsletter');
        $this->contact(10);
        $this->contact(11);
        $this->campaign(7, 'Onboarding');
        $this->campaignStep(70, 7, 1);
        $this->send(100, 1, 10, '2026-10-09 05:00:00', 70);
        $this->send(101, 1, 11, '2026-10-09 05:00:00');      // not from the campaign
        $this->send(102, 2, 10, '2026-10-09 06:00:00');
        $this->click(500, 1, 10, '2026-10-09 05:11:00', 'https://acme.test', 'hash100');
        $this->click(501, 1, 11, '2026-10-09 05:12:00', 'https://acme.test', 'hash101');
        $this->dnc(60, 10, 1, 1, '2026-10-09 07:00:00');

        $byEmail = $this->report(self::DAY + ['emailId' => '2']);
        self::assertSame([2], array_map(static fn (array $g): int => $g['email']['id'], $byEmail['data']));

        $byContact = $this->report(self::DAY + ['contactId' => '11']);
        self::assertSame([1], array_map(static fn (array $g): int => $g['email']['id'], $byContact['data']));
        self::assertSame([11], array_map(static fn (array $c): int => $c['contact']['id'], $byContact['data'][0]['contacts']));

        $byCampaign = $this->group($this->report(self::DAY + ['campaignId' => '7']), 1);
        self::assertSame([10], array_map(static fn (array $c): int => $c['contact']['id'], $byCampaign['contacts']));
        self::assertSame(['email.click', 'email.sent'], $this->events($byCampaign['contacts'][0]), 'DNC has no campaign link');
        self::assertSame(2, $byCampaign['activitySummary']['totalActivities']);
    }

    // 14. Stable pagination across pages
    public function testPaginationIsStableAndComplete(): void
    {
        $this->contact(10);
        for ($email = 1; $email <= 30; ++$email) {
            $this->email($email, 'Email '.$email);
            // Several emails share the same latest timestamp to exercise the tie-breaker.
            $this->send(1000 + $email, $email, 10, '2026-10-09 0'.($email % 3).':00:00');
        }

        $seen = [];
        for ($page = 1; $page <= 5; ++$page) {
            $report = $this->report(self::DAY + ['limit' => '7', 'page' => (string) $page]);
            self::assertSame(30, $report['pagination']['totalEmails']);
            self::assertSame(5, $report['pagination']['totalPages']);
            self::assertSame($page < 5, $report['pagination']['hasNextPage']);
            self::assertSame($page > 1, $report['pagination']['hasPreviousPage']);
            foreach ($report['data'] as $group) {
                $seen[] = $group['email']['id'];
            }
            self::assertSame($report, $this->stripGenerated($this->report(self::DAY + ['limit' => '7', 'page' => (string) $page]), $report), 'same page twice gives the same result');
        }

        sort($seen);
        self::assertSame(range(1, 30), $seen, 'every email exactly once');

        $beyond = $this->report(self::DAY + ['limit' => '7', 'page' => '9']);
        self::assertSame([], $beyond['data']);
        self::assertSame(30, $beyond['pagination']['totalEmails']);
    }

    // 15. Duplicate prevention (joins to IP, device and redirect tables)
    public function testNoDuplicateActivities(): void
    {
        $this->email(1, 'Welcome');
        $this->contact(10);
        $this->send(100, 1, 10, '2026-10-09 05:00:00');
        $this->send(101, 1, 10, '2026-10-09 06:00:00'); // second send of the same email
        $this->ip(5, '203.0.113.7', []);
        $this->device(6, 10, 'Chrome', 'Windows');
        $this->redirect(41, 'abc41', 'https://acme.test');
        $this->open(1, 100, '2026-10-09 05:10:00', 5, 6);
        $this->click(500, 1, 10, '2026-10-09 05:11:00', 'https://acme.test', 'hash100', 5, 41);

        $contact = $this->contactIn($this->group($this->report(self::DAY), 1), 10);
        $ids     = array_column($contact['activities'], 'eventId');

        self::assertSame(count($ids), count(array_unique($ids)));
        self::assertSame(['email.click', 'email.read', 'email.sent', 'email.sent'], $this->events($contact));
        self::assertSame(4, $contact['activityCount']);
    }

    // 16. Emails with many contacts and activities: bounded, paged, truncation is explicit
    public function testLargeEmailIsBoundedAndPaged(): void
    {
        $this->email(1, 'Big send');
        for ($lead = 1; $lead <= 120; ++$lead) {
            $this->contact($lead);
            $this->send($lead, 1, $lead, sprintf('2026-10-09 %02d:%02d:00', intdiv($lead, 60), $lead % 60));
        }
        for ($i = 1; $i <= 15; ++$i) {
            $this->open($i, 120, sprintf('2026-10-09 10:%02d:00', $i)); // contact 120 opens 15 times
        }

        $report = $this->report(self::DAY + ['contactLimit' => '50', 'contactPage' => '1', 'activityLimit' => '5']);
        $group  = $report['data'][0];

        self::assertCount(50, $group['contacts']);
        self::assertSame(120, $group['contactsPagination']['totalContacts']);
        self::assertSame(3, $group['contactsPagination']['totalPages']);
        self::assertTrue($group['contactsPagination']['hasNextPage']);

        $heavy = $this->contactIn($group, 120);
        self::assertSame(16, $heavy['activityCount']);
        self::assertSame(5, $heavy['activitiesReturned']);
        self::assertTrue($heavy['activitiesTruncated']);
        self::assertSame(135, $group['activitySummary']['totalActivities'], 'summary covers every activity, not just the page');

        $all = [];
        for ($page = 1; $page <= 3; ++$page) {
            $paged = $this->report(self::DAY + ['contactLimit' => '50', 'contactPage' => (string) $page, 'includeActivities' => 'false']);
            foreach ($paged['data'][0]['contacts'] as $contact) {
                self::assertArrayNotHasKey('activities', $contact);
                $all[] = $contact['contact']['id'];
            }
        }
        sort($all);
        self::assertSame(range(1, 120), $all);
    }

    public function testSummaryOnlyModeSkipsContacts(): void
    {
        $this->email(1, 'Welcome');
        $this->contact(10);
        $this->send(100, 1, 10, '2026-10-09 05:00:00');

        $group = $this->report(self::DAY + ['includeContacts' => 'false'])['data'][0];

        self::assertArrayNotHasKey('contacts', $group);
        self::assertSame(1, $group['activitySummary']['sent']);
    }

    public function testSearchMatchesEmailNameAndContactEmail(): void
    {
        $this->email(1, 'Spring sale');
        $this->email(2, 'Welcome');
        $this->contact(10, 'jane@acme.test');
        $this->contact(11, 'raj@other.test');
        $this->send(100, 1, 11, '2026-10-09 05:00:00');
        $this->send(101, 2, 10, '2026-10-09 06:00:00');
        $this->send(102, 2, 11, '2026-10-09 07:00:00');

        self::assertSame([1], array_column(array_column($this->report(self::DAY + ['search' => 'spring'])['data'], 'email'), 'id'));
        $byContact = $this->report(self::DAY + ['search' => 'jane@'])['data'];
        self::assertSame([2], array_column(array_column($byContact, 'email'), 'id'));
        self::assertSame([10], array_map(static fn (array $c): int => $c['contact']['id'], $byContact[0]['contacts']));
        self::assertSame([], $this->report(self::DAY + ['search' => '100%'])['data'], 'LIKE wildcards are escaped');
    }

    public function testOwnerRestrictionsLimitContactsAndEmails(): void
    {
        $this->email(1, 'Mine', '2026-01-01 00:00:00', 5);
        $this->email(2, 'Not mine', '2026-01-01 00:00:00', 6);
        $this->contact(10, null, 5);
        $this->contact(11, null, 6);
        $this->send(100, 1, 10, '2026-10-09 05:00:00');
        $this->send(101, 1, 11, '2026-10-09 05:00:00');
        $this->send(102, 2, 10, '2026-10-09 05:00:00');

        $criteria = (new \MauticPlugin\MauticStatusiBundle\EmailActivity\ReportCriteriaFactory())
            ->fromRequest(\Symfony\Component\HttpFoundation\Request::create('/', 'GET', self::DAY))
            ->withAccessRestrictions(5, 5);
        $report = $this->builder->build($criteria);

        self::assertSame([1], array_column(array_column($report['data'], 'email'), 'id'));
        self::assertSame([10], array_map(static fn (array $c): int => $c['contact']['id'], $report['data'][0]['contacts']));
    }

    public function testSortByNameAndTotals(): void
    {
        $this->email(1, 'Bravo');
        $this->email(2, 'Alpha');
        $this->contact(10);
        $this->send(100, 1, 10, '2026-10-09 05:00:00');
        $this->open(1, 100, '2026-10-09 05:10:00');
        $this->send(101, 2, 10, '2026-10-09 06:00:00');

        self::assertSame([2, 1], array_column(array_column($this->report(self::DAY + ['sortBy' => 'emailName', 'order' => 'ASC'])['data'], 'email'), 'id'));
        self::assertSame([1, 2], array_column(array_column($this->report(self::DAY + ['sortBy' => 'totalActivities'])['data'], 'email'), 'id'));
    }

    public function testQueryCountDoesNotGrowWithData(): void
    {
        $this->email(1, 'Welcome');
        $this->contact(10);
        $this->send(100, 1, 10, '2026-10-09 05:00:00');
        $small = $this->report(self::DAY)['meta']['queryCount'];

        for ($i = 2; $i <= 40; ++$i) {
            $this->email($i, 'Email '.$i);
            $this->contact(100 + $i);
            $this->send(1000 + $i, $i, 100 + $i, '2026-10-09 06:00:00');
            $this->open($i, 1000 + $i, '2026-10-09 07:00:00');
            $this->click(5000 + $i, $i, 100 + $i, '2026-10-09 07:01:00', 'https://acme.test', 'hash'.(1000 + $i));
        }
        $large = $this->report(self::DAY + ['limit' => '40'])['meta']['queryCount'];

        // 40x the emails, contacts and activities: only the fixed set of batched lookups is added
        // (click rows, IPs, devices, redirects when present), never one query per row.
        self::assertLessThanOrEqual(17, $large);
        self::assertLessThanOrEqual($small + 4, $large);
    }

    /**
     * @param array<string, mixed> $report
     * @param array<string, mixed> $reference
     *
     * @return array<string, mixed>
     */
    private function stripGenerated(array $report, array $reference): array
    {
        // Timing values differ between runs; everything else must be identical.
        $report['meta']['generatedAt'] = $reference['meta']['generatedAt'];
        $report['meta']['dbTimeMs']    = $reference['meta']['dbTimeMs'];

        return $report;
    }
}
