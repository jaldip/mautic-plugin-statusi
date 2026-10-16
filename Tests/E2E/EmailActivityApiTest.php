<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * HTTP tests against a running Mautic with the API enabled (Settings > Configuration > API Settings,
 * with HTTP basic auth on). Skipped unless these environment variables are set:
 *
 *   STATUSI_E2E_BASE_URL          e.g. http://mauticproject.local
 *   STATUSI_E2E_USER / _PASSWORD  a Mautic user allowed to view contacts and emails
 *   STATUSI_E2E_LIMITED_USER / _LIMITED_PASSWORD  (optional) a user without contact view permission
 */
final class EmailActivityApiTest extends TestCase
{
    private const PATH = '/api/custom/email-activity-report';

    private HttpClientInterface $http;
    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = rtrim((string) getenv('STATUSI_E2E_BASE_URL'), '/');
        if ('' === $this->baseUrl || !getenv('STATUSI_E2E_USER')) {
            self::markTestSkipped('Set STATUSI_E2E_BASE_URL, STATUSI_E2E_USER and STATUSI_E2E_PASSWORD to run the HTTP tests.');
        }
        $this->http = HttpClient::create(['timeout' => 60]);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function get(string $path, array $query = [], ?array $auth = null): ResponseInterface
    {
        $options = ['query' => $query, 'headers' => ['Accept' => 'application/json']];
        if ($auth) {
            $options['auth_basic'] = $auth;
        }

        return $this->http->request('GET', $this->baseUrl.$path, $options);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function admin(): array
    {
        return [(string) getenv('STATUSI_E2E_USER'), (string) getenv('STATUSI_E2E_PASSWORD')];
    }

    public function testRequiresAuthentication(): void
    {
        $response = $this->get(self::PATH, ['dateFrom' => '2026-10-09', 'dateTo' => '2026-10-09']);

        self::assertContains($response->getStatusCode(), [401, 403]);
    }

    public function testReturnsTheReport(): void
    {
        $response = $this->get(self::PATH, ['dateFrom' => '2026-10-09', 'dateTo' => '2026-10-09', 'limit' => '5'], $this->admin());
        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));

        $body = $response->toArray();
        self::assertSame(['data', 'pagination', 'filters', 'unassignedActivities', 'meta'], array_keys($body));
        self::assertSame(5, $body['pagination']['limit']);
        self::assertSame('2026-10-09T00:00:00+00:00', $body['filters']['dateFrom']);
        self::assertSame('UTC', $body['meta']['timezone']);

        // Unavailable values are returned as null, not dropped by the API serializer.
        foreach (['contactId', 'emailId', 'campaignId', 'search'] as $key) {
            self::assertArrayHasKey($key, $body['filters']);
            self::assertNull($body['filters'][$key]);
        }
    }

    public function testRejectsInvalidParameters(): void
    {
        $response = $this->get(self::PATH, ['dateFrom' => 'yesterday'], $this->admin());
        self::assertSame(400, $response->getStatusCode());

        $details = $response->toArray(false)['errors'][0]['details'] ?? [];
        self::assertArrayHasKey('dateFrom', $details);
        self::assertArrayHasKey('dateTo', $details);
    }

    public function testDeniesUsersWithoutPermission(): void
    {
        if (!getenv('STATUSI_E2E_LIMITED_USER')) {
            self::markTestSkipped('Set STATUSI_E2E_LIMITED_USER and STATUSI_E2E_LIMITED_PASSWORD to test permissions.');
        }

        $response = $this->get(
            self::PATH,
            ['dateFrom' => '2026-10-09', 'dateTo' => '2026-10-09'],
            [(string) getenv('STATUSI_E2E_LIMITED_USER'), (string) getenv('STATUSI_E2E_LIMITED_PASSWORD')]
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testOriginalActivityApiIsUnchanged(): void
    {
        $response = $this->get('/api/contacts/activity', ['limit' => '1'], $this->admin());
        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));

        $body = $response->toArray();
        foreach (['events', 'filters', 'order', 'types', 'total', 'page', 'limit', 'maxPages'] as $key) {
            self::assertArrayHasKey($key, $body);
        }
    }
}
