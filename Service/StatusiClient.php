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
     * Integrations of the connected account (Statusi > Integrations), as listed by Statusi's
     * /integration/get_lists: one row per integration_config (platform + task).
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws StatusiApiException
     */
    public function getIntegrations(): array
    {
        $connection = $this->requireConnection();

        return array_values(array_filter(
            $this->accountRequest('GET', '/integration/get_lists?'.http_build_query(['account_id' => $connection['account_id']])),
            'is_array'
        ));
    }

    /**
     * Platforms an integration can be created for (id, name, image_url, path).
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws StatusiApiException
     */
    public function getPlatforms(): array
    {
        try {
            $platforms = $this->accountRequest('GET', '/integration/platforms');
        } catch (StatusiApiException $e) {
            if (404 === $e->getCode()) {
                return [];
            }

            throw $e;
        }

        return array_values(array_filter($platforms, 'is_array'));
    }

    /**
     * @throws StatusiApiException
     */
    public function setIntegrationStatus(int $integrationConfigId, bool $active): void
    {
        $this->accountRequest('PUT', '/integration/integration-status', [
            'integration_config_id' => $integrationConfigId,
            'status'                => $active ? 1 : 0,
        ]);
    }

    /**
     * Creates a Click Go integration (Statusi verifies the API key with Click Go first).
     *
     * @throws StatusiApiException
     */
    public function saveClickgoIntegration(int $platformId, string $apiKey): void
    {
        $this->accountRequest('POST', '/integration/clickgo/save-credentials', ['platform_id' => $platformId, 'api_key' => $apiKey], self::QUERY_TIMEOUT);
    }

    /**
     * Checks a ChatGPT Ads API key: returns ['ad_account' => [...], 'already_integrated' => bool].
     *
     * @return array<string, mixed>
     *
     * @throws StatusiApiException
     */
    public function validateChatgptAdsKey(int $platformId, string $apiKey): array
    {
        return $this->accountRequest('POST', '/integration/chatgpt-ads/validate', $this->withAccount(['platform_id' => $platformId, 'api_key' => $apiKey]), self::QUERY_TIMEOUT);
    }

    /**
     * Connects one more ChatGPT Ads ad account (creates the integration on the first one).
     *
     * @throws StatusiApiException
     */
    public function saveChatgptAdsAccount(int $platformId, string $apiKey, ?string $displayName, ?int $integrationConfigId): void
    {
        $this->accountRequest('POST', '/integration/save-chatgpt-ads-config', $this->withAccount([
            'platform_id'    => $platformId,
            'api_key'        => $apiKey,
            'display_name'   => $displayName ?: null,
            'integration_id' => $integrationConfigId,
        ]), self::QUERY_TIMEOUT);
    }

    /**
     * @return array<string, mixed> integration_id, platform_id, status, accounts[]
     *
     * @throws StatusiApiException
     */
    public function getChatgptAdsIntegration(int $integrationConfigId): array
    {
        return $this->accountRequest('GET', '/integration/get-chatgpt-ads-config?'.http_build_query(['integration_id' => $integrationConfigId]));
    }

    /**
     * Saves the final list of ad accounts (status / display name); accounts left out are removed.
     *
     * @param array<int, array{ad_account_id: string, status: string, display_name?: string|null}> $accounts
     *
     * @throws StatusiApiException
     */
    public function updateChatgptAdsAccounts(int $integrationConfigId, array $accounts): void
    {
        $this->accountRequest('PUT', '/integration/update-chatgpt-ads-config', ['integration_id' => $integrationConfigId, 'accounts' => $accounts]);
    }

    /**
     * @throws StatusiApiException
     */
    public function replaceChatgptAdsKey(int $integrationConfigId, string $adAccountId, string $apiKey): void
    {
        $this->accountRequest('PUT', '/integration/chatgpt-ads/replace-key', [
            'integration_id' => $integrationConfigId,
            'ad_account_id'  => $adAccountId,
            'api_key'        => $apiKey,
        ], self::QUERY_TIMEOUT);
    }

    /**
     * Starts a provider sign-in (Google, Meta, Mailchimp) for the connected account. Statusi returns the URL
     * of the provider's consent page; after the sign-in Statusi sends the user back to this Mautic.
     *
     * @throws StatusiApiException
     */
    public function startOAuth(string $kind, int $platformId): string
    {
        $data = $this->accountRequest('POST', IntegrationList::OAUTH[$kind]['start'], $this->withAccount(['platform_id' => $platformId]));
        $url  = (string) ($data['auth_url'] ?? '');
        if (!str_starts_with($url, 'https://')) {
            throw new StatusiApiException('Statusi did not return a sign-in URL.');
        }

        return $url;
    }

    /**
     * Accounts / properties (or Mailchimp settings) of a signed-in integration.
     *
     * @return array<string, mixed>
     *
     * @throws StatusiApiException
     */
    public function getOAuthIntegration(string $kind, int $platformId, int $integrationConfigId): array
    {
        return $this->accountRequest('POST', IntegrationList::OAUTH[$kind]['accounts'], [
            'platform_id'    => $platformId,
            'integration_id' => $integrationConfigId,
        ], self::QUERY_TIMEOUT);
    }

    /**
     * Saves the selection of a signed-in integration: active account ids (Google Ads, AdSense, Meta), accounts with
     * active property ids (Analytics) or, for Mailchimp, campaign_history_tracking_days.
     *
     * @param array<string, mixed> $values
     *
     * @throws StatusiApiException
     */
    public function saveOAuthIntegration(string $kind, int $platformId, int $integrationConfigId, array $values): void
    {
        $this->accountRequest('POST', IntegrationList::OAUTH[$kind]['save'], [
            'platform_id'    => $platformId,
            'integration_id' => $integrationConfigId,
        ] + $values);
    }

    /**
     * Creates an Ongage integration (Statusi checks the API key with Ongage) and returns its id.
     *
     * @throws StatusiApiException
     */
    public function createOngageIntegration(int $platformId, string $apiKey, bool $contactManager, bool $testCampaign, bool $includeNewList): int
    {
        $data = $this->accountRequest('POST', '/integration/ongage-integration', [
            'platform_id'      => $platformId,
            'api_key'          => $apiKey,
            'contact_manager'  => $contactManager ? '1' : '0',
            'test_campaign'    => $testCampaign,
            'include_new_list' => $includeNewList,
        ], self::QUERY_TIMEOUT);

        return (int) ($data['integration_id'] ?? 0);
    }

    /**
     * Sending lists of an Ongage integration (id, name, default, field_mapping_override, ...).
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws StatusiApiException
     */
    public function getOngageLists(int $integrationConfigId): array
    {
        $data = $this->accountRequest('GET', '/integration/get-ongage-lists?'.http_build_query(['integration_config_id' => $integrationConfigId]), null, self::QUERY_TIMEOUT);

        return array_values(array_filter(is_array($data['payload'] ?? null) ? $data['payload'] : [], 'is_array'));
    }

    /**
     * Field titles of one Ongage list.
     *
     * @return string[]
     *
     * @throws StatusiApiException
     */
    public function getOngageFields(int $integrationConfigId, int $listId): array
    {
        $data = $this->accountRequest('GET', '/integration/get-ongage-fields?'.http_build_query(['integration_config_id' => $integrationConfigId, 'list_id' => $listId]), null, self::QUERY_TIMEOUT);

        $titles = [];
        foreach (is_array($data['payload'] ?? null) ? $data['payload'] : [] as $field) {
            if (is_array($field) && isset($field['title']) && '' !== (string) $field['title']) {
                $titles[] = (string) $field['title'];
            }
        }

        return $titles;
    }

    /**
     * Statusi fields that can be mapped ("table.column") and the fixed mappings Statusi always applies.
     *
     * @return array{fields: string[], fixed: array<string, string>}
     *
     * @throws StatusiApiException
     */
    public function getFieldMappingDefinition(): array
    {
        $fields = $this->accountRequest('GET', '/integration/get_default_fields');
        $fixed  = $this->accountRequest('GET', '/integration/get-fixed-field-mappings');

        return [
            'fields' => array_values(array_filter(is_array($fields['fields'] ?? null) ? $fields['fields'] : [], 'is_string')),
            'fixed'  => array_filter($fixed, 'is_string'),
        ];
    }

    /**
     * Saves the field mapping of an Ongage Contact Manager integration (default mapping + per-list mappings).
     *
     * @param array<string, mixed>  $fieldsMapping  {"field_mapping": {...}, "<list id>": {"field_mapping": {...}}}
     * @param array<string, string> $listNames
     * @param string[]              $deletedListIds
     *
     * @throws StatusiApiException
     */
    public function saveOngageFieldMapping(int $integrationConfigId, int $platformId, array $fieldsMapping, array $listNames, array $deletedListIds): void
    {
        $this->accountRequest('PUT', '/integration/update_field_mapping', [[
            'id'               => $integrationConfigId,
            'status'           => 1,
            'platform_id'      => $platformId,
            'deleted_list_ids' => array_values($deletedListIds),
            'list_names'       => $listNames ?: new \stdClass(),
            'listdata'         => ['fields_mapping' => $fieldsMapping],
        ]], self::QUERY_TIMEOUT);
    }

    /**
     * @return array{api_token: string, account_id: int}
     *
     * @throws StatusiApiException
     */
    private function requireConnection(): array
    {
        $connection = $this->connectionManager->getConnection();
        if (null === $connection) {
            throw new StatusiApiException('Mautic is not connected to Statusi.');
        }

        return $connection;
    }

    /**
     * @param array<string, mixed> $json
     *
     * @return array<string, mixed>
     */
    private function withAccount(array $json): array
    {
        return $json + ['account_id' => $this->requireConnection()['account_id']];
    }

    /**
     * Request for the connected account: Statusi checks the account in the Account-Id header against the token.
     *
     * @param array<string, mixed>|null $json
     *
     * @return array<string, mixed>
     *
     * @throws StatusiApiException
     */
    private function accountRequest(string $method, string $path, ?array $json = null, int $timeout = self::TIMEOUT): array
    {
        $connection = $this->requireConnection();

        return $this->request($method, $path, $connection['api_token'], $json, $timeout, accountId: $connection['account_id']);
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
    private function request(string $method, string $path, ?string $apiToken = null, ?array $json = null, int $timeout = self::TIMEOUT, ?string &$content = null, ?int $accountId = null): array
    {
        $headers = [
            'Accept'           => 'application/json',
            self::CLIENT_HEADER => self::CLIENT_TYPE,
        ];
        if ($apiToken) {
            $headers['Authorization'] = 'Bearer '.$apiToken;
        }
        if (null !== $accountId) {
            $headers['Account-Id'] = (string) $accountId;
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
            throw new StatusiApiException($this->errorMessage($body), $statusCode);
        }

        return is_array($body['data'] ?? null) ? $body['data'] : [];
    }

    /**
     * Statusi's message, plus the field errors of a validation error ({"api_key": ["..."]}).
     *
     * @param array<string, mixed> $body
     */
    private function errorMessage(array $body): string
    {
        $message = (string) ($body['message'] ?? 'Statusi returned an error.');

        $details = [];
        foreach (is_array($body['data'] ?? null) ? $body['data'] : [] as $field => $errors) {
            if (is_string($field) && is_array($errors) && array_is_list($errors)) {
                $details[] = $field.': '.implode(' ', array_filter($errors, 'is_string'));
            }
        }

        return $details ? $message.' ('.implode('; ', $details).')' : $message;
    }
}
