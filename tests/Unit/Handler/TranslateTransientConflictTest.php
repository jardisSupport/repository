<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Tests\Unit\Handler;

use JardisSupport\Repository\Handler\TranslateTransientConflict;
use PDOException;
use PHPUnit\Framework\TestCase;

final class TranslateTransientConflictTest extends TestCase
{
    public function testTransientConflictCarriesDriverCodePreviousAndTable(): void
    {
        $pdoException = new PDOException('Deadlock found');
        $pdoException->errorInfo = ['40001', 1213, 'Deadlock found'];

        $result = (new TranslateTransientConflict())($pdoException, 'mysql', 'orders');

        $this->assertNotNull($result);
        $this->assertSame('1213', $result->getDriverCode());
        $this->assertSame($pdoException, $result->getPrevious());
        $this->assertStringContainsString('orders', $result->getMessage());
        $this->assertStringContainsString('Deadlock found', $result->getMessage());
    }

    public function testOtherErrorsYieldNull(): void
    {
        $pdoException = new PDOException('dup');
        $pdoException->errorInfo = ['23000', 1062, 'Duplicate entry'];

        $this->assertNull((new TranslateTransientConflict())($pdoException, 'mysql', 'orders'));
    }
}
