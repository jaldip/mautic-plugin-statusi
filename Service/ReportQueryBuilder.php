<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Service;

use MauticPlugin\MauticStatusiBundle\Exception\InvalidReportCriteriaException;

/**
 * Builds the /report/dynamic_query request for a Statusi report exactly like the Statusi UI does
 * when a report is opened (statusi_ui: getDefaultFilters.ts applyDefaultFilters() and
 * ReportTable.tsx fetchDynamicQueryData()). Mautic shows reports read-only, so the filters are
 * always the report's configured defaults; only the drill-down path ("operator") and the page vary.
 */
class ReportQueryBuilder
{
    private const DEFAULT_PAGINATION = ['pageIndex' => 1, 'pageSize' => 20, 'rowCount' => 0];

    /**
     * @param string               $configuration report configuration JSON, as returned by Statusi
     * @param array<string, mixed> $operator      drill-down path (row field => value), see sanitizeOperator()
     *
     * @return array<string, mixed> request body without account_id (StatusiClient::dynamicQuery() adds it)
     */
    public function buildRequest(string $configuration, ?string $slug, array $operator, int $pageIndex, \DateTimeImmutable $today): array
    {
        $config  = $this->decodeObject($configuration);
        $filters = $this->applyDefaultFilters($this->decodeArray($configuration)['filters'] ?? [], $today);

        $systemFilter  = is_array($config->system_filter ?? null) ? $config->system_filter : null;
        $isContactBase = 'contact-attribute' === ($config->base ?? null);
        $hasCte        = $isContactBase && !empty($config->cte);

        $cte = $config->cte ?? null;
        if ($hasCte && $cte instanceof \stdClass) {
            $cte          = clone $cte;
            $cte->filters = $filters;
        }

        $pagination = array_merge(self::DEFAULT_PAGINATION, $this->decodeArray($configuration)['pagination'] ?? []);
        $pagination['pageIndex'] = max(1, $pageIndex);

        $body = [
            'base'       => $config->base ?? null,
            'cte'        => $cte,
            'rows'       => is_array($config->rows ?? null) ? $config->rows : [],
            'values'     => is_array($config->values ?? null) ? $config->values : [],
            // Statusi expects a JSON object here; an empty PHP array would be sent as []
            'operator'   => [] === $operator ? new \stdClass() : $operator,
            'redis_flag' => false,
            'pagination' => $pagination,
            'filters'    => $hasCte
                ? ($systemFilter ?? [])
                : (null !== $systemFilter ? array_merge($filters, $systemFilter) : $filters),
            'columns'    => is_array($config->columns ?? null) ? $config->columns : [],
            'slug'       => $slug,
        ];

        if (is_array($config->system_rows ?? null) && [] !== $config->system_rows) {
            $body['system_rows'] = $config->system_rows;
        }

        return $body;
    }

    /**
     * Human-readable list of the filters applied when the report is opened.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function describeFilters(string $configuration, \DateTimeImmutable $today): array
    {
        $described = [];
        foreach ($this->applyDefaultFilters($this->decodeArray($configuration)['filters'] ?? [], $today) as $filter) {
            $described[] = [
                'label' => (string) $filter['lable'],
                'value' => $this->describeValue((string) $filter['action'], $filter['values']),
            ];
        }

        return $described;
    }

    /**
     * Only lets the drill-down path contain the report's row fields (in order, one level at a time)
     * and its system row fields, with scalar values. The caller can never change filters or rows.
     *
     * @param array<mixed> $operator
     *
     * @return array<string, string|int|float>
     *
     * @throws InvalidReportCriteriaException
     */
    public function sanitizeOperator(string $configuration, array $operator): array
    {
        $config     = $this->decodeArray($configuration);
        $rowFields  = [];
        foreach (is_array($config['rows'] ?? null) ? $config['rows'] : [] as $row) {
            $field = is_array($row) ? (!empty($row['hasRowId']) ? ($row['rowField'] ?? null) : ($row['field'] ?? null)) : null;
            if (is_string($field) && '' !== $field) {
                $rowFields[] = $field;
            }
        }
        $systemFields = [];
        foreach (is_array($config['system_rows'] ?? null) ? $config['system_rows'] : [] as $systemRow) {
            if (is_array($systemRow) && is_string($systemRow['field'] ?? null)) {
                $systemFields[] = $systemRow['field'];
            }
        }

        $sanitized = [];
        $depth     = 0;
        foreach ($operator as $field => $value) {
            $field = (string) $field;
            if (!is_scalar($value) || is_bool($value)) {
                throw new InvalidReportCriteriaException(['operator' => 'Invalid drill-down value for "'.$field.'".']);
            }

            if (in_array($field, $systemFields, true)) {
                $sanitized[$field] = $value;
                continue;
            }

            // Row levels must be drilled in the configured order and the last row can't be expanded
            if (($rowFields[$depth] ?? null) !== $field || $depth >= count($rowFields) - 1) {
                throw new InvalidReportCriteriaException(['operator' => 'Invalid drill-down field "'.$field.'".']);
            }

            $sanitized[$field] = $value;
            ++$depth;
        }

        return $sanitized;
    }

    /**
     * Port of applyDefaultFilters() from statusi_ui/src/pages/Reports/common/table/getDefaultFilters.ts.
     *
     * @param array<mixed> $filterData
     *
     * @return array<int, array<string, mixed>>
     */
    public function applyDefaultFilters(array $filterData, \DateTimeImmutable $today): array
    {
        $filterData = array_values(array_filter($filterData, 'is_array'));
        $newFilters = [];

        foreach (['list_dropdown', 'lead_gen_vendor'] as $filterName) {
            $filter = $this->find($filterData, $filterName);
            if (null === $filter) {
                continue;
            }

            $defaultValues = $filter['default_values'] ?? null;
            if (is_array($defaultValues) && [] !== $defaultValues && !in_array('All', $defaultValues, true)) {
                $newFilters[] = $this->filter($filter, $filter['field'] ?? null, 'CONTAINS', $defaultValues);
            }
        }

        $dateFilter = $this->find($filterData, 'isSevenDays', 'isThirtyDays', 'isNinetyDays');
        if (null !== $dateFilter) {
            $defaultValue = $dateFilter['default_value'] ?? null;
            $range        = is_array($defaultValue) && 2 === count($defaultValue)
                ? [array_values($defaultValue)[0], array_values($defaultValue)[1]]
                : $this->getDateRange((string) $dateFilter['filter_name'], $defaultValue, $today);

            $newFilter = $this->filter($dateFilter, $dateFilter['field'] ?? null, 'BETWEEN', $range);
            if (!empty($dateFilter['transform'])) {
                $newFilter['transform'] = $dateFilter['transform'];
            }
            $newFilters[] = $newFilter;
        }

        $campaignFilter = $this->find($filterData, 'campaign_type_dropdown');
        if (null !== $campaignFilter) {
            $newFilters[] = $this->filter($campaignFilter, $campaignFilter['field'] ?? null, 'LIKE', ['quiz']);
        }

        foreach (['campaign_type', 'message_type'] as $filterName) {
            foreach ($filterData as $filter) {
                if (($filter['filter_name'] ?? null) !== $filterName) {
                    continue;
                }

                $defaultValues = $filter['default_values'] ?? null;
                if (!is_array($defaultValues) || [] === $defaultValues) {
                    continue;
                }

                $grouped = [];
                foreach ($defaultValues as $value) {
                    if (is_array($value) && !empty($value['key']) && !empty($value['value'])) {
                        $grouped[(string) $value['key']][] = $value['value'];
                    }
                }
                if ([] === $grouped) {
                    continue;
                }

                $apiValues = [];
                foreach ($grouped as $key => $values) {
                    $apiValues[] = [(string) $key, $values];
                }

                $isInclude    = $filter['include_key'] ?? true;
                $newFilters[] = $this->filter($filter, $filter['field'] ?? null, $isInclude ? 'HAS_KEY_VALUE' : 'NOT_HAS_KEY_VALUE', $apiValues);
            }
        }

        $dateSelector = $this->find($filterData, 'dateSelector');
        if (null !== $dateSelector) {
            $yesterday    = $today->modify('-1 day')->format('Y-m-d');
            $newFilters[] = $this->filter($dateSelector, $dateSelector['field'] ?? null, 'BETWEEN', [$yesterday, $yesterday]);
        }

        $testCampaignFilter = $this->find($filterData, 'test_campaign_column');
        if (null !== $testCampaignFilter) {
            $newFilters[] = $this->filter($testCampaignFilter, $testCampaignFilter['field'] ?? null, 'NOT LIKE', ['TEST']);
        }

        $leadGenerationFilter = $this->find($filterData, 'lead_gen_range');
        if (null !== $leadGenerationFilter) {
            // date-fns previousWednesday(): the last Wednesday strictly before today
            $to        = $today->modify('last wednesday');
            $from      = $to->modify('-6 days');
            $fromField = null;
            $toField   = null;
            foreach (is_array($leadGenerationFilter['field'] ?? null) ? $leadGenerationFilter['field'] : [] as $field) {
                if (is_array($field) && array_key_exists('from', $field) && null === $fromField) {
                    $fromField = $field['from'];
                }
                if (is_array($field) && array_key_exists('to', $field) && null === $toField) {
                    $toField = $field['to'];
                }
            }

            if ($fromField && $toField) {
                $newFilters[] = $this->filter($leadGenerationFilter, $fromField, '=', [$from->format('Y-m-d')]);
                $newFilters[] = $this->filter($leadGenerationFilter, $toField, '=', [$to->format('Y-m-d')]);
            }
        }

        $platformFilter = $this->find($filterData, 'platform_id');
        if (null !== $platformFilter) {
            $defaultValues = $platformFilter['default_values'] ?? null;
            if (is_array($defaultValues) && [] !== $defaultValues) {
                $values = array_map(static fn ($value) => is_array($value) ? ($value['value'] ?? null) : null, $defaultValues);
                if (!in_array('All', $values, true)) {
                    $newFilters[] = $this->filter($platformFilter, $platformFilter['field'] ?? null, 'CONTAINS', $values);
                }
            }
        }

        $monthRangeFilter = $this->find($filterData, 'monthRange');
        if (null !== $monthRangeFilter) {
            $defaultValue = $monthRangeFilter['default_values'] ?? null;
            if (is_array($defaultValue) && 2 === count($defaultValue)) {
                $defaultValue = array_values($defaultValue);
                if ($defaultValue[0] && $defaultValue[1]) {
                    $newFilters[] = $this->filter($monthRangeFilter, $monthRangeFilter['field'] ?? null, 'BETWEEN', [$defaultValue[0], $defaultValue[1]]);
                }
            }
        }

        $campaignNameFilter = $this->find($filterData, 'campaign_name_contains');
        if (null !== $campaignNameFilter && !empty($campaignNameFilter['default_value'])) {
            $defaultValue = $campaignNameFilter['default_value'];
            $values       = is_string($defaultValue)
                ? array_values(array_filter(array_map('trim', explode(',', $defaultValue)), static fn ($value) => '' !== $value))
                : (is_array($defaultValue) ? $defaultValue : []);

            if ([] !== $values) {
                $newFilters[] = $this->filter($campaignNameFilter, $campaignNameFilter['field'] ?? null, count($values) > 1 ? 'MULTI_CONTAINS' : 'LIKE', $values);
            }
        }

        $numberOfSentFilter = $this->find($filterData, 'number_of_sent');
        if (null !== $numberOfSentFilter && is_array($numberOfSentFilter['default_values'] ?? null) && [] !== $numberOfSentFilter['default_values']) {
            $value = array_values($numberOfSentFilter['default_values'])[0];
            if (null !== $value && '' !== $value) {
                // Statusi sends this single value as a string, not a list
                $newFilters[] = $this->filter($numberOfSentFilter, $numberOfSentFilter['field'] ?? null, '=', is_scalar($value) ? (string) $value : $value);
            }
        }

        return $newFilters;
    }

    /**
     * Port of getDateRange() (getDefaultFilters.ts): "to" is yesterday, "from" is N days ago.
     *
     * @return array{0: string, 1: string}
     */
    private function getDateRange(string $filterName, mixed $defaultValue, \DateTimeImmutable $today): array
    {
        $days = match ($filterName) {
            'isSevenDays'  => is_numeric($defaultValue) ? (int) $defaultValue : 7,
            'isThirtyDays' => 30,
            'isNinetyDays' => 90,
            default        => 0,
        };

        return [
            $today->modify(sprintf('-%d days', $days))->format('Y-m-d'),
            $today->modify('-1 day')->format('Y-m-d'),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $filterData
     *
     * @return array<string, mixed>|null the first filter with one of the given names
     */
    private function find(array $filterData, string ...$filterNames): ?array
    {
        foreach ($filterData as $filter) {
            if (in_array($filter['filter_name'] ?? null, $filterNames, true)) {
                return $filter;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function filter(array $config, mixed $field, string $action, mixed $values): array
    {
        // Key names (incl. the "lable"/"oprator" spelling) match what Statusi's API expects
        return [
            'lable'   => ($config['placeholder'] ?? null) ?: ($config['filter_name'] ?? ''),
            'field'   => $field,
            'action'  => $action,
            'values'  => $values,
            'id'      => '',
            'type'    => 'basic',
            'oprator' => 'AND',
        ];
    }

    private function describeValue(string $action, mixed $values): string
    {
        $flatten = static function (mixed $value) use (&$flatten): string {
            if (is_array($value)) {
                return implode(', ', array_map($flatten, $value));
            }

            return is_scalar($value) ? (string) $value : '';
        };

        // [[key, [values]], ...] -> "key: v1, v2; key2: v3"
        $keyValues = static fn (mixed $pairs): string => is_array($pairs)
            ? implode('; ', array_map(static fn ($pair) => is_array($pair) ? $flatten($pair[0] ?? '').': '.$flatten($pair[1] ?? []) : $flatten($pair), $pairs))
            : $flatten($pairs);

        return match ($action) {
            'BETWEEN'                            => is_array($values) ? implode(' → ', array_map($flatten, $values)) : $flatten($values),
            'HAS_KEY_VALUE'                      => $keyValues($values),
            'NOT_HAS_KEY_VALUE'                  => 'not '.$keyValues($values),
            'NOT LIKE'                           => 'excludes '.$flatten($values),
            'LIKE', 'CONTAINS', 'MULTI_CONTAINS' => 'contains '.$flatten($values),
            default                              => $flatten($values),
        };
    }

    private function decodeObject(string $configuration): \stdClass
    {
        $decoded = json_decode($configuration);

        return $decoded instanceof \stdClass ? $decoded : new \stdClass();
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeArray(string $configuration): array
    {
        $decoded = json_decode($configuration, true);

        return is_array($decoded) ? $decoded : [];
    }
}
