<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Tests\Unit\Handler\Transaction;

use JardisSupport\Contract\DbConnection\ConnectionPoolInterface;
use JardisSupport\Contract\DbConnection\SavepointInterface;
use JardisSupport\Repository\Adapter\PdoConnection;
use JardisSupport\Repository\Adapter\PdoConnectionPool;
use JardisSupport\Repository\Handler\Transaction\ResolveConnection;
use JardisSupport\Repository\Tests\Support\Db\Ledger;
use PHPUnit\Framework\TestCase;

/**
 * The writer connection comes from a pool, a plain PDO or nothing - without any kernel.
 */
final class ResolveConnectionTest extends TestCase
{
    public function testPoolGivesItsWriter(): void
    {
        $pool = new PdoConnectionPool(Ledger::sqlite());

        $this->assertSame($pool->getWriter(), (new ResolveConnection())($pool));
    }

    public function testPdoIsWrapped(): void
    {
        $pdo = Ledger::sqlite();

        $connection = (new ResolveConnection())($pdo);

        $this->assertInstanceOf(PdoConnection::class, $connection);
        $this->assertInstanceOf(SavepointInterface::class, $connection);
        $this->assertSame($pdo, $connection->pdo());
    }

    public function testNullGivesNull(): void
    {
        $this->assertNull((new ResolveConnection())(null));
    }

    public function testSignatureHasNoKernelDependency(): void
    {
        $type = (new \ReflectionMethod(ResolveConnection::class, '__invoke'))->getParameters()[0]->getType();

        $this->assertInstanceOf(\ReflectionUnionType::class, $type);
        $names = array_map(static fn(\ReflectionNamedType $t): string => $t->getName(), $type->getTypes());
        sort($names);
        $expected = [ConnectionPoolInterface::class, 'PDO', 'null'];
        sort($expected);
        $this->assertSame($expected, $names);
    }
}
