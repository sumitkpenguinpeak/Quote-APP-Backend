<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\ShopEmailAccount;
use App\Services\GmailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Email Configuration (Settings > Email Configuration):
 * connect / show / disconnect the Gmail account used to send quote emails.
 */
class EmailConfigController extends Controller
{
    public function __construct(private GmailService $gmail) {}

    /** GET /api/email-config -> { data: { connected, email, provider, status } } */
    public function show(Request $request)
    {
        $account = $request->attributes->get('shop')->emailAccount;

        return response()->json([
            'data' => [
                'connected' => (bool) $account?->isActive(),
                'email' => $account?->email,
                'provider' => $account?->provider,
                'status' => $account?->status,
            ],
        ]);
    }

    /** GET /api/email-config/connect -> { data: { url } }  (frontend opens it in the top window) */
    public function connect(Request $request)
    {
        abort_unless($this->gmail->isConfigured(), 500, 'Google email connection is not configured on the server.');

        $shop = $request->attributes->get('shop');

        // `state` is a one-time random nonce that maps back to this shop (10 min).
        $nonce = Str::random(40);
        Cache::put("email-oauth:{$nonce}", $shop->id, now()->addMinutes(10));

        return response()->json(['data' => ['url' => $this->gmail->authUrl($nonce)]]);
    }

    /**
     * GET /api/email-config/callback  (public - Google redirects the browser here)
     * Stores the tokens, then sends the merchant back to Settings in the frontend.
     */
    public function callback(Request $request)
    {
        $shopId = Cache::pull('email-oauth:' . $request->query('state', ''));
        $shop = $shopId ? Shop::find($shopId) : null;

        if (! $shop) {
            return $this->redirectToFrontend(null, 'error', 'Invalid or expired connection request. Please try again.');
        }

        if ($request->filled('error') || ! $request->filled('code')) {
            return $this->redirectToFrontend($shop, 'error', 'Google access was not granted.');
        }

        try {
            $tokens = $this->gmail->exchangeCode((string) $request->query('code'));

            // Google's consent screen has a separate checkbox per permission. If the
            // merchant unticked "send email", the token is useless - don't save it.
            if (! str_contains((string) ($tokens['scope'] ?? ''), 'gmail.send')) {
                throw new \RuntimeException('Gmail send permission was not granted. Please connect again and allow "Send email on your behalf".');
            }

            $email = $this->gmail->fetchEmail($tokens['access_token']);

            $existing = $shop->emailAccount;
            $refreshToken = $tokens['refresh_token'] ?? $existing?->refresh_token;

            if (! $refreshToken) {
                throw new \RuntimeException('Google did not return a refresh token. Please try connecting again.');
            }

            ShopEmailAccount::updateOrCreate(
                ['shop_id' => $shop->id],
                [
                    'provider' => 'gmail',
                    'email' => $email,
                    'access_token' => $tokens['access_token'],
                    'refresh_token' => $refreshToken,
                    'expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
                    'status' => ShopEmailAccount::STATUS_ACTIVE,
                    'last_error' => null,
                ]
            );
        } catch (Throwable $e) {
            Log::error('Email account connection failed', ['shop' => $shop->shop, 'error' => $e->getMessage()]);

            return $this->redirectToFrontend($shop, 'error', $e->getMessage());
        }

        return $this->redirectToFrontend($shop, 'connected');
    }

    /** DELETE /api/email-config */
    public function destroy(Request $request)
    {
        $account = $request->attributes->get('shop')->emailAccount;

        if ($account) {
            $this->gmail->revoke($account);
            $account->delete();
        }

        return response()->json(['message' => 'Email account disconnected.']);
    }

    private function redirectToFrontend(?Shop $shop, string $result, ?string $reason = null)
    {
        $frontendUrl = rtrim(config('shopify.frontend_url'), '/');

        $query = array_filter([
            'shop' => $shop?->shop,
            'email_config' => $result,
            'reason' => $reason,
        ]);

        return redirect()->away("{$frontendUrl}/settings?" . http_build_query($query));
    }
}
