<?php

declare(strict_types=1);

// Fixture, not real code. Every call here is one the scanner should find,
// except the GraphQL one, which it must leave alone.

namespace Fixtures;

final class SampleApp
{
    public function __construct(private readonly object $client)
    {
    }

    public function readProduct(): mixed
    {
        return $this->client->get('https://shop.myshopify.com/admin/api/2024-10/products/1234.json');
    }

    public function createProduct(): mixed
    {
        return $this->client->post('https://shop.myshopify.com/admin/api/2024-10/products.json', [
            'json' => ['product' => ['title' => 'My new product']],
        ]);
    }

    public function updateVariant(int $variantId): mixed
    {
        return $this->client->request(
            'PUT',
            "https://shop.myshopify.com/admin/api/2024-10/variants/{$variantId}.json",
            ['json' => ['variant' => ['price' => '24.99']]],
        );
    }

    public function deleteCustomer(int $customerId): mixed
    {
        return $this->client->delete("https://shop.myshopify.com/admin/api/2024-10/customers/{$customerId}.json");
    }

    public function setInventory(): mixed
    {
        return $this->client->post('https://shop.myshopify.com/admin/api/2024-10/inventory_levels/set.json', [
            'json' => ['location_id' => 1, 'inventory_item_id' => 2, 'available' => 42],
        ]);
    }

    public function alreadyMigrated(): mixed
    {
        // This one is already GraphQL. It must not appear in the report.
        return $this->client->post('https://shop.myshopify.com/admin/api/2024-10/graphql.json', [
            'json' => ['query' => '{ shop { name } }'],
        ]);
    }
}
