<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\MenuEvent;
use MauticPlugin\MauticStatusiBundle\Service\ConnectionManager;
use MauticPlugin\MauticStatusiBundle\Service\ReportCatalog;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Shows the "Statusi" sidebar section only while Mautic is connected to Statusi,
 * with one sub item per report of the connected Statusi account.
 */
final readonly class MenuSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ConnectionManager $connectionManager,
        private ReportCatalog $reportCatalog,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CoreEvents::BUILD_MENU => ['onBuildMenu', 0],
        ];
    }

    public function onBuildMenu(MenuEvent $event): void
    {
        if ('main' !== $event->getType()) {
            return;
        }

        try {
            if (!$this->connectionManager->isConnected()) {
                return;
            }
        } catch (\Throwable) {
            // Never break Mautic's navigation (e.g. before the plugin tables exist).
            return;
        }

        $children = [
            'mautic.statusi.menu.all_reports' => [
                'id'       => 'mautic_statusi_index',
                'route'    => 'mautic_statusi_index',
                'priority' => 1000,
            ],
        ];

        try {
            $reports = $this->reportCatalog->getReports();
        } catch (\Throwable) {
            // Statusi unreachable: keep the section with only "All reports" (it shows the error).
            $reports = [];
        }

        $priority = 999;
        foreach ($reports as $report) {
            // Keys must be unique; the visible text comes from "label" (report names can repeat).
            $children['mautic_statusi_report_'.$report['id']] = [
                'id'              => 'mautic_statusi_report_'.$report['id'],
                'label'           => $report['name'],
                'route'           => 'mautic_statusi_report',
                'routeParameters' => ['id' => $report['id']],
                'priority'        => $priority--,
            ];
        }

        $event->addMenuItems([
            'priority' => 35,
            'items'    => [
                'mautic.statusi.menu.index' => [
                    'id'        => 'mautic_statusi_root',
                    'iconClass' => 'ri-pulse-line',
                    'children'  => $children,
                ],
            ],
        ]);
    }
}
