<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Tests\Unit;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
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
