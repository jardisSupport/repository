<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Handler\Transaction;

use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use JardisSupport\Repository\Data\OwnedResult;

/**
 * The transaction bracket for a use case that knows only its depth: {@see RunInTransaction} with the three
 * things a caller would otherwise compute itself derived here.
 *
 * - The savepoint name is `sp_<depth>` (made unique among the open brackets by the bracket itself).
 * - The unit is nested when the depth is above 0: without an open transaction it runs directly.
 * - The ownership is answered, not asked: `owner` is true when no transaction was open on entry - read
 *   before the bracket acts, so the caller never calls `inTransaction()`.
 *
 * Failure is recognised by the bracket's default: an {@see \JardisSupport\Contract\Kernel\OutcomeInterface}
 * that `isFailed()`, or a {@see \JardisSupport\Contract\Kernel\DomainResponseInterface} with status 400 and
 * above; a `$failed` closure overrides it. A failed answer rolls back and is returned as the result.
 *
 * With `transactional: false` the use case opted out of the bracket: the work runs as it is, nothing is begun,
 * committed or rolled back, and a connection may be missing. Ownership is read the same way (no transaction open
 * on entry) and a throw comes back the same way in {@see OwnedResult::$failure}; a failed answer is simply the
 * result (nothing to roll back, `$failed` and `$depth` do not apply).
 *
 * A throw is not thrown here: the bracket rolls back (to the savepoint), and the cause comes back in
 * {@see OwnedResult::$failure} together with `owner`, because only now the caller can map it - the owner
 * answers, one who is not the owner throws it on. That includes the bracket's own
 * {@see \JardisSupport\Repository\Exception\TransactionLostException} and the configuration error of a
 * missing connection (owner: true). Nothing else changes: commit, rollback and savepoint order are those of
 * {@see RunInTransaction}.
 */
final class RunOwnedTransaction
{
    public function __construct(private readonly RunInTransaction $bracket = new RunInTransaction())
    {
    }

    /**
     * @param \Closure(): mixed $work
     * @param (\Closure(mixed): bool)|null $failed
     * @param bool $transactional false runs the work without the bracket (ownership and failure answered the same)
     * @throws \InvalidArgumentException when the depth is negative
     */
    public function __invoke(
        ?DbConnectionInterface $connection,
        \Closure $work,
        int $depth = 0,
        ?\Closure $failed = null,
        bool $transactional = true
    ): OwnedResult {
        if ($depth < 0) {
            throw new \InvalidArgumentException(
                sprintf('The depth of a unit of work cannot be negative, got %d', $depth)
            );
        }

        $owner = $connection?->inTransaction() !== true;

        try {
            if (!$transactional) {
                return new OwnedResult($work(), $owner);
            }

            return new OwnedResult(($this->bracket)($connection, 'sp_' . $depth, $work, $failed, $depth > 0), $owner);
        } catch (\Throwable $failure) {
            return new OwnedResult(null, $owner, $failure);
        }
    }
}
