<?php

declare(strict_types=1);

namespace JardisSupport\Repository\PrimaryKey;

enum PkStrategy
{
    case AUTOINCREMENT;
    case INTEGER;
    case STRING;
    case NONE;
}
