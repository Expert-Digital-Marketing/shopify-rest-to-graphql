<?php

declare(strict_types=1);

namespace EdmUk\ShopifyRestToGraphql\Tests;

use EdmUk\ShopifyRestToGraphql\Detector;
use EdmUk\ShopifyRestToGraphql\Finding;
use EdmUk\ShopifyRestToGraphql\MappingRepository;
use EdmUk\ShopifyRestToGraphql\PathNormaliser;
use EdmUk\ShopifyRestToGraphql\RestCallSite;
use EdmUk\ShopifyRestToGraphql\Scanner;
use PHPUnit\Framework\TestCase;

final class DetectorTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/fixtures/SampleApp.php';

    private const MAPPINGS = __DIR__ . '/../../../mappings';

    /** @return list<RestCallSite> */
    private function detect(): array
    {
        $source = file_get_contents(self::FIXTURE);
        self::assertIsString($source);

        return Detector::detect('SampleApp.php', $source);
    }

    private function callFor(string $path): ?RestCallSite
    {
        foreach ($this->detect() as $call) {
            if ($call->path === $path) {
                return $call;
            }
        }

        return null;
    }

    public function test_it_finds_a_get_from_a_client_method_call(): void
    {
        $call = $this->callFor('products/1234.json');

        self::assertNotNull($call);
        self::assertSame('GET', $call->method);
        self::assertSame('2024-10', $call->apiVersion);
        self::assertSame('certain', $call->confidence);
    }

    public function test_it_takes_the_verb_from_a_request_call(): void
    {
        $call = null;
        foreach ($this->detect() as $candidate) {
            if (str_starts_with($candidate->path, 'variants/')) {
                $call = $candidate;
            }
        }

        self::assertNotNull($call);
        self::assertSame('PUT', $call->method);
        self::assertSame('likely', $call->confidence, 'the id is interpolated, so the path is not fully known');
    }

    public function test_it_reads_an_interpolated_path(): void
    {
        $call = null;
        foreach ($this->detect() as $candidate) {
            if (str_starts_with($candidate->path, 'customers/')) {
                $call = $candidate;
            }
        }

        self::assertNotNull($call);
        self::assertSame('DELETE', $call->method);
        self::assertStringContainsString('{...}', $call->path);
    }

    public function test_it_leaves_the_graphql_endpoint_alone(): void
    {
        foreach ($this->detect() as $call) {
            self::assertStringNotContainsString('graphql', $call->path);
        }
    }

    public function test_it_reports_one_based_lines(): void
    {
        foreach ($this->detect() as $call) {
            self::assertGreaterThanOrEqual(1, $call->line);
        }
    }

    public function test_normaliser_strips_origin_version_and_query(): void
    {
        self::assertSame(
            ['path' => 'products.json', 'apiVersion' => '2024-10'],
            PathNormaliser::normalise('https://x.myshopify.com/admin/api/2024-10/products.json?limit=50'),
        );
        self::assertSame(
            ['path' => 'products/1234.json', 'apiVersion' => null],
            PathNormaliser::normalise('products/1234.json'),
        );
        self::assertNull(PathNormaliser::normalise('   '));
        self::assertNull(PathNormaliser::normalise('https://example.com/not/shopify at all'));
    }

    public function test_a_base_url_with_no_resource_is_not_a_call(): void
    {
        // Found in abecms/shopify-app-starter, where a helper builds the prefix
        // and the resource is appended later.
        self::assertNull(PathNormaliser::normalise('https://{...}/admin/api/2020-04'));
        self::assertNull(PathNormaliser::normalise('https://shop.myshopify.com/admin/api/2024-10/'));
        self::assertSame(
            ['path' => 'products.json', 'apiVersion' => '2024-10'],
            PathNormaliser::normalise('https://shop.myshopify.com/admin/api/2024-10/products.json'),
        );
    }

    public function test_a_named_segment_matches_an_id_not_an_action(): void
    {
        // Found in robwittman/shopify-php-sdk, where `products/{id}.json` was
        // swallowing `products/count.json` and `customers/search.json`.
        $repository = new MappingRepository(self::MAPPINGS);

        self::assertSame('product.get', $repository->find('GET', 'products/632910392.json')?->id);
        self::assertSame('product.get', $repository->find('GET', 'products/{...}.json')?->id);
        self::assertNull($repository->find('GET', 'products/count.json'));
        self::assertNull($repository->find('GET', 'customers/search.json'));
    }

    public function test_local_string_variables_are_resolved(): void
    {
        // `$endpoint = 'products.json';` then `$this->request($endpoint, 'GET')`
        // is the dominant idiom in PHP Shopify wrappers.
        $source = <<<'PHP'
        <?php
        final class ProductService
        {
            public function all($client)
            {
                $endpoint = 'products.json';

                return $client->request($endpoint, 'GET');
            }
        }
        PHP;

        $calls = Detector::detect('ProductService.php', $source);

        self::assertCount(1, $calls);
        self::assertSame('GET', $calls[0]->method);
        self::assertSame('products.json', $calls[0]->path);
        self::assertSame('likely', $calls[0]->confidence);
    }

    public function test_a_bare_word_is_not_a_path(): void
    {
        // `$request->get('state')` in an OAuth helper is not a Shopify call.
        $source = <<<'PHP'
        <?php
        $state = $request->get('state');
        $shop = $session->get('shop');
        PHP;

        self::assertSame([], Detector::detect('OAuthHelper.php', $source));
    }

    public function test_an_unversioned_admin_path_needs_the_json_suffix(): void
    {
        // Found in osiset/laravel-shopify, which writes `/admin/script_tags.json`
        // for the API and `/admin/oauth/authorize` for the browser redirect.
        self::assertSame(
            ['path' => 'script_tags.json', 'apiVersion' => null],
            PathNormaliser::normalise('/admin/script_tags.json'),
        );
        self::assertNull(PathNormaliser::normalise('https://shop.myshopify.com/admin/oauth/authorize'));
        self::assertNull(PathNormaliser::normalise('/admin/charges/1029266947/confirm_recurring_application_charge'));
    }

    public function test_the_verb_is_read_from_an_enum_constant(): void
    {
        // osiset/laravel-shopify passes `ApiMethod::GET()` rather than a string.
        $source = <<<'PHP'
        <?php
        $response = $this->doRequest(
            ApiMethod::POST(),
            '/admin/script_tags.json',
            $payload
        );
        PHP;

        $calls = Detector::detect('ApiHelper.php', $source);

        self::assertCount(1, $calls);
        self::assertSame('POST', $calls[0]->method);
        self::assertSame('script_tags.json', $calls[0]->path);
    }

    public function test_the_verb_is_read_from_an_http_method_key(): void
    {
        // Guzzle service descriptions, as used by PHP-Shopify-API-Wrapper.
        // Without this every entry in the file reads as a GET.
        $source = <<<'PHP'
        <?php
        return array(
            "createProduct" => array(
                "httpMethod" => "POST",
                "uri" => "/admin/products.json",
            ),
        );
        PHP;

        $calls = Detector::detect('product.php', $source);

        self::assertCount(1, $calls);
        self::assertSame('POST', $calls[0]->method);
        self::assertSame('products.json', $calls[0]->path);
    }

    public function test_findings_carry_the_rule_that_replaces_them(): void
    {
        $source = file_get_contents(self::FIXTURE);
        self::assertIsString($source);

        $scanner = new Scanner(new MappingRepository(self::MAPPINGS));
        $findings = $scanner->scanSource('SampleApp.php', $source);

        $byPath = [];
        foreach ($findings as $finding) {
            $byPath[$finding->call->path] = $finding;
        }

        self::assertArrayHasKey('products/1234.json', $byPath);
        $productRead = $byPath['products/1234.json'];
        self::assertInstanceOf(Finding::class, $productRead);
        self::assertNotNull($productRead->rule);
        self::assertSame('product.get', $productRead->rule->id);
        self::assertSame('product', $productRead->rule->rootField);

        self::assertArrayHasKey('inventory_levels/set.json', $byPath);
        self::assertSame('inventorySetQuantities', $byPath['inventory_levels/set.json']->rule?->rootField);
    }

    public function test_the_two_packages_agree_on_the_same_rules(): void
    {
        $repository = new MappingRepository(self::MAPPINGS);

        // Every rule that claims a replacement must carry a document that
        // actually calls it. The loader enforces this, so reaching here at all
        // means every shipped mapping passed.
        foreach ($repository->rules() as $rule) {
            if ($rule->hasReplacement()) {
                self::assertNotNull($rule->document);
                self::assertMatchesRegularExpression(
                    '~\b' . preg_quote((string) $rule->rootField, '~') . '\s*\(~',
                    $rule->document,
                );
            }
        }

        self::assertNotEmpty($repository->rules());
    }
}
