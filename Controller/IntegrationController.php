<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Controller;

use MauticPlugin\MauticStatusiBundle\Exception\StatusiApiException;
use MauticPlugin\MauticStatusiBundle\Service\ConnectionManager;
use MauticPlugin\MauticStatusiBundle\Service\IntegrationList;
use MauticPlugin\MauticStatusiBundle\Service\StatusiClient;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Statusi integrations: the connected Statusi account's integrations, like Statusi's own Integrations page,
 * plus the Click Go and ChatGPT Ads forms. Sign-in platforms: OAuthIntegrationController; Ongage:
 * OngageIntegrationController.
 */
final class IntegrationController extends AbstractIntegrationController
{
    public function indexAction(Request $request, ConnectionManager $connectionManager, StatusiClient $statusiClient, IntegrationList $integrationList): Response
    {
        if (!$connectionManager->isConnected()) {
            return $this->redirectToRoute('mautic_statusi_index');
        }

        $rows  = [];
        $error = null;
        try {
            $rows = $integrationList->rows($statusiClient->getIntegrations());
        } catch (StatusiApiException $e) {
            $error = $e->getMessage();
        }

        return $this->page($request, '@MauticStatusi/Integrations/index.html.twig', 'mautic_statusi_integrations', [
            'accountName'  => $connectionManager->getConnection()['account_name'] ?? null,
            'integrations' => $rows,
            'error'        => $error,
        ], 'statusiIntegrations');
    }

    public function newAction(Request $request, ConnectionManager $connectionManager, StatusiClient $statusiClient, IntegrationList $integrationList): Response
    {
        if (!$connectionManager->isConnected()) {
            return $this->redirectToRoute('mautic_statusi_index');
        }

        $platforms = [];
        $error     = null;
        try {
            $platforms = $integrationList->platforms($statusiClient->getPlatforms());
        } catch (StatusiApiException $e) {
            $error = $e->getMessage();
        }

        return $this->page($request, '@MauticStatusi/Integrations/new.html.twig', 'mautic_statusi_integration_new', [
            'platforms' => $platforms,
            'error'     => $error,
        ], 'statusiIntegrations');
    }

    public function statusAction(int $id, Request $request, ConnectionManager $connectionManager, StatusiClient $statusiClient): JsonResponse
    {
        if ($response = $this->refuseUnlessConnectedAjaxPost($request, $connectionManager)) {
            return $response;
        }

        $active = $request->request->getBoolean('active');
        try {
            $statusiClient->setIntegrationStatus($id, $active);
        } catch (StatusiApiException $e) {
            return $this->failure('mautic.statusi.integrations.status_error', $e);
        }

        $this->addFlashMessage($active ? 'mautic.statusi.integrations.activated' : 'mautic.statusi.integrations.deactivated');

        return new JsonResponse(['success' => true, 'active' => $active, 'flashes' => $this->getFlashContent()]);
    }

    public function clickgoAction(Request $request, ConnectionManager $connectionManager, StatusiClient $statusiClient): Response
    {
        if (!$connectionManager->isConnected()) {
            return $this->redirectToRoute('mautic_statusi_index');
        }

        $platformId = $request->query->getInt('platform_id');
        if ($platformId <= 0) {
            return $this->notFound();
        }

        if ($request->isMethod('POST')) {
            if ($response = $this->refuseUnlessConnectedAjaxPost($request, $connectionManager)) {
                return $response;
            }

            $apiKey = trim((string) $request->request->get('api_key', ''));
            if ('' === $apiKey) {
                return $this->invalid('mautic.statusi.integrations.api_key_required');
            }

            try {
                $statusiClient->saveClickgoIntegration($platformId, $apiKey);
            } catch (StatusiApiException $e) {
                return $this->failure('mautic.statusi.integrations.save_error', $e);
            }

            return $this->savedAndBackToList('mautic.statusi.integrations.created');
        }

        return $this->page($request, '@MauticStatusi/Integrations/clickgo.html.twig', 'mautic_statusi_integration_clickgo', [
            'platformId' => $platformId,
        ], 'statusiIntegrationForm');
    }

    /**
     * ChatGPT Ads: one integration per account holding any number of ad accounts (one API key each).
     * Without integration_config_id the first ad account is connected; with it, its ad accounts are managed.
     */
    public function chatgptAction(Request $request, ConnectionManager $connectionManager, StatusiClient $statusiClient): Response
    {
        if (!$connectionManager->isConnected()) {
            return $this->redirectToRoute('mautic_statusi_index');
        }

        $platformId          = $request->query->getInt('platform_id');
        $integrationConfigId = $request->query->getInt('integration_config_id') ?: null;

        if ($request->isMethod('POST')) {
            if ($response = $this->refuseUnlessConnectedAjaxPost($request, $connectionManager)) {
                return $response;
            }

            return $this->chatgptPost($request, $statusiClient, $platformId, $integrationConfigId);
        }

        $integration = null;
        $error       = null;
        if (null !== $integrationConfigId) {
            try {
                $integration = $statusiClient->getChatgptAdsIntegration($integrationConfigId);
                $platformId  = (int) ($integration['platform_id'] ?? $platformId);
            } catch (StatusiApiException $e) {
                $error = $e->getMessage();
            }
        }

        if ($platformId <= 0) {
            return $this->notFound();
        }

        return $this->page($request, '@MauticStatusi/Integrations/chatgpt.html.twig', 'mautic_statusi_integration_chatgpt', [
            'platformId'          => $platformId,
            'integrationConfigId' => $integrationConfigId,
            'accounts'            => is_array($integration['accounts'] ?? null) ? $integration['accounts'] : [],
            'status'              => $integration['status'] ?? null,
            'error'               => $error,
        ], 'statusiIntegrationForm');
    }

    private function chatgptPost(Request $request, StatusiClient $statusiClient, int $platformId, ?int $integrationConfigId): JsonResponse
    {
        $action = (string) $request->request->get('action', '');

        try {
            switch ($action) {
                case 'add':
                    $apiKey = trim((string) $request->request->get('api_key', ''));
                    if ('' === $apiKey || $platformId <= 0) {
                        return $this->invalid('mautic.statusi.integrations.api_key_required');
                    }
                    $statusiClient->saveChatgptAdsAccount($platformId, $apiKey, trim((string) $request->request->get('display_name', '')) ?: null, $integrationConfigId);

                    return $this->savedAndBackToList(null === $integrationConfigId ? 'mautic.statusi.integrations.created' : 'mautic.statusi.integrations.chatgpt.account_added');

                case 'update':
                    if (null === $integrationConfigId) {
                        return $this->invalid('mautic.statusi.integrations.not_found');
                    }
                    $statusiClient->updateChatgptAdsAccounts($integrationConfigId, $this->chatgptAccounts($request));

                    return $this->savedAndBackToList('mautic.statusi.integrations.saved');

                case 'replace_key':
                    $apiKey      = trim((string) $request->request->get('api_key', ''));
                    $adAccountId = trim((string) $request->request->get('ad_account_id', ''));
                    if (null === $integrationConfigId || '' === $adAccountId || '' === $apiKey) {
                        return $this->invalid('mautic.statusi.integrations.api_key_required');
                    }
                    $statusiClient->replaceChatgptAdsKey($integrationConfigId, $adAccountId, $apiKey);

                    return $this->savedAndBackToList('mautic.statusi.integrations.chatgpt.key_replaced');
            }
        } catch (StatusiApiException $e) {
            return $this->failure('mautic.statusi.integrations.save_error', $e);
        }

        return $this->invalid('mautic.statusi.integrations.not_found');
    }

    /**
     * accounts[<ad_account_id>][status|display_name|keep] -> the list Statusi expects; unticked "keep" = removed.
     *
     * @return array<int, array{ad_account_id: string, status: string, display_name: string|null}>
     */
    private function chatgptAccounts(Request $request): array
    {
        $accounts = [];
        foreach ($request->request->all('accounts') as $adAccountId => $values) {
            if (!is_array($values) || empty($values['keep'])) {
                continue;
            }
            $accounts[] = [
                'ad_account_id' => (string) $adAccountId,
                'status'        => 'Active' === ($values['status'] ?? '') ? 'Active' : 'Inactive',
                'display_name'  => trim((string) ($values['display_name'] ?? '')) ?: null,
            ];
        }

        return $accounts;
    }
}
