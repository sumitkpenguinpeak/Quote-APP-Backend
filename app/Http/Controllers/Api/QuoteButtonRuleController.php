<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuoteButtonRuleRequest;
use App\Models\QuoteButtonRule;
use Illuminate\Http\Request;

class QuoteButtonRuleController extends Controller
{
    /** GET /api/button-rules */
    public function index(Request $request)
    {
        $shop = $request->attributes->get('shop');

        return response()->json([
            'data' => QuoteButtonRule::where('shop_id', $shop->id)
                ->orderByDesc('priority')
                ->get(),
        ]);
    }

    /** POST /api/button-rules */
    public function store(StoreQuoteButtonRuleRequest $request)
    {
        $shop = $request->attributes->get('shop');

        $rule = QuoteButtonRule::create([...$request->validated(), 'shop_id' => $shop->id]);

        return response()->json(['data' => $rule], 201);
    }

    /** PUT /api/button-rules/{rule} */
    public function update(StoreQuoteButtonRuleRequest $request, QuoteButtonRule $rule)
    {
        abort_unless($rule->shop_id === $request->attributes->get('shop')->id, 403);

        $rule->update($request->validated());

        return response()->json(['data' => $rule->fresh()]);
    }

    /** DELETE /api/button-rules/{rule} */
    public function destroy(Request $request, QuoteButtonRule $rule)
    {
        abort_unless($rule->shop_id === $request->attributes->get('shop')->id, 403);

        $rule->delete();

        return response()->json(['message' => 'Rule deleted.']);
    }
}
