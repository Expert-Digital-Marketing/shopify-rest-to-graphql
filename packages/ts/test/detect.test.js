import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { detectInSource, normalisePath, allRules, loadMappings, scanSource, findRule } from '../dist/index.js';

const here = dirname(fileURLToPath(import.meta.url));
const fixture = readFileSync(join(here, 'fixtures', 'sample-app.ts'), 'utf8');
const rules = allRules(loadMappings(join(here, '..', '..', '..', 'mappings')));

function detect() {
  return detectInSource('sample-app.ts', fixture);
}

test('finds a GET from a plain URL literal', () => {
  const hit = detect().find((call) => call.path === 'products/1234.json');
  assert.ok(hit, 'expected the single product read to be found');
  assert.equal(hit.method, 'GET');
  assert.equal(hit.apiVersion, '2024-10');
  assert.equal(hit.confidence, 'certain');
});

test('takes the method from the callee when axios names it', () => {
  const hit = detect().find((call) => call.path === 'products.json');
  assert.ok(hit);
  assert.equal(hit.method, 'POST');
});

test('reads a template literal and marks the runtime part as likely', () => {
  const hit = detect().find((call) => call.path.startsWith('variants/'));
  assert.ok(hit);
  assert.equal(hit.method, 'PUT');
  assert.equal(hit.confidence, 'likely');
});

test('finds the client path option form', () => {
  const list = detect().find((call) => call.path === 'customers');
  assert.ok(list);
  assert.equal(list.method, 'GET');
  assert.equal(list.detector, 'client-path-option');

  const remove = detect().find((call) => call.path === 'customers/6201722765389.json');
  assert.ok(remove);
  assert.equal(remove.method, 'DELETE');
});

test('finds a REST resource class call', () => {
  const hit = detect().find((call) => call.detector === 'rest-resource-class');
  assert.ok(hit);
  assert.equal(hit.method, 'GET');
  assert.equal(hit.path, 'products/{...}.json');
});

test('leaves the GraphQL endpoint alone', () => {
  const hits = detect().filter((call) => call.path.includes('graphql'));
  assert.deepEqual(hits, []);
});

test('does not report the same call twice', () => {
  const seen = new Set();
  for (const call of detect()) {
    const key = `${call.line}:${call.column}`;
    assert.ok(!seen.has(key), `duplicate finding at ${key}`);
    seen.add(key);
  }
});

test('every call site reports one based line numbers', () => {
  for (const call of detect()) {
    assert.ok(call.line >= 1, 'lines start at one');
    assert.ok(call.column >= 1, 'columns start at one');
  }
});

test('normalisePath strips origin, version and query string', () => {
  assert.deepEqual(normalisePath('https://x.myshopify.com/admin/api/2024-10/products.json?limit=50'), {
    path: 'products.json',
    apiVersion: '2024-10',
  });
  assert.deepEqual(normalisePath('products/1234.json'), { path: 'products/1234.json' });
  assert.equal(normalisePath('   '), undefined);
  assert.equal(normalisePath('https://example.com/not/shopify at all'), undefined);
});

test('a base URL with no resource is not a call', () => {
  // Found in abecms/shopify-app-starter, where a helper builds the prefix and
  // the resource is appended later.
  assert.equal(normalisePath('https://{...}/admin/api/2020-04'), undefined);
  assert.equal(normalisePath('https://shop.myshopify.com/admin/api/2024-10/'), undefined);
  assert.deepEqual(normalisePath('https://shop.myshopify.com/admin/api/2024-10/products.json'), {
    path: 'products.json',
    apiVersion: '2024-10',
  });
});

test('an unversioned admin path needs the json suffix', () => {
  // Found in osiset/laravel-shopify, which writes `/admin/script_tags.json`
  // for the API and `/admin/oauth/authorize` for the browser redirect.
  assert.deepEqual(normalisePath('/admin/script_tags.json'), { path: 'script_tags.json' });
  assert.equal(normalisePath('https://shop.myshopify.com/admin/oauth/authorize'), undefined);
  assert.equal(normalisePath('/admin/charges/1029266947/confirm_recurring_application_charge'), undefined);
});

test('a named segment matches an id, not an action', () => {
  // Found in robwittman/shopify-php-sdk, where `products/{id}.json` was
  // swallowing `products/count.json` and `customers/search.json`.
  const single = { method: 'GET', path: 'products/632910392.json', file: 'x', line: 1, column: 1, detector: 't', evidence: '', confidence: 'certain' };
  const count = { ...single, path: 'products/count.json' };
  const search = { ...single, path: 'customers/search.json' };
  const unread = { ...single, path: 'products/{...}.json' };

  assert.equal(findRule(rules, single)?.id, 'product.get');
  assert.equal(findRule(rules, unread)?.id, 'product.get');
  assert.equal(findRule(rules, count), undefined);
  assert.equal(findRule(rules, search), undefined);
});

test('findings carry the rule that replaces them', () => {
  const findings = scanSource('sample-app.ts', fixture, rules);
  const productRead = findings.find((finding) => finding.call.path === 'products/1234.json');
  assert.ok(productRead?.rule, 'the single product read should match a rule');
  assert.equal(productRead.rule.id, 'product.get');
  assert.equal(productRead.rule.graphql.rootField, 'product');

  const inventory = findings.find((finding) => finding.call.path === 'inventory_levels/set.json');
  assert.ok(inventory?.rule);
  assert.equal(inventory.rule.graphql.rootField, 'inventorySetQuantities');
});
