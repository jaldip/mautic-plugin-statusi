<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The plugin adds new API routes and must leave Mautic's own contact endpoints untouched.
 */
final class RouteRegressionTest extends TestCase
{
    public function testPluginRoutesAreSeparateFromCoreRoutes(): void
    {
        $root   = dirname(__DIR__, 4);
        $plugin = include dirname(__DIR__, 2).'/Config/config.php';

        $api = $plugin['routes']['api'];
        self::assertSame(
            ['/custom/email-activity-report', '/custom/contact-attributes', '/custom/email-activity-export', '/custom/contact-attribute-export'],
            array_column($api, 'path')
        );
        foreach ($api as $route) {
            self::assertSame('GET', $route['method']);
            self::assertStringStartsWith('MauticPlugin\\MauticStatusiBundle\\', $route['controller']);
        }

        // Core contact routes still point at Mautic's controllers (read from the core config file).
        $core = (string) preg_replace('/\s+/', ' ', (string) file_get_contents($root.'/app/bundles/LeadBundle/Config/config.php'));
        foreach ([
            "'path' => '/contacts/activity', 'controller' => 'Mautic\\LeadBundle\\Controller\\Api\\LeadApiController::getAllActivityAction'",
            "'name' => 'contacts', 'path' => '/contacts', 'controller' => Mautic\\LeadBundle\\Controller\\Api\\LeadApiController::class,",
        ] as $expected) {
            self::assertStringContainsString($expected, $core);
        }

        foreach ($plugin['routes'] as $routes) {
            foreach ($routes as $route) {
                self::assertDoesNotMatchRegularExpression('~^/contacts(/|$)~', $route['path'], 'plugin must not shadow core contact routes');
            }
        }
    }
}
