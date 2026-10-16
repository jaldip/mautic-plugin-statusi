<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Exception;

final class StatusiApiException extends \RuntimeException
{
    public function isUnauthorized(): bool
    {
        return 401 === $this->getCode();
    }
}
