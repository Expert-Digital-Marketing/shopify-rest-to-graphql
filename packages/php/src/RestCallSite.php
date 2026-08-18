<?php

declare(strict_types=1);

namespace EdmUk\ShopifyRestToGraphql;

/** A REST call found in source, before it is matched against the rules. */
final readonly class RestCallSite
{
    /**
     * @param  'certain'|'likely'  $confidence
     */
    public function __construct(
        public string $file,
        public int $line,
        public string $method,
        public string $path,
        public ?string $apiVersion,
        public string $detector,
        public string $evidence,
        public string $confidence,
    ) {
    }
}
