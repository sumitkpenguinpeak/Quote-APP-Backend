<?php

namespace App\Services;

use App\Models\Shop;

class ShopifyProductService
{
    private ShopifyGraphQLService $graphql;

    public function __construct(private Shop $shop)
    {
        $this->graphql = ShopifyGraphQLService::for($shop);
    }

    public static function for(Shop $shop): self
    {
        return new self($shop);
    }

    /**
     * Fetches a single product variant by its numeric Shopify ID -
     * useful to confirm the *current* price server-side rather than
     * trusting whatever price the storefront form submitted.
     */
    public function getVariant(string $variantId): ?array
    {
        $query = <<<'GRAPHQL'
            query getVariant($id: ID!) {
                productVariant(id: $id) {
                    id
                    title
                    price
                    sku
                    image { url }
                    product {
                        id
                        title
                        featuredImage { url }
                    }
                }
            }
        GRAPHQL;

        $data = $this->graphql->query($query, [
            'id' => "gid://shopify/ProductVariant/{$variantId}",
        ]);

        return $data['productVariant'] ?? null;
    }

    /**
     * Fetches a product with its variants - e.g. to power a "select variant"
     * step in the quote form when the customer only picked a product.
     */
    public function getProductWithVariants(string $productId): ?array
    {
        $query = <<<'GRAPHQL'
            query getProduct($id: ID!) {
                product(id: $id) {
                    id
                    title
                    tags
                    featuredImage { url }
                    variants(first: 50) {
                        edges {
                            node {
                                id
                                title
                                price
                                sku
                            }
                        }
                    }
                }
            }
        GRAPHQL;

        $data = $this->graphql->query($query, [
            'id' => "gid://shopify/Product/{$productId}",
        ]);

        return $data['product'] ?? null;
    }
}
