<?php

declare(strict_types=1);

namespace EdmUk\ShopifyRestToGraphql;

use JsonException;
use RuntimeException;

/**
 * Loads the shared mapping files and validates them.
 *
 * The checks match the TypeScript loader, including the one that matters most:
 * a rule's document has to actually call the root field it advertises.
 */
final class MappingRepository
{
    private const METHODS = ['GET', 'POST', 'PUT', 'DELETE'];

    private const STATUSES = ['direct', 'partial', 'manual'];

    /** @var list<Rule> */
    private array $rules = [];

    public function __construct(string $directory)
    {
        $files = glob(rtrim($directory, '/') . '/*.json');
        if ($files === false || $files === []) {
            throw new RuntimeException("No mapping files found in {$directory}");
        }

        sort($files);

        foreach ($files as $file) {
            if (basename($file) === 'schema.json') {
                continue;
            }
            foreach ($this->load($file) as $rule) {
                $this->rules[] = $rule;
            }
        }
    }

    /** @return list<Rule> */
    public function rules(): array
    {
        return $this->rules;
    }

    public function find(string $method, string $path): ?Rule
    {
        foreach ($this->rules as $rule) {
            if ($rule->method !== $method) {
                continue;
            }
            if (preg_match(PathNormaliser::toPattern($rule->path), $path) === 1) {
                return $rule;
            }
        }

        return null;
    }

    /** @return list<Rule> */
    private function load(string $file): array
    {
        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new RuntimeException("Cannot read {$file}");
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(basename($file) . ': ' . $exception->getMessage(), 0, $exception);
        }

        $name = basename($file);
        $rawRules = $decoded['rules'] ?? null;
        if (! is_array($rawRules) || $rawRules === []) {
            throw new RuntimeException("{$name}: rules is empty");
        }

        $rules = [];
        foreach (array_values($rawRules) as $index => $raw) {
            $rules[] = $this->toRule($name, $index, $raw);
        }

        return $rules;
    }

    private function toRule(string $file, int $index, mixed $raw): Rule
    {
        $where = "{$file}: rules[{$index}]";
        if (! is_array($raw)) {
            throw new RuntimeException("{$where} is not an object");
        }

        $id = $raw['id'] ?? null;
        $docs = $raw['docs'] ?? null;
        $status = $raw['status'] ?? null;
        $rest = $raw['rest'] ?? null;

        if (! is_string($id)) {
            throw new RuntimeException("{$where}.id is missing");
        }
        if (! is_string($docs)) {
            throw new RuntimeException("{$where}.docs is missing");
        }
        if (! is_string($status) || ! in_array($status, self::STATUSES, true)) {
            throw new RuntimeException("{$where}.status must be direct, partial or manual");
        }
        if (! is_array($rest) || ! is_string($rest['method'] ?? null) || ! is_string($rest['path'] ?? null)) {
            throw new RuntimeException("{$where}.rest is malformed");
        }
        if (! in_array($rest['method'], self::METHODS, true)) {
            throw new RuntimeException("{$where}.rest.method is not an HTTP method");
        }

        $graphql = $raw['graphql'] ?? null;

        if ($status === 'manual') {
            if ($graphql !== null) {
                throw new RuntimeException("{$where} is manual, so it must not carry a graphql operation");
            }

            /** @var 'direct'|'partial'|'manual' $status */
            return new Rule(
                id: $id,
                method: $rest['method'],
                path: $rest['path'],
                status: $status,
                docs: $docs,
                notes: self::stringList($raw['notes'] ?? []),
            );
        }

        if (! is_array($graphql)) {
            throw new RuntimeException("{$where}.graphql is required unless the status is manual");
        }

        $rootField = $graphql['rootField'] ?? null;
        $document = $graphql['document'] ?? null;
        if (! is_string($rootField) || ! is_string($document)) {
            throw new RuntimeException("{$where}.graphql is missing rootField or document");
        }
        if (preg_match('~\b' . preg_quote($rootField, '~') . '\s*\(~', $document) !== 1) {
            throw new RuntimeException("{$where}.graphql.document never calls {$rootField}");
        }

        $variables = $graphql['variables'] ?? null;
        $operation = $graphql['operation'] ?? null;

        /** @var 'direct'|'partial'|'manual' $status */
        return new Rule(
            id: $id,
            method: $rest['method'],
            path: $rest['path'],
            status: $status,
            docs: $docs,
            operation: is_string($operation) ? $operation : null,
            rootField: $rootField,
            document: $document,
            variables: is_array($variables) ? $variables : null,
            accessScopes: self::stringList($graphql['accessScopes'] ?? []),
            notes: self::stringList($raw['notes'] ?? []),
        );
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
