<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Controller;

use Mautic\CoreBundle\Controller\CommonController;
use MauticPlugin\MauticStatusiBundle\Exception\StatusiApiException;
use MauticPlugin\MauticStatusiBundle\Service\ConnectionManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared by the Statusi integrations pages. Changes are AJAX POSTs (Mautic checks their CSRF token under /s/)
 * answered with JSON: {success, redirect, flashes} or {success: false, error}.
 */
abstract class AbstractIntegrationController extends CommonController
{
    /**
     * @param array<string, mixed> $parameters
     */
    protected function page(Request $request, string $template, string $route, array $parameters, string $mauticContent, array $routeParameters = []): Response
    {
        return $this->delegateView([
            'viewParameters'  => $parameters,
            'contentTemplate' => $template,
            'passthroughVars' => [
                'activeLink'    => '#mautic_statusi_integrations',
                'mauticContent' => $mauticContent,
                'route'         => $this->generateUrl($route, $routeParameters + $request->query->all()),
            ],
        ]);
    }

    protected function refuseUnlessConnectedAjaxPost(Request $request, ConnectionManager $connectionManager): ?JsonResponse
    {
        // Mautic validates the CSRF token of AJAX POSTs under /s/; refuse anything else.
        if (!$request->isMethod('POST') || !$request->isXmlHttpRequest()) {
            return new JsonResponse(['success' => false], Response::HTTP_BAD_REQUEST);
        }

        if (!$connectionManager->isConnected()) {
            return new JsonResponse(['success' => false, 'error' => $this->translator->trans('mautic.statusi.page.not_connected.header')], Response::HTTP_CONFLICT);
        }

        return null;
    }

    protected function savedAndBackToList(string $message, ?string $redirect = null): JsonResponse
    {
        $this->addFlashMessage($message);

        return new JsonResponse([
            'success'  => true,
            'redirect' => $redirect ?? $this->generateUrl('mautic_statusi_integrations'),
            'flashes'  => $this->getFlashContent(),
        ]);
    }

    protected function invalid(string $message): JsonResponse
    {
        return new JsonResponse(['success' => false, 'error' => $this->translator->trans($message)], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    protected function failure(string $message, StatusiApiException $e): JsonResponse
    {
        return new JsonResponse(
            ['success' => false, 'error' => $this->translator->trans($message, ['%error%' => $e->getMessage()])],
            Response::HTTP_BAD_GATEWAY
        );
    }

    /**
     * Full page redirect to the list with an error flash (used outside AJAX, e.g. after a provider sign-in).
     */
    protected function backToListWithError(string $message, array $vars = []): Response
    {
        $this->addFlashMessage($message, $vars, 'error');

        return $this->redirectToRoute('mautic_statusi_integrations');
    }
}
