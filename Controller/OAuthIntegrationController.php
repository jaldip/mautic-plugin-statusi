<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Controller;

use MauticPlugin\MauticStatusiBundle\Exception\StatusiApiException;
use MauticPlugin\MauticStatusiBundle\Service\ConnectionManager;
use MauticPlugin\MauticStatusiBundle\Service\IntegrationList;
use MauticPlugin\MauticStatusiBundle\Service\StatusiClient;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Google Ads / AdSense / Analytics, Meta Ads and Mailchimp, set up from Mautic:
 *  connect  -> Statusi builds the provider's consent URL for the connected account; the browser goes there
 *  return   -> the provider returns to Statusi, which stores the tokens and sends the browser back here with
 *              base64("<account id>:<platform id>:<integration config id>") in "state"
 *  accounts -> pick the accounts / properties (Mailchimp: settings) Statusi pulls, saved through Statusi
 */
final class OAuthIntegrationController extends AbstractIntegrationController
{
    public function connectAction(string $kind, Request $request, ConnectionManager $connectionManager, StatusiClient $statusiClient): Response
    {
        $platformId = $request->query->getInt('platform_id');
        if (!$connectionManager->isConnected() || !IntegrationList::isOAuth($kind) || $platformId <= 0) {
            return $this->notFound();
        }

        try {
            return new RedirectResponse($statusiClient->startOAuth($kind, $platformId));
        } catch (StatusiApiException $e) {
            return $this->backToListWithError('mautic.statusi.integrations.oauth_start_error', ['%error%' => $e->getMessage()]);
        }
    }

    public function returnAction(string $key, Request $request, ConnectionManager $connectionManager): Response
    {
        $kind       = IntegrationList::kindForOAuthKey($key);
        $connection = $connectionManager->isConnected() ? $connectionManager->getConnection() : null;
        if (null === $kind || null === $connection) {
            return $this->notFound();
        }

        if ('true' === $request->query->get('error')) {
            return $this->backToListWithError('mautic.statusi.integrations.oauth_failed', ['%code%' => (string) $request->query->get('code', '-')]);
        }

        $state = base64_decode(strtr((string) $request->query->get('state', ''), '-_', '+/'), true);
        $parts = array_map('intval', explode(':', (string) $state));
        if (3 !== count($parts) || $parts[0] !== (int) $connection['account_id'] || $parts[1] <= 0 || $parts[2] <= 0) {
            return $this->backToListWithError('mautic.statusi.integrations.oauth_failed', ['%code%' => 'state']);
        }

        return $this->redirectToRoute('mautic_statusi_integration_accounts', [
            'kind'                  => $kind,
            'platform_id'           => $parts[1],
            'integration_config_id' => $parts[2],
        ]);
    }

    public function accountsAction(string $kind, Request $request, ConnectionManager $connectionManager, StatusiClient $statusiClient): Response
    {
        $platformId          = $request->query->getInt('platform_id');
        $integrationConfigId = $request->query->getInt('integration_config_id');
        if (!IntegrationList::isOAuth($kind) || $platformId <= 0 || $integrationConfigId <= 0) {
            return $this->notFound();
        }
        if (!$connectionManager->isConnected()) {
            return $this->redirectToRoute('mautic_statusi_index');
        }

        if ($request->isMethod('POST')) {
            if ($response = $this->refuseUnlessConnectedAjaxPost($request, $connectionManager)) {
                return $response;
            }

            $values = $this->postedValues($kind, $request);
            if (null === $values) {
                return $this->invalid('mautic.statusi.integrations.mailchimp.days_invalid');
            }

            try {
                $statusiClient->saveOAuthIntegration($kind, $platformId, $integrationConfigId, $values);
            } catch (StatusiApiException $e) {
                return $this->failure('mautic.statusi.integrations.save_error', $e);
            }

            return $this->savedAndBackToList('mautic.statusi.integrations.saved');
        }

        $data  = [];
        $error = null;
        try {
            $data = $statusiClient->getOAuthIntegration($kind, $platformId, $integrationConfigId);
        } catch (StatusiApiException $e) {
            $error = $e->getMessage();
        }

        return $this->page($request, '@MauticStatusi/Integrations/accounts.html.twig', 'mautic_statusi_integration_accounts', [
            'kind'                => $kind,
            'platformId'          => $platformId,
            'integrationConfigId' => $integrationConfigId,
            'accounts'            => array_values(array_filter(is_array($data['accounts'] ?? null) ? $data['accounts'] : [], 'is_array')),
            'email'               => (string) ($data['email'] ?? ''),
            'serverPrefix'        => (string) ($data['server_prefix'] ?? ''),
            'trackingDays'        => (int) ($data['campaign_history_tracking_days'] ?? 0) ?: 30,
            'error'               => $error,
        ], 'statusiIntegrationForm', ['kind' => $kind]);
    }

    /**
     * The selection in the format Statusi's save endpoint expects (as statusi_ui sends it), or null if invalid.
     *
     * @return array<string, mixed>|null
     */
    private function postedValues(string $kind, Request $request): ?array
    {
        if (IntegrationList::KIND_MAILCHIMP === $kind) {
            $days = $request->request->getInt('campaign_history_tracking_days');

            return $days >= 1 && $days <= 90 ? ['campaign_history_tracking_days' => $days] : null;
        }

        $email = (string) $request->request->get('email', '');

        if (IntegrationList::KIND_GOOGLE_ANALYTICS === $kind) {
            // Every account is sent, each with its active property ids (possibly none).
            $properties = $request->request->all('properties');
            $accounts   = [];
            foreach ($request->request->all('account_ids') as $accountId) {
                $accountId  = (string) $accountId;
                $accounts[] = [
                    'account_id' => $accountId,
                    'properties' => array_values(array_map('strval', is_array($properties[$accountId] ?? null) ? $properties[$accountId] : [])),
                ];
            }

            return ['accounts' => $accounts, 'email' => $email];
        }

        // Google Ads, AdSense, Meta Ads: the active account ids.
        return ['accounts' => array_values(array_map('strval', $request->request->all('accounts'))), 'email' => $email];
    }
}
