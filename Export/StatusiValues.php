<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Export;

use Symfony\Component\Intl\Countries;

/**
 * Mautic -> Statusi value mapping used by the export endpoints. Statusi stores every email platform
 * in the same tables, so activity types and contact statuses use the values its reports count.
 */
final class StatusiValues
{
    public const SEND        = 'send';
    public const OPEN        = 'open';
    public const CLICK       = 'click';
    public const UNSUBSCRIBE = 'unsubscribe';
    public const COMPLAINT   = 'complaint';
    public const HARD_BOUNCE = 'hard_bounce';
    public const SOFT_BOUNCE = 'soft_bounce';

    // contact_list_attribute.platform_status, same values as the Mailchimp integration
    public const STATUS_SUBSCRIBED   = 'subscribed';
    public const STATUS_UNSUBSCRIBED = 'unsubscribed';
    public const STATUS_CLEANED      = 'cleaned';

    // lead_donotcontact.reason (Mautic\LeadBundle\Entity\DoNotContact)
    public const DNC_UNSUBSCRIBED = 1;
    public const DNC_BOUNCED      = 2;
    public const DNC_MANUAL       = 3;

    /**
     * An unsubscribe recorded by a mail provider's spam feedback loop. Mautic stores these as
     * "unsubscribed" do-not-contact entries; the provider's reason is kept in the comment.
     */
    private const COMPLAINT_PATTERN = '/spam|complain|abuse|feedback.?loop/i';

    /** @var array<string, string>|null normalized English name => code */
    private static ?array $countryCodes = null;

    /**
     * Statusi activity type of a do-not-contact entry, or null when Statusi doesn't track it (manual DNC).
     */
    public static function dncActivityType(int $reason, ?string $comments): ?string
    {
        return match ($reason) {
            self::DNC_UNSUBSCRIBED => self::isComplaint($comments) ? self::COMPLAINT : self::UNSUBSCRIBE,
            self::DNC_BOUNCED      => self::HARD_BOUNCE,
            default                => null,
        };
    }

    public static function isComplaint(?string $comments): bool
    {
        return null !== $comments && 1 === preg_match(self::COMPLAINT_PATTERN, $comments);
    }

    /**
     * Contact status from its email do-not-contact reasons: a bounce wins over an unsubscribe.
     *
     * @param int[] $reasons
     */
    public static function platformStatus(array $reasons): string
    {
        if (in_array(self::DNC_BOUNCED, $reasons, true)) {
            return self::STATUS_CLEANED;
        }
        if ($reasons) {
            return self::STATUS_UNSUBSCRIBED;
        }

        return self::STATUS_SUBSCRIBED;
    }

    /**
     * ISO 3166-1 alpha-2 code for a country as Mautic's IP lookup stores it (an English name, sometimes a code).
     */
    public static function countryCode(?string $country): ?string
    {
        $country = null === $country ? '' : trim($country);
        if ('' === $country) {
            return null;
        }
        if (2 === strlen($country) && Countries::exists(strtoupper($country))) {
            return strtoupper($country);
        }

        if (null === self::$countryCodes) {
            self::$countryCodes = [];
            foreach (Countries::getNames('en') as $code => $name) {
                self::$countryCodes[self::normalize($name)] = $code;
            }
            // Names IP lookup services use that differ from the CLDR English names
            foreach ([
                'United States of America' => 'US', 'USA' => 'US', 'UK' => 'GB', 'Great Britain' => 'GB',
                'Russian Federation' => 'RU', 'Republic of Korea' => 'KR', 'South Korea' => 'KR', 'Korea, Republic of' => 'KR',
                'Viet Nam' => 'VN', 'Czech Republic' => 'CZ', 'Iran, Islamic Republic of' => 'IR', 'Taiwan, Province of China' => 'TW',
                'Hong Kong SAR China' => 'HK', 'Macao' => 'MO', 'Turkey' => 'TR', 'Ivory Coast' => 'CI', 'Burma' => 'MM',
            ] as $name => $code) {
                self::$countryCodes[self::normalize($name)] = $code;
            }
        }

        return self::$countryCodes[self::normalize($country)] ?? null;
    }

    /**
     * Whole days between the send and the activity, like Ongage's days_passed; null without a send.
     */
    public static function daysPassed(?string $sentAt, string $activityAt): ?int
    {
        if (null === $sentAt || '' === $sentAt) {
            return null;
        }

        $seconds = strtotime($activityAt.' UTC') - strtotime($sentAt.' UTC');

        return $seconds < 0 ? null : intdiv($seconds, 86400);
    }

    private static function normalize(string $name): string
    {
        return strtolower((string) preg_replace('/[^a-z]/i', '', $name));
    }
}
