<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Tests\Integration;

use JardisSupport\Contract\Kernel\DomainResponseInterface;
use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Repository\Exception\TransactionConfigurationException;
use JardisSupport\Repository\Exception\TransactionLostException;
use JardisSupport\Repository\Handler\Transaction\RunOwnedTransaction;
use JardisSupport\Repository\Tests\Support\Db\FakeOutcome;
use JardisSupport\Repository\Tests\Support\Db\Ledger;
use JardisSupport\Repository\Tests\Support\Db\RecordingConnection;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The bracket for a use case that knows only its depth: savepoint name, nesting and ownership are derived,
 * failure is recognised through the contracts' outcome, a throw comes back as a value. Real SQLite
 * `:memory:` transactions; the dialect-specific bracket behaviour is covered by RunInTransactionIntegrationTest.
 */
final class RunOwnedTransactionIntegrationTest extends TestCase
{
    private PDO $pdo;
    private Ledger $ledger;
    private RecordingConnection $connection;
    private RunOwnedTransaction $bracket;

    protected function setUp(): void
    {
        $this->pdo = Ledger::sqlite();
        $this->ledger = new Ledger($this->pdo);
        $this->connection = new RecordingConnection($this->pdo);
        $this->bracket = new RunOwnedTransaction();
    }

    /**
     * @param list<string> $calls
     */
    private function savepointName(array $calls, string $callerName): string
    {
        foreach ($calls as $call) {
            if (str_starts_with($call, 'savepoint:')) {
                $name = substr($call, strlen('savepoint:'));
                $this->assertMatchesRegularExpression('/^' . preg_quote($callerName, '/') . '_\d+$/', $name);

                return $name;
            }
        }
        $this->fail('no savepoint was set');
    }

    public function testOwnerCommitsASuccessfulUnitAndIsAnswered(): void
    {
        $outcome = new FakeOutcome(ResponseStatus::Success);

        $done = ($this->bracket)($this->connection, function () use ($outcome) {
            $this->ledger->add('kept');

            return $outcome;
        });

        $this->assertSame($outcome, $done->result);
        $this->assertTrue($done->owner);
        $this->assertNull($done->failure);
        $this->assertSame(['beginTransaction', 'commit'], $this->connection->calls());
        $this->assertSame(['kept'], $this->ledger->notes());
    }

    public function testOwnerRollsBackWhenTheOutcomeIsFailed(): void
    {
        $outcome = new FakeOutcome(ResponseStatus::RuleViolation);

        $done = ($this->bracket)($this->connection, function () use ($outcome) {
            $this->ledger->add('lost');

            return $outcome;
        });

        $this->assertSame($outcome, $done->result, 'a failed answer is an answer, not a throw');
        $this->assertTrue($done->owner);
        $this->assertNull($done->failure);
        $this->assertSame(['beginTransaction', 'rollback'], $this->connection->calls());
        $this->assertSame([], $this->ledger->notes());
    }

    public function testOpenTransactionMeansSavepointNamedByDepthAndNotOwner(): void
    {
        $this->pdo->beginTransaction();
        $this->ledger->add('outer');

        $done = ($this->bracket)($this->connection, function () {
            $this->ledger->add('inner');

            return new FakeOutcome(ResponseStatus::Success);
        }, 2);

        $this->assertFalse($done->owner);
        $this->assertNull($done->failure);
        $name = $this->savepointName($this->connection->calls(), 'sp_2');
        $this->assertSame(['savepoint:' . $name, 'release:' . $name], $this->connection->calls());
        $this->assertTrue($this->pdo->inTransaction(), 'the outer transaction stays open - it is not ours');
        $this->pdo->commit();
        $this->assertSame(['outer', 'inner'], $this->ledger->notes());
    }

    public function testFailedOutcomeInASavepointRollsBackOnlyTheSavepoint(): void
    {
        $this->pdo->beginTransaction();
        $this->ledger->add('outer');

        $done = ($this->bracket)($this->connection, function () {
            $this->ledger->add('inner');

            return new FakeOutcome(ResponseStatus::NotFound);
        });

        $this->assertFalse($done->owner);
        $this->assertTrue($done->result instanceof FakeOutcome);
        $name = $this->savepointName($this->connection->calls(), 'sp_0');
        $this->assertSame(
            ['savepoint:' . $name, 'rollbackTo:' . $name, 'release:' . $name],
            $this->connection->calls()
        );
        $this->pdo->commit();
        $this->assertSame(['outer'], $this->ledger->notes());
    }

    public function testNestedWithoutOpenTransactionRunsDirectlyAndStillOwnsTheCall(): void
    {
        $done = ($this->bracket)($this->connection, function () {
            $this->ledger->add('direct');

            return 'sub';
        }, 1);

        $this->assertSame('sub', $done->result);
        $this->assertTrue($done->owner, 'no transaction was open on entry');
        $this->assertSame([], $this->connection->calls(), 'a nested unit opens no transaction of its own');
        $this->assertFalse($this->pdo->inTransaction());
    }

    public function testThrowRollsBackAndComesBackAsFailureOfTheOwner(): void
    {
        $cause = new \RuntimeException('boom');

        $done = ($this->bracket)($this->connection, function () use ($cause) {
            $this->ledger->add('lost');

            throw $cause;
        });

        $this->assertNull($done->result);
        $this->assertTrue($done->owner);
        $this->assertSame($cause, $done->failure);
        $this->assertSame(['beginTransaction', 'rollback'], $this->connection->calls());
        $this->assertSame([], $this->ledger->notes());
    }

    public function testThrowInASavepointComesBackWithOwnerFalse(): void
    {
        $this->pdo->beginTransaction();
        $this->ledger->add('outer');
        $cause = new \RuntimeException('boom');

        $done = ($this->bracket)($this->connection, function () use ($cause) {
            $this->ledger->add('lost');

            throw $cause;
        }, 1);

        $this->assertFalse($done->owner);
        $this->assertSame($cause, $done->failure);
        $this->pdo->commit();
        $this->assertSame(['outer'], $this->ledger->notes());
    }

    public function testLostTransactionComesBackAsFailureWithItsCause(): void
    {
        $cause = new \RuntimeException('boom');
        $this->connection->failOn('rollback', new \RuntimeException('rollback failed'));

        $done = ($this->bracket)($this->connection, static function () use ($cause) {
            throw $cause;
        });

        $this->assertTrue($done->owner);
        $this->assertInstanceOf(TransactionLostException::class, $done->failure);
        $this->assertSame($cause, $done->failure->getPrevious());
    }

    public function testMissingConnectionComesBackAsConfigurationFailureOfTheOwner(): void
    {
        $done = ($this->bracket)(null, static fn() => 'never');

        $this->assertTrue($done->owner);
        $this->assertInstanceOf(TransactionConfigurationException::class, $done->failure);
        $this->assertNull($done->result);
    }

    public function testDefaultStaysTheDomainResponseStatusLine(): void
    {
        $failed = $this->createStub(DomainResponseInterface::class);
        $failed->method('getStatus')->willReturn(422);
        $ok = $this->createStub(DomainResponseInterface::class);
        $ok->method('getStatus')->willReturn(200);

        ($this->bracket)($this->connection, function () use ($failed) {
            $this->ledger->add('lost');

            return $failed;
        });
        ($this->bracket)($this->connection, function () use ($ok) {
            $this->ledger->add('kept');

            return $ok;
        });

        $this->assertSame(
            ['beginTransaction', 'rollback', 'beginTransaction', 'commit'],
            $this->connection->calls()
        );
        $this->assertSame(['kept'], $this->ledger->notes());
    }

    public function testAGivenPredicateOverridesTheDefault(): void
    {
        $done = ($this->bracket)($this->connection, function () {
            $this->ledger->add('lost');

            return 'no';
        }, 0, static fn(mixed $result): bool => $result === 'no');

        $this->assertSame('no', $done->result);
        $this->assertSame(['beginTransaction', 'rollback'], $this->connection->calls());
        $this->assertSame([], $this->ledger->notes());
    }

    public function testNegativeDepthIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ($this->bracket)($this->connection, static fn() => 'x', -1);
    }
}
