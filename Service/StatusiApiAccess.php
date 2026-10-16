<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Service;

use Mautic\ApiBundle\Entity\oAuth2\Client;
use Mautic\ApiBundle\Model\ClientModel;
use Mautic\CoreBundle\Configurator\Configurator;
use Mautic\CoreBundle\Helper\CacheHelper;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\UserBundle\Entity\Role;
use Mautic\UserBundle\Model\RoleModel;
use OAuth2\OAuth2;

/**
 * Gives Statusi read access to this Mautic's API for its daily data pulls:
 *  - turns on Mautic's API (OAuth2; Basic auth is left as it is),
 *  - keeps a dedicated read-only role (all contacts + all emails, view only),
 *  - creates / deletes an OAuth2 client-credentials client with that role.
 *
 * A client-credentials token acts as a pseudo user with the client's role, so Statusi can
 * only read contacts and emails, which is what the export endpoints need.
 */
class StatusiApiAccess
{
    public const ROLE_NAME   = 'Statusi API (read only)';
    public const CLIENT_NAME = 'Statusi';

    public const ROLE_PERMISSIONS = [
        'lead:leads'   => ['viewother'],
        'email:emails' => ['viewother'],
    ];

    public function __construct(
        private CoreParametersHelper $coreParametersHelper,
        private Configurator $configurator,
        private CacheHelper $cacheHelper,
        private RoleModel $roleModel,
        private ClientModel $clientModel,
    ) {
    }

    /**
     * @throws \RuntimeException when the API is off and config/local.php is not writable
     */
    public function ensureApiEnabled(): void
    {
        if ($this->coreParametersHelper->get('api_enabled')) {
            return;
        }
        if (!$this->configurator->isFileWritable()) {
            throw new \RuntimeException('Mautic\'s API is turned off and config/local.php is not writable. Turn the API on under Settings > Configuration > API Settings, then connect again.');
        }

        $this->configurator->mergeParameters(['api_enabled' => true]);
        $this->configurator->write();
        $this->cacheHelper->refreshConfig();
    }

    /**
     * Creates a new client-credentials client for Statusi.
     *
     * @return array{id: int, client_id: string, client_secret: string}
     */
    public function createClient(string $siteUrl): array
    {
        $client = new Client();
        $client->setName(self::CLIENT_NAME);
        $client->setRedirectUris([rtrim($siteUrl, '/')]);
        $client->addGrantType(OAuth2::GRANT_TYPE_CLIENT_CREDENTIALS);
        $client->setRole($this->getRole());

        $this->clientModel->setApiMode(ClientModel::API_MODE_OAUTH2);
        $this->clientModel->saveEntity($client);

        return [
            'id'            => (int) $client->getId(),
            'client_id'     => $client->getPublicId(),
            'client_secret' => (string) $client->getSecret(),
        ];
    }

    /**
     * Deletes a client created by createClient(); unknown ids are ignored.
     */
    public function deleteClient(?int $id): void
    {
        if (null === $id) {
            return;
        }

        $this->clientModel->setApiMode(ClientModel::API_MODE_OAUTH2);
        $client = $this->clientModel->getEntity($id);
        if ($client instanceof Client && null !== $client->getId() && self::CLIENT_NAME === $client->getName()) {
            $this->clientModel->deleteEntity($client);
        }
    }

    /**
     * The dedicated read-only role, created on first use.
     */
    public function getRole(): Role
    {
        $role = $this->roleModel->getRepository()->findOneBy(['name' => self::ROLE_NAME]);
        if ($role instanceof Role) {
            return $role;
        }

        $role = new Role();
        $role->setName(self::ROLE_NAME);
        $role->setDescription('Used by the Statusi plugin: Statusi reads contacts and email activity with it. Do not edit.');
        $role->setIsAdmin(false);
        $this->roleModel->setRolePermissions($role, self::ROLE_PERMISSIONS);
        $this->roleModel->saveEntity($role);

        return $role;
    }
}
