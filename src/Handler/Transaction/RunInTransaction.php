<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Handler\Transaction;

use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use JardisSupport\Contract\DbConnection\SavepointInterface;
use JardisSupport\Contract\Kernel\DomainResponseInterface;
use JardisSupport\Repository\Exception\TransactionConfigurationException;
use JardisSupport\Repository\Exception\TransactionLostException;

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
 *    the cause stays. A failing release never replaces the cause. A failing rollback (to the savepoint)
 *    after a throw means the transaction is lost: the bracket throws {@see TransactionLostException} with
 *    the cause as previous. A failing rollback after a failed answer without a throw propagates as it is.
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
     * @throws TransactionLostException          when the work threw and the rollback failed as well
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
            static fn() => $connection->rollback(),
            null
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
            static fn() => $connection->rollbackToSavepoint($savepoint),
            static fn() => $connection->releaseSavepoint($savepoint)
        );
    }

    /**
     * @param \Closure(): mixed $work
     * @param \Closure(mixed): bool $failed
     * @param \Closure(): void $commit
     * @param \Closure(): void $rollback
     * @param (\Closure(): void)|null $release runs after the rollback (savepoint); its failure never
     *                                         replaces the cause
     * @throws TransactionLostException when the work threw and the rollback failed as well
     */
    private function settle(
        DbConnectionInterface $connection,
        \Closure $work,
        \Closure $failed,
        \Closure $commit,
        \Closure $rollback,
        ?\Closure $release
    ): mixed {
        try {
            $result = $work();
            $isFailed = $failed($result) === true;
            if (!$isFailed) {
                $commit();
            }
        } catch (\Throwable $cause) {
            // The cause stays: a transaction already ended has nothing to roll back. A rollback that fails
            // here means the transaction is lost - the cause rides along as previous.
            if ($connection->inTransaction()) {
                try {
                    $rollback();
                } catch (\Throwable $rollbackFailure) {
                    throw new TransactionLostException($cause, $rollbackFailure);
                }

                try {
                    if ($release !== null) {
                        $release();
                    }
                } catch (\Throwable) {
                    // dropped on purpose - the cause is thrown on
                }
            }

            throw $cause;
        }

        if ($isFailed && $connection->inTransaction()) {
            $rollback();
            if ($release !== null) {
                $release();
            }
        }

        return $result;
    }
}
