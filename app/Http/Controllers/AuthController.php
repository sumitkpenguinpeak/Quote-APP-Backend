<?php
namespace App\Http\Controllers;

use App\Jobs\SetupQuotePage;
use App\Models\Shop;
use App\Services\ShopDefaultsService;
use App\Services\ShopifyService;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Class AuthController
 *
 * Handles the full Shopify OAuth 2.0 installation flow:
 *   1. GET /api/install       - redirects the merchant to Shopify's consent screen
 *   2. GET /api/auth/callback - exchanges the auth code for an access token
 */
class AuthController extends Controller
{
    public function __construct(protected ShopifyService $shopifyService)
    {
    }

    /**
     * Step 1: Begin installation.
     *
     * Validates the shop parameter, generates a CSRF-protecting state
     * value, stores it in the session, and redirects the merchant to
     * Shopify's OAuth consent screen.
     */
    public function install(Request $request): RedirectResponse|JsonResponse|Response
    {
        $request->validate([
            'shop' => ['required', 'string'],
        ]);

        $shop = strtolower(trim($request->query('shop')));

        if (! $this->shopifyService->isValidShopDomain($shop)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid shop domain. Expected format: your-store.myshopify.com',
            ], 422);
        }

        // Shopify (managed install / app load) signed request moklay chhe, tene verify karvu.
        // Direct URL (?shop=...) ma hmac hoto nathi, te allow chhe: callback ma state + HMAC check thay j chhe.
        if ($request->query->has('hmac')) {
            $timestamp = (int) $request->query('timestamp', 0);
            if (
                ! $this->shopifyService->verifyHmac($request->query())
                || $timestamp <= 0
                || abs(time() - $timestamp) > 3600
            ) {
                return response()->json(['success' => false, 'message' => 'Invalid install request. Open the app from your Shopify admin.'], 401);
            }
        }

        // Already installed with the current scopes -> straight into the app.
        $existing = Shop::where('shop', $shop)->first();
        if ($existing && $existing->is_active && $existing->access_token && $this->scopesMatch($existing->scope)) {
            return redirect()->away($this->shopifyService->adminAppUrl($shop, $existing->hasSelectedPlan() ? '/dashboard' : '/pricing'));
        }

        $state = $this->makeState($shop);
        $authorizationUrl = $this->shopifyService->buildAuthorizationUrl($shop, $state);

        return response()->view('shopify.redirect', ['url' => $authorizationUrl]);
    }

    /**
     * Step 2: OAuth callback.
     *
     * Verifies HMAC + state, exchanges the code for an access token,
     * persists (or reinstalls) the shop record, registers webhooks, and
     * redirects the merchant into the embedded app dashboard.
     */
    public function callback(Request $request): RedirectResponse|JsonResponse
    {
        $request->validate([
            'shop' => ['required', 'string'],
            'code' => ['required', 'string'],
            'state' => ['required', 'string'],
            'hmac' => ['required', 'string'],
        ]);

        $shop = strtolower(trim($request->query('shop')));

        if (! $this->shopifyService->isValidShopDomain($shop)) {
            return response()->json(['success' => false, 'message' => 'Invalid shop domain.'], 422);
        }

        // Verify the HMAC signature Shopify attached to this request.
        if (! $this->shopifyService->verifyHmac($request->query())) {
            Log::warning('Shopify OAuth callback failed HMAC verification', ['shop' => $shop]);

            return response()->json(['success' => false, 'message' => 'HMAC validation failed.'], 401);
        }

        // Verify the signed, single-use, expiring state (no cookie/session needed,
        // so it also works when third-party cookies are blocked).
        if (! $this->verifyState((string) $request->query('state'), $shop)) {
            Log::warning('Shopify OAuth callback failed state verification', ['shop' => $shop]);

            return response()->json(['success' => false, 'message' => 'Invalid OAuth state.'], 401);
        }

        try {
            $tokenResponse = $this->shopifyService->exchangeAccessToken($shop, $request->query('code'));
        } catch (GuzzleException $exception) {
            Log::error('Failed to exchange Shopify access token', [
                'shop' => $shop,
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => 'Failed to authenticate with Shopify.'], 502);
        }

        $accessToken = $tokenResponse['access_token'] ?? null;
        $scope = $tokenResponse['scope'] ?? null;
        $refreshToken = $tokenResponse['refresh_token'] ?? null;
        $expiresIn = $tokenResponse['expires_in'] ?? null;
        $refreshExpiresIn = $tokenResponse['refresh_token_expires_in'] ?? null;

        if (! $accessToken) {
            return response()->json(['success' => false, 'message' => 'Shopify did not return an access token.'], 502);
        }

        $shopifyPlan = null;

        try {
            $details = $this->shopifyService->getShopDetails($shop, $accessToken);
            $shopifyPlan = $details['plan']['displayName'] ?? null;
        } catch (GuzzleException $exception) {
            Log::warning('Failed to fetch shop details after install', [
                'shop' => $shop,
                'error' => $exception->getMessage(),
            ]);
        }

        // A reinstall always requires the merchant to re-approve billing,
        // since Shopify cancels all subscriptions on uninstall.
        $existingShop = Shop::where('shop', $shop)->first();
        $isReinstall = $existingShop && ! $existingShop->is_active;

        // Upsert: this correctly handles both a fresh install and a
        // reinstall of a previously uninstalled shop.
        $shopModel = Shop::updateOrCreate(
            ['shop' => $shop],
            [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'access_token_expires_at' => $expiresIn ? now()->addSeconds((int) $expiresIn) : null,
                'refresh_token_expires_at' => $refreshExpiresIn ? now()->addSeconds((int) $refreshExpiresIn) : null,
                'scope' => $scope,
                'shopify_plan' => $shopifyPlan,
                'is_active' => true,
                'installed_at' => now(),
                'uninstalled_at' => null,
                // Navi install / reinstall par merchant ne pehla pricing page par plan select karavo
                'plan_selected_at' => $isReinstall ? null : ($existingShop->plan_selected_at ?? null),
                'billing_status' => $isReinstall
                    ? Shop::BILLING_STATUS_PENDING
                    : ($existingShop->billing_status ?? Shop::BILLING_STATUS_PENDING),
                'plan' => $isReinstall
                    ? config('shopify.billing.default_plan', 'free')
                    : ($existingShop->plan ?? config('shopify.billing.default_plan', 'free')),
            ]
        );

        // Install vakhate j default data DB ma banavo:
        //  - shop_settings (prefix, suffix, formats, button settings + Quote PDF / Invoice PDF template)
        //  - email_templates (badha default mail template)
        // Reinstall par merchant ni edit kareli template overwrite thati nathi.
        app(ShopDefaultsService::class)->seed($shopModel);

        // /pages/request-a-quote page + theme template banavo (install ane reinstall banne par).
        // Response moklya pachhi chale chhe, etle queue worker na hoy to pan chale ane install ne atkavtu nathi.
        try {
            dispatch(new SetupQuotePage($shopModel->id))->afterResponse();
        } catch (\Throwable $exception) {
            Log::warning('Failed to queue quote page setup after install', [
                'shop' => $shop,
                'error' => $exception->getMessage(),
            ]);
        }

        try {
            $this->shopifyService->registerWebhooks($shop, $accessToken);
        } catch (GuzzleException $exception) {
            Log::warning('Failed to register webhooks after install', [
                'shop' => $shop,
                'error' => $exception->getMessage(),
            ]);
        }

        Log::info('Shop installed Review Pro App', ['shop' => $shop]);

        // Install pachhi pehla pricing page (plan select), plan select thaya pachhi j dashboard.
        // Redirect back INTO the Shopify admin so the app opens embedded straight after install.
        $destination = $shopModel->hasSelectedPlan() ? '/dashboard' : '/pricing';

        return redirect()->away($this->shopifyService->adminAppUrl($shop, $destination));
    }

    /**
     * GET /api/session  (verify.shop) - used by the frontend entry screen. A request that
     * gets here has a valid session token and an installed shop; it tells the app whether
     * the merchant must re-approve (new scopes) and where to land.
     */
    public function session(Request $request): JsonResponse
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        return response()->json([
            'success' => true,
            'data' => [
                'shop' => $shop->shop,
                'needs_reauth' => ! $this->scopesMatch($shop->scope),
                'plan_selected' => $shop->hasSelectedPlan(),
            ],
        ]);
    }

    protected function scopesMatch(?string $granted): bool
    {
        $want = array_filter(array_map('trim', explode(',', (string) config('shopify.scopes'))));

        foreach ($want as $scope) {
            // write_X => read_X pan gani levay (Shopify token response ma matra write_X aave)
            if (! \App\Models\Shop::scopeGranted($granted ?: '-', $scope)) {
                return false;
            }
        }

        return true;
    }

    /** state = base64url(json{shop,nonce,exp}) . "." . hmac(secret) */
    protected function makeState(string $shop): string
    {
        $payload = rtrim(strtr(base64_encode(json_encode([
            'shop' => $shop,
            'nonce' => bin2hex(random_bytes(16)),
            'exp' => time() + 600,
        ])), '+/', '-_'), '=');

        return $payload . '.' . hash_hmac('sha256', $payload, (string) config('shopify.api_secret'));
    }

    protected function verifyState(string $state, string $shop): bool
    {
        $parts = explode('.', $state);
        if (count($parts) !== 2) {
            return false;
        }

        [$payload, $signature] = $parts;

        if (! hash_equals(hash_hmac('sha256', $payload, (string) config('shopify.api_secret')), $signature)) {
            return false;
        }

        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);

        if (! is_array($data) || ($data['shop'] ?? null) !== $shop || ($data['exp'] ?? 0) < time()) {
            return false;
        }

        // single use
        return Cache::add('shopify-oauth-state:' . ($data['nonce'] ?? ''), 1, 700);
    }
}