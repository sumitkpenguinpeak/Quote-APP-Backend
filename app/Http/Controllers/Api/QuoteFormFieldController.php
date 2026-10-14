<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuoteFormFieldRequest;
use App\Models\QuoteFormField;
use Illuminate\Http\Request;

class QuoteFormFieldController extends Controller
{
    /** GET /api/form-fields */
    public function index(Request $request)
    {
        $shop = $request->attributes->get('shop');

        return response()->json([
            'data' => QuoteFormField::where('shop_id', $shop->id)
                ->orderBy('sort_order')
                ->get(),
        ]);
    }

    /** POST /api/form-fields */
    public function store(StoreQuoteFormFieldRequest $request)
    {
        $shop = $request->attributes->get('shop');

        $field = QuoteFormField::create([...$request->validated(), 'shop_id' => $shop->id]);

        return response()->json(['data' => $field], 201);
    }

    /** PUT /api/form-fields/{field} */
    public function update(StoreQuoteFormFieldRequest $request, QuoteFormField $field)
    {
        abort_unless($field->shop_id === $request->attributes->get('shop')->id, 403);

        $field->update($request->validated());

        return response()->json(['data' => $field->fresh()]);
    }

    /** DELETE /api/form-fields/{field} */
    public function destroy(Request $request, QuoteFormField $field)
    {
        abort_unless($field->shop_id === $request->attributes->get('shop')->id, 403);
        abort_if($field->is_default, 422, 'Default fields cannot be deleted.');

        $field->delete();

        return response()->json(['message' => 'Field deleted.']);
    }

    /** PATCH /api/form-fields/reorder  { order: [3,1,2] } */
    public function reorder(Request $request)
    {
        $shop = $request->attributes->get('shop');
        $request->validate(['order' => ['required', 'array']]);

        foreach ($request->order as $index => $fieldId) {
            QuoteFormField::where('shop_id', $shop->id)->where('id', $fieldId)
                ->update(['sort_order' => $index]);
        }

        return response()->json(['message' => 'Order updated.']);
    }
}
