<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Exception;

final class InvalidReportCriteriaException extends \InvalidArgumentException
{
    /**
     * @param array<string, string> $errors parameter name => message
     */
    public function __construct(private array $errors)
    {
        parent::__construct('Invalid request parameters: '.implode(' ', $errors));
    }

    /**
     * @return array<string, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
