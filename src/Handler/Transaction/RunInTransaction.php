<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Handler\Transaction;

use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use JardisSupport\Contract\DbConnection\SavepointInterface;
use JardisSupport\Contract\Kernel\DomainResponseInterface;
use JardisSupport\Repository\Exception\TransactionConfigurationException;

/**
 * The transaction bracket around a unit of work.
 *
 * 1. No open transaction and not nested: the bracket begins its own and commits on success.
 * 2. An open transaction: the work runs in a savepoint with the name the caller gives; success releases
 *    it, a failure rolls back to it and then releases it. A connection without
 *    {@see SavepointInterface} is a configuration error.
 * 3. Nested and no open transaction: no transaction of its own, the work runs directly.
 * 4. A failed answer (`$failed($result) === true`) or a throw rolls back; `$failed` and the commit run
 *    inside the guarded block, so a throw there rolls back too - only while a transaction is open - and
 *    the cause stays. A failing rollback or release never replaces the cause, and every throw is thrown
 *    on unchanged.
 * 5. No connection is a configuration error; opting out is the caller's choice - it does not call the
 *    bracket.
 *
 * Without `$failed`, an answer is failed when it is a {@see DomainResponseInterface} with status 400 or
 * above.
 */
final class RunInTransaction
{
    /**
     * @param \Closure(): mixed $work
     * @param (\Closure(mixed): bool)|null $failed
     * @throws TransactionConfigurationException when there is no connection, or a savepoint is needed and
     *                                           the connection offers none
     */
    public function __invoke(
        ?DbConnectionInterface $connection,
        string $savepoint,
        \Closure $work,
        ?\Closure $failed = null,
        bool $nested = false
    ): mixed {
        if ($connection === null) {
            throw new TransactionConfigurationException(
                'The transaction bracket needs a database connection, but there is none'
            );
        }

        $failed ??= static fn(mixed $result): bool => $result instanceof DomainResponseInterface
            && $result->getStatus() >= 400;

        if ($connection->inTransaction()) {
            return $this->inSavepoint($connection, $savepoint, $work, $failed);
        }

        if ($nested) {
            return $work();
        }

        $connection->beginTransaction();

        return $this->settle(
            $connection,
            $work,
            $failed,
            static fn() => $connection->commit(),
            static fn() => $connection->rollback()
        );
    }

    /**
     * @param \Closure(): mixed $work
     * @param \Closure(mixed): bool $failed
     */
    private function inSavepoint(
        DbConnectionInterface $connection,
        string $savepoint,
        \Closure $work,
        \Closure $failed
    ): mixed {
        if (!$connection instanceof SavepointInterface) {
            throw new TransactionConfigurationException(sprintf(
                'The work runs inside an open transaction and needs savepoints, but %s offers none',
                $connection::class
            ));
        }

        $connection->savepoint($savepoint);

        return $this->settle(
            $connection,
            $work,
            $failed,
            static fn() => $connection->releaseSavepoint($savepoint),
            static function () use ($connection, $savepoint): void {
                $connection->rollbackToSavepoint($savepoint);
                $connection->releaseSavepoint($savepoint);
            }
        );
    }

    /**
     * @param \Closure(): mixed $work
     * @param \Closure(mixed): bool $failed
     * @param \Closure(): void $commit
     * @param \Closure(): void $rollback
     */
    private function settle(
        DbConnectionInterface $connection,
        \Closure $work,
        \Closure $failed,
        \Closure $commit,
        \Closure $rollback
    ): mixed {
        try {
            $result = $work();
            $isFailed = $failed($result) === true;
            if (!$isFailed) {
                $commit();
            }
        } catch (\Throwable $cause) {
            // The cause stays: a transaction already ended has nothing to roll back, and a failing
            // rollback must not replace the error that led here.
            try {
                if ($connection->inTransaction()) {
                    $rollback();
                }
            } catch (\Throwable) {
                // dropped on purpose - the cause is thrown on
            }

            throw $cause;
        }

        if ($isFailed && $connection->inTransaction()) {
            $rollback();
        }

        return $result;
    }
}
