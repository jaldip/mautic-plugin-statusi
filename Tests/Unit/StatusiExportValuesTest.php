<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Tests\Unit;

use MauticPlugin\MauticStatusiBundle\DTO\Export\ExportRequest;
use MauticPlugin\MauticStatusiBundle\Exception\InvalidReportCriteriaException;
use MauticPlugin\MauticStatusiBundle\Export\ExportCursor;
use MauticPlugin\MauticStatusiBundle\Export\StatusiValues;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class StatusiExportValuesTest extends TestCase
{
    /**
     * @return iterable<string, array{int, ?string, ?string}>
     */
    public static function dncTypes(): iterable
    {
        yield 'unsubscribe'                  => [1, 'User unsubscribed', 'unsubscribe'];
        yield 'unsubscribe without comment'  => [1, null, 'unsubscribe'];
        yield 'spam complaint'               => [1, 'Marked as spam by recipient', 'complaint'];
        yield 'feedback loop complaint'      => [1, 'Feedback Loop: abuse report', 'complaint'];
        yield 'bounce'                       => [2, '550 5.1.1 User unknown', 'hard_bounce'];
        yield 'manual is not tracked'        => [3, 'Added by admin', null];
    }

    #[DataProvider('dncTypes')]
    public function testDncActivityType(int $reason, ?string $comments, ?string $expected): void
    {
        self::assertSame($expected, StatusiValues::dncActivityType($reason, $comments));
    }

    public function testPlatformStatus(): void
    {
        self::assertSame('subscribed', StatusiValues::platformStatus([]));
        self::assertSame('unsubscribed', StatusiValues::platformStatus([1]));
        self::assertSame('unsubscribed', StatusiValues::platformStatus([3]));
        self::assertSame('cleaned', StatusiValues::platformStatus([1, 2]));
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function countries(): iterable
    {
        yield 'English name'        => ['India', 'IN'];
        yield 'case and spaces'     => [' united kingdom ', 'GB'];
        yield 'IP lookup variant'   => ['United States of America', 'US'];
        yield 'already a code'      => ['de', 'DE'];
        yield 'unknown'             => ['Atlantis', null];
        yield 'empty'               => ['', null];
        yield 'null'                => [null, null];
    }

    #[DataProvider('countries')]
    public function testCountryCode(?string $country, ?string $expected): void
    {
        self::assertSame($expected, StatusiValues::countryCode($country));
    }

    public function testDaysPassed(): void
    {
        self::assertSame(0, StatusiValues::daysPassed('2026-10-01 10:00:00', '2026-10-02 09:59:59'));
        self::assertSame(1, StatusiValues::daysPassed('2026-10-01 10:00:00', '2026-10-02 10:00:00'));
        self::assertNull(StatusiValues::daysPassed(null, '2026-10-02 10:00:00'));
        self::assertNull(StatusiValues::daysPassed('2026-10-03 00:00:00', '2026-10-02 10:00:00'));
    }

    public function testCursorRoundTripAndRejectsGarbage(): void
    {
        $key = ['2026-10-13 05:00:00', 3, 1234];
        self::assertSame($key, ExportCursor::decode(ExportCursor::encode($key)));
        self::assertNull(ExportCursor::decode('not a cursor!'));
        self::assertNull(ExportCursor::decode(rtrim(strtr(base64_encode('{"a":1}'), '+/', '-_'), '=')), 'a JSON object is not a cursor');
    }

    public function testRequestDefaultsAndDayRange(): void
    {
        $request = ExportRequest::fromRequest(Request::create('/', 'GET', ['dateFrom' => '2026-10-13', 'dateTo' => '2026-10-13']));

        self::assertSame('2026-10-13 00:00:00', $request->dateFromSql());
        self::assertSame('2026-10-13 23:59:59', $request->dateToSql());
        self::assertSame(1000, $request->limit);
        self::assertNull($request->cursor);
    }

    public function testRequestValidation(): void
    {
        try {
            ExportRequest::fromRequest(Request::create('/', 'GET', ['dateFrom' => '2026-01-01', 'dateTo' => '2026-03-01', 'limit' => '9999', 'cursor' => '%%%']));
            self::fail('Expected InvalidReportCriteriaException');
        } catch (InvalidReportCriteriaException $e) {
            self::assertSame(['dateTo', 'limit', 'cursor'], array_keys($e->getErrors()));
        }
    }
}
