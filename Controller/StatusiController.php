<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Controller;

use Mautic\CoreBundle\Controller\CommonController;
use MauticPlugin\MauticStatusiBundle\Exception\InvalidReportCriteriaException;
use MauticPlugin\MauticStatusiBundle\Exception\StatusiApiException;
use MauticPlugin\MauticStatusiBundle\Service\ConnectionManager;
use MauticPlugin\MauticStatusiBundle\Service\ReportCatalog;
use MauticPlugin\MauticStatusiBundle\Service\ReportQueryBuilder;
use MauticPlugin\MauticStatusiBundle\Service\StatusiClient;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class StatusiController extends CommonController
{
    public function indexAction(ConnectionManager $connectionManager, ReportCatalog $reportCatalog): Response
    {
        $connection = $connectionManager->isConnected() ? $connectionManager->getConnection() : null;

        $reports = [];
        $error   = null;
        if (null !== $connection) {
            try {
                $reports = $reportCatalog->getReports();
            } catch (StatusiApiException $e) {
                $error = $e->getMessage();
            }
        }

        return $this->delegateView([
            'viewParameters' => [
                'accountName' => $connection['account_name'] ?? null,
                'canManage'   => $this->security->isGranted('plugin:plugins:manage'),
                'reports'     => $reports,
                'error'       => $error,
            ],
            'contentTemplate' => '@MauticStatusi/Statusi/index.html.twig',
            'passthroughVars' => [
                'activeLink'    => '#mautic_statusi_index',
                'mauticContent' => 'statusi',
                'route'         => $this->generateUrl('mautic_statusi_index'),
            ],
        ]);
    }

    public function reportAction(int $id, ConnectionManager $connectionManager, ReportCatalog $reportCatalog, ReportQueryBuilder $queryBuilder): Response
    {
        if (!$connectionManager->isConnected()) {
            return $this->redirectToRoute('mautic_statusi_index');
        }

        try {
            $report = $reportCatalog->getReport($id);
        } catch (StatusiApiException) {
            return $this->redirectToRoute('mautic_statusi_index');
        }

        if (null === $report) {
            return $this->notFound('mautic.statusi.report.not_found');
        }

        $connection = $connectionManager->getConnection();

        return $this->delegateView([
            'viewParameters' => [
                'report'    => $report,
                'filters'   => $queryBuilder->describeFilters($report['configuration'], $this->today()),
                'accountId' => $connection['account_id'] ?? null,
            ],
            'contentTemplate' => '@MauticStatusi/Statusi/report.html.twig',
            'passthroughVars' => [
                'activeLink'    => '#mautic_statusi_report_'.$id,
                'mauticContent' => 'statusiReport',
                'route'         => $this->generateUrl('mautic_statusi_report', ['id' => $id]),
            ],
        ]);
    }

    /**
     * Rows of one page of a report (JSON), for the drill-down path in the "operator" query parameter.
     * The request is built server side from the report configuration, so viewers can't change filters.
     */
    public function reportDataAction(
        int $id,
        Request $request,
        ConnectionManager $connectionManager,
        ReportCatalog $reportCatalog,
        ReportQueryBuilder $queryBuilder,
        StatusiClient $statusiClient,
    ): JsonResponse {
        if (!$connectionManager->isConnected()) {
            return new JsonResponse(['error' => $this->translator->trans('mautic.statusi.page.not_connected.header')], Response::HTTP_CONFLICT);
        }

        try {
            $report = $reportCatalog->getReport($id);
            if (null === $report) {
                return new JsonResponse(['error' => $this->translator->trans('mautic.statusi.report.not_found')], Response::HTTP_NOT_FOUND);
            }

            $operator = json_decode((string) $request->query->get('operator', '{}'), true);
            $operator = $queryBuilder->sanitizeOperator($report['configuration'], is_array($operator) ? $operator : []);

            $body   = $queryBuilder->buildRequest($report['configuration'], $report['slug'], $operator, max(1, $request->query->getInt('page', 1)), $this->today());
            $result = $statusiClient->dynamicQuery($body);
        } catch (InvalidReportCriteriaException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (StatusiApiException $e) {
            return new JsonResponse(['error' => $this->translator->trans('mautic.statusi.report.load_error', ['%error%' => $e->getMessage()])], Response::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse([
            'data'       => is_array($result['data'] ?? null) ? $result['data'] : [],
            'parentData' => is_array($result['parent_data'] ?? null) ? $result['parent_data'] : null,
            'rowCount'   => (int) ($result['pagination']['rowCount'] ?? $result['rowCount'] ?? 0),
            'pageIndex'  => $body['pagination']['pageIndex'],
            'pageSize'   => $body['pagination']['pageSize'],
        ]);
    }

    /**
     * Statusi computes relative date filters from the UTC date (JavaScript toISOString()).
     */
    private function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
