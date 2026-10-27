<?php

declare(strict_types=1);

return [
    'name'        => 'Statusi',
    'description' => 'Connect Mautic to Statusi to bring Statusi reports into Mautic.',
    'version'     => '1.2.0',
    'author'      => 'Statusi',

    'routes' => [
        'main' => [
            'mautic_statusi_index' => [
                'path'       => '/statusi',
                'controller' => 'MauticPlugin\MauticStatusiBundle\Controller\StatusiController::indexAction',
            ],
            'mautic_statusi_report' => [
                'path'         => '/statusi/report/{id}',
                'controller'   => 'MauticPlugin\MauticStatusiBundle\Controller\StatusiController::reportAction',
                'requirements' => ['id' => '\d+'],
            ],
            'mautic_statusi_report_data' => [
                'path'         => '/statusi/report/{id}/data',
                'controller'   => 'MauticPlugin\MauticStatusiBundle\Controller\StatusiController::reportDataAction',
                'method'       => 'GET',
                'requirements' => ['id' => '\d+'],
            ],
            // Statusi > Integrations (the connected Statusi account's integrations)
            'mautic_statusi_integrations' => [
                'path'       => '/statusi/integrations',
                'controller' => 'MauticPlugin\MauticStatusiBundle\Controller\IntegrationController::indexAction',
                'method'     => 'GET',
            ],
            'mautic_statusi_integration_new' => [
                'path'       => '/statusi/integrations/new',
                'controller' => 'MauticPlugin\MauticStatusiBundle\Controller\IntegrationController::newAction',
                'method'     => 'GET',
            ],
            'mautic_statusi_integration_status' => [
                'path'         => '/statusi/integrations/{id}/status',
                'controller'   => 'MauticPlugin\MauticStatusiBundle\Controller\IntegrationController::statusAction',
                'method'       => 'POST',
                'requirements' => ['id' => '\d+'],
            ],
            // Google Ads / AdSense / Analytics, Meta Ads, Mailchimp: provider sign-in started from Mautic
            'mautic_statusi_integration_connect' => [
                'path'         => '/statusi/integrations/connect/{kind}',
                'controller'   => 'MauticPlugin\MauticStatusiBundle\Controller\OAuthIntegrationController::connectAction',
                'method'       => 'GET',
                'requirements' => ['kind' => '[a-z_]+'],
            ],
            // Statusi sends the browser back here after the sign-in (path is fixed in statusi_backend models/mautic_oauth.py)
            'mautic_statusi_integration_oauth_return' => [
                'path'         => '/statusi/integrations/oauth/{key}',
                'controller'   => 'MauticPlugin\MauticStatusiBundle\Controller\OAuthIntegrationController::returnAction',
                'method'       => 'GET',
                'requirements' => ['key' => '[a-z-]+'],
            ],
            'mautic_statusi_integration_accounts' => [
                'path'         => '/statusi/integrations/accounts/{kind}',
                'controller'   => 'MauticPlugin\MauticStatusiBundle\Controller\OAuthIntegrationController::accountsAction',
                'requirements' => ['kind' => '[a-z_]+'],
            ],
            'mautic_statusi_integration_ongage' => [
                'path'       => '/statusi/integrations/ongage',
                'controller' => 'MauticPlugin\MauticStatusiBundle\Controller\OngageIntegrationController::createAction',
            ],
            'mautic_statusi_integration_ongage_mapping' => [
                'path'       => '/statusi/integrations/ongage/mapping',
                'controller' => 'MauticPlugin\MauticStatusiBundle\Controller\OngageIntegrationController::mappingAction',
            ],
            'mautic_statusi_integration_ongage_fields' => [
                'path'       => '/statusi/integrations/ongage/fields',
                'controller' => 'MauticPlugin\MauticStatusiBundle\Controller\OngageIntegrationController::fieldsAction',
                'method'     => 'GET',
            ],
            'mautic_statusi_integration_clickgo' => [
                'path'       => '/statusi/integrations/clickgo',
                'controller' => 'MauticPlugin\MauticStatusiBundle\Controller\IntegrationController::clickgoAction',
            ],
            'mautic_statusi_integration_chatgpt' => [
                'path'       => '/statusi/integrations/chatgpt',
                'controller' => 'MauticPlugin\MauticStatusiBundle\Controller\IntegrationController::chatgptAction',
            ],
            'mautic_statusi_connect' => [
                'path'       => '/statusi/connect',
                'controller' => 'MauticPlugin\MauticStatusiBundle\Controller\ConnectController::connectAction',
                'method'     => 'GET',
            ],
            'mautic_statusi_callback' => [
                'path'       => '/statusi/callback',
                'controller' => 'MauticPlugin\MauticStatusiBundle\Controller\ConnectController::callbackAction',
                'method'     => 'GET',
            ],
            'mautic_statusi_disconnect' => [
                'path'       => '/statusi/disconnect',
                'controller' => 'MauticPlugin\MauticStatusiBundle\Controller\ConnectController::disconnectAction',
                'method'     => 'POST',
            ],
        ],
        // Mautic prefixes these with /api and applies its API authentication (OAuth2 / Basic).
        'api' => [
            'mautic_statusi_api_email_activity_report' => [
                'path'       => '/custom/email-activity-report',
                'controller' => 'MauticPlugin\MauticStatusiBundle\Controller\Api\EmailActivityReportApiController::reportAction',
                'method'     => 'GET',
            ],
            'mautic_statusi_api_contact_attributes' => [
                'path'       => '/custom/contact-attributes',
                'controller' => 'MauticPlugin\MauticStatusiBundle\Controller\Api\ContactAttributesApiController::listAction',
                'method'     => 'GET',
            ],
            // Flat, cursor-paged exports for Statusi's daily Mautic DAGs
            'mautic_statusi_api_email_activity_export' => [
                'path'       => '/custom/email-activity-export',
                'controller' => 'MauticPlugin\MauticStatusiBundle\Controller\Api\StatusiExportApiController::activityAction',
                'method'     => 'GET',
            ],
            'mautic_statusi_api_contact_attribute_export' => [
                'path'       => '/custom/contact-attribute-export',
                'controller' => 'MauticPlugin\MauticStatusiBundle\Controller\Api\StatusiExportApiController::contactsAction',
                'method'     => 'GET',
            ],
        ],
    ],

    // The "Statusi" sidebar item is added by EventListener\MenuSubscriber, only while connected.

    'parameters' => [
        // Statusi web app that hosts the login + account selection popup
        'statusi_app_url' => 'https://app.statusi.io',
        // Statusi API used for the token exchange and reports
        'statusi_api_url' => 'https://api.statusi.io',
    ],
];
