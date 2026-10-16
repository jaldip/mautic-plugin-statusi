<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\EmailActivity;

/**
 * Activity types returned by the email activity report, and where each one is stored.
 *
 * email.sent / email.failed   -> email_stats (one row per send, date_sent)
 * email.read                  -> email_stats_devices (one row per open, date_opened)
 * email.click                 -> page_hits with email_id set (one row per click, date_hit)
 * email.unsubscribe / .bounce
 *   / .dnc_manual             -> lead_donotcontact, channel = email, reason 1 / 2 / 3 (date_added)
 * campaign.event              -> campaign_lead_event_log, channel = email (date_triggered)
 */
final class ActivityType
{
    public const SENT           = 'email.sent';
    public const FAILED         = 'email.failed';
    public const READ           = 'email.read';
    public const CLICK          = 'email.click';
    public const UNSUBSCRIBE    = 'email.unsubscribe';
    public const BOUNCE         = 'email.bounce';
    public const DNC_MANUAL     = 'email.dnc_manual';
    public const CAMPAIGN_EVENT = 'campaign.event';

    public const ALL = [
        self::SENT,
        self::FAILED,
        self::READ,
        self::CLICK,
        self::UNSUBSCRIBE,
        self::BOUNCE,
        self::DNC_MANUAL,
        self::CAMPAIGN_EVENT,
    ];

    /**
     * Mautic timeline keys accepted as aliases, so clients can reuse /api/contacts/activity names.
     */
    public const ALIASES = [
        'email.open'        => [self::READ],
        'page.hit'          => [self::CLICK],
        'lead.donotcontact' => [self::UNSUBSCRIBE, self::BOUNCE, self::DNC_MANUAL],
    ];

    public const LABELS = [
        self::SENT           => 'Email sent',
        self::FAILED         => 'Email failed',
        self::READ           => 'Email opened',
        self::CLICK          => 'Email link clicked',
        self::UNSUBSCRIBE    => 'Unsubscribed',
        self::BOUNCE         => 'Bounced',
        self::DNC_MANUAL     => 'Do not contact (manual)',
        self::CAMPAIGN_EVENT => 'Campaign step triggered',
    ];

    public const ACTIVITY = [
        self::SENT           => 'sent',
        self::FAILED         => 'failed',
        self::READ           => 'opened',
        self::CLICK          => 'clicked',
        self::UNSUBSCRIBE    => 'unsubscribed',
        self::BOUNCE         => 'bounced',
        self::DNC_MANUAL     => 'dnc_manual',
        self::CAMPAIGN_EVENT => 'campaign_step',
    ];

    /**
     * lead_donotcontact.reason values (Mautic\LeadBundle\Entity\DoNotContact constants).
     */
    public const DNC_REASONS = [
        1 => self::UNSUBSCRIBE,
        2 => self::BOUNCE,
        3 => self::DNC_MANUAL,
    ];

    /**
     * Summary counter name for each type.
     */
    public const SUMMARY_KEYS = [
        self::SENT           => 'sent',
        self::FAILED         => 'failed',
        self::READ           => 'opened',
        self::CLICK          => 'clicked',
        self::UNSUBSCRIBE    => 'unsubscribed',
        self::BOUNCE         => 'bounced',
        self::DNC_MANUAL     => 'dncManual',
        self::CAMPAIGN_EVENT => 'campaignSteps',
    ];

    /**
     * @return string[]|null valid types for the given name (alias aware), or null when unknown
     */
    public static function resolve(string $name): ?array
    {
        if (in_array($name, self::ALL, true)) {
            return [$name];
        }

        return self::ALIASES[$name] ?? null;
    }
}
