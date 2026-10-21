<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Tests\Unit\Handler\Transaction;

use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use JardisSupport\Contract\Kernel\DomainResponseInterface;
use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Contract\Repository\Exception\TransientConflictException;
use JardisSupport\Repository\Exception\TransactionConfigurationException;
use JardisSupport\Repository\Exception\TransactionLostException;
use JardisSupport\Repository\Handler\Transaction\RunInTransaction;
use JardisSupport\Repository\Tests\Support\Db\FakeOutcome;
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

    /**
     * The savepoint name the bracket derived from the caller's name: `<caller name>_<object id>`.
     *
     * @param list<string> $calls
     */
    private function derivedName(array $calls, string $callerName, int $position = 0): string
    {
        $names = [];
        foreach ($calls as $call) {
            if (str_starts_with($call, 'savepoint:')) {
                $names[] = substr($call, strlen('savepoint:'));
            }
        }
        $this->assertArrayHasKey($position, $names);
        $this->assertMatchesRegularExpression('/^' . preg_quote($callerName, '/') . '_\d+$/', $names[$position]);

        return $names[$position];
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

    public function testDefaultPredicateAlsoRecognisesAFailedContractsOutcome(): void
    {
        $failed = new FakeOutcome(ResponseStatus::Conflict);
        $answer = ($this->bracket)($this->connection, 'sp', function () use ($failed) {
            $this->ledger->add('lost');

            return $failed;
        });

        $this->assertSame($failed, $answer);
        $this->assertSame(['beginTransaction', 'rollback'], $this->connection->calls());
        $this->assertSame([], $this->ledger->notes());

        $ok = new FakeOutcome(ResponseStatus::Created);
        $answer = ($this->bracket)($this->connection, 'sp', function () use ($ok) {
            $this->ledger->add('kept');

            return $ok;
        });

        $this->assertSame($ok, $answer);
        $this->assertSame(['kept'], $this->ledger->notes(), 'an outcome that did not fail commits');
    }

    public function testSavepointNameIsDerivedFromTheOneTheCallerGives(): void
    {
        $failing = new FakeSavepointConnection();
        ($this->bracket)($failing, 'jardis_persist_order', fn() => 'x', fn(): bool => true);

        $name = $this->derivedName($failing->calls(), 'jardis_persist_order');
        $this->assertSame(
            ['savepoint:' . $name, 'rollbackTo:' . $name, 'release:' . $name],
            $failing->calls(),
            'a failure rolls back to the savepoint and only then releases it'
        );

        $succeeding = new FakeSavepointConnection();
        ($this->bracket)($succeeding, 'sp_2', fn() => 'x');

        $name = $this->derivedName($succeeding->calls(), 'sp_2');
        $this->assertSame(['savepoint:' . $name, 'release:' . $name], $succeeding->calls());
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
        $calls = $this->connection->calls();
        $one = $this->derivedName($calls, 'sp_1', 0);
        $two = $this->derivedName($calls, 'sp_2', 1);
        $this->assertNotSame($one, $two);
        $this->assertSame(
            ['beginTransaction', 'savepoint:' . $one, 'savepoint:' . $two, 'release:' . $two, 'release:' . $one, 'commit'],
            $calls,
            'the one transaction is the outermost one; the nested units work in savepoints derived from the caller names'
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

    public function testFailingReleaseAfterASuccessfulRollbackKeepsTheCause(): void
    {
        // The release failing "verdraengt nichts": the rollback worked, the cause is thrown on unchanged.
        $this->connection->pdo()->beginTransaction();
        $this->connection->failOn('release', new \RuntimeException('release failed'));
        $original = new \DomainException('original');

        try {
            ($this->bracket)($this->connection, 'sp', function () use ($original) {
                throw $original;
            });
            $this->fail('the original must be thrown on');
        } catch (\DomainException $e) {
            $this->assertSame($original, $e);
        }

        $name = $this->derivedName($this->connection->calls(), 'sp');
        $this->assertSame(['savepoint:' . $name, 'rollbackTo:' . $name, 'release:' . $name], $this->connection->calls());
        $this->connection->pdo()->rollBack();
    }

    public function testFailingRollbackToTheSavepointOnARejectWithoutThrowPropagatesAsItIs(): void
    {
        // E2 decision: the reject path has no cause to keep - the failure of the rollback propagates raw.
        $this->connection->pdo()->beginTransaction();
        $rollbackFailure = new \RuntimeException('rollback to savepoint failed');
        $this->connection->failOn('rollbackTo', $rollbackFailure);

        try {
            ($this->bracket)($this->connection, 'sp', fn() => 'rejected', fn(mixed $r): bool => $r === 'rejected');
            $this->fail('the rollback failure must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame($rollbackFailure, $e);
        }

        $this->connection->pdo()->rollBack();
    }

    public function testTransactionLostCarriesTheCauseTheRollbackFailureAndTheDriverCode(): void
    {
        $cause = new TransientConflictException('conflict', '40001');
        $rollbackFailure = new \RuntimeException('rollback failed');

        $lost = new TransactionLostException($cause, $rollbackFailure);

        $this->assertInstanceOf(TransientConflictException::class, $lost);
        $this->assertSame($cause, $lost->getPrevious());
        $this->assertSame($rollbackFailure, $lost->getRollbackFailure());
        $this->assertSame('40001', $lost->getDriverCode());

        $other = new TransactionLostException(new \DomainException('x'), $rollbackFailure);
        $this->assertNull($other->getDriverCode(), 'only a transient cause hands its driver code on');
    }
}
