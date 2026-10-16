<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Controller;

use Mautic\CoreBundle\Controller\CommonController;
use MauticPlugin\MauticStatusiBundle\Exception\StatusiApiException;
use MauticPlugin\MauticStatusiBundle\Service\ConnectionManager;
use MauticPlugin\MauticStatusiBundle\Service\StatusiApiAccess;
use MauticPlugin\MauticStatusiBundle\Service\StatusiClient;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Connect flow (runs in a popup opened from the plugin modal):
 *  connect  -> stores state + PKCE verifier in the session, sends the user to the Statusi login popup
 *  callback -> verifies state, exchanges the one-time code server-to-server for the API token, stores it
 */
final class ConnectController extends CommonController
{
    private const SESSION_KEY = 'mautic.statusi.connect';

    private const FLOW_TTL_SECONDS = 900;

    public function connectAction(Request $request, StatusiClient $statusiClient): Response
    {
        $this->denyUnlessCanManagePlugins();

        $state        = bin2hex(random_bytes(32));
        $codeVerifier = $this->base64UrlEncode(random_bytes(48));

        $request->getSession()->set(self::SESSION_KEY, [
            'state'         => $state,
            'code_verifier' => $codeVerifier,
            'created_at'    => time(),
        ]);

        $query = http_build_query([
            'client_type'           => StatusiClient::CLIENT_TYPE,
            'site_url'              => $request->getSchemeAndHttpHost().$request->getBasePath(),
            'redirect_uri'          => $this->getCallbackUrl(),
            'state'                 => $state,
            'code_challenge'        => $this->base64UrlEncode(hash('sha256', $codeVerifier, true)),
            'code_challenge_method' => 'S256',
        ]);

        return new RedirectResponse($statusiClient->getAppUrl().'/mautic/connect?'.$query);
    }

    public function callbackAction(Request $request, StatusiClient $statusiClient, ConnectionManager $connectionManager, StatusiApiAccess $apiAccess): Response
    {
        $this->denyUnlessCanManagePlugins();

        $session = $request->getSession();
        $pending = $session->get(self::SESSION_KEY);
        $session->remove(self::SESSION_KEY);

        $state = (string) $request->query->get('state', '');
        $code  = (string) $request->query->get('code', '');

        if (!is_array($pending) || !hash_equals($pending['state'], $state)) {
            return $this->renderResult(false, 'mautic.statusi.connect.error.state');
        }

        if ($request->query->has('error')) {
            return $this->renderResult(false, 'mautic.statusi.connect.error.cancelled');
        }

        if ('' === $code || time() - $pending['created_at'] > self::FLOW_TTL_SECONDS) {
            return $this->renderResult(false, 'mautic.statusi.connect.error.expired');
        }

        // Read access for Statusi's daily data pulls: API on + a dedicated read-only API client.
        try {
            $apiAccess->ensureApiEnabled();
            $apiClient = $apiAccess->createClient($this->getSiteUrl($request));
        } catch (\Throwable $e) {
            return $this->renderResult(false, 'mautic.statusi.connect.error.api_access', ['%error%' => $e->getMessage()]);
        }

        $previous = $connectionManager->getConnection();
        try {
            $tokenData = $statusiClient->exchangeCode($code, $pending['code_verifier'], $this->getCallbackUrl(), [
                'base_url'      => $this->getSiteUrl($request),
                'client_id'     => $apiClient['client_id'],
                'client_secret' => $apiClient['client_secret'],
            ]);
            $connectionManager->saveConnection($tokenData, $apiClient['id']);
        } catch (StatusiApiException|\RuntimeException $e) {
            $apiAccess->deleteClient($apiClient['id']);

            return $this->renderResult(false, 'mautic.statusi.connect.error.exchange', ['%error%' => $e->getMessage()]);
        }

        // Reconnecting (possibly to another account): retire the token and API client we held until now.
        if ($previous) {
            if (!hash_equals($previous['api_token'], $tokenData['api_token'])) {
                $this->revokeQuietly($statusiClient, $previous['api_token']);
            }
            $apiAccess->deleteClient($previous['api_client_id']);
        }

        $accountName = $tokenData['account_name'] ?: '#'.$tokenData['account_id'];
        $this->addFlashMessage('mautic.statusi.connect.success.flash', ['%account%' => $accountName]);

        return $this->renderResult(true, 'mautic.statusi.connect.success', ['%account%' => $accountName]);
    }

    public function disconnectAction(Request $request, StatusiClient $statusiClient, ConnectionManager $connectionManager, StatusiApiAccess $apiAccess): JsonResponse
    {
        $this->denyUnlessCanManagePlugins();

        // Mautic validates the CSRF token of AJAX POSTs under /s/; refuse anything else.
        if (!$request->isXmlHttpRequest()) {
            return new JsonResponse(['success' => false], Response::HTTP_BAD_REQUEST);
        }

        $connection = $connectionManager->getConnection();
        if ($connection) {
            // Statusi deactivates its integration for this Mautic and stops the daily pulls
            $this->revokeQuietly($statusiClient, $connection['api_token']);
            $apiAccess->deleteClient($connection['api_client_id']);
        }
        $connectionManager->clearConnection();

        $this->addFlashMessage('mautic.statusi.disconnect.success');

        return new JsonResponse(['success' => true, 'flashes' => $this->getFlashContent()]);
    }

    /**
     * Revoking on Statusi is best effort: the local token is always removed, and an already
     * revoked/unknown token on Statusi's side is fine.
     */
    private function revokeQuietly(StatusiClient $statusiClient, string $apiToken): void
    {
        try {
            $statusiClient->revokeToken($apiToken);
        } catch (StatusiApiException) {
        }
    }

    private function denyUnlessCanManagePlugins(): void
    {
        if (!$this->security->isGranted('plugin:plugins:manage')) {
            $this->throwAccessDenied();
        }
    }

    /**
     * This Mautic's base URL as the admin reached it; the same value the connect flow sends Statusi as
     * site_url, and the base URL Statusi calls the API on (it must be reachable from Statusi's servers).
     */
    private function getSiteUrl(Request $request): string
    {
        return $request->getSchemeAndHttpHost().$request->getBasePath();
    }

    private function getCallbackUrl(): string
    {
        return $this->generateUrl('mautic_statusi_callback', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * @param array<string, string> $messageVars
     */
    private function renderResult(bool $success, string $message, array $messageVars = []): Response
    {
        return $this->render('@MauticStatusi/Connect/callback.html.twig', [
            'success' => $success,
            'message' => $this->translator->trans($message, $messageVars),
        ]);
    }

    private function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
