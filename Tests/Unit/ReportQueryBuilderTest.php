<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Tests\Unit;

use MauticPlugin\MauticStatusiBundle\Exception\InvalidReportCriteriaException;
use MauticPlugin\MauticStatusiBundle\Service\ReportQueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Expected values follow statusi_ui getDefaultFilters.ts applyDefaultFilters() / ReportTable.tsx fetchDynamicQueryData().
 */
final class ReportQueryBuilderTest extends TestCase
{
    private ReportQueryBuilder $builder;

    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        $this->builder = new ReportQueryBuilder();
        // A Thursday
        $this->today = new \DateTimeImmutable('2026-10-15 09:30:00', new \DateTimeZone('UTC'));
    }

    /**
     * @return iterable<string, array{array<int, array<string, mixed>>, array<int, array<string, mixed>>}>
     */
    public static function defaultFilters(): iterable
    {
        yield 'list dropdown with values' => [
            [['filter_name' => 'list_dropdown', 'field' => 'list.name', 'placeholder' => 'List', 'default_values' => ['A', 'B']]],
            [self::filter('List', 'list.name', 'CONTAINS', ['A', 'B'])],
        ];
        yield 'list dropdown "All" adds nothing' => [
            [['filter_name' => 'list_dropdown', 'field' => 'list.name', 'default_values' => ['All']]],
            [],
        ];
        yield 'seven days uses default_value as day count' => [
            [['filter_name' => 'isSevenDays', 'field' => 'activity_date', 'placeholder' => 'Date', 'default_value' => '14']],
            [self::filter('Date', 'activity_date', 'BETWEEN', ['2026-10-01', '2026-10-14'])],
        ];
        yield 'thirty days with transform' => [
            [['filter_name' => 'isThirtyDays', 'field' => 'activity_date', 'transform' => 'week']],
            [self::filter('isThirtyDays', 'activity_date', 'BETWEEN', ['2026-09-15', '2026-10-14']) + ['transform' => 'week']],
        ];
        yield 'stored custom date range' => [
            [['filter_name' => 'isNinetyDays', 'field' => 'd', 'default_value' => ['2026-01-01', '2026-02-01']]],
            [self::filter('isNinetyDays', 'd', 'BETWEEN', ['2026-01-01', '2026-02-01'])],
        ];
        yield 'campaign type dropdown is always quiz' => [
            [['filter_name' => 'campaign_type_dropdown', 'field' => 'c.type']],
            [self::filter('campaign_type_dropdown', 'c.type', 'LIKE', ['quiz'])],
        ];
        yield 'campaign/message type key values grouped, include and exclude' => [
            [
                ['filter_name' => 'campaign_type', 'field' => 'tags', 'default_values' => [['key' => 'k1', 'value' => 'a'], ['key' => 'k1', 'value' => 'b'], ['key' => 'k2', 'value' => 'c'], ['key' => '', 'value' => 'x']]],
                ['filter_name' => 'message_type', 'field' => 'mtags', 'include_key' => false, 'default_values' => [['key' => 'm', 'value' => 'v']]],
            ],
            [
                self::filter('campaign_type', 'tags', 'HAS_KEY_VALUE', [['k1', ['a', 'b']], ['k2', ['c']]]),
                self::filter('message_type', 'mtags', 'NOT_HAS_KEY_VALUE', [['m', ['v']]]),
            ],
        ];
        yield 'date selector is yesterday' => [
            [['filter_name' => 'dateSelector', 'field' => 'd']],
            [self::filter('dateSelector', 'd', 'BETWEEN', ['2026-10-14', '2026-10-14'])],
        ];
        yield 'test campaigns are excluded' => [
            [['filter_name' => 'test_campaign_column', 'field' => 'c.name']],
            [self::filter('test_campaign_column', 'c.name', 'NOT LIKE', ['TEST'])],
        ];
        yield 'lead gen range is the week ending the previous wednesday' => [
            [['filter_name' => 'lead_gen_range', 'field' => [['from' => 'start'], ['to' => 'end']]]],
            [
                self::filter('lead_gen_range', 'start', '=', ['2026-10-08']),
                self::filter('lead_gen_range', 'end', '=', ['2026-10-14']),
            ],
        ];
        yield 'platform values' => [
            [['filter_name' => 'platform_id', 'field' => 'p', 'default_values' => [['label' => 'X', 'value' => 3]]]],
            [self::filter('platform_id', 'p', 'CONTAINS', [3])],
        ];
        yield 'month range needs both ends' => [
            [['filter_name' => 'monthRange', 'field' => 'm', 'default_values' => ['2026-01', '2026-06']]],
            [self::filter('monthRange', 'm', 'BETWEEN', ['2026-01', '2026-06'])],
        ];
        yield 'campaign name contains several values' => [
            [['filter_name' => 'campaign_name_contains', 'field' => 'n', 'default_value' => 'foo, bar ,']],
            [self::filter('campaign_name_contains', 'n', 'MULTI_CONTAINS', ['foo', 'bar'])],
        ];
        yield 'number of sent is sent as a string' => [
            [['filter_name' => 'number_of_sent', 'field' => 's', 'default_values' => [5]]],
            [self::filter('number_of_sent', 's', '=', '5')],
        ];
        yield 'ui-only filters add nothing' => [
            [['filter_name' => 'seed_column', 'default_value' => 'include_column'], ['filter_name' => 'source_dropdown', 'default_values' => ['x']]],
            [],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $config
     * @param array<int, array<string, mixed>> $expected
     */
    #[DataProvider('defaultFilters')]
    public function testApplyDefaultFilters(array $config, array $expected): void
    {
        self::assertSame($expected, $this->builder->applyDefaultFilters($config, $this->today));
    }

    public function testBuildRequestMatchesStatusiUi(): void
    {
        $configuration = '{"base":null,"cte":null,"rows":[{"field":"isp","lable":"ISP","format":{}}],'
            .'"values":[{"field":"sent","lable":"Sent","action":"sum"}],"columns":[],'
            .'"filters":[{"filter_name":"test_campaign_column","field":"c.name"}],'
            .'"system_filter":[{"field":"x","action":"=","values":["1"]}],"system_rows":[{"field":"year"}]}';

        $body = $this->builder->buildRequest($configuration, 'isp-report', [], 3, $this->today);

        self::assertSame(
            '{"base":null,"cte":null,"rows":[{"field":"isp","lable":"ISP","format":{}}],"values":[{"field":"sent","lable":"Sent","action":"sum"}],'
            .'"operator":{},"redis_flag":false,"pagination":{"pageIndex":3,"pageSize":20,"rowCount":0},'
            .'"filters":[{"lable":"test_campaign_column","field":"c.name","action":"NOT LIKE","values":["TEST"],"id":"","type":"basic","oprator":"AND"},{"field":"x","action":"=","values":["1"]}],'
            .'"columns":[],"slug":"isp-report","system_rows":[{"field":"year"}]}',
            json_encode($body),
            'empty objects ("format":{}, "operator":{}) must stay objects'
        );
    }

    public function testContactAttributeBaseSendsFiltersInsideCte(): void
    {
        $configuration = '{"base":"contact-attribute","cte":{"name":"c"},"rows":[],"values":[],'
            .'"filters":[{"filter_name":"dateSelector","field":"d"}],"system_filter":[{"field":"s"}],"pagination":{"pageSize":50}}';

        $body = $this->builder->buildRequest($configuration, null, ['isp' => 'gmail'], 1, $this->today);

        self::assertSame('{"name":"c","filters":[{"lable":"dateSelector","field":"d","action":"BETWEEN","values":["2026-10-14","2026-10-14"],"id":"","type":"basic","oprator":"AND"}]}', json_encode($body['cte']));
        self::assertSame([['field' => 's']], json_decode((string) json_encode($body['filters']), true));
        self::assertSame(['pageIndex' => 1, 'pageSize' => 50, 'rowCount' => 0], $body['pagination']);
        self::assertSame(['isp' => 'gmail'], $body['operator']);
    }

    public function testDescribeFilters(): void
    {
        $configuration = '{"filters":[{"filter_name":"isSevenDays","field":"d","placeholder":"Last 7 days"},{"filter_name":"test_campaign_column","field":"c","placeholder":"Test campaigns"}]}';

        self::assertSame([
            ['label' => 'Last 7 days', 'value' => '2026-10-08 → 2026-10-14'],
            ['label' => 'Test campaigns', 'value' => 'excludes TEST'],
        ], $this->builder->describeFilters($configuration, $this->today));
    }

    public function testSanitizeOperatorAllowsConfiguredDrillPath(): void
    {
        $configuration = '{"rows":[{"field":"year"},{"field":"isp","hasRowId":true,"rowField":"isp_id"},{"field":"day"}],"system_rows":[{"field":"sys"}]}';

        self::assertSame(['year' => 2026, 'isp_id' => '7', 'sys' => 'x'], $this->builder->sanitizeOperator($configuration, ['year' => 2026, 'isp_id' => '7', 'sys' => 'x']));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidOperators(): iterable
    {
        yield 'unknown field' => [['filters' => 'x']];
        yield 'skips a level' => [['isp_id' => '7']];
        yield 'last row cannot be expanded' => [['year' => 1, 'isp_id' => 2, 'day' => 3]];
        yield 'non scalar value' => [['year' => ['a']]];
    }

    /**
     * @param array<string, mixed> $operator
     */
    #[DataProvider('invalidOperators')]
    public function testSanitizeOperatorRejectsAnythingElse(array $operator): void
    {
        $this->expectException(InvalidReportCriteriaException::class);

        $this->builder->sanitizeOperator('{"rows":[{"field":"year"},{"field":"isp","hasRowId":true,"rowField":"isp_id"},{"field":"day"}]}', $operator);
    }

    /**
     * @return array<string, mixed>
     */
    private static function filter(string $label, mixed $field, string $action, mixed $values): array
    {
        return ['lable' => $label, 'field' => $field, 'action' => $action, 'values' => $values, 'id' => '', 'type' => 'basic', 'oprator' => 'AND'];
    }
}
