<?php

declare(strict_types=1);

namespace EdmUk\ShopifyRestToGraphql;

/** A call site paired with the rule that replaces it, when one exists. */
final readonly class Finding
{
    public function __construct(
        public RestCallSite $call,
        public ?Rule $rule,
    ) {
    }

    public function status(): string
    {
        return $this->rule?->status ?? 'unmapped';
    }
}
