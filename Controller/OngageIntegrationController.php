<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Controller;

use MauticPlugin\MauticStatusiBundle\Exception\StatusiApiException;
use MauticPlugin\MauticStatusiBundle\Service\ConnectionManager;
use MauticPlugin\MauticStatusiBundle\Service\OngageFieldMapping;
use MauticPlugin\MauticStatusiBundle\Service\StatusiClient;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ongage, set up from Mautic like on Statusi's Ongage pages:
 *  create  -> API key + task (Contact Activity / Contact Manager); Statusi checks the key with Ongage
 *  mapping -> Contact Manager only: which Statusi contact field comes from which Ongage field, by default and
 *             per Ongage list (statusi_ui/src/pages/IntegratedList/IntegratedList.tsx)
 */
final class OngageIntegrationController extends AbstractIntegrationController
{
    public function createAction(Request $request, ConnectionManager $connectionManager, StatusiClient $statusiClient): Response
    {
        if (!$connectionManager->isConnected()) {
            return $this->redirectToRoute('mautic_statusi_index');
        }

        $platformId = $request->query->getInt('platform_id');
        if ($platformId <= 0) {
            return $this->notFound();
        }

        if ($request->isMethod('POST')) {
            if ($response = $this->refuseUnlessConnectedAjaxPost($request, $connectionManager)) {
                return $response;
            }

            $apiKey    = trim((string) $request->request->get('api_key', ''));
            $isManager = 'manager' === $request->request->get('task');
            if ('' === $apiKey) {
                return $this->invalid('mautic.statusi.integrations.api_key_required');
            }

            try {
                $integrationConfigId = $statusiClient->createOngageIntegration(
                    $platformId,
                    $apiKey,
                    $isManager,
                    $request->request->getBoolean('test_campaign'),
                    $request->request->getBoolean('include_new_list'),
                );
            } catch (StatusiApiException $e) {
                return $this->failure('mautic.statusi.integrations.save_error', $e);
            }

            // Contact Manager continues with the field mapping, like in Statusi.
            $redirect = $isManager && $integrationConfigId > 0
                ? $this->generateUrl('mautic_statusi_integration_ongage_mapping', ['integration_config_id' => $integrationConfigId, 'platform_id' => $platformId])
                : null;

            return $this->savedAndBackToList('mautic.statusi.integrations.created', $redirect);
        }

        return $this->page($request, '@MauticStatusi/Integrations/ongage.html.twig', 'mautic_statusi_integration_ongage', [
            'platformId' => $platformId,
        ], 'statusiIntegrationForm');
    }

    public function mappingAction(Request $request, ConnectionManager $connectionManager, StatusiClient $statusiClient, OngageFieldMapping $fieldMapping): Response
    {
        $integrationConfigId = $request->query->getInt('integration_config_id');
        $platformId          = $request->query->getInt('platform_id');
        if ($integrationConfigId <= 0 || $platformId <= 0) {
            return $this->notFound();
        }
        if (!$connectionManager->isConnected()) {
            return $this->redirectToRoute('mautic_statusi_index');
        }

        if ($request->isMethod('POST')) {
            if ($response = $this->refuseUnlessConnectedAjaxPost($request, $connectionManager)) {
                return $response;
            }

            try {
                $definition = $statusiClient->getFieldMappingDefinition();
                $payload    = $fieldMapping->payload(
                    $definition,
                    $request->request->all('mapping'),
                    array_map('strval', $request->request->all('lists')),
                    array_filter(array_map('strval', explode(',', (string) $request->request->get('previous_lists', '')))),
                    array_map('strval', $request->request->all('list_names')),
                );
                $statusiClient->saveOngageFieldMapping($integrationConfigId, $platformId, $payload['fields_mapping'], $payload['list_names'], $payload['deleted_list_ids']);
            } catch (StatusiApiException $e) {
                return $this->failure('mautic.statusi.integrations.save_error', $e);
            }

            return $this->savedAndBackToList('mautic.statusi.integrations.ongage.mapping_saved');
        }

        $view  = null;
        $error = null;
        try {
            $view = $fieldMapping->view(
                $statusiClient->getFieldMappingDefinition(),
                $statusiClient->getOngageLists($integrationConfigId),
                $this->existingMapping($statusiClient, $integrationConfigId),
                static fn (int $listId): array => $statusiClient->getOngageFields($integrationConfigId, $listId),
            );
        } catch (StatusiApiException $e) {
            $error = $e->getMessage();
        }

        return $this->page($request, '@MauticStatusi/Integrations/ongage_mapping.html.twig', 'mautic_statusi_integration_ongage_mapping', [
            'integrationConfigId' => $integrationConfigId,
            'platformId'          => $platformId,
            'mapping'             => $view,
            'error'               => $error,
        ], 'statusiIntegrationForm');
    }

    /**
     * Field titles of one Ongage list (JSON), when a list is ticked on the mapping page.
     */
    public function fieldsAction(Request $request, ConnectionManager $connectionManager, StatusiClient $statusiClient): JsonResponse
    {
        $integrationConfigId = $request->query->getInt('integration_config_id');
        $listId              = $request->query->getInt('list_id');
        if (!$connectionManager->isConnected() || $integrationConfigId <= 0 || $listId <= 0) {
            return new JsonResponse(['success' => false], Response::HTTP_BAD_REQUEST);
        }

        try {
            return new JsonResponse(['success' => true, 'titles' => $statusiClient->getOngageFields($integrationConfigId, $listId)]);
        } catch (StatusiApiException $e) {
            return $this->failure('mautic.statusi.integrations.load_error', $e);
        }
    }

    /**
     * The saved fields_mapping of the integration ({"field_mapping": {...}, "<list id>": {...}}), from Statusi's listing.
     *
     * @return array<string, mixed>
     *
     * @throws StatusiApiException
     */
    private function existingMapping(StatusiClient $statusiClient, int $integrationConfigId): array
    {
        foreach ($statusiClient->getIntegrations() as $row) {
            if ((int) ($row['integration_config_id'] ?? 0) !== $integrationConfigId) {
                continue;
            }
            $mapping = $row['fields_mapping'] ?? null;
            if (is_string($mapping)) {
                $mapping = json_decode($mapping, true);
            }

            return is_array($mapping) ? $mapping : [];
        }

        return [];
    }
}
