<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Service;

use Mautic\CacheBundle\Cache\CacheProviderInterface;
use MauticPlugin\MauticStatusiBundle\Exception\StatusiApiException;
use Psr\Log\LoggerInterface;

/**
 * Cached list of the connected Statusi account's reports, used by the sidebar menu and the
 * Statusi page. The list is fetched from Statusi at most once per TTL, so rendering Mautic
 * pages does not call Statusi on every request.
 */
class ReportCatalog
{
    private const CACHE_TTL = 300;

    // A failed fetch is cached briefly so an unreachable Statusi does not slow down every page
    private const ERROR_CACHE_TTL = 60;

    public function __construct(
        private CacheProviderInterface $cacheProvider,
        private StatusiClient $statusiClient,
        private ConnectionManager $connectionManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<int, array{id: int, name: string, description: ?string, slug: ?string, configuration: string}>
     *
     * @throws StatusiApiException when Statusi could not be reached (also while the failure is cached)
     */
    public function getReports(): array
    {
        $connection = $this->connectionManager->getConnection();
        if (null === $connection) {
            return [];
        }

        $item   = $this->cacheProvider->getItem($this->getCacheKey($connection));
        $cached = $item->isHit() ? $item->get() : null;

        if (!is_array($cached)) {
            try {
                $cached = ['reports' => $this->normalize($this->statusiClient->getReports())];
                $item->expiresAfter(self::CACHE_TTL);
            } catch (StatusiApiException $e) {
                $this->logger->warning('Statusi: could not load the report list: '.$e->getMessage());
                $cached = ['error' => $e->getMessage()];
                $item->expiresAfter(self::ERROR_CACHE_TTL);
            }

            $item->set($cached);
            $this->cacheProvider->save($item);
        }

        if (isset($cached['error'])) {
            throw new StatusiApiException((string) $cached['error']);
        }

        return $cached['reports'];
    }

    /**
     * @return array{id: int, name: string, description: ?string, slug: ?string, configuration: string}|null
     *
     * @throws StatusiApiException
     */
    public function getReport(int $id): ?array
    {
        foreach ($this->getReports() as $report) {
            if ($report['id'] === $id) {
                return $report;
            }
        }

        return null;
    }

    /**
     * @param array<int, mixed> $reports
     *
     * @return array<int, array{id: int, name: string, description: ?string, slug: ?string, configuration: string}>
     */
    private function normalize(array $reports): array
    {
        $normalized = [];
        foreach ($reports as $report) {
            if (!is_array($report) || !isset($report['id']) || !is_numeric($report['id'])) {
                continue;
            }

            $name = trim((string) ($report['report_name'] ?? ''));

            $normalized[] = [
                'id'            => (int) $report['id'],
                'name'          => '' !== $name ? $name : 'Report #'.$report['id'],
                'description'   => isset($report['description']) ? (string) $report['description'] : null,
                'slug'          => isset($report['slug']) ? (string) $report['slug'] : null,
                'configuration' => $this->normalizeConfiguration($report['configuration'] ?? null),
            ];
        }

        return $normalized;
    }

    /**
     * The configuration is kept as a JSON object string, exactly as Statusi sent it (see StatusiClient::getReports()).
     */
    private function normalizeConfiguration(mixed $configuration): string
    {
        if (is_string($configuration)) {
            $decoded = json_decode($configuration);

            return $decoded instanceof \stdClass ? $configuration : '{}';
        }

        return is_array($configuration) && [] !== $configuration ? (string) json_encode($configuration) : '{}';
    }

    /**
     * Keyed by account and connection time, so reconnecting (even to the same account) refreshes the list.
     *
     * @param array{account_id: int, connected_at: string} $connection
     */
    private function getCacheKey(array $connection): string
    {
        return 'statusi_reports_'.$connection['account_id'].'_'.md5($connection['connected_at']);
    }
}
