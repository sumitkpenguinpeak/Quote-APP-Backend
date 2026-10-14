<?php

namespace App\Services;

use App\Models\Shop;
use Illuminate\Support\Facades\Log;

/**
 * App install thay tyare store ma  /pages/request-a-quote  page banave chhe
 * ane tema Quote App no section (app block) mukine theme template banave chhe.
 *
 * Steps:
 *  1. Main theme ma  templates/page.request-a-quote.json  upsert (app block sathe)
 *  2. Page (handle = request-a-quote, templateSuffix = request-a-quote) create
 *
 * Required scopes: write_content (pages), write_themes (template file).
 */
class ShopifyPageService
{
    public const PAGE_HANDLE = 'request-a-quote';
    public const PAGE_TITLE = 'Request a Quote';
    public const TEMPLATE_SUFFIX = 'request-a-quote';
    public const TEMPLATE_FILE = 'templates/page.request-a-quote.json';

    /**
     * Idempotent: reinstall par fari chalavso to duplicate page nahi bane.
     */
    public function setup(Shop $shop): array
    {
        $gql = ShopifyGraphQLService::for($shop);

        // Template fail thay (e.g. write_themes nathi, theme OS2 nathi) to pan page to banavo.
        try {
            $templateOk = $this->upsertTemplate($gql, $shop);
        } catch (\Throwable $e) {
            Log::warning('ShopifyPageService: template step failed', ['shop' => $shop->shop, 'error' => $e->getMessage()]);
            $templateOk = false;
        }

        $pageId = $this->ensurePage($gql, $shop);

        return ['template' => $templateOk, 'page_id' => $pageId];
    }

    /**
     * Theme template: page.request-a-quote.json  (app block sathe)
     */
    protected function upsertTemplate(ShopifyGraphQLService $gql, Shop $shop): bool
    {
        $themeId = $this->mainThemeId($gql);
        if (! $themeId) {
            Log::warning('ShopifyPageService: main theme not found', ['shop' => $shop->shop]);
            return false;
        }

        // .env ma SHOPIFY_APP_HANDLE / SHOPIFY_THEME_EXTENSION_UID na hoy to app block no type khoto banse
        if (! config('shopify.page.app_handle') || ! config('shopify.page.extension_uid')) {
            Log::warning('ShopifyPageService: SHOPIFY_APP_HANDLE / SHOPIFY_THEME_EXTENSION_UID missing in .env', ['shop' => $shop->shop]);
            return false;
        }

        // shopify://apps/<app-handle>/blocks/<block-handle>/<extension-uid>
        $blockType = sprintf(
            'shopify://apps/%s/blocks/%s/%s',
            config('shopify.page.app_handle'),
            config('shopify.page.block_handle', 'quote-page'),
            config('shopify.page.extension_uid')
        );

        $template = [
            'sections' => [
                'quote_app' => [
                    'type' => 'apps',
                    'blocks' => [
                        'quote_widget' => ['type' => $blockType, 'settings' => new \stdClass()],
                    ],
                    'block_order' => ['quote_widget'],
                    'settings' => new \stdClass(),
                ],
            ],
            'order' => ['quote_app'],
        ];

        $data = $gql->query(<<<'GRAPHQL'
        mutation themeFilesUpsert($themeId: ID!, $files: [OnlineStoreThemeFilesUpsertFileInput!]!) {
            themeFilesUpsert(themeId: $themeId, files: $files) {
                upsertedThemeFiles { filename }
                userErrors { field message code }
            }
        }
        GRAPHQL, [
            'themeId' => $themeId,
            'files' => [[
                'filename' => self::TEMPLATE_FILE,
                'body' => ['type' => 'TEXT', 'value' => json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)],
            ]],
        ]);

        $errors = $data['themeFilesUpsert']['userErrors'] ?? [];
        if ($errors) {
            Log::warning('ShopifyPageService: template upsert failed', ['shop' => $shop->shop, 'errors' => $errors]);
            return false;
        }

        return true;
    }

    protected function mainThemeId(ShopifyGraphQLService $gql): ?string
    {
        $data = $gql->query('query { themes(first: 1, roles: [MAIN]) { nodes { id } } }');

        return $data['themes']['nodes'][0]['id'] ?? null;
    }

    /**
     * Page already hoy to tena ne j vapre, nahitar create kare.
     */
    protected function ensurePage(ShopifyGraphQLService $gql, Shop $shop): ?string
    {
        $existing = $gql->query(
            'query($q: String!) { pages(first: 1, query: $q) { nodes { id templateSuffix } } }',
            ['q' => 'handle:' . self::PAGE_HANDLE]
        )['pages']['nodes'][0] ?? null;

        if ($existing) {
            if (($existing['templateSuffix'] ?? null) !== self::TEMPLATE_SUFFIX) {
                $gql->query(<<<'GRAPHQL'
                mutation pageUpdate($id: ID!, $page: PageUpdateInput!) {
                    pageUpdate(id: $id, page: $page) { page { id } userErrors { field message } }
                }
                GRAPHQL, ['id' => $existing['id'], 'page' => ['templateSuffix' => self::TEMPLATE_SUFFIX]]);
            }

            return $existing['id'];
        }

        $data = $gql->query(<<<'GRAPHQL'
        mutation pageCreate($page: PageCreateInput!) {
            pageCreate(page: $page) {
                page { id handle }
                userErrors { field message code }
            }
        }
        GRAPHQL, [
            'page' => [
                'title' => self::PAGE_TITLE,
                'handle' => self::PAGE_HANDLE,
                'body' => '',
                'isPublished' => true,
                'templateSuffix' => self::TEMPLATE_SUFFIX,
            ],
        ]);

        $errors = $data['pageCreate']['userErrors'] ?? [];
        if ($errors) {
            Log::warning('ShopifyPageService: page create failed', ['shop' => $shop->shop, 'errors' => $errors]);
            return null;
        }

        return $data['pageCreate']['page']['id'] ?? null;
    }
}
