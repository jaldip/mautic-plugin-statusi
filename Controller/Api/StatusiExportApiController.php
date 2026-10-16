<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Controller\Api;

use Mautic\ApiBundle\Controller\CommonApiController;
use Mautic\CoreBundle\Helper\UserHelper;
use MauticPlugin\MauticStatusiBundle\DTO\Export\ExportRequest;
use MauticPlugin\MauticStatusiBundle\EmailActivity\ReportAccessResolver;
use MauticPlugin\MauticStatusiBundle\Exception\InvalidReportCriteriaException;
use MauticPlugin\MauticStatusiBundle\Export\ActivityExporter;
use MauticPlugin\MauticStatusiBundle\Export\ContactExporter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bulk exports read by Statusi's daily Mautic DAGs:
 *   GET /api/custom/email-activity-export
 *   GET /api/custom/contact-attribute-export
 *
 * A partial export would silently drop data in Statusi, so both require access to all contacts
 * (and, for activity, all emails); "own only" permissions get a 403.
 *
 * @extends CommonApiController<object>
 */
final class StatusiExportApiController extends CommonApiController
{
    public function activityAction(Request $request, UserHelper $userHelper, ReportAccessResolver $accessResolver, ActivityExporter $exporter): Response
    {
        $access = $accessResolver->resolve($userHelper->getUser()?->getId());
        if (null === $access || null !== $access['leadOwnerId'] || null !== $access['emailCreatedBy']) {
            return $this->accessDenied();
        }

        return $this->export($request, static fn (ExportRequest $export): array => $exporter->export($export));
    }

    public function contactsAction(Request $request, UserHelper $userHelper, ReportAccessResolver $accessResolver, ContactExporter $exporter): Response
    {
        $access = $accessResolver->resolveContacts($userHelper->getUser()?->getId());
        if (null === $access || null !== $access['leadOwnerId']) {
            return $this->accessDenied();
        }

        return $this->export($request, static fn (ExportRequest $export): array => $exporter->export($export));
    }

    /**
     * @param callable(ExportRequest): array<string, mixed> $build
     */
    private function export(Request $request, callable $build): Response
    {
        try {
            $export = ExportRequest::fromRequest($request);
        } catch (InvalidReportCriteriaException $e) {
            return $this->returnError($e->getMessage(), Response::HTTP_BAD_REQUEST, $e->getErrors());
        }

        $view = $this->view($build($export), Response::HTTP_OK);
        // Mautic's serializer drops null values by default; the export keeps every column
        $view->getContext()->setSerializeNull(true);

        return $this->handleView($view);
    }
}
