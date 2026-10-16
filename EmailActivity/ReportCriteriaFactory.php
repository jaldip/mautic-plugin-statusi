<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\EmailActivity;

use MauticPlugin\MauticStatusiBundle\Api\QueryParameters;
use MauticPlugin\MauticStatusiBundle\DTO\EmailActivity\ReportCriteria;
use MauticPlugin\MauticStatusiBundle\Exception\InvalidReportCriteriaException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Parses and validates the query string of GET /api/custom/email-activity-report.
 * Date formats and timezone handling: see QueryParameters::date().
 */
class ReportCriteriaFactory
{
    public const MAX_LIMIT          = 100;
    public const MAX_CONTACT_LIMIT  = 500;
    public const MAX_ACTIVITY_LIMIT = 1000;
    public const MAX_RANGE_DAYS     = 366;
    public const MAX_SEARCH_LENGTH  = 100;

    public function fromRequest(Request $request): ReportCriteria
    {
        $query  = $request->query->all();
        $errors = [];

        $dateFrom = QueryParameters::date($query['dateFrom'] ?? null, false, 'dateFrom', $errors);
        $dateTo   = QueryParameters::date($query['dateTo'] ?? null, true, 'dateTo', $errors);
        QueryParameters::range($dateFrom, $dateTo, self::MAX_RANGE_DAYS, $errors);

        $include    = $this->parseEventList($query['includeEvents'] ?? null, 'includeEvents', $errors);
        $exclude    = $this->parseEventList($query['excludeEvents'] ?? null, 'excludeEvents', $errors);
        $eventTypes = array_values(array_diff($include ?: ActivityType::ALL, $exclude));
        if (!$eventTypes && !isset($errors['includeEvents']) && !isset($errors['excludeEvents'])) {
            $errors['excludeEvents'] = 'The event filters exclude every activity type.';
        }

        $criteria = new ReportCriteria(
            dateFrom: $dateFrom ?? new \DateTimeImmutable('@0'),
            dateTo: $dateTo ?? new \DateTimeImmutable('@0'),
            eventTypes: $eventTypes,
            contactId: QueryParameters::id($query, 'contactId', $errors),
            emailId: QueryParameters::id($query, 'emailId', $errors),
            campaignId: QueryParameters::id($query, 'campaignId', $errors),
            search: QueryParameters::search($query, self::MAX_SEARCH_LENGTH, $errors),
            page: QueryParameters::int($query, 'page', 1, 1, PHP_INT_MAX, $errors),
            limit: QueryParameters::int($query, 'limit', 25, 1, self::MAX_LIMIT, $errors),
            order: QueryParameters::choice($query, 'order', 'DESC', ['ASC', 'DESC'], $errors, true),
            sortBy: QueryParameters::choice($query, 'sortBy', ReportCriteria::SORT_LATEST_ACTIVITY, ReportCriteria::SORT_FIELDS, $errors),
            contactPage: QueryParameters::int($query, 'contactPage', 1, 1, PHP_INT_MAX, $errors),
            contactLimit: QueryParameters::int($query, 'contactLimit', 50, 1, self::MAX_CONTACT_LIMIT, $errors),
            activityLimit: QueryParameters::int($query, 'activityLimit', 100, 1, self::MAX_ACTIVITY_LIMIT, $errors),
            includeContacts: QueryParameters::bool($query, 'includeContacts', true, $errors),
            includeActivities: QueryParameters::bool($query, 'includeActivities', true, $errors),
        );

        if ($errors) {
            throw new InvalidReportCriteriaException($errors);
        }

        return $criteria;
    }

    /**
     * Accepts includeEvents[]=a&includeEvents[]=b (Mautic convention) or a comma separated string.
     *
     * @param array<string, string> $errors
     *
     * @return string[]
     */
    private function parseEventList(mixed $value, string $name, array &$errors): array
    {
        $names = QueryParameters::list($value, $name, $errors) ?? [];
        $types = [];
        foreach ($names as $eventName) {
            $resolved = ActivityType::resolve($eventName);
            if (null === $resolved) {
                $errors[$name] = sprintf(
                    'Unknown event "%s". Allowed: %s (aliases: %s).',
                    $eventName,
                    implode(', ', ActivityType::ALL),
                    implode(', ', array_keys(ActivityType::ALIASES))
                );

                return [];
            }
            array_push($types, ...$resolved);
        }

        return array_values(array_unique($types));
    }
}
