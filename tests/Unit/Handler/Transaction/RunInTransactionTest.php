<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Tests\Unit\Handler\Transaction;

use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use JardisSupport\Contract\Kernel\DomainResponseInterface;
use JardisSupport\Repository\Exception\TransactionConfigurationException;
use JardisSupport\Repository\Handler\Transaction\RunInTransaction;
use JardisSupport\Repository\Tests\Support\Db\FakeSavepointConnection;
use JardisSupport\Repository\Tests\Support\Db\Ledger;
use JardisSupport\Repository\Tests\Support\Db\PlainConnection;
use JardisSupport\Repository\Tests\Support\Db\RecordingConnection;
use PHPUnit\Framework\TestCase;

/**
 * The transaction bracket over SQLite `:memory:` and fakes: signature, default predicate, savepoint names
 * and order, nesting, configuration errors and the edge cases of a transaction that is already gone.
 */
final class RunInTransactionTest extends TestCase
{
    private RecordingConnection $connection;
    private Ledger $ledger;
    private RunInTransaction $bracket;

    protected function setUp(): void
    {
        $pdo = Ledger::sqlite();
        $this->ledger = new Ledger($pdo);
        $this->connection = new RecordingConnection($pdo);
        $this->bracket = new RunInTransaction();
    }

    private function response(int $status): DomainResponseInterface
    {
        $response = $this->createStub(DomainResponseInterface::class);
        $response->method('getStatus')->willReturn($status);

        return $response;
    }

    public function testSignatureMatchesTheContract(): void
    {
        $method = new \ReflectionMethod(RunInTransaction::class, '__invoke');
        $parameters = $method->getParameters();

        $this->assertSame(
            ['connection', 'savepoint', 'work', 'failed', 'nested'],
            array_map(static fn(\ReflectionParameter $p): string => $p->getName(), $parameters)
        );
        $this->assertSame('?' . DbConnectionInterface::class, (string) $parameters[0]->getType());
        $this->assertSame('string', (string) $parameters[1]->getType());
        $this->assertSame(\Closure::class, (string) $parameters[2]->getType());
        $this->assertSame('?' . \Closure::class, (string) $parameters[3]->getType());
        $this->assertNull($parameters[3]->getDefaultValue());
        $this->assertSame('bool', (string) $parameters[4]->getType());
        $this->assertFalse($parameters[4]->getDefaultValue());
        $this->assertSame('mixed', (string) $method->getReturnType());
    }

    public function testDefaultPredicateRollsBackA4xxResponse(): void
    {
        $failed = $this->response(422);
        $answer = ($this->bracket)($this->connection, 'sp', function () use ($failed) {
            $this->ledger->add('lost');

            return $failed;
        });

        $this->assertSame($failed, $answer);
        $this->assertSame(['beginTransaction', 'rollback'], $this->connection->calls());
        $this->assertSame([], $this->ledger->notes());

        $ok = $this->response(200);
        $answer = ($this->bracket)($this->connection, 'sp', function () use ($ok) {
            $this->ledger->add('kept');

            return $ok;
        });

        $this->assertSame($ok, $answer);
        $this->assertSame(['kept'], $this->ledger->notes(), 'a 2xx answer commits');
    }

    public function testSavepointNameIsTheOneTheCallerGives(): void
    {
        $failing = new FakeSavepointConnection();
        ($this->bracket)($failing, 'jardis_persist_order', fn() => 'x', fn(): bool => true);

        $this->assertSame(
            ['savepoint:jardis_persist_order', 'rollbackTo:jardis_persist_order', 'release:jardis_persist_order'],
            $failing->calls(),
            'a failure rolls back to the savepoint and only then releases it'
        );

        $succeeding = new FakeSavepointConnection();
        ($this->bracket)($succeeding, 'sp_2', fn() => 'x');

        $this->assertSame(['savepoint:sp_2', 'release:sp_2'], $succeeding->calls());
    }

    public function testNestedWithoutOpenTransactionRunsWithoutOwnTransaction(): void
    {
        $answer = ($this->bracket)($this->connection, 'sp_1', function () {
            $this->ledger->add('inner');

            return 'done';
        }, null, true);

        $this->assertSame('done', $answer);
        $this->assertSame([], $this->connection->calls());
        $this->assertSame(['inner'], $this->ledger->notes());
    }

    public function testMissingConnectionIsAConfigurationError(): void
    {
        $called = false;

        try {
            ($this->bracket)(null, 'sp', function () use (&$called) {
                $called = true;
            });
            $this->fail('a TransactionConfigurationException must be thrown');
        } catch (TransactionConfigurationException $e) {
            $this->assertInstanceOf(\RuntimeException::class, $e);
        }

        $this->assertFalse($called, 'the work must not run without a connection');
    }

    public function testNoSavepointSupportIsAConfigurationError(): void
    {
        $pdo = Ledger::sqlite();
        $connection = new PlainConnection($pdo);
        $connection->beginTransaction();
        $called = false;

        try {
            ($this->bracket)($connection, 'sp', function () use (&$called) {
                $called = true;
            });
            $this->fail('a TransactionConfigurationException must be thrown');
        } catch (TransactionConfigurationException) {
            $this->assertFalse($called);
            $this->assertTrue($connection->inTransaction(), 'the holder of the transaction decides');
        } finally {
            $connection->rollback();
        }
    }

    public function testSubProcessesWorkInSavepointsNamedByDepthAndNeverBeginATransaction(): void
    {
        $answer = ($this->bracket)($this->connection, 'sp_0', function () {
            return ($this->bracket)($this->connection, 'sp_1', function () {
                return ($this->bracket)($this->connection, 'sp_2', function () {
                    $this->ledger->add('deep');

                    return 'deep';
                }, null, true);
            }, null, true);
        });

        $this->assertSame('deep', $answer);
        $this->assertSame(
            ['beginTransaction', 'savepoint:sp_1', 'savepoint:sp_2', 'release:sp_2', 'release:sp_1', 'commit'],
            $this->connection->calls(),
            'the one transaction is the outermost one; the nested units work in savepoints named by the caller'
        );
        $this->assertSame(['deep'], $this->ledger->notes());
    }

    public function testThrowAfterTheTransactionWasEndedKeepsTheOriginalFailureAsCause(): void
    {
        try {
            ($this->bracket)($this->connection, 'sp', function () {
                $this->ledger->add('a');
                $this->connection->pdo()->commit();

                throw new \DomainException('original');
            });
            $this->fail('the exception must be thrown on');
        } catch (\DomainException $e) {
            $this->assertSame('original', $e->getMessage());
        }

        $this->assertSame(['beginTransaction'], $this->connection->calls(), 'no rollback without an open transaction');
    }

    public function testBracketThrowsTheOriginalWhenNoTransactionIsOpenAnymore(): void
    {
        $original = new \DomainException('boom');

        try {
            ($this->bracket)($this->connection, 'sp', function () use ($original) {
                $this->connection->pdo()->commit();

                throw $original;
            });
            $this->fail('the exception must be thrown on');
        } catch (\DomainException $e) {
            $this->assertSame($original, $e);
        }
    }

    public function testRejectAfterTheTransactionWasEndedAnswersTheRejectWithoutRollback(): void
    {
        $answer = ($this->bracket)($this->connection, 'sp', function () {
            $this->connection->pdo()->commit();

            return 'rejected';
        }, fn(mixed $result): bool => $result === 'rejected');

        $this->assertSame('rejected', $answer);
        $this->assertSame(['beginTransaction'], $this->connection->calls());
    }
}
