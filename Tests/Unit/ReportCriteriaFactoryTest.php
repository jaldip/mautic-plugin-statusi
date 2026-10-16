<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Tests\Unit;

use MauticPlugin\MauticStatusiBundle\DTO\EmailActivity\ReportCriteria;
use MauticPlugin\MauticStatusiBundle\EmailActivity\ActivityType;
use MauticPlugin\MauticStatusiBundle\EmailActivity\ReportCriteriaFactory;
use MauticPlugin\MauticStatusiBundle\Exception\InvalidReportCriteriaException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ReportCriteriaFactoryTest extends TestCase
{
    /**
     * @param array<string, mixed> $query
     */
    private function criteria(array $query): ReportCriteria
    {
        return (new ReportCriteriaFactory())->fromRequest(Request::create('/', 'GET', $query));
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, string>
     */
    private function errors(array $query): array
    {
        try {
            $this->criteria($query);
        } catch (InvalidReportCriteriaException $e) {
            return $e->getErrors();
        }
        self::fail('Expected a validation error.');
    }

    public function testDefaults(): void
    {
        $criteria = $this->criteria(['dateFrom' => '2026-10-09', 'dateTo' => '2026-10-09']);

        self::assertSame('2026-10-09 00:00:00', $criteria->dateFromSql());
        self::assertSame('2026-10-09 23:59:59', $criteria->dateToSql(), 'date-only dateTo is the end of that day');
        self::assertSame(ActivityType::ALL, $criteria->eventTypes);
        self::assertSame([1, 25, 'DESC', 'latestActivity'], [$criteria->page, $criteria->limit, $criteria->order, $criteria->sortBy]);
        self::assertSame([1, 50, 100], [$criteria->contactPage, $criteria->contactLimit, $criteria->activityLimit]);
        self::assertTrue($criteria->includeContacts);
        self::assertTrue($criteria->includeActivities);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function dates(): iterable
    {
        yield 'naive datetime is UTC' => ['2026-10-09 05:30:00', '2026-10-09 05:30:00'];
        yield 'T separator'           => ['2026-10-09T05:30:00', '2026-10-09 05:30:00'];
        yield 'Z suffix'              => ['2026-10-09T05:30:00Z', '2026-10-09 05:30:00'];
        yield 'India offset'          => ['2026-10-09T05:30:00+05:30', '2026-10-09 00:00:00'];
        yield 'negative offset'       => ['2026-10-08T20:00:00-04:00', '2026-10-09 00:00:00'];
    }

    #[DataProvider('dates')]
    public function testDatesAreConvertedToUtc(string $input, string $utc): void
    {
        self::assertSame($utc, $this->criteria(['dateFrom' => $input, 'dateTo' => '2026-10-10'])->dateFromSql());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalid(): iterable
    {
        yield 'missing dateFrom'      => [['dateTo' => '2026-10-09'], 'dateFrom'];
        yield 'missing dateTo'        => [['dateFrom' => '2026-10-09'], 'dateTo'];
        yield 'impossible date'       => [['dateFrom' => '2026-02-30', 'dateTo' => '2026-03-01'], 'dateFrom'];
        yield 'free text date'        => [['dateFrom' => 'yesterday', 'dateTo' => '2026-10-09'], 'dateFrom'];
        yield 'reversed range'        => [['dateFrom' => '2026-10-10', 'dateTo' => '2026-10-09'], 'dateTo'];
        yield 'range too long'        => [['dateFrom' => '2025-01-01', 'dateTo' => '2026-10-09'], 'dateTo'];
        yield 'unknown event'         => [['dateFrom' => '2026-10-09', 'dateTo' => '2026-10-09', 'includeEvents' => ['email.nope']], 'includeEvents'];
        yield 'everything excluded'   => [['dateFrom' => '2026-10-09', 'dateTo' => '2026-10-09', 'includeEvents' => ['email.sent'], 'excludeEvents' => ['email.sent']], 'excludeEvents'];
        yield 'limit too high'        => [['dateFrom' => '2026-10-09', 'dateTo' => '2026-10-09', 'limit' => '1000'], 'limit'];
        yield 'page zero'             => [['dateFrom' => '2026-10-09', 'dateTo' => '2026-10-09', 'page' => '0'], 'page'];
        yield 'negative id'           => [['dateFrom' => '2026-10-09', 'dateTo' => '2026-10-09', 'contactId' => '-3'], 'contactId'];
        yield 'sql in id'             => [['dateFrom' => '2026-10-09', 'dateTo' => '2026-10-09', 'emailId' => '1 OR 1=1'], 'emailId'];
        yield 'bad order'             => [['dateFrom' => '2026-10-09', 'dateTo' => '2026-10-09', 'order' => 'sideways'], 'order'];
        yield 'bad sort'              => [['dateFrom' => '2026-10-09', 'dateTo' => '2026-10-09', 'sortBy' => 'name; DROP'], 'sortBy'];
        yield 'bad bool'              => [['dateFrom' => '2026-10-09', 'dateTo' => '2026-10-09', 'includeContacts' => 'maybe'], 'includeContacts'];
        yield 'contact limit too big' => [['dateFrom' => '2026-10-09', 'dateTo' => '2026-10-09', 'contactLimit' => '501'], 'contactLimit'];
        yield 'search too long'       => [['dateFrom' => '2026-10-09', 'dateTo' => '2026-10-09', 'search' => str_repeat('a', 101)], 'search'];
    }

    /**
     * @param array<string, mixed> $query
     */
    #[DataProvider('invalid')]
    public function testInvalidInputIsRejected(array $query, string $field): void
    {
        self::assertArrayHasKey($field, $this->errors($query));
    }

    public function testEventListsAcceptArraysCommasAndAliases(): void
    {
        $base = ['dateFrom' => '2026-10-09', 'dateTo' => '2026-10-09'];

        self::assertSame(['email.sent', 'email.read'], $this->criteria($base + ['includeEvents' => ['email.sent', 'email.read']])->eventTypes);
        self::assertSame(['email.sent', 'email.click'], $this->criteria($base + ['includeEvents' => 'email.sent,page.hit'])->eventTypes);
        self::assertSame(
            ['email.unsubscribe', 'email.bounce', 'email.dnc_manual'],
            $this->criteria($base + ['includeEvents' => ['lead.donotcontact']])->eventTypes
        );
        self::assertNotContains('email.read', $this->criteria($base + ['excludeEvents' => ['email.open']])->eventTypes);
    }

    public function testAccessRestrictionsAreAddedWithoutChangingFilters(): void
    {
        $criteria   = $this->criteria(['dateFrom' => '2026-10-09', 'dateTo' => '2026-10-09', 'emailId' => '4']);
        $restricted = $criteria->withAccessRestrictions(7, null);

        self::assertSame(7, $restricted->leadOwnerId);
        self::assertNull($restricted->emailCreatedBy);
        self::assertSame(4, $restricted->emailId);
        self::assertNull($criteria->leadOwnerId, 'original stays unchanged');
    }
}
