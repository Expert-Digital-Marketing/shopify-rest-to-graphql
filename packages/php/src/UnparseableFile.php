<?php

declare(strict_types=1);

namespace EdmUk\ShopifyRestToGraphql;

use RuntimeException;

/**
 * A file the parser could not read.
 *
 * Thrown rather than swallowed so the scanner can count it and report the
 * total, because a silently skipped file is a hole in the migration plan.
 *
 * The property is `path` and not `file`, because Exception already owns `$file`
 * and PHP will not let a subclass redeclare it.
 */
final class UnparseableFile extends RuntimeException
{
    public function __construct(public readonly string $path)
    {
        parent::__construct("Could not parse {$path}");
    }
}
