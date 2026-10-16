<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Export;

/**
 * Opaque keyset cursor: the sort key of the last row of a page, as URL-safe base64 JSON.
 */
final class ExportCursor
{
    /**
     * @param array<int, string|int> $key
     */
    public static function encode(array $key): string
    {
        return rtrim(strtr(base64_encode((string) json_encode(array_values($key))), '+/', '-_'), '=');
    }

    /**
     * @return array<int, mixed>|null null when the value is not a cursor produced by encode()
     */
    public static function decode(string $cursor): ?array
    {
        $json = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (false === $json) {
            return null;
        }

        $key = json_decode($json, true);
        if (!is_array($key) || !array_is_list($key)) {
            return null;
        }
        foreach ($key as $value) {
            if (!is_int($value) && !is_string($value)) {
                return null;
            }
        }

        return $key;
    }
}
