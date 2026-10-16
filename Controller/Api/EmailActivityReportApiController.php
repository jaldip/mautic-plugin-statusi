<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Controller\Api;

use Mautic\ApiBundle\Controller\CommonApiController;
use Mautic\CoreBundle\Helper\UserHelper;
use MauticPlugin\MauticStatusiBundle\EmailActivity\EmailActivityReportBuilder;
use MauticPlugin\MauticStatusiBundle\EmailActivity\ReportAccessResolver;
use MauticPlugin\MauticStatusiBundle\EmailActivity\ReportCriteriaFactory;
use MauticPlugin\MauticStatusiBundle\Exception\InvalidReportCriteriaException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/custom/email-activity-report.
 *
 * Email-wise activity report for a date range. Authentication is Mautic's API authentication
 * (OAuth2 or Basic); what the caller sees follows their permissions (see ReportAccessResolver).
 *
 * @extends CommonApiController<object>
 */
final class EmailActivityReportApiController extends CommonApiController
{
    public function reportAction(
        Request $request,
        UserHelper $userHelper,
        ReportAccessResolver $accessResolver,
        ReportCriteriaFactory $criteriaFactory,
        EmailActivityReportBuilder $reportBuilder,
    ): Response {
        $access = $accessResolver->resolve($userHelper->getUser()?->getId());
        if (null === $access) {
            return $this->accessDenied();
        }

        try {
            $criteria = $criteriaFactory->fromRequest($request);
        } catch (InvalidReportCriteriaException $e) {
            return $this->returnError($e->getMessage(), Response::HTTP_BAD_REQUEST, $e->getErrors());
        }

        $criteria = $criteria->withAccessRestrictions($access['leadOwnerId'], $access['emailCreatedBy']);

        $view = $this->view($reportBuilder->build($criteria), Response::HTTP_OK);
        // Mautic's serializer drops null values by default; the report returns unavailable fields as null.
        $view->getContext()->setSerializeNull(true);

        return $this->handleView($view);
    }
}
