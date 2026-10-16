<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\EmailActivity;

use Mautic\CoreBundle\Security\Permissions\CorePermissions;

/**
 * Turns the API user's Mautic permissions into report restrictions.
 *
 *  - contacts: lead:leads:viewother = all contacts, lead:leads:viewown = contacts the user owns
 *  - emails:   email:emails:viewother = all emails, email:emails:viewown = emails the user created
 *
 * Without a contact view permission and an email view permission, access is denied.
 */
class ReportAccessResolver
{
    public function __construct(private CorePermissions $security)
    {
    }

    /**
     * @return array{leadOwnerId: ?int, emailCreatedBy: ?int}|null null when access is denied
     */
    public function resolve(?int $userId): ?array
    {
        $allContacts = (bool) $this->security->isGranted('lead:leads:viewother');
        $ownContacts = (bool) $this->security->isGranted('lead:leads:viewown');
        $allEmails   = (bool) $this->security->isGranted('email:emails:viewother');
        $ownEmails   = (bool) $this->security->isGranted('email:emails:viewown');

        if ((!$allContacts && !$ownContacts) || (!$allEmails && !$ownEmails)) {
            return null;
        }
        if ((!$allContacts || !$allEmails) && null === $userId) {
            return null; // "own" restrictions need a known user
        }

        return [
            'leadOwnerId'    => $allContacts ? null : $userId,
            'emailCreatedBy' => $allEmails ? null : $userId,
        ];
    }

    /**
     * Contact-only endpoints: lead:leads:viewother = all contacts, lead:leads:viewown = contacts the user owns.
     *
     * @return array{leadOwnerId: ?int}|null null when access is denied
     */
    public function resolveContacts(?int $userId): ?array
    {
        if ($this->security->isGranted('lead:leads:viewother')) {
            return ['leadOwnerId' => null];
        }
        if ($this->security->isGranted('lead:leads:viewown') && null !== $userId) {
            return ['leadOwnerId' => $userId];
        }

        return null;
    }
}
