<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\EmailActivity;

/**
 * Reads Mautic's serialized array columns (page_hits.query, ip_addresses.ip_details,
 * lead_devices.client_info). Objects are never instantiated.
 */
final class ValueParser
{
    /**
     * @return array<mixed>
     */
    public static function unserializeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || '' === $value) {
            return [];
        }

        if ('{' === $value[0] || '[' === $value[0]) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        if (!str_starts_with($value, 'a:')) {
            return [];
        }

        $decoded = @unserialize($value, ['allowed_classes' => false]);

        return is_array($decoded) ? $decoded : [];
    }
}
