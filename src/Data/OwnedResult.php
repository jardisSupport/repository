<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Data;

/**
 * What {@see \JardisSupport\Repository\Handler\Transaction\RunOwnedTransaction} hands back: the answer of the
 * unit of work, who owned the transaction, and - instead of a throw - the failure the unit of work ended with.
 *
 * `$owner` is true when no transaction was open on entry (the bracket opened its own, or ran the nested work
 * directly): the owner answers a 5xx cause, one who is not the owner throws it on to its owner.
 * `$failure` is null on a finished unit of work; otherwise it is the cause after the rollback, and `$result`
 * is null.
 */
final readonly class OwnedResult
{
    public function __construct(
        public mixed $result,
        public bool $owner,
        public ?\Throwable $failure = null,
    ) {
    }
}
