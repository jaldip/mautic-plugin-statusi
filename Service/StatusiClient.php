<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Service;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use MauticPlugin\MauticStatusiBundle\Exception\StatusiApiException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HTTP client for the Statusi API. Every call carries the Mautic client flag header,
 * which Statusi requires for all Mautic endpoints and Mautic API tokens.
 */
class StatusiClient
{
    public const CLIENT_TYPE = 'mautic';

    private const CLIENT_HEADER = 'X-Statusi-Client';

    private const TIMEOUT = 30;

    // Used for calls made while rendering Mautic pages (e.g. the sidebar menu)
    private const LIST_TIMEOUT = 5;

    // Report queries can be heavy on the Statusi side
    private const QUERY_TIMEOUT = 120;

    public function __construct(
        private HttpClientInterface $httpClient,
        private CoreParametersHelper $coreParametersHelper,
        private ConnectionManager $connectionManager,
    ) {
    }

    public function getAppUrl(): string
    {
        return rtrim((string) $this->coreParametersHelper->get('statusi_app_url'), '/');
    }

    public function getApiUrl(): string
    {
        return rtrim((string) $this->coreParametersHelper->get('statusi_api_url'), '/');
    }

    /**
     * Exchanges the one-time authorization code (PKCE) for the long-lived API token.
     *
     * $mauticApi is the read-only Mautic API client Statusi pulls daily data with; it travels only
     * in this server-to-server request and Statusi stores the secret encrypted.
     *
     * @param array{base_url: string, client_id: string, client_secret: string}|null $mauticApi
     *
     * @return array{api_token: string, account_id: int, account_name: ?string, user_email: ?string, connected_at: string}
     *
     * @throws StatusiApiException
     */
    public function exchangeCode(string $code, string $codeVerifier, string $redirectUri, ?array $mauticApi = null): array
    {
        $payload = [
            'client_type'   => self::CLIENT_TYPE,
            'code'          => $code,
            'code_verifier' => $codeVerifier,
            'redirect_uri'  => $redirectUri,
        ];
        if (null !== $mauticApi) {
            $payload['mautic_api'] = $mauticApi;
        }

        $data = $this->request('POST', '/mautic/token', null, $payload);

        if (empty($data['api_token']) || empty($data['account_id'])) {
            throw new StatusiApiException('Statusi returned an incomplete token response.');
        }

        return $data;
    }

    /**
     * Revokes the token on Statusi's side.
     *
     * @throws StatusiApiException
     */
    public function revokeToken(string $apiToken): void
    {
        $this->request('POST', '/mautic/disconnect', $apiToken);
    }

    /**
     * Runs a Statusi report through /report/dynamic_query for the connected account.
     *
     * @param array<string, mixed> $query rows/values/columns/filters/... as expected by Statusi
     *
     * @return array<string, mixed>
     *
     * @throws StatusiApiException
     */
    public function dynamicQuery(array $query): array
    {
        $connection = $this->connectionManager->getConnection();
        if (null === $connection) {
            throw new StatusiApiException('Mautic is not connected to Statusi.');
        }

        // The token is locked to one account; always send that account.
        $query['account_id'] = $connection['account_id'];

        return $this->request('POST', '/report/dynamic_query', $connection['api_token'], $query, self::QUERY_TIMEOUT);
    }

    /**
     * Lists the reports of the connected account through /report/get-reports.
     * Statusi answers 404 when the account has no reports, which is returned as an empty list.
     *
     * Each report's "configuration" is returned as the exact JSON Statusi sent: it is passed back
     * to /report/dynamic_query, and decoding it to PHP arrays would turn empty objects into lists.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws StatusiApiException
     */
    public function getReports(): array
    {
        $connection = $this->connectionManager->getConnection();
        if (null === $connection) {
            throw new StatusiApiException('Mautic is not connected to Statusi.');
        }

        $path    = '/report/get-reports?'.http_build_query(['account_id' => $connection['account_id']]);
        $content = null;

        try {
            $this->request('GET', $path, $connection['api_token'], null, self::LIST_TIMEOUT, $content);
        } catch (StatusiApiException $e) {
            if (404 === $e->getCode()) {
                return [];
            }

            throw $e;
        }

        $body    = json_decode((string) $content);
        $reports = [];
        foreach (is_array($body->data ?? null) ? $body->data : [] as $report) {
            if (!$report instanceof \stdClass) {
                continue;
            }

            $item = get_object_vars($report);
            if (isset($report->configuration) && !is_string($report->configuration)) {
                $item['configuration'] = json_encode($report->configuration);
            }
            $reports[] = $item;
        }

        return $reports;
    }

    /**
     * @param array<string, mixed>|null $json
     * @param string|null               $content receives the raw response body
     *
     * @param-out string $content
     *
     * @return array<string, mixed>
     *
     * @throws StatusiApiException
     */
    private function request(string $method, string $path, ?string $apiToken = null, ?array $json = null, int $timeout = self::TIMEOUT, ?string &$content = null): array
    {
        $headers = [
            'Accept'           => 'application/json',
            self::CLIENT_HEADER => self::CLIENT_TYPE,
        ];
        if ($apiToken) {
            $headers['Authorization'] = 'Bearer '.$apiToken;
        }

        $options = ['headers' => $headers, 'timeout' => $timeout, 'max_duration' => $timeout];
        if (null !== $json) {
            $options['json'] = $json;
        }

        try {
            $response   = $this->httpClient->request($method, $this->getApiUrl().$path, $options);
            $statusCode = $response->getStatusCode();
            $body       = $response->toArray(false);
            $content    = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            throw new StatusiApiException('Could not reach Statusi: '.$e->getMessage(), 0, $e);
        }

        if ($statusCode >= 400 || !empty($body['error'])) {
            throw new StatusiApiException((string) ($body['message'] ?? 'Statusi returned an error.'), $statusCode);
        }

        return is_array($body['data'] ?? null) ? $body['data'] : [];
    }
}
