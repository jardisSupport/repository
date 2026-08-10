<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Tests\Unit\Handler\Query;

use JardisSupport\DbQuery\DbUpdate;
use JardisSupport\Repository\Handler\Query\ApplyExpectedConditions;
use PHPUnit\Framework\TestCase;

final class ApplyExpectedConditionsTest extends TestCase
{
    private ApplyExpectedConditions $applyExpectedConditions;

    protected function setUp(): void
    {
        $this->applyExpectedConditions = new ApplyExpectedConditions();
    }

    // ── U1: leeres $expected beruehrt die Query-Baukette strukturell nicht ──

    public function testEmptyExpectedReturnsSameQueryInstance(): void
    {
        $query = (new DbUpdate())->table('items')->setMultiple(['name' => 'X'])->where('id')->equals(1);

        $result = ($this->applyExpectedConditions)($query, []);

        $this->assertSame($query, $result);
    }

    public function testEmptyExpectedProducesByteIdenticalSql(): void
    {
        $baseline = (new DbUpdate())
            ->table('items')->setMultiple(['name' => 'X'])->where('id')->equals(1)
            ->sql('sqlite', prepared: true);

        $query = (new DbUpdate())->table('items')->setMultiple(['name' => 'X'])->where('id')->equals(1);
        $withEmptyExpected = ($this->applyExpectedConditions)($query, [])
            ->sql('sqlite', prepared: true);

        $this->assertSame($baseline->sql(), $withEmptyExpected->sql());
        $this->assertSame($baseline->bindings(), $withEmptyExpected->bindings());
    }

    // ── Bedingungskette ──

    public function testScalarValueAppendsEqualsCondition(): void
    {
        $query = (new DbUpdate())->table('items')->setMultiple(['name' => 'X'])->where('id')->equals(1);

        $prepared = ($this->applyExpectedConditions)($query, ['status' => 'active'])
            ->sql('sqlite', prepared: true);

        $this->assertStringContainsString('AND status = ?', $prepared->sql());
        $this->assertSame(['X', 1, 'active'], $prepared->bindings());
    }

    public function testNullValueAppendsIsNullCondition(): void
    {
        $query = (new DbUpdate())->table('items')->setMultiple(['name' => 'X'])->where('id')->equals(1);

        $prepared = ($this->applyExpectedConditions)($query, ['email' => null])
            ->sql('sqlite', prepared: true);

        $this->assertStringContainsString('AND email IS NULL', $prepared->sql());
        $this->assertSame(['X', 1], $prepared->bindings());
    }

    // ── U5: Mehrfeld-Erwartung ──

    public function testMultipleExpectedConditionsAreAllChained(): void
    {
        $query = (new DbUpdate())->table('items')->setMultiple(['name' => 'X'])->where('id')->equals(1);

        $prepared = ($this->applyExpectedConditions)($query, ['status' => 'active', 'email' => null])
            ->sql('sqlite', prepared: true);

        $this->assertStringContainsString('AND status = ?', $prepared->sql());
        $this->assertStringContainsString('AND email IS NULL', $prepared->sql());
        $this->assertSame(['X', 1, 'active'], $prepared->bindings());
    }

    // ── U10: Vertragsgrenze — nur scalar|null ──

    public function testNonScalarObjectValueThrowsInvalidArgumentException(): void
    {
        $query = (new DbUpdate())->table('items')->setMultiple(['name' => 'X'])->where('id')->equals(1);

        $this->expectException(\InvalidArgumentException::class);

        ($this->applyExpectedConditions)($query, ['createdAt' => new \DateTime()]);
    }

    public function testArrayValueThrowsInvalidArgumentException(): void
    {
        $query = (new DbUpdate())->table('items')->setMultiple(['name' => 'X'])->where('id')->equals(1);

        $this->expectException(\InvalidArgumentException::class);

        ($this->applyExpectedConditions)($query, ['tags' => ['a', 'b']]);
    }
}
