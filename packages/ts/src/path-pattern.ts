import type { HttpMethod, MappingRule, RestCallSite } from './types.js';

/**
 * Turn a mapping path such as `products/{id}.json` into a matcher.
 *
 * Named segments match one path segment that is not a slash. The `.json` suffix
 * is optional on the observed path, because plenty of real code omits it and
 * Shopify serves both.
 */
/**
 * What a named segment is allowed to match.
 *
 * REST ids are numeric, and a value the scanner could not read is rendered as
 * `{...}`. Allowing anything would make `products/{id}.json` swallow
 * `products/count.json`, which has a different answer in GraphQL. Real
 * codebases hit that: a customer search and an order count were both being
 * reported as single record reads.
 */
const NAMED_SEGMENT = '(?:\\d+|\\{\\.\\.\\.\\})';

function toPattern(path: string): RegExp {
  const escaped = path
    .split('/')
    .map((segment) =>
      segment
        .replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
        .replace(/\\\{[a-z_]+\\\}/g, NAMED_SEGMENT),
    )
    .join('/');

  // Allow the trailing `.json` to be absent.
  const withOptionalSuffix = escaped.replace(/\\\.json$/, '(?:\\.json)?');
  return new RegExp(`^${withOptionalSuffix}$`);
}

const patternCache = new Map<string, RegExp>();

function patternFor(path: string): RegExp {
  let pattern = patternCache.get(path);
  if (pattern === undefined) {
    pattern = toPattern(path);
    patternCache.set(path, pattern);
  }
  return pattern;
}

/**
 * Strip everything that is not the resource path: the origin, the
 * `/admin/api/{version}/` prefix, any query string and any leading slash.
 *
 * Returns the path and the version when the URL carried one.
 */
export function normalisePath(raw: string): { path: string; apiVersion?: string } | undefined {
  let value = raw.trim();
  if (value === '') return undefined;

  // Drop the query string and fragment. Neither takes part in matching.
  value = value.split('#')[0] ?? '';
  value = value.split('?')[0] ?? '';

  // Drop scheme and host if present, remembering that there was one. A URL
  // pointing somewhere other than the Admin API is not a REST call to migrate,
  // so the bare resource form below must not rescue it.
  const withoutOrigin = value.replace(/^[a-z][a-z0-9+.-]*:\/\/[^/]+/i, '');
  const hadOrigin = withoutOrigin !== value;
  value = withoutOrigin;

  const versioned = /\/?admin\/api\/([^/]+)\/(.+)$/.exec(value);
  if (versioned) {
    const apiVersion = versioned[1];
    const path = versioned[2];
    if (apiVersion === undefined || path === undefined) return undefined;
    return { path: path.replace(/^\/+/, ''), apiVersion };
  }

  // `https://${shop}/admin/api/2020-04` with no resource after it is a base
  // URL that some helper appends to. It is not a call, and reporting it as one
  // puts a line in the plan that nobody can act on.
  if (/^\/?admin\/api\/[^/]+\/?$/.test(value)) return undefined;

  // Unversioned admin path. The `.json` suffix is required, because
  // `/admin/oauth/authorize` and `/admin/charges/{id}/confirm...` are browser
  // URLs on the same prefix and are not REST resources. Real codebases hit
  // this: an OAuth redirect was being reported as a call to migrate.
  const bare = /^\/?admin\/(.+\.json)$/.exec(value);
  if (bare) {
    const path = bare[1];
    if (path === undefined) return undefined;
    return { path: path.replace(/^\/+/, '') };
  }

  // Anything still carrying the admin prefix failed the checks above, so it is
  // not a REST resource. It must not fall through to the bare resource branch
  // and be reported as `admin/oauth/authorize`.
  if (/^\/?admin\//.test(value)) return undefined;

  // A bare resource path, as passed to `client.get({path: 'products'})`.
  // Only valid when the source did not name a host, and never with whitespace
  // in it, which is the signature of prose rather than a path.
  if (!hadOrigin && /^\/?[a-z0-9_]+(?:\/[^/\s]+)*(?:\.json)?$/i.test(value)) {
    return { path: value.replace(/^\/+/, '') };
  }

  return undefined;
}

/** True when a normalised path plus method is covered by the rule. */
export function ruleMatches(rule: MappingRule, method: HttpMethod, path: string): boolean {
  if (rule.rest.method !== method) return false;
  return patternFor(rule.rest.path).test(path);
}

/**
 * Find the rule for a call.
 *
 * Rules are checked in the order given. Mapping files list the specific paths
 * before the general ones, so `products/{id}.json` wins over anything broader.
 */
export function findRule(rules: readonly MappingRule[], call: RestCallSite): MappingRule | undefined {
  return rules.find((rule) => ruleMatches(rule, call.method, call.path));
}
