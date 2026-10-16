<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * HTTP tests for GET /api/custom/contact-attributes against a running Mautic (API + basic auth on).
 * Same environment variables as EmailActivityApiTest.
 */
final class ContactAttributesApiTest extends TestCase
{
    private const PATH = '/api/custom/contact-attributes';

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
     * @param array<string, mixed>             $query
     * @param array{0: string, 1: string}|null $auth
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
        self::assertContains($this->get(self::PATH, ['dateFrom' => '2026-10-01', 'dateTo' => '2026-10-09'])->getStatusCode(), [401, 403]);
    }

    public function testReturnsContacts(): void
    {
        $response = $this->get(self::PATH, ['dateFrom' => '2026-01-01', 'dateTo' => '2026-12-31', 'limit' => '5', 'includeHistory' => 'true'], $this->admin());
        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));

        $body = $response->toArray();
        self::assertSame(['data', 'pagination', 'filters', 'meta'], array_keys($body));
        self::assertSame(5, $body['pagination']['limit']);
        self::assertSame('all', $body['filters']['changeType']);
        self::assertArrayHasKey('contactId', $body['filters']);
        self::assertNull($body['filters']['contactId'], 'nulls are kept');

        foreach ($body['data'] as $row) {
            self::assertSame(['contact', 'changeInfo', 'history'], array_keys($row));
            self::assertArrayHasKey('fields', $row['contact']);
            self::assertArrayHasKey('dateModified', $row['contact']);
            self::assertContains($row['changeInfo']['changeType'], ['created', 'updated', 'created_and_updated']);
        }
    }

    public function testRejectsInvalidParameters(): void
    {
        $response = $this->get(self::PATH, ['dateFrom' => '2026-10-09', 'dateTo' => '2026-10-01', 'sortBy' => 'email', 'limit' => '0'], $this->admin());
        self::assertSame(400, $response->getStatusCode());

        $details = $response->toArray(false)['errors'][0]['details'] ?? [];
        foreach (['dateTo', 'sortBy', 'limit'] as $field) {
            self::assertArrayHasKey($field, $details);
        }
    }

    public function testDeniesUsersWithoutContactPermission(): void
    {
        if (!getenv('STATUSI_E2E_LIMITED_USER')) {
            self::markTestSkipped('Set STATUSI_E2E_LIMITED_USER and STATUSI_E2E_LIMITED_PASSWORD to test permissions.');
        }

        $response = $this->get(
            self::PATH,
            ['dateFrom' => '2026-10-01', 'dateTo' => '2026-10-09'],
            [(string) getenv('STATUSI_E2E_LIMITED_USER'), (string) getenv('STATUSI_E2E_LIMITED_PASSWORD')]
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testCoreContactEndpointsAreUnchanged(): void
    {
        $list = $this->get('/api/contacts', ['limit' => '1'], $this->admin());
        self::assertSame(200, $list->getStatusCode(), $list->getContent(false));
        $body = $list->toArray();
        self::assertArrayHasKey('total', $body);
        self::assertArrayHasKey('contacts', $body);

        foreach ($body['contacts'] as $id => $contact) {
            $single = $this->get('/api/contacts/'.$id, [], $this->admin());
            self::assertSame(200, $single->getStatusCode());
            self::assertSame((int) $id, (int) $single->toArray()['contact']['id']);
        }
    }
}
