import ts from 'typescript';
import { normalisePath } from './path-pattern.js';
import type { HttpMethod, RestCallSite } from './types.js';

const REST_METHOD_NAMES: ReadonlyMap<string, HttpMethod> = new Map([
  ['get', 'GET'],
  ['post', 'POST'],
  ['put', 'PUT'],
  ['delete', 'DELETE'],
  ['del', 'DELETE'],
]);

/**
 * REST resource classes in shopify-api-js are named after the singular
 * resource. The REST path is the plural. Only the irregular ones need
 * spelling out; the rest take a trailing `s`.
 */
const IRREGULAR_PLURALS: ReadonlyMap<string, string> = new Map([
  ['Country', 'countries'],
  ['InventoryLevel', 'inventory_levels'],
  ['InventoryItem', 'inventory_items'],
  ['PriceRule', 'price_rules'],
  ['SmartCollection', 'smart_collections'],
  ['CustomCollection', 'custom_collections'],
  ['DraftOrder', 'draft_orders'],
  ['GiftCard', 'gift_cards'],
  ['ScriptTag', 'script_tags'],
  ['Policy', 'policies'],
]);

function restPathForResourceClass(className: string): string | undefined {
  const irregular = IRREGULAR_PLURALS.get(className);
  if (irregular !== undefined) return irregular;
  if (!/^[A-Z][A-Za-z]*$/.test(className)) return undefined;
  const snake = className.replace(/([a-z0-9])([A-Z])/g, '$1_$2').toLowerCase();
  return `${snake}s`;
}

function textOfStringLike(node: ts.Node): string | undefined {
  if (ts.isStringLiteralLike(node)) return node.text;
  if (ts.isTemplateExpression(node)) {
    // A template with substitutions still tells us the prefix, which is where
    // the `/admin/api/{version}/` marker lives. The tail is reported as a
    // wildcard so the reader knows part of it was built at runtime.
    const head = node.head.text;
    const spans = node.templateSpans.map((span) => `{...}${span.literal.text}`).join('');
    return `${head}${spans}`;
  }
  return undefined;
}

function findProperty(object: ts.ObjectLiteralExpression, name: string): ts.Expression | undefined {
  for (const property of object.properties) {
    if (!ts.isPropertyAssignment(property)) continue;
    const key = property.name;
    const keyText = ts.isIdentifier(key) || ts.isStringLiteralLike(key) ? key.text : undefined;
    if (keyText === name) return property.initializer;
  }
  return undefined;
}

/** Walk up looking for a `method: 'POST'` sitting in an options object. */
function methodFromEnclosingOptions(node: ts.Node): HttpMethod | undefined {
  let current: ts.Node | undefined = node;
  // Three levels is enough to climb out of a property assignment into the
  // options object that holds it, without wandering into an unrelated scope.
  for (let depth = 0; current !== undefined && depth < 6; depth += 1) {
    if (ts.isObjectLiteralExpression(current)) {
      const method = findProperty(current, 'method');
      if (method !== undefined && ts.isStringLiteralLike(method)) {
        const upper = method.text.toUpperCase();
        if (upper === 'GET' || upper === 'POST' || upper === 'PUT' || upper === 'DELETE') return upper;
      }
    }
    current = current.parent;
  }
  return undefined;
}

/**
 * `fetch(url, {method: 'PUT'})` keeps the method in a sibling argument, not an
 * ancestor, so walking up the tree never finds it.
 */
function methodFromSiblingArgument(node: ts.Node): HttpMethod | undefined {
  const parent = node.parent;
  if (parent === undefined || !ts.isCallExpression(parent)) return undefined;
  if (!parent.arguments.includes(node as ts.Expression)) return undefined;

  for (const argument of parent.arguments) {
    if (argument === node || !ts.isObjectLiteralExpression(argument)) continue;
    const method = findProperty(argument, 'method');
    if (method === undefined || !ts.isStringLiteralLike(method)) continue;
    const upper = method.text.toUpperCase();
    if (upper === 'GET' || upper === 'POST' || upper === 'PUT' || upper === 'DELETE') return upper;
  }
  return undefined;
}

/** `axios.post(url)` and friends carry the method in the callee name. */
function methodFromEnclosingCall(node: ts.Node): HttpMethod | undefined {
  let current: ts.Node | undefined = node.parent;
  for (let depth = 0; current !== undefined && depth < 4; depth += 1) {
    if (ts.isCallExpression(current) && ts.isPropertyAccessExpression(current.expression)) {
      const method = REST_METHOD_NAMES.get(current.expression.name.text);
      if (method !== undefined) return method;
    }
    current = current.parent;
  }
  return undefined;
}

function trimEvidence(text: string): string {
  const single = text.replace(/\s+/g, ' ').trim();
  return single.length > 120 ? `${single.slice(0, 117)}...` : single;
}

interface Emitter {
  (
    site: Omit<RestCallSite, 'file' | 'line' | 'column'>,
    node: ts.Node,
    /** Nodes consumed by this match, so a later detector does not report them again. */
    consumed?: readonly ts.Node[],
  ): void;
}

/**
 * The GraphQL endpoint lives under the same `/admin/api/{version}/` prefix.
 * Reporting it as REST to migrate would be exactly wrong.
 */
function isGraphqlEndpoint(path: string): boolean {
  return /^graphql(?:\.json)?$/.test(path);
}

/**
 * Find Shopify REST Admin API calls in one TypeScript or JavaScript file.
 *
 * Detection is syntactic. It reads what the source says rather than what it
 * would do at runtime, so a path assembled from variables is reported with
 * `likely` confidence and a wildcard in place of the parts it could not read.
 */
export function detectInSource(fileName: string, sourceText: string): RestCallSite[] {
  const sourceFile = ts.createSourceFile(
    fileName,
    sourceText,
    ts.ScriptTarget.Latest,
    /* setParentNodes */ true,
    fileName.endsWith('.tsx') || fileName.endsWith('.jsx') ? ts.ScriptKind.TSX : undefined,
  );

  const found: RestCallSite[] = [];
  const claimed = new Set<number>();

  const emit: Emitter = (site, node, consumed) => {
    if (claimed.has(node.pos)) return;
    claimed.add(node.pos);
    for (const extra of consumed ?? []) claimed.add(extra.pos);
    const { line, character } = sourceFile.getLineAndCharacterOfPosition(node.getStart(sourceFile));
    found.push({ ...site, file: fileName, line: line + 1, column: character + 1 });
  };

  const visit = (node: ts.Node): void => {
    detectResourceClassCall(node, emit);
    detectClientPathCall(node, emit);
    detectUrlLiteral(node, emit);
    ts.forEachChild(node, visit);
  };

  visit(sourceFile);
  return found.sort((a, b) => a.line - b.line || a.column - b.column);
}

/** `admin.rest.resources.Product.find({...})`, and the `client.rest` variants. */
function detectResourceClassCall(node: ts.Node, emit: Emitter): void {
  if (!ts.isCallExpression(node)) return;
  const callee = node.expression;
  if (!ts.isPropertyAccessExpression(callee)) return;

  const action = callee.name.text;
  const target = callee.expression;
  if (!ts.isPropertyAccessExpression(target)) return;

  const className = target.name.text;
  const chain = target.expression.getText();
  if (!/\brest\b.*\bresources\b/.test(chain) && !/\bresources\b/.test(chain)) return;

  const resourcePath = restPathForResourceClass(className);
  if (resourcePath === undefined) return;

  // The id is a runtime value, so it uses the same wildcard every other
  // detector emits. Writing `{id}` here would not match the rules, which
  // expect a numeric id or that wildcard.
  const byAction: Record<string, { method: HttpMethod; path: string } | undefined> = {
    find: { method: 'GET', path: `${resourcePath}/{...}.json` },
    all: { method: 'GET', path: `${resourcePath}.json` },
    count: { method: 'GET', path: `${resourcePath}/count.json` },
    save: { method: 'POST', path: `${resourcePath}.json` },
    delete: { method: 'DELETE', path: `${resourcePath}/{...}.json` },
  };

  const mapped = byAction[action];
  if (mapped === undefined) return;

  emit(
    {
      method: mapped.method,
      path: mapped.path,
      detector: 'rest-resource-class',
      evidence: trimEvidence(node.getText()),
      // `save` is create or update depending on whether the instance already
      // had an id, which is a runtime fact this pass cannot see.
      confidence: action === 'save' ? 'likely' : 'certain',
    },
    node,
  );
}

/** `client.get({path: 'products'})` and `admin.rest.post({path, data})`. */
function detectClientPathCall(node: ts.Node, emit: Emitter): void {
  if (!ts.isCallExpression(node)) return;
  const callee = node.expression;
  if (!ts.isPropertyAccessExpression(callee)) return;

  const method = REST_METHOD_NAMES.get(callee.name.text);
  if (method === undefined) return;

  const [first] = node.arguments;
  if (first === undefined || !ts.isObjectLiteralExpression(first)) return;

  const pathExpression = findProperty(first, 'path');
  if (pathExpression === undefined) return;

  const raw = textOfStringLike(pathExpression);
  if (raw === undefined) return;

  const normalised = normalisePath(raw);
  if (normalised === undefined || isGraphqlEndpoint(normalised.path)) return;

  emit(
    {
      method,
      path: normalised.path,
      ...(normalised.apiVersion !== undefined ? { apiVersion: normalised.apiVersion } : {}),
      detector: 'client-path-option',
      evidence: trimEvidence(node.getText()),
      confidence: raw.includes('{...}') ? 'likely' : 'certain',
    },
    node,
    [pathExpression],
  );
}

/** A URL string anywhere that names `/admin/api/{version}/`. */
function detectUrlLiteral(node: ts.Node, emit: Emitter): void {
  if (!ts.isStringLiteralLike(node) && !ts.isTemplateExpression(node)) return;

  const raw = textOfStringLike(node);
  if (raw === undefined || !raw.includes('/admin/api/')) return;

  const normalised = normalisePath(raw);
  if (normalised === undefined || isGraphqlEndpoint(normalised.path)) return;

  const method =
    methodFromSiblingArgument(node) ??
    methodFromEnclosingOptions(node) ??
    methodFromEnclosingCall(node) ??
    'GET';

  emit(
    {
      method,
      path: normalised.path,
      ...(normalised.apiVersion !== undefined ? { apiVersion: normalised.apiVersion } : {}),
      detector: 'admin-url-literal',
      evidence: trimEvidence(raw),
      confidence: raw.includes('{...}') ? 'likely' : 'certain',
    },
    node,
  );
}
