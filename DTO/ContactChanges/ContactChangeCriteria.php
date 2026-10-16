<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\DTO\ContactChanges;

/**
 * Validated, immutable input of the contact attribute change report. All dates are UTC.
 */
final class ContactChangeCriteria
{
    public const CHANGE_ALL     = 'all';
    public const CHANGE_CREATED = 'created';
    public const CHANGE_UPDATED = 'updated';

    public const CHANGE_TYPES = [self::CHANGE_ALL, self::CHANGE_CREATED, self::CHANGE_UPDATED];

    public const SORT_LAST_CHANGE   = 'lastChange';
    public const SORT_DATE_ADDED    = 'dateAdded';
    public const SORT_DATE_MODIFIED = 'dateModified';
    public const SORT_ID            = 'id';

    public const SORT_FIELDS = [self::SORT_LAST_CHANGE, self::SORT_DATE_ADDED, self::SORT_DATE_MODIFIED, self::SORT_ID];

    /**
     * @param string[]|null $fields contact field aliases to return (null = all permitted fields)
     */
    public function __construct(
        public readonly \DateTimeImmutable $dateFrom,
        public readonly \DateTimeImmutable $dateTo,
        public readonly string $changeType = self::CHANGE_ALL,
        public readonly ?int $contactId = null,
        public readonly ?string $search = null,
        public readonly ?array $fields = null,
        public readonly int $page = 1,
        public readonly int $limit = 100,
        public readonly string $order = 'DESC',
        public readonly string $sortBy = self::SORT_LAST_CHANGE,
        public readonly bool $includeHistory = false,
        public readonly int $historyLimit = 50,
        public readonly ?int $leadOwnerId = null,
    ) {
    }

    public function withOwner(?int $leadOwnerId): self
    {
        $values                = get_object_vars($this);
        $values['leadOwnerId'] = $leadOwnerId;

        return new self(...$values);
    }

    public function withFields(?array $fields): self
    {
        $values           = get_object_vars($this);
        $values['fields'] = $fields;

        return new self(...$values);
    }

    public function includesCreated(): bool
    {
        return self::CHANGE_UPDATED !== $this->changeType;
    }

    public function includesUpdated(): bool
    {
        return self::CHANGE_CREATED !== $this->changeType;
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
