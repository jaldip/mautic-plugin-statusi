<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Integration\Support;

use Mautic\IntegrationsBundle\Integration\DefaultConfigFormTrait;
use Mautic\IntegrationsBundle\Integration\Interfaces\ConfigFormInterface;
use MauticPlugin\MauticStatusiBundle\Integration\StatusiIntegration;
use MauticPlugin\MauticStatusiBundle\Service\ConnectionManager;

/**
 * The plugin modal does not expose the API token as an editable field (ConfigFormAuthInterface is
 * intentionally not implemented): the token is only ever written by the connect callback, so saving
 * the modal can never wipe or leak it.
 */
final class ConfigSupport extends StatusiIntegration implements ConfigFormInterface
{
    use DefaultConfigFormTrait;

    public function getConfigFormContentTemplate(): string
    {
        return '@MauticStatusi/Integration/config_form.html.twig';
    }

    /**
     * Connection details for the config modal (never includes the API token).
     *
     * @return array{account_id: int, account_name: string, user_email: string, connected_at: string}|null
     */
    public function getConnectionDetails(): ?array
    {
        if (!$this->hasIntegrationConfiguration()) {
            return null;
        }

        $connection = ConnectionManager::connectionFromApiKeys($this->getIntegrationConfiguration()->getApiKeys() ?? []);
        if (null === $connection) {
            return null;
        }

        unset($connection['api_token']);

        return $connection;
    }
}
