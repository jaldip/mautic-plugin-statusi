<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Service;

use Mautic\IntegrationsBundle\Exception\IntegrationNotFoundException;
use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\MauticStatusiBundle\Integration\StatusiIntegration;

/**
 * Reads and writes the Statusi connection. It is stored in the integration's api_keys,
 * which Mautic encrypts at rest.
 */
class ConnectionManager
{
    private const REQUIRED_KEYS = ['api_token', 'account_id'];

    public function __construct(
        private IntegrationsHelper $integrationsHelper,
    ) {
    }

    /**
     * @param array<string, mixed> $apiKeys
     *
     * @return array{api_token: string, account_id: int, account_name: string, user_email: string, connected_at: string, api_client_id: ?int}|null
     */
    public static function connectionFromApiKeys(array $apiKeys): ?array
    {
        foreach (self::REQUIRED_KEYS as $key) {
            if (empty($apiKeys[$key])) {
                return null;
            }
        }

        return [
            'api_token'    => (string) $apiKeys['api_token'],
            'account_id'   => (int) $apiKeys['account_id'],
            'account_name' => (string) ($apiKeys['account_name'] ?? ''),
            'user_email'   => (string) ($apiKeys['user_email'] ?? ''),
            'connected_at' => (string) ($apiKeys['connected_at'] ?? ''),
            // Mautic OAuth2 client created for Statusi's daily data pulls
            'api_client_id' => !empty($apiKeys['api_client_id']) ? (int) $apiKeys['api_client_id'] : null,
        ];
    }

    /**
     * @return array{api_token: string, account_id: int, account_name: string, user_email: string, connected_at: string, api_client_id: ?int}|null
     */
    public function getConnection(): ?array
    {
        $configuration = $this->getConfiguration();

        return $configuration ? self::connectionFromApiKeys($configuration->getApiKeys() ?? []) : null;
    }

    /**
     * Connected = has a token and the plugin is enabled in Mautic.
     */
    public function isConnected(): bool
    {
        $configuration = $this->getConfiguration();

        return $configuration
            && $configuration->getIsPublished()
            && null !== self::connectionFromApiKeys($configuration->getApiKeys() ?? []);
    }

    /**
     * @param array<string, mixed> $tokenData   Response data of Statusi's /mautic/token
     * @param int|null             $apiClientId Mautic API client Statusi pulls data with
     */
    public function saveConnection(array $tokenData, ?int $apiClientId = null): void
    {
        $configuration = $this->getConfiguration();
        if (!$configuration) {
            throw new \RuntimeException('The Statusi plugin is not installed. Install it from Settings > Plugins first.');
        }

        $configuration->setApiKeys([
            'api_token'    => (string) $tokenData['api_token'],
            'account_id'   => (int) $tokenData['account_id'],
            'account_name' => (string) ($tokenData['account_name'] ?? ''),
            'user_email'   => (string) ($tokenData['user_email'] ?? ''),
            'connected_at' => (string) ($tokenData['connected_at'] ?? (new \DateTimeImmutable())->format(DATE_ATOM)),
            'api_client_id' => $apiClientId,
        ]);
        $configuration->setIsPublished(true);

        $this->integrationsHelper->saveIntegrationConfiguration($configuration);
    }

    public function clearConnection(): void
    {
        $configuration = $this->getConfiguration();
        if (!$configuration) {
            return;
        }

        $configuration->setApiKeys([]);
        $configuration->setIsPublished(false);

        $this->integrationsHelper->saveIntegrationConfiguration($configuration);
    }

    private function getConfiguration(): ?Integration
    {
        try {
            return $this->integrationsHelper->getIntegration(StatusiIntegration::NAME)->getIntegrationConfiguration();
        } catch (IntegrationNotFoundException) {
            return null;
        }
    }
}
