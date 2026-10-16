<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Tests\Unit;

use MauticPlugin\MauticStatusiBundle\EmailActivity\ValueParser;
use PHPUnit\Framework\TestCase;

final class ValueParserTest extends TestCase
{
    public function testReadsSerializedAndJsonArrays(): void
    {
        self::assertSame(['city' => 'Pune'], ValueParser::unserializeArray(serialize(['city' => 'Pune'])));
        self::assertSame(['name' => 'Chrome'], ValueParser::unserializeArray('{"name":"Chrome"}'));
        self::assertSame(['a'], ValueParser::unserializeArray(['a']));
    }

    public function testRejectsAnythingElse(): void
    {
        self::assertSame([], ValueParser::unserializeArray(null));
        self::assertSame([], ValueParser::unserializeArray(''));
        self::assertSame([], ValueParser::unserializeArray('not serialized'));
        self::assertSame([], ValueParser::unserializeArray('a:1:{broken'));
        self::assertSame([], ValueParser::unserializeArray(serialize('just a string')));
    }

    public function testNeverInstantiatesObjects(): void
    {
        $payload = 'a:1:{s:1:"x";'.serialize(new \ArrayObject([1])).'}';
        $result  = ValueParser::unserializeArray($payload);

        self::assertInstanceOf(\__PHP_Incomplete_Class::class, $result['x']);
    }
}
