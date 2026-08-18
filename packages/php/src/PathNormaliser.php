<?php

declare(strict_types=1);

namespace EdmUk\ShopifyRestToGraphql;

/**
 * Reduce anything that looks like a Shopify Admin URL to the resource path.
 *
 * Deliberately the same rules as the TypeScript package, because a report that
 * differed by language would be worse than useless on a mixed codebase.
 */
final class PathNormaliser
{
    /**
     * @return array{path: string, apiVersion: string|null}|null
     */
    public static function normalise(string $raw): ?array
    {
        $value = trim($raw);
        if ($value === '') {
            return null;
        }

        // The query string and fragment take no part in matching.
        $value = (string) preg_replace('/[?#].*$/', '', $value);

        $withoutOrigin = (string) preg_replace('~^[a-z][a-z0-9+.-]*://[^/]+~i', '', $value);
        $hadOrigin = $withoutOrigin !== $value;
        $value = $withoutOrigin;

        if (preg_match('~/?admin/api/([^/]+)/(.+)$~', $value, $matches) === 1) {
            return [
                'path' => ltrim($matches[2], '/'),
                'apiVersion' => $matches[1],
            ];
        }

        // A base URL such as `https://{shop}/admin/api/2020-04`, with no
        // resource after it, is something a helper appends to. It is not a
        // call, and reporting it puts a line in the plan nobody can act on.
        if (preg_match('~^/?admin/api/[^/]+/?$~', $value) === 1) {
            return null;
        }

        // Unversioned admin path. Require the `.json` suffix, because
        // `/admin/oauth/authorize` and `/admin/charges/{id}/confirm...` are
        // browser URLs on the same prefix and are not REST resources.
        if (preg_match('~^/?admin/(.+\.json)$~', $value, $matches) === 1) {
            return ['path' => ltrim($matches[1], '/'), 'apiVersion' => null];
        }

        // Anything still carrying the admin prefix failed the checks above, so
        // it is not a REST resource. It must not fall through to the bare
        // resource branch and be reported as `admin/oauth/authorize`.
        if (preg_match('~^/?admin/~', $value) === 1) {
            return null;
        }

        // A bare resource path, as passed to a client that adds the prefix.
        // Never when a host was named, and never with whitespace in it.
        if (! $hadOrigin && preg_match('~^/?[a-z0-9_]+(?:/[^/\s]+)*(?:\.json)?$~i', $value) === 1) {
            return ['path' => ltrim($value, '/'), 'apiVersion' => null];
        }

        return null;
    }

    /** The GraphQL endpoint shares the Admin prefix and must never be reported. */
    public static function isGraphqlEndpoint(string $path): bool
    {
        return preg_match('~^graphql(?:\.json)?$~', $path) === 1;
    }

    /**
     * What a named segment is allowed to match.
     *
     * REST ids are numeric, and a value the scanner could not read is rendered
     * as `{...}`. Allowing anything would make `products/{id}.json` swallow
     * `products/count.json`, which has a different answer in GraphQL.
     */
    private const NAMED_SEGMENT = '(?:\d+|\{\.\.\.\})';

    /** Turn a rule path such as `products/{id}.json` into a matcher. */
    public static function toPattern(string $path): string
    {
        $escaped = preg_quote($path, '~');
        $escaped = (string) preg_replace('~\\\\\{[a-z_]+\\\\\}~', self::NAMED_SEGMENT, $escaped);
        $escaped = (string) preg_replace('~\\\\\.json$~', '(?:\.json)?', $escaped);

        return '~^' . $escaped . '$~';
    }
}
