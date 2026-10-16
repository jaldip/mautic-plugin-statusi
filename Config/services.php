<?php

declare(strict_types=1);

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->public();

    $services->load('MauticPlugin\\MauticStatusiBundle\\', '../')
        ->exclude('../{'.implode(',', MauticCoreExtension::DEFAULT_EXCLUDES).'}');

    // Basic definitions with name, display name and icon
    $services->alias('mautic.integration.statusi', MauticPlugin\MauticStatusiBundle\Integration\StatusiIntegration::class);
    // Provides the template used for the configuration UI (connect / disconnect)
    $services->alias('mautic.integration.statusi.configuration', MauticPlugin\MauticStatusiBundle\Integration\Support\ConfigSupport::class);
};
