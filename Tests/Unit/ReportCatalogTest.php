<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Tests\Unit;

use Mautic\CacheBundle\Cache\CacheProviderInterface;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use MauticPlugin\MauticStatusiBundle\Exception\StatusiApiException;
use MauticPlugin\MauticStatusiBundle\Service\ConnectionManager;
use MauticPlugin\MauticStatusiBundle\Service\ReportCatalog;
use MauticPlugin\MauticStatusiBundle\Service\StatusiClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ReportCatalogTest extends TestCase
{
    private const CONNECTION = [
        'api_token'    => 'mtc_test',
        'account_id'   => 42,
        'account_name' => 'Acme',
        'user_email'   => 'user@example.com',
        'connected_at' => '2026-10-14T10:00:00+00:00',
    ];

    public function testReportsAreNormalizedAndCached(): void
    {
        $client = $this->createMock(StatusiClient::class);
        $client->expects(self::once())->method('getReports')->willReturn([
            ['id' => 7, 'report_name' => '  Email Performance ', 'description' => 'Opens and clicks', 'slug' => 'email-performance', 'configuration' => '{"rows":[],"cte":{}}'],
            ['id' => '8', 'report_name' => '', 'description' => null, 'configuration' => 'not json'],
            ['report_name' => 'No id'],
            'not a report',
        ]);

        $catalog  = new ReportCatalog($this->cache(), $client, $this->connectionManager(self::CONNECTION), new NullLogger());
        $expected = [
            ['id' => 7, 'name' => 'Email Performance', 'description' => 'Opens and clicks', 'slug' => 'email-performance', 'configuration' => '{"rows":[],"cte":{}}'],
            ['id' => 8, 'name' => 'Report #8', 'description' => null, 'slug' => null, 'configuration' => '{}'],
        ];

        self::assertSame($expected, $catalog->getReports());
        self::assertSame($expected, $catalog->getReports(), 'second call is served from the cache');
        self::assertSame($expected[1], $catalog->getReport(8));
        self::assertNull($catalog->getReport(99));
    }

    public function testFailureIsCachedAndRethrown(): void
    {
        $client = $this->createMock(StatusiClient::class);
        $client->expects(self::once())->method('getReports')->willThrowException(new StatusiApiException('Could not reach Statusi: timeout'));

        $catalog = new ReportCatalog($this->cache(), $client, $this->connectionManager(self::CONNECTION), new NullLogger());

        foreach ([1, 2] as $attempt) {
            try {
                $catalog->getReports();
                self::fail('Expected StatusiApiException on attempt '.$attempt);
            } catch (StatusiApiException $e) {
                self::assertSame('Could not reach Statusi: timeout', $e->getMessage());
            }
        }
    }

    public function testNotConnectedReturnsNoReports(): void
    {
        $client = $this->createMock(StatusiClient::class);
        $client->expects(self::never())->method('getReports');

        $catalog = new ReportCatalog($this->cache(), $client, $this->connectionManager(null), new NullLogger());

        self::assertSame([], $catalog->getReports());
    }

    public function testClientSendsTokenForConnectedAccountAndTreats404AsEmpty(): void
    {
        $requests = [];
        $http     = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, $options['normalized_headers']];

            return 1 === count($requests)
                ? new MockResponse('{"data":[{"id":1,"report_name":"A","configuration":{"rows":[{"field":"isp","format":{}}],"operator":{}}}]}')
                : new MockResponse((string) json_encode(['error' => true, 'message' => 'No reports found']), ['http_code' => 404]);
        });

        $parameters = $this->createMock(CoreParametersHelper::class);
        $parameters->method('get')->with('statusi_api_url')->willReturn('http://127.0.0.1:5000/');

        $client = new StatusiClient($http, $parameters, $this->connectionManager(self::CONNECTION));

        self::assertSame(
            [['id' => 1, 'report_name' => 'A', 'configuration' => '{"rows":[{"field":"isp","format":{}}],"operator":{}}']],
            $client->getReports(),
            'the configuration keeps empty JSON objects'
        );
        self::assertSame([], $client->getReports());

        [$method, $url, $headers] = $requests[0];
        self::assertSame('GET', $method);
        self::assertSame('http://127.0.0.1:5000/report/get-reports?account_id=42', $url);
        self::assertSame(['Authorization: Bearer mtc_test'], $headers['authorization']);
        self::assertSame(['X-Statusi-Client: mautic'], $headers['x-statusi-client']);
    }

    /**
     * @param array<string, mixed>|null $connection
     */
    private function connectionManager(?array $connection): ConnectionManager
    {
        $manager = $this->createMock(ConnectionManager::class);
        $manager->method('getConnection')->willReturn($connection);
        $manager->method('isConnected')->willReturn(null !== $connection);

        return $manager;
    }

    private function cache(): CacheProviderInterface
    {
        return new class() extends ArrayAdapter implements CacheProviderInterface {
            public function getSimpleCache(): Psr16Cache
            {
                return new Psr16Cache($this);
            }
        };
    }
}
