<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Tests\Unit;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use MauticPlugin\MauticStatusiBundle\Exception\StatusiApiException;
use MauticPlugin\MauticStatusiBundle\Service\ConnectionManager;
use MauticPlugin\MauticStatusiBundle\Service\StatusiClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class StatusiClientTest extends TestCase
{
    public function testExchangeCodeSendsTheMauticApiClient(): void
    {
        $bodies = [];
        $client = $this->client($bodies);

        $client->exchangeCode('code', str_repeat('v', 43), 'https://mautic.test/s/statusi/callback', [
            'base_url' => 'https://mautic.test', 'client_id' => '1_abc', 'client_secret' => 'secret',
        ]);
        $client->exchangeCode('code', str_repeat('v', 43), 'https://mautic.test/s/statusi/callback');

        self::assertSame(['base_url' => 'https://mautic.test', 'client_id' => '1_abc', 'client_secret' => 'secret'], $bodies[0]['mautic_api']);
        self::assertSame('mautic', $bodies[0]['client_type']);
        self::assertArrayNotHasKey('mautic_api', $bodies[1]);
    }

    public function testIntegrationRequestsAreLockedToTheConnectedAccount(): void
    {
        $requests = [];
        $http     = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $headers = [];
            foreach ($options['headers'] as $header) {
                [$name, $value]                  = explode(': ', $header, 2);
                $headers[strtolower($name)] = $value;
            }
            $requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => json_decode((string) ($options['body'] ?? 'null'), true)];

            return new MockResponse((string) json_encode(['error' => false, 'data' => [['integration_config_id' => 5], 'junk']]));
        });

        $client = $this->connectedClient($http);
        self::assertSame([['integration_config_id' => 5]], $client->getIntegrations());
        $client->setIntegrationStatus(5, false);
        $client->saveChatgptAdsAccount(11, 'sk-test-key', '', null);

        self::assertSame(['GET', 'http://127.0.0.1:5000/integration/get_lists?account_id=32'], [$requests[0]['method'], $requests[0]['url']]);
        foreach ($requests as $request) {
            self::assertSame('32', $request['headers']['account-id']);
            self::assertSame('Bearer stmc_token', $request['headers']['authorization']);
            self::assertSame('mautic', $request['headers']['x-statusi-client']);
        }
        self::assertSame(['PUT', ['integration_config_id' => 5, 'status' => 0]], [$requests[1]['method'], $requests[1]['body']]);
        self::assertSame(
            ['platform_id' => 11, 'api_key' => 'sk-test-key', 'display_name' => null, 'integration_id' => null, 'account_id' => 32],
            $requests[2]['body']
        );
    }

    public function testErrorsIncludeStatusiFieldErrors(): void
    {
        $http = new MockHttpClient(new MockResponse(
            (string) json_encode(['error' => true, 'message' => 'Validation Error', 'data' => ['api_key' => ['Shorter than minimum length 8.']]]),
            ['http_code' => 400]
        ));

        try {
            $this->connectedClient($http)->validateChatgptAdsKey(11, 'short');
            self::fail('Expected an exception');
        } catch (StatusiApiException $e) {
            self::assertSame('Validation Error (api_key: Shorter than minimum length 8.)', $e->getMessage());
            self::assertSame(400, $e->getCode());
        }
    }

    public function testSignInAndFieldMappingRequests(): void
    {
        $requests  = [];
        $responses = [
            ['auth_url' => 'https://accounts.google.com/o/oauth2/auth?state=abc.m7'],
            ['auth_url' => 'javascript:alert(1)'],
            null,
        ];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests, &$responses): MockResponse {
            $requests[] = ['method' => $method, 'url' => $url, 'body' => json_decode((string) ($options['body'] ?? 'null'), true)];

            return new MockResponse((string) json_encode(['error' => false, 'data' => array_shift($responses)]));
        });
        $client = $this->connectedClient($http);

        self::assertSame('https://accounts.google.com/o/oauth2/auth?state=abc.m7', $client->startOAuth('google_ads', 6));
        self::assertSame(['POST', 'http://127.0.0.1:5000/integration/google-ads', ['platform_id' => 6, 'account_id' => 32]], array_values($requests[0]));

        try {
            $client->startOAuth('meta_ads', 9);
            self::fail('Only https sign-in URLs may be followed');
        } catch (StatusiApiException) {
        }

        $client->saveOngageFieldMapping(5, 1, ['field_mapping' => ['contacts.email' => 'Email']], [], ['10']);
        self::assertSame('PUT', $requests[2]['method']);
        self::assertSame([[
            'id' => 5, 'status' => 1, 'platform_id' => 1, 'deleted_list_ids' => ['10'], 'list_names' => [],
            'listdata' => ['fields_mapping' => ['field_mapping' => ['contacts.email' => 'Email']]],
        ]], $requests[2]['body']);
    }

    public function testIntegrationRequestsNeedAConnection(): void
    {
        $this->expectException(StatusiApiException::class);

        $parameters = $this->createMock(CoreParametersHelper::class);
        $parameters->method('get')->willReturn('http://127.0.0.1:5000');
        (new StatusiClient(new MockHttpClient(), $parameters, $this->createMock(ConnectionManager::class)))->getPlatforms();
    }

    private function connectedClient(MockHttpClient $http): StatusiClient
    {
        $parameters = $this->createMock(CoreParametersHelper::class);
        $parameters->method('get')->willReturn('http://127.0.0.1:5000');

        $connections = $this->createMock(ConnectionManager::class);
        $connections->method('getConnection')->willReturn([
            'api_token' => 'stmc_token', 'account_id' => 32, 'account_name' => 'acc', 'user_email' => 'a@b.c', 'connected_at' => '', 'api_client_id' => 5,
        ]);

        return new StatusiClient($http, $parameters, $connections);
    }

    /**
     * @param array<int, array<string, mixed>> $bodies
     */
    private function client(array &$bodies): StatusiClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$bodies): MockResponse {
            $bodies[] = json_decode((string) $options['body'], true);

            return new MockResponse((string) json_encode(['data' => ['api_token' => 'stmc_x', 'account_id' => 13]]));
        });

        $parameters = $this->createMock(CoreParametersHelper::class);
        $parameters->method('get')->willReturn('http://127.0.0.1:5000');

        return new StatusiClient($http, $parameters, $this->createMock(ConnectionManager::class));
    }
}
