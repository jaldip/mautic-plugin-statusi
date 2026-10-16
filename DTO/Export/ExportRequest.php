<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\DTO\Export;

use MauticPlugin\MauticStatusiBundle\Api\QueryParameters;
use MauticPlugin\MauticStatusiBundle\Exception\InvalidReportCriteriaException;
use MauticPlugin\MauticStatusiBundle\Export\ExportCursor;
use Symfony\Component\HttpFoundation\Request;

/**
 * Parameters of the Statusi export endpoints: a UTC date range, a page size and an opaque cursor.
 */
final readonly class ExportRequest
{
    public const MAX_DAYS      = 31;
    public const DEFAULT_LIMIT = 1000;
    public const MAX_LIMIT     = 5000;

    /**
     * @param array<int, mixed>|null $cursor decoded cursor, null for the first page
     */
    public function __construct(
        public \DateTimeImmutable $dateFrom,
        public \DateTimeImmutable $dateTo,
        public int $limit,
        public ?array $cursor,
    ) {
    }

    /**
     * @throws InvalidReportCriteriaException
     */
    public static function fromRequest(Request $request): self
    {
        $query  = $request->query->all();
        $errors = [];

        $dateFrom = QueryParameters::date($query['dateFrom'] ?? null, false, 'dateFrom', $errors);
        $dateTo   = QueryParameters::date($query['dateTo'] ?? null, true, 'dateTo', $errors);
        QueryParameters::range($dateFrom, $dateTo, self::MAX_DAYS, $errors);
        $limit = QueryParameters::int($query, 'limit', self::DEFAULT_LIMIT, 1, self::MAX_LIMIT, $errors);

        $cursor = null;
        if (isset($query['cursor']) && '' !== $query['cursor']) {
            $cursor = is_string($query['cursor']) ? ExportCursor::decode($query['cursor']) : null;
            if (null === $cursor) {
                $errors['cursor'] = 'cursor is invalid. Use the nextCursor value of the previous page.';
            }
        }

        if ($errors || !$dateFrom || !$dateTo) {
            throw new InvalidReportCriteriaException($errors);
        }

        return new self($dateFrom, $dateTo, $limit, $cursor);
    }

    public function dateFromSql(): string
    {
        return $this->dateFrom->format('Y-m-d H:i:s');
    }

    public function dateToSql(): string
    {
        return $this->dateTo->format('Y-m-d H:i:s');
    }
}
