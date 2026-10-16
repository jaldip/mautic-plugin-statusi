<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Api;

/**
 * Shared query-string parsing for the plugin's custom API endpoints. Every method records a
 * message in $errors (parameter name => message) instead of throwing, so all problems are
 * reported together.
 *
 * Dates: "YYYY-MM-DD", "YYYY-MM-DD HH:MM:SS" or ISO 8601 with an offset ("2026-10-09T00:00:00+05:30").
 * Values without an offset are read as UTC. A date-only end date means the end of that day.
 */
final class QueryParameters
{
    /**
     * @param array<string, string> $errors
     */
    public static function date(mixed $value, bool $endOfDay, string $name, array &$errors): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            $errors[$name] = $name.' is required.';

            return null;
        }

        if (!is_string($value)) {
            $errors[$name] = $name.' must be a date string.';

            return null;
        }

        $value = trim($value);
        $utc   = new \DateTimeZone('UTC');

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $utc);
            if ($date && $date->format('Y-m-d') === $value) {
                return $endOfDay ? $date->setTime(23, 59, 59) : $date;
            }
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/', $value)) {
            $normalized = str_replace('T', ' ', $value);
            $date       = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $normalized, $utc);
            if ($date && $date->format('Y-m-d H:i:s') === $normalized) {
                return $date;
            }
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})$/', $value)) {
            try {
                return (new \DateTimeImmutable($value))->setTimezone($utc);
            } catch (\Exception) {
            }
        }

        $errors[$name] = $name.' must be YYYY-MM-DD, YYYY-MM-DD HH:MM:SS or ISO 8601 with an offset.';

        return null;
    }

    /**
     * Validates order and length of a range.
     *
     * @param array<string, string> $errors
     */
    public static function range(?\DateTimeImmutable $from, ?\DateTimeImmutable $to, int $maxDays, array &$errors): void
    {
        if (!$from || !$to) {
            return;
        }
        if ($from > $to) {
            $errors['dateTo'] = 'dateTo must be on or after dateFrom.';
        } elseif ($from->diff($to)->days > $maxDays) {
            $errors['dateTo'] = sprintf('The date range may not exceed %d days.', $maxDays);
        }
    }

    /**
     * @param array<string, mixed>  $query
     * @param array<string, string> $errors
     */
    public static function id(array $query, string $name, array &$errors): ?int
    {
        if (!isset($query[$name]) || '' === $query[$name]) {
            return null;
        }

        if (!is_string($query[$name]) || !ctype_digit($query[$name]) || (int) $query[$name] < 1) {
            $errors[$name] = $name.' must be a positive integer.';

            return null;
        }

        return (int) $query[$name];
    }

    /**
     * @param array<string, mixed>  $query
     * @param array<string, string> $errors
     */
    public static function int(array $query, string $name, int $default, int $min, int $max, array &$errors): int
    {
        if (!isset($query[$name]) || '' === $query[$name]) {
            return $default;
        }

        if (!is_string($query[$name]) || !ctype_digit($query[$name]) || (int) $query[$name] < $min || (int) $query[$name] > $max) {
            $errors[$name] = sprintf('%s must be an integer between %d and %d.', $name, $min, $max);

            return $default;
        }

        return (int) $query[$name];
    }

    /**
     * @param array<string, mixed>  $query
     * @param array<string, string> $errors
     */
    public static function bool(array $query, string $name, bool $default, array &$errors): bool
    {
        if (!isset($query[$name]) || '' === $query[$name]) {
            return $default;
        }

        $value = is_string($query[$name]) ? strtolower($query[$name]) : null;
        if (in_array($value, ['1', 'true', 'yes'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'false', 'no'], true)) {
            return false;
        }

        $errors[$name] = $name.' must be true or false.';

        return $default;
    }

    /**
     * @param array<string, mixed>  $query
     * @param string[]              $allowed
     * @param array<string, string> $errors
     */
    public static function choice(array $query, string $name, string $default, array $allowed, array &$errors, bool $upperCase = false): string
    {
        $value = $query[$name] ?? $default;
        if (!is_string($value)) {
            $errors[$name] = $name.' must be one of: '.implode(', ', $allowed).'.';

            return $default;
        }
        $value = $upperCase ? strtoupper($value) : $value;
        if (!in_array($value, $allowed, true)) {
            $errors[$name] = $name.' must be one of: '.implode(', ', $allowed).'.';

            return $default;
        }

        return $value;
    }

    /**
     * @param array<string, mixed>  $query
     * @param array<string, string> $errors
     */
    public static function search(array $query, int $maxLength, array &$errors): ?string
    {
        if (!isset($query['search']) || '' === $query['search']) {
            return null;
        }
        if (!is_string($query['search'])) {
            $errors['search'] = 'search must be a string.';

            return null;
        }
        $search = trim($query['search']);
        if ('' === $search) {
            return null;
        }
        if (mb_strlen($search) > $maxLength) {
            $errors['search'] = sprintf('search may not be longer than %d characters.', $maxLength);

            return null;
        }

        return $search;
    }

    /**
     * Comma separated string or repeated parameter (name[]=a&name[]=b).
     *
     * @param array<string, string> $errors
     *
     * @return string[]|null null when the parameter is absent
     */
    public static function list(mixed $value, string $name, array &$errors): ?array
    {
        if (null === $value || '' === $value || [] === $value) {
            return null;
        }
        $items = is_array($value) ? $value : explode(',', (string) $value);
        $clean = [];
        foreach ($items as $item) {
            if (!is_string($item)) {
                $errors[$name] = $name.' must contain names.';

                return null;
            }
            if ('' !== trim($item)) {
                $clean[] = trim($item);
            }
        }

        return array_values(array_unique($clean));
    }

    /**
     * LIKE pattern parameters with % and _ matched literally.
     *
     * @return array{contains: string, prefix: string}
     */
    public static function likePatterns(string $search): array
    {
        $escaped = addcslashes($search, '%_\\');

        return ['contains' => '%'.$escaped.'%', 'prefix' => $escaped.'%'];
    }
}
