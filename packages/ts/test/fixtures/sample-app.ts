// Fixture, not real code. Every call here is one the scanner should find,
// except the GraphQL one, which it must leave alone.

declare const admin: any;
declare const session: any;
declare const axios: any;
declare const client: any;

export async function readProduct() {
  const url = 'https://shop.myshopify.com/admin/api/2024-10/products/1234.json';
  const response = await fetch(url, {
    method: 'GET',
    headers: { 'X-Shopify-Access-Token': 'token' },
  });
  return response.json();
}

export async function createProduct() {
  return axios.post('https://shop.myshopify.com/admin/api/2024-10/products.json', {
    product: { title: 'My new product' },
  });
}

export async function updateVariant(variantId: number) {
  return fetch(`https://shop.myshopify.com/admin/api/2024-10/variants/${variantId}.json`, {
    method: 'PUT',
    body: JSON.stringify({ variant: { price: '24.99' } }),
  });
}

export async function listCustomers() {
  return client.get({ path: 'customers' });
}

export async function deleteCustomer() {
  return client.delete({ path: 'customers/6201722765389.json' });
}

export async function findProductViaResourceClass() {
  return admin.rest.resources.Product.find({ session, id: 1234 });
}

export async function setInventory() {
  return admin.rest.post({
    path: 'inventory_levels/set.json',
    data: { location_id: 1, inventory_item_id: 2, available: 42 },
  });
}

export async function alreadyMigrated() {
  // This one is already GraphQL. It must not appear in the report.
  return fetch('https://shop.myshopify.com/admin/api/2024-10/graphql.json', {
    method: 'POST',
    body: JSON.stringify({ query: '{ shop { name } }' }),
  });
}
