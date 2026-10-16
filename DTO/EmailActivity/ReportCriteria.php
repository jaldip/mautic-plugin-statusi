<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\DTO\EmailActivity;

/**
 * Validated, immutable input of the email activity report. All dates are UTC.
 */
final class ReportCriteria
{
    public const SORT_LATEST_ACTIVITY = 'latestActivity';
    public const SORT_TOTAL_ACTIVITIES = 'totalActivities';
    public const SORT_EMAIL_ID         = 'emailId';
    public const SORT_EMAIL_NAME       = 'emailName';

    public const SORT_FIELDS = [
        self::SORT_LATEST_ACTIVITY,
        self::SORT_TOTAL_ACTIVITIES,
        self::SORT_EMAIL_ID,
        self::SORT_EMAIL_NAME,
    ];

    /**
     * @param string[] $eventTypes ActivityType values to include
     */
    public function __construct(
        public readonly \DateTimeImmutable $dateFrom,
        public readonly \DateTimeImmutable $dateTo,
        public readonly array $eventTypes,
        public readonly ?int $contactId = null,
        public readonly ?int $emailId = null,
        public readonly ?int $campaignId = null,
        public readonly ?string $search = null,
        public readonly int $page = 1,
        public readonly int $limit = 25,
        public readonly string $order = 'DESC',
        public readonly string $sortBy = self::SORT_LATEST_ACTIVITY,
        public readonly int $contactPage = 1,
        public readonly int $contactLimit = 50,
        public readonly int $activityLimit = 100,
        public readonly bool $includeContacts = true,
        public readonly bool $includeActivities = true,
        public readonly ?int $leadOwnerId = null,
        public readonly ?int $emailCreatedBy = null,
    ) {
    }

    /**
     * Restrict the report to what the API user may see (set by the controller from permissions).
     */
    public function withAccessRestrictions(?int $leadOwnerId, ?int $emailCreatedBy): self
    {
        $values                   = get_object_vars($this);
        $values['leadOwnerId']    = $leadOwnerId;
        $values['emailCreatedBy'] = $emailCreatedBy;

        return new self(...$values);
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->limit;
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
