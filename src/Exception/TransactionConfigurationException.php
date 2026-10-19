<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Exception;

/**
 * The transaction bracket cannot work with what it was given: no connection although a
 * transaction is needed, or a savepoint is needed and the connection offers none.
 *
 * A wiring error, not a runtime condition - it is thrown, never answered.
 */
final class TransactionConfigurationException extends \RuntimeException
{
}
