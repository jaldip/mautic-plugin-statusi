<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Tests\Unit;

use MauticPlugin\MauticStatusiBundle\ContactChanges\ContactChangeCriteriaFactory;
use MauticPlugin\MauticStatusiBundle\ContactChanges\ContactFieldCatalog;
use MauticPlugin\MauticStatusiBundle\DTO\ContactChanges\ContactChangeCriteria;
use MauticPlugin\MauticStatusiBundle\Exception\InvalidReportCriteriaException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ContactChangeCriteriaFactoryTest extends TestCase
{
    private function factory(): ContactChangeCriteriaFactory
    {
        $catalog = $this->createMock(ContactFieldCatalog::class);
        $catalog->method('aliases')->willReturn(['email', 'firstname', 'city', 'loyalty_tier']);

        return new ContactChangeCriteriaFactory($catalog);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function criteria(array $query): ContactChangeCriteria
    {
        return $this->factory()->fromRequest(Request::create('/', 'GET', $query));
    }

    public function testDefaults(): void
    {
        $c = $this->criteria(['dateFrom' => '2026-10-01', 'dateTo' => '2026-10-09']);

        self::assertSame(['2026-10-01 00:00:00', '2026-10-09 23:59:59'], [$c->dateFromSql(), $c->dateToSql()]);
        self::assertSame('all', $c->changeType);
        self::assertTrue($c->includesCreated());
        self::assertTrue($c->includesUpdated());
        self::assertSame([1, 100, 'DESC', 'lastChange'], [$c->page, $c->limit, $c->order, $c->sortBy]);
        self::assertNull($c->fields);
        self::assertFalse($c->includeHistory);
        self::assertSame(50, $c->historyLimit);
    }

    public function testChangeTypesAndFields(): void
    {
        $created = $this->criteria(['dateFrom' => '2026-10-01', 'dateTo' => '2026-10-09', 'changeType' => 'created']);
        self::assertTrue($created->includesCreated());
        self::assertFalse($created->includesUpdated());

        $updated = $this->criteria(['dateFrom' => '2026-10-01', 'dateTo' => '2026-10-09', 'changeType' => 'updated']);
        self::assertFalse($updated->includesCreated());
        self::assertTrue($updated->includesUpdated());

        self::assertSame(['email', 'city'], $this->criteria(['dateFrom' => '2026-10-01', 'dateTo' => '2026-10-09', 'fields' => 'email, city'])->fields);
        self::assertSame(['loyalty_tier'], $this->criteria(['dateFrom' => '2026-10-01', 'dateTo' => '2026-10-09', 'fields' => ['loyalty_tier']])->fields);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalid(): iterable
    {
        $range = ['dateFrom' => '2026-10-01', 'dateTo' => '2026-10-09'];
        yield 'missing dateFrom'    => [['dateTo' => '2026-10-09'], 'dateFrom'];
        yield 'bad date'            => [['dateFrom' => '2026-13-01', 'dateTo' => '2026-10-09'], 'dateFrom'];
        yield 'reversed'            => [['dateFrom' => '2026-10-09', 'dateTo' => '2026-10-01'], 'dateTo'];
        yield 'too long'            => [['dateFrom' => '2025-01-01', 'dateTo' => '2026-10-09'], 'dateTo'];
        yield 'bad changeType'      => [$range + ['changeType' => 'deleted'], 'changeType'];
        yield 'unknown field'       => [$range + ['fields' => 'email,password'], 'fields'];
        yield 'injection field'     => [$range + ['fields' => 'email) OR 1=1 --'], 'fields'];
        yield 'page 0'              => [$range + ['page' => '0'], 'page'];
        yield 'page text'           => [$range + ['page' => 'two'], 'page'];
        yield 'limit 0'             => [$range + ['limit' => '0'], 'limit'];
        yield 'limit too high'      => [$range + ['limit' => '501'], 'limit'];
        yield 'bad sortBy'          => [$range + ['sortBy' => 'email'], 'sortBy'];
        yield 'bad order'           => [$range + ['order' => 'random'], 'order'];
        yield 'bad contactId'       => [$range + ['contactId' => 'abc'], 'contactId'];
        yield 'bad includeHistory'  => [$range + ['includeHistory' => 'sure'], 'includeHistory'];
        yield 'historyLimit 0'      => [$range + ['historyLimit' => '0'], 'historyLimit'];
    }

    /**
     * @param array<string, mixed> $query
     */
    #[DataProvider('invalid')]
    public function testInvalidInput(array $query, string $field): void
    {
        try {
            $this->criteria($query);
            self::fail('Expected a validation error.');
        } catch (InvalidReportCriteriaException $e) {
            self::assertArrayHasKey($field, $e->getErrors());
        }
    }
}
