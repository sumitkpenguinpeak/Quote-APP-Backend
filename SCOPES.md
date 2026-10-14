# Access scopes - justification (Shopify review)

| Scope | Why the app needs it | Code |
|---|---|---|
| read_products | Merchant searches products/variants when building or editing a quote. | `ProductController`, `ShopifyProductService` |
| read_draft_orders / write_draft_orders | "Convert quote to draft order" and sync quote <-> draft order. | `ShopifyDraftOrderService`, `QuoteController::createDraftOrder` |
| read_themes | Dashboard checks whether the "Quote App Embed" app embed is enabled in the live theme (settings_data.json). | `DashboardController::appEmbed` |
| write_content | Creates the "Request a quote" page (`/pages/request-a-quote`) on install. | `ShopifyPageService::createPage` |
| write_themes | Creates the page template (`page.request-a-quote.json`) that renders our theme-app-extension block `quote-page`, so the page shows the quote form. | `ShopifyPageService` (`themeFilesUpsert`) |

Removed (not used by any code): `write_products`, `read_orders`.
After deploying, existing merchants are asked to re-approve once (fewer scopes never blocks them).
