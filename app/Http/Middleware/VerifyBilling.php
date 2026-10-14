<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class VerifyBilling
 *
 * Runs after VerifyShop. The app has a Free plan, so an installed shop never
 * needs a paid subscription - but it must pick a plan (Free included) on the
 * pricing page first (plan_selected_at). Plan limits and paid features
 * are enforced where they are used (see Shop::canUse() / quoteLimit()).
 */
class VerifyBilling
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Shop|null $shop */
        $shop = $request->attributes->get('shop');

        if (! $shop) {
            return response()->json([
                'success' => false,
                'message' => 'Shop context missing. VerifyShop must run before VerifyBilling.',
            ], 500);
        }

        if (! $shop->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'The app is not installed on this shop.',
            ], 403);
        }

        // Install pachhi merchant e pehla pricing page par plan select karvo pade.
        // (Billing routes aa middleware ma nathi, etle plan select kari shake.)
        if (! $shop->hasSelectedPlan()) {
            return response()->json([
                'success' => false,
                'code' => 'plan_not_selected',
                'message' => 'Please choose a plan to continue.',
            ], 402);
        }

        return $next($request);
    }
}
