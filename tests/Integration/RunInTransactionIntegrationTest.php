<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Tests\Integration;

use JardisSupport\Contract\Repository\Exception\TransientConflictException;
use JardisSupport\Repository\Adapter\PdoConnectionPool;
use JardisSupport\Repository\Exception\TransactionLostException;
use JardisSupport\Repository\Handler\Transaction\RunInTransaction;
use JardisSupport\Repository\Repository;
use JardisSupport\Repository\Tests\Support\Db\Ledger;
use JardisSupport\Repository\Tests\Support\Db\RecordingConnection;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The transaction bracket against real databases: SQLite `:memory:`, MySQL, MariaDB and PostgreSQL (services from
 * support/docker-compose.yml). Every test runs on every dialect; the transient conflict on those that have
 * snapshot conflicts (MariaDB with innodb_snapshot_isolation, PostgreSQL with REPEATABLE READ).
 */
final class RunInTransactionIntegrationTest extends TestCase
{
    private ?PDO $pdo = null;
    private ?PDO $peer = null;
    private ?Ledger $ledger = null;
    private RecordingConnection $connection;
    private RunInTransaction $bracket;

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->ledger?->drop();
        }
        $this->pdo = null;
        $this->peer = null;
        $this->ledger = null;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function dialects(): array
    {
        return ['sqlite' => ['sqlite'], 'mysql' => ['mysql'], 'mariadb' => ['mariadb'], 'postgres' => ['postgres']];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function snapshotDialects(): array
    {
        return ['mariadb' => ['mariadb'], 'postgres' => ['postgres']];
    }

    private function open(string $dialect): void
    {
        $this->pdo = $this->pdoFor($dialect);
        $this->ledger = new Ledger($this->pdo);
        $this->connection = new RecordingConnection($this->pdo);
        $this->bracket = new RunInTransaction();
    }

    private function pdoFor(string $dialect): PDO
    {
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];

        return match ($dialect) {
            'mysql' => new PDO(
                sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    $_ENV['MYSQL_HOST'] ?? 'mysql',
                    (int) ($_ENV['MYSQL_PORT'] ?? 3306),
                    $_ENV['MYSQL_DATABASE'] ?? 'test_db'
                ),
                $_ENV['MYSQL_USER'] ?? 'test_user',
                $_ENV['MYSQL_PASSWORD'] ?? 'test_password',
                $options
            ),
            'mariadb' => new PDO(
                sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    $_ENV['MARIADB_HOST'] ?? 'mariadb',
                    (int) ($_ENV['MARIADB_PORT'] ?? 3306),
                    $_ENV['MARIADB_DATABASE'] ?? 'test_db'
                ),
                $_ENV['MARIADB_USER'] ?? 'test_user',
                $_ENV['MARIADB_PASSWORD'] ?? 'test_password',
                $options
            ),
            'postgres' => new PDO(
                sprintf(
                    'pgsql:host=%s;port=%d;dbname=%s',
                    $_ENV['POSTGRES_HOST'] ?? 'postgres',
                    (int) ($_ENV['POSTGRES_PORT'] ?? 5432),
                    $_ENV['POSTGRES_DATABASE'] ?? 'test_db'
                ),
                $_ENV['POSTGRES_USER'] ?? 'test_user',
                $_ENV['POSTGRES_PASSWORD'] ?? 'test_password',
                $options
            ),
            default => Ledger::sqlite(),
        };
    }

    private function ledger(): Ledger
    {
        return $this->ledger ?? throw new \LogicException('open() first');
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? throw new \LogicException('open() first');
    }

    #[DataProvider('dialects')]
    public function testOwnsItsTransactionAndCommitsOnSuccess(string $dialect): void
    {
        $this->open($dialect);

        $answer = ($this->bracket)($this->connection, 'sp', function () {
            $this->ledger()->add('a');
            $this->ledger()->add('b');

            return 'ok';
        });

        $this->assertSame('ok', $answer);
        $this->assertSame(['beginTransaction', 'commit'], $this->connection->calls());
        $this->assertSame(['a', 'b'], $this->ledger()->notes());
        $this->assertFalse($this->connection->inTransaction());
    }

    #[DataProvider('dialects')]
    public function testWorksInASavepointWhenATransactionIsOpenAndLeavesItOpen(string $dialect): void
    {
        $this->open($dialect);
        $this->pdo()->beginTransaction();
        $this->ledger()->add('outer');

        ($this->bracket)($this->connection, 'jardis_persist_order', function () {
            $this->ledger()->add('inner');
        });

        $name = $this->derivedName('jardis_persist_order');
        $this->assertSame(['savepoint:' . $name, 'release:' . $name], $this->connection->calls());
        $this->assertTrue($this->connection->inTransaction(), 'the holder of the transaction decides');
        $this->pdo()->rollBack();
        $this->assertSame([], $this->ledger()->notes(), 'the work belonged to the surrounding transaction');
    }

    #[DataProvider('dialects')]
    public function testRollsBackOnAFailedResult(string $dialect): void
    {
        $this->open($dialect);

        $answer = ($this->bracket)($this->connection, 'sp', function () {
            $this->ledger()->add('lost');

            return 'rejected';
        }, fn(mixed $result): bool => $result === 'rejected');

        $this->assertSame('rejected', $answer);
        $this->assertSame(['beginTransaction', 'rollback'], $this->connection->calls());
        $this->assertSame([], $this->ledger()->notes());
        $this->assertFalse($this->connection->inTransaction());
    }

    #[DataProvider('dialects')]
    public function testRejectInASavepointRollsBackOnlyItself(string $dialect): void
    {
        $this->open($dialect);
        $this->pdo()->beginTransaction();
        $this->ledger()->add('outer');

        $answer = ($this->bracket)($this->connection, 'sp_1', function () {
            $this->ledger()->add('inner');

            return 'rejected';
        }, fn(mixed $result): bool => $result === 'rejected');

        $this->assertSame('rejected', $answer);
        $name = $this->derivedName('sp_1');
        $this->assertSame(
            ['savepoint:' . $name, 'rollbackTo:' . $name, 'release:' . $name],
            $this->connection->calls()
        );
        $this->assertTrue($this->connection->inTransaction());
        $this->pdo()->commit();
        $this->assertSame(['outer'], $this->ledger()->notes());
    }

    /**
     * @return list<string> the names of the `savepoint:` calls, in order
     */
    private function savepointNames(): array
    {
        $names = [];
        foreach ($this->connection->calls() as $call) {
            if (str_starts_with($call, 'savepoint:')) {
                $names[] = substr($call, strlen('savepoint:'));
            }
        }

        return $names;
    }

    /**
     * The one savepoint name of the run: `<caller name>_<object id>`.
     */
    private function derivedName(string $callerName): string
    {
        $names = $this->savepointNames();
        $this->assertCount(1, $names);
        $this->assertMatchesRegularExpression('/^' . preg_quote($callerName, '/') . '_\d+$/', $names[0]);

        return $names[0];
    }

    /**
     * @param list<string> $names
     */
    private function assertDerivedAndUnique(array $names, int $expected): void
    {
        $this->assertCount($expected, $names);
        $this->assertCount($expected, array_unique($names), 'no two open savepoints carry the same name');
        foreach ($names as $name) {
            $this->assertMatchesRegularExpression('/^sp_0_\d+$/', $name, 'derived from the caller name');
        }
    }

    #[DataProvider('dialects')]
    public function testNestedBracketsWithTheSameCallerNameGetOwnSavepoints(string $dialect): void
    {
        $this->open($dialect);
        $this->pdo()->beginTransaction();
        $this->ledger()->add('outer');

        $answer = ($this->bracket)($this->connection, 'sp_0', function () {
            $this->ledger()->add('first');

            return ($this->bracket)($this->connection, 'sp_0', function () {
                $this->ledger()->add('second');

                return 'done';
            });
        });

        $this->assertSame('done', $answer);
        $this->assertDerivedAndUnique($this->savepointNames(), 2);
        $this->assertTrue($this->connection->inTransaction());
        $this->pdo()->commit();
        $this->assertSame(['outer', 'first', 'second'], $this->ledger()->notes());
    }

    #[DataProvider('dialects')]
    public function testThreeLevelsOfTheSameNameInnermostRejectRollsBackOnlyItself(string $dialect): void
    {
        $this->open($dialect);
        $this->pdo()->beginTransaction();
        $rejected = fn(mixed $result): bool => $result === 'rejected';

        $answer = ($this->bracket)($this->connection, 'sp_0', function () use ($rejected) {
            $this->ledger()->add('level1');

            return ($this->bracket)($this->connection, 'sp_0', function () use ($rejected) {
                $this->ledger()->add('level2');
                ($this->bracket)($this->connection, 'sp_0', function () {
                    $this->ledger()->add('level3');

                    return 'rejected';
                }, $rejected);

                return 'ok';
            }, $rejected);
        }, $rejected);

        $this->assertSame('ok', $answer);
        $this->assertDerivedAndUnique($this->savepointNames(), 3);
        $this->assertTrue($this->connection->inTransaction());
        $this->pdo()->commit();
        $this->assertSame(['level1', 'level2'], $this->ledger()->notes());
    }

    #[DataProvider('dialects')]
    public function testInnermostThrowOfTheSameNameArrivesAsCauseAndRollsBackTheOuterOnes(string $dialect): void
    {
        $this->open($dialect);
        $this->pdo()->beginTransaction();
        $this->ledger()->add('holder');
        $original = new \DomainException('innermost');

        try {
            ($this->bracket)($this->connection, 'sp_0', function () use ($original) {
                $this->ledger()->add('level1');

                return ($this->bracket)($this->connection, 'sp_0', function () use ($original) {
                    $this->ledger()->add('level2');

                    return ($this->bracket)($this->connection, 'sp_0', function () use ($original) {
                        $this->ledger()->add('level3');

                        throw $original;
                    });
                });
            });
            $this->fail('the cause must arrive');
        } catch (TransactionLostException $e) {
            $this->fail('a collision of savepoint names must not lose the transaction: ' . $e->getMessage());
        } catch (\DomainException $e) {
            $this->assertSame($original, $e);
        }

        $this->assertDerivedAndUnique($this->savepointNames(), 3);
        $this->assertTrue($this->connection->inTransaction());
        $this->pdo()->commit();
        $this->assertSame(['holder'], $this->ledger()->notes());
    }

    #[DataProvider('dialects')]
    public function testLongCallerNameIsCutSoTheDerivedNameFitsTheLimit(string $dialect): void
    {
        $this->open($dialect);
        $this->pdo()->beginTransaction();

        ($this->bracket)($this->connection, str_repeat('a', 63), function () {
            $this->ledger()->add('inner');
        });

        $names = $this->savepointNames();
        $this->assertCount(1, $names);
        $this->assertLessThanOrEqual(63, strlen($names[0]));
        $this->assertMatchesRegularExpression('/^a+_\d+$/', $names[0]);
        $this->pdo()->commit();
        $this->assertSame(['inner'], $this->ledger()->notes());
    }

    #[DataProvider('dialects')]
    public function testRollsBackAndRethrowsOnThrow(string $dialect): void
    {
        $this->open($dialect);
        $thrown = new \DomainException('boom');

        try {
            ($this->bracket)($this->connection, 'sp', function () use ($thrown) {
                $this->ledger()->add('lost');

                throw $thrown;
            });
            $this->fail('the exception must be thrown on');
        } catch (\DomainException $e) {
            $this->assertSame($thrown, $e, 'the very same instance runs through');
        }

        $this->assertSame(['beginTransaction', 'rollback'], $this->connection->calls());
        $this->assertSame([], $this->ledger()->notes());
        $this->assertFalse($this->connection->inTransaction());
    }

    #[DataProvider('snapshotDialects')]
    public function testTransientConflictExceptionPassesThroughUnchanged(string $dialect): void
    {
        $this->open($dialect);
        $this->peer = $this->pdoFor($dialect);
        $this->ledger()->add('a');
        $repository = new Repository(new PdoConnectionPool($this->pdo()));
        $table = Ledger::TABLE;
        $seen = null;

        if ($dialect === 'mariadb') {
            $this->pdo()->exec('SET SESSION innodb_snapshot_isolation=1');
        }

        try {
            ($this->bracket)($this->connection, 'sp', function () use ($dialect, $repository, $table, &$seen) {
                if ($dialect === 'postgres') {
                    $this->pdo()->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                }
                // the snapshot of this transaction is taken here ...
                $this->pdo()->query('SELECT note FROM ' . $table . ' WHERE id = 1')->fetchColumn();
                // ... and the row changes under it
                $this->peer?->exec("UPDATE " . $table . " SET note = 'b' WHERE id = 1");

                try {
                    $repository->update($table, 'id', 1, ['note' => 'c'], ['note' => 'a']);
                } catch (TransientConflictException $conflict) {
                    $seen = $conflict;

                    throw $conflict;
                }
            });
            $this->fail('TransientConflictException expected');
        } catch (TransientConflictException $e) {
            $this->assertNotNull($seen);
            $this->assertSame($seen, $e, 'the bracket does not translate or wrap it');
            $this->assertSame($dialect === 'mariadb' ? '1020' : '40001', $e->getDriverCode());
        }

        $this->assertSame(['beginTransaction', 'rollback'], $this->connection->calls());
        $this->assertFalse($this->connection->inTransaction());
        $this->assertSame(['b'], $this->ledger()->notes(), 'only the peer\'s change is left');
    }

    #[DataProvider('dialects')]
    public function testFailingRollbackAfterAThrowIsATransactionLostWithTheCauseAsPrevious(string $dialect): void
    {
        // E4-P2 Nachlauf, Q7 (d): a failing rollback no longer swallows - the transaction is lost.
        $this->open($dialect);
        $rollbackFailure = new \RuntimeException('rollback failed');
        $this->connection->failOn('rollback', $rollbackFailure);
        $original = new \DomainException('original');

        try {
            ($this->bracket)($this->connection, 'sp', function () use ($original) {
                throw $original;
            });
            $this->fail('the transaction lost must be thrown');
        } catch (TransactionLostException $e) {
            $this->assertSame($original, $e->getPrevious());
            $this->assertSame($rollbackFailure, $e->getRollbackFailure());
        }

        $this->assertSame(['beginTransaction', 'rollback'], $this->connection->calls());
    }

    #[DataProvider('dialects')]
    public function testFailingRollbackToTheSavepointAfterAThrowIsATransactionLost(string $dialect): void
    {
        $this->open($dialect);
        $this->pdo()->beginTransaction();
        $rollbackFailure = new \RuntimeException('rollback to savepoint failed');
        $this->connection->failOn('rollbackTo', $rollbackFailure);
        $original = new \DomainException('original');

        try {
            ($this->bracket)($this->connection, 'sp', function () use ($original) {
                throw $original;
            });
            $this->fail('the transaction lost must be thrown');
        } catch (TransactionLostException $e) {
            $this->assertSame($original, $e->getPrevious());
            $this->assertSame($rollbackFailure, $e->getRollbackFailure());
        }

        $name = $this->derivedName('sp');
        $this->assertSame(
            ['savepoint:' . $name, 'rollbackTo:' . $name],
            $this->connection->calls(),
            'no release after a lost rollback'
        );
        $this->pdo()->rollBack();
    }

    #[DataProvider('dialects')]
    public function testThrowingFailedPredicateRollsBackAndRethrows(string $dialect): void
    {
        $this->open($dialect);
        $thrown = new \LogicException('predicate failed');

        try {
            ($this->bracket)($this->connection, 'sp', function () {
                $this->ledger()->add('lost');

                return 'result';
            }, function () use ($thrown): bool {
                throw $thrown;
            });
            $this->fail('the exception must be thrown on');
        } catch (\LogicException $e) {
            $this->assertSame($thrown, $e);
        }

        $this->assertSame(['beginTransaction', 'rollback'], $this->connection->calls());
        $this->assertSame([], $this->ledger()->notes());
        $this->assertFalse($this->connection->inTransaction());
    }

    #[DataProvider('dialects')]
    public function testThrowingCommitRollsBackWhileTheTransactionIsOpenAndKeepsTheCause(string $dialect): void
    {
        $this->open($dialect);
        $commitFailure = new \RuntimeException('commit failed');
        $this->connection->failOn('commit', $commitFailure);

        try {
            ($this->bracket)($this->connection, 'sp', function () {
                $this->ledger()->add('lost');
            });
            $this->fail('the commit failure must be thrown on');
        } catch (\RuntimeException $e) {
            $this->assertSame($commitFailure, $e);
        }

        $this->assertSame(['beginTransaction', 'commit', 'rollback'], $this->connection->calls());
        $this->assertFalse($this->connection->inTransaction(), 'the transaction was not left open');
        $this->assertSame([], $this->ledger()->notes());
    }
}
