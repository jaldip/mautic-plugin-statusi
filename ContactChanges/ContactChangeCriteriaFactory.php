<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\ContactChanges;

use MauticPlugin\MauticStatusiBundle\Api\QueryParameters;
use MauticPlugin\MauticStatusiBundle\DTO\ContactChanges\ContactChangeCriteria;
use MauticPlugin\MauticStatusiBundle\Exception\InvalidReportCriteriaException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Parses and validates the query string of GET /api/custom/contact-attributes.
 * Date formats and timezone handling: see QueryParameters::date().
 */
class ContactChangeCriteriaFactory
{
    public const MAX_LIMIT         = 500;
    public const MAX_HISTORY_LIMIT = 500;
    public const MAX_RANGE_DAYS    = 366;
    public const MAX_SEARCH_LENGTH = 100;

    public function __construct(private ContactFieldCatalog $catalog)
    {
    }

    public function fromRequest(Request $request): ContactChangeCriteria
    {
        $query  = $request->query->all();
        $errors = [];

        $dateFrom = QueryParameters::date($query['dateFrom'] ?? null, false, 'dateFrom', $errors);
        $dateTo   = QueryParameters::date($query['dateTo'] ?? null, true, 'dateTo', $errors);
        QueryParameters::range($dateFrom, $dateTo, self::MAX_RANGE_DAYS, $errors);

        $fields = QueryParameters::list($query['fields'] ?? null, 'fields', $errors);
        if (null !== $fields) {
            $allowed = $this->catalog->aliases();
            $unknown = array_diff($fields, $allowed);
            if ($unknown) {
                $errors['fields'] = sprintf('Unknown or unavailable contact field(s): %s. Allowed: %s.', implode(', ', $unknown), implode(', ', $allowed));
            }
        }

        $criteria = new ContactChangeCriteria(
            dateFrom: $dateFrom ?? new \DateTimeImmutable('@0'),
            dateTo: $dateTo ?? new \DateTimeImmutable('@0'),
            changeType: QueryParameters::choice($query, 'changeType', ContactChangeCriteria::CHANGE_ALL, ContactChangeCriteria::CHANGE_TYPES, $errors),
            contactId: QueryParameters::id($query, 'contactId', $errors),
            search: QueryParameters::search($query, self::MAX_SEARCH_LENGTH, $errors),
            fields: $fields,
            page: QueryParameters::int($query, 'page', 1, 1, PHP_INT_MAX, $errors),
            limit: QueryParameters::int($query, 'limit', 100, 1, self::MAX_LIMIT, $errors),
            order: QueryParameters::choice($query, 'order', 'DESC', ['ASC', 'DESC'], $errors, true),
            sortBy: QueryParameters::choice($query, 'sortBy', ContactChangeCriteria::SORT_LAST_CHANGE, ContactChangeCriteria::SORT_FIELDS, $errors),
            includeHistory: QueryParameters::bool($query, 'includeHistory', false, $errors),
            historyLimit: QueryParameters::int($query, 'historyLimit', 50, 1, self::MAX_HISTORY_LIMIT, $errors),
        );

        if ($errors) {
            throw new InvalidReportCriteriaException($errors);
        }

        return $criteria;
    }
}
