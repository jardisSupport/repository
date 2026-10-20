<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Tests\Support\Db;

use JardisSupport\Contract\Kernel\OutcomeInterface;
use JardisSupport\Contract\Kernel\ResponseStatus;

/**
 * A real implementation of the contracts' outcome (not a mock): the status decides, the way an engine's
 * outcome does.
 */
final class FakeOutcome implements OutcomeInterface
{
    public function __construct(private readonly ResponseStatus $status)
    {
    }

    public function status(): ResponseStatus
    {
        return $this->status;
    }

    public function isFailed(): bool
    {
        return $this->status->value >= 400;
    }
}
