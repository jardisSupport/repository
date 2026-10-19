<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Exception;

use JardisSupport\Contract\Repository\Exception\TransientConflictException;

/**
 * The transaction is lost: the unit of work threw, and the rollback that should have cleaned up after it
 * failed as well. Whatever the connection still holds is in an unknown state.
 *
 * The cause is the previous exception ({@see getPrevious()}); the rollback failure is
 * {@see getRollbackFailure()}. When the cause is a {@see TransientConflictException}, its driver code is
 * carried on.
 *
 * Who owns the transaction answers 409 transient, one who does not own it throws it on; Persist never
 * translates it.
 */
final class TransactionLostException extends TransientConflictException
{
    public function __construct(
        \Throwable $cause,
        private readonly \Throwable $rollbackFailure
    ) {
        parent::__construct(
            'The transaction is lost: the rollback failed after the unit of work threw',
            $cause instanceof TransientConflictException ? $cause->getDriverCode() : null,
            $cause
        );
    }

    /**
     * The failure of the rollback (or rollback to the savepoint) that could not clean up after the cause.
     */
    public function getRollbackFailure(): \Throwable
    {
        return $this->rollbackFailure;
    }
}
