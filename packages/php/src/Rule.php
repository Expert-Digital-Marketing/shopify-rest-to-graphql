<?php

declare(strict_types=1);

namespace EdmUk\ShopifyRestToGraphql;

/**
 * One REST endpoint and the GraphQL operation that replaces it.
 *
 * Rules are data, loaded from the mapping files shared with the TypeScript
 * package. Nothing here is inferred at runtime.
 */
final readonly class Rule
{
    /**
     * @param  'direct'|'partial'|'manual'  $status
     * @param  list<string>  $notes
     * @param  array<string, mixed>|null  $variables
     * @param  list<string>  $accessScopes
     */
    public function __construct(
        public string $id,
        public string $method,
        public string $path,
        public string $status,
        public string $docs,
        public ?string $operation = null,
        public ?string $rootField = null,
        public ?string $document = null,
        public ?array $variables = null,
        public array $accessScopes = [],
        public array $notes = [],
    ) {
    }

    public function hasReplacement(): bool
    {
        return $this->rootField !== null;
    }
}
