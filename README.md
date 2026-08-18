# Shopify REST To GraphQL

Scans a codebase for Shopify REST Admin API calls and prints the GraphQL operation that replaces each one.

Shopify made the REST Admin API legacy on 1 October 2024. New public apps have had to use GraphQL since 1 April 2025. There is a migration guide but no endpoint by endpoint mapping.

This does not rewrite your code. It prints a report.

## Install

```
npm install --save-dev @edm-uk/shopify-rest-to-graphql
npx shopify-rest-to-graphql ./src
```

```
composer require --dev edm-uk/shopify-rest-to-graphql
vendor/bin/shopify-rest-to-graphql ./app
```

## Output

```
$ shopify-rest-to-graphql ./app

direct   GET    products/1234.json          product                    api/products.ts:10
partial  POST   products.json               productCreate              api/products.ts:19
partial  PUT    variants/{...}.json         productVariantsBulkUpdate  api/variants.ts:25
direct   DELETE customers/1234.json         customerDelete             api/customers.ts:36
partial  POST   inventory_levels/set.json   inventorySetQuantities     jobs/sync.ts:44

5 calls: 2 direct, 3 partial, 0 manual, 0 unmapped.
```

- `direct` swaps over as is.
- `partial` works, but the call site needs more than a transport change.
- `manual` has no single replacement.
- `unmapped` is not covered by a rule here.

Use `--format=markdown` for a report with the full operation, example variables and the notes for each call.

## Options

```
--format=<text|json|markdown>
--mappings=<dir>
--exclude=<name>
--fail-on=<none|any|unmapped>
```

`--fail-on=any` in CI stops new REST calls landing in a codebase you are part way through migrating.

## What It Detects

TypeScript and JavaScript, parsed with the TypeScript compiler API:

- URLs containing `/admin/api/{version}/`. The verb comes from `fetch(url, {method})`, from an `axios.post(...)` callee, or from an enclosing options object.
- `client.get({path: 'products'})` and `admin.rest.post({path, data})`.
- `admin.rest.resources.Product.find({...})` and the other resource class actions.

PHP, parsed with nikic/php-parser:

- `$client->get($url)`, `$client->request('PUT', $url)`, `Http::put($url)`.
- `$this->request($endpoint, 'GET')` and `$this->doRequest(ApiMethod::GET(), $path)`, where the verb is the second argument or an enum constant.
- Guzzle service descriptions, where the verb is an `httpMethod` key beside the `uri`.
- Paths built by concatenation, such as `'products/' . $id . '.json'`.
- Local variables assigned a string earlier in the same function, such as `$endpoint = 'products.json'`.
- Interpolated URLs. Parts built at runtime show as `{...}`.

Calls to `graphql.json` are ignored, as are admin URLs that are not REST resources, such as `/admin/oauth/authorize`. A file that fails to parse is skipped and counted, not fatal.

## Limits

- Paths built from variables are marked `likely` with `{...}` where the value could not be read.
- A URL stored in a constant in another file is only found where the literal is written. Local variables are resolved in PHP within the same function, and not at all in TypeScript.
- Variable resolution does not follow branches, so a path reassigned in an `if` is reported as its first value, marked `likely`.
- `Resource.save()` is a create or an update depending on runtime state. It is reported as a create, marked `likely`.
- 20 rules covering products, variants, customers, orders, inventory and metafields. Anything else comes back `unmapped` rather than guessed. That includes `count` and `search` endpoints, which are deliberately not treated as single record reads.

Tested against real codebases, including `Shopify/shopify-app-js`, `robwittman/shopify-php-sdk`, `osiset/laravel-shopify`, `ShopifyExtras/PHP-Shopify-API-Wrapper` and `abecms/shopify-app-starter`.

## Mappings

Rules live in `mappings/` as JSON, one file per resource, shared by both packages. Each rule has the REST endpoint, the replacement operation, a runnable document, example variables, and notes.

Both loaders check that a rule's document actually calls the root field it names. A mapping that does not fails to load.

Each rule links the Shopify reference page it came from.

## Notes The Report Carries

Examples of what shows up against a call, taken from the reference pages:

- `productCreate` and `productUpdate` take a `product` argument. `input` is deprecated, though Shopify's migration examples still use it.
- Neither updates variants. That is `productVariantsBulkUpdate`, which needs the parent product id your REST call did not have.
- `productVariantsBulkUpdate` rejects the whole array if one variant is invalid, unless you pass `allowPartialUpdates`.
- The variant delete argument is `variantsIds`, not `variantIds`.
- Orders older than 60 days need the `read_all_orders` scope.
- `orders` sorts by `PROCESSED_AT`, not by id.
- Throttling returns `200` with errors in the body, not `429`.
- Mutations only report failures if you request `userErrors`.

## Development

```
cd packages/ts && npm install && npm test
cd packages/php && composer install && vendor/bin/phpunit
```

## Licence

MIT.

## Who Built It

Expert Digital Marketing Ltd  
14/2E Docklands Business Centre  
10-16 Tiller Road  
London E14 8PX  
United Kingdom  

+44 208 050 0701  
[edm-uk.com](https://edm-uk.com)

Registered in England and Wales. Company No. 12325320. VAT GB460577382.
