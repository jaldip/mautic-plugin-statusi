<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Controller\Api;

use Mautic\ApiBundle\Controller\CommonApiController;
use Mautic\CoreBundle\Helper\UserHelper;
use MauticPlugin\MauticStatusiBundle\ContactChanges\ContactChangeCriteriaFactory;
use MauticPlugin\MauticStatusiBundle\ContactChanges\ContactChangeReportBuilder;
use MauticPlugin\MauticStatusiBundle\EmailActivity\ReportAccessResolver;
use MauticPlugin\MauticStatusiBundle\Exception\InvalidReportCriteriaException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/custom/contact-attributes.
 *
 * Contacts created or updated in a date range, with their current attributes and, on request,
 * the field-level history Mautic recorded in its audit log. Mautic API authentication applies;
 * lead:leads:viewother sees all contacts, lead:leads:viewown only contacts the user owns.
 *
 * @extends CommonApiController<object>
 */
final class ContactAttributesApiController extends CommonApiController
{
    public function listAction(
        Request $request,
        UserHelper $userHelper,
        ReportAccessResolver $accessResolver,
        ContactChangeCriteriaFactory $criteriaFactory,
        ContactChangeReportBuilder $reportBuilder,
    ): Response {
        $access = $accessResolver->resolveContacts($userHelper->getUser()?->getId());
        if (null === $access) {
            return $this->accessDenied();
        }

        try {
            $criteria = $criteriaFactory->fromRequest($request);
        } catch (InvalidReportCriteriaException $e) {
            return $this->returnError($e->getMessage(), Response::HTTP_BAD_REQUEST, $e->getErrors());
        }

        $view = $this->view($reportBuilder->build($criteria->withOwner($access['leadOwnerId'])), Response::HTTP_OK);
        // Mautic's serializer drops null values by default; unavailable values are returned as null.
        $view->getContext()->setSerializeNull(true);

        return $this->handleView($view);
    }
}
