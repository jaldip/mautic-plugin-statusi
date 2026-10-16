<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Tests\Unit;

use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use MauticPlugin\MauticStatusiBundle\EmailActivity\ReportAccessResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportAccessResolverTest extends TestCase
{
    /**
     * @return iterable<string, array{string[], ?int, ?array{leadOwnerId: ?int, emailCreatedBy: ?int}}>
     */
    public static function permissions(): iterable
    {
        yield 'admin-like: everything' => [
            ['lead:leads:viewother', 'lead:leads:viewown', 'email:emails:viewother', 'email:emails:viewown'], 9,
            ['leadOwnerId' => null, 'emailCreatedBy' => null],
        ];
        yield 'own contacts, all emails' => [
            ['lead:leads:viewown', 'email:emails:viewother'], 9,
            ['leadOwnerId' => 9, 'emailCreatedBy' => null],
        ];
        yield 'all contacts, own emails' => [
            ['lead:leads:viewother', 'email:emails:viewown'], 9,
            ['leadOwnerId' => null, 'emailCreatedBy' => 9],
        ];
        yield 'no contact permission'   => [['email:emails:viewother'], 9, null];
        yield 'no email permission'     => [['lead:leads:viewother'], 9, null];
        yield 'nothing'                 => [[], 9, null];
        yield 'own permission, no user' => [['lead:leads:viewown', 'email:emails:viewother'], null, null];
    }

    /**
     * @param string[]                                            $granted
     * @param array{leadOwnerId: ?int, emailCreatedBy: ?int}|null $expected
     */
    #[DataProvider('permissions')]
    public function testPermissionsMapToRestrictions(array $granted, ?int $userId, ?array $expected): void
    {
        $security = $this->createMock(CorePermissions::class);
        $security->method('isGranted')->willReturnCallback(
            static fn (string $permission): bool => in_array($permission, $granted, true)
        );

        self::assertSame($expected, (new ReportAccessResolver($security))->resolve($userId));
    }

    /**
     * @return iterable<string, array{string[], ?int, ?array{leadOwnerId: ?int}}>
     */
    public static function contactPermissions(): iterable
    {
        yield 'view others'          => [['lead:leads:viewother', 'lead:leads:viewown'], 9, ['leadOwnerId' => null]];
        yield 'view own only'        => [['lead:leads:viewown'], 9, ['leadOwnerId' => 9]];
        yield 'own without user'     => [['lead:leads:viewown'], null, null];
        yield 'email rights only'    => [['email:emails:viewother'], 9, null];
        yield 'nothing'              => [[], 9, null];
    }

    /**
     * @param string[]                      $granted
     * @param array{leadOwnerId: ?int}|null $expected
     */
    #[DataProvider('contactPermissions')]
    public function testContactPermissions(array $granted, ?int $userId, ?array $expected): void
    {
        $security = $this->createMock(CorePermissions::class);
        $security->method('isGranted')->willReturnCallback(
            static fn (string $permission): bool => in_array($permission, $granted, true)
        );

        self::assertSame($expected, (new ReportAccessResolver($security))->resolveContacts($userId));
    }
}
