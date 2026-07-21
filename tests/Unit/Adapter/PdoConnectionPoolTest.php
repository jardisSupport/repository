<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Tests\Unit\Adapter;

use JardisSupport\Repository\Adapter\PdoConnectionPool;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoConnectionPoolTest extends TestCase
{
    private PdoConnectionPool $pool;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $this->pool = new PdoConnectionPool($pdo);
    }

    public function testGetWriterReturnsConnection(): void
    {
        $writer = $this->pool->getWriter();

        $this->assertSame('sqlite', $writer->getDriverName());
    }

    public function testGetReaderReturnsSameConnectionAsWriter(): void
    {
        $this->assertSame($this->pool->getWriter(), $this->pool->getReader());
    }

    public function testGetReadersReturnsSingleElementArray(): void
    {
        $readers = $this->pool->getReaders();

        $this->assertCount(1, $readers);
        $this->assertSame($this->pool->getWriter(), $readers[0]);
    }

    public function testGetReaderCountReturnsOne(): void
    {
        $this->assertSame(1, $this->pool->getReaderCount());
    }

    public function testGetStatsReturnsDefaultStats(): void
    {
        $expected = ['reads' => 0, 'writes' => 0, 'failovers' => 0, 'readers' => 1];

        $this->assertSame($expected, $this->pool->getStats());
    }

    public function testResetStatsIsNoOp(): void
    {
        $this->pool->resetStats();

        $expected = ['reads' => 0, 'writes' => 0, 'failovers' => 0, 'readers' => 1];
        $this->assertSame($expected, $this->pool->getStats());
    }
}
