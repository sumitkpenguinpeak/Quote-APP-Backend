<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReviewRequest;
use App\Models\Review;
use App\Models\Shop;
use Illuminate\Http\Request;

class ReviewController extends Controller
{   
    public function index(Request $request)
    {
        $request->validate([
            'shop'           => 'required|string|max:255',
            'product_id'     => 'required',
            'product_handle' => 'required|string',
        ]);

        $shop = Shop::where('shop', $request->shop)->first();
        if (!$shop) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid shop.'
            ], 404);
        }
        $reviews = Review::where('shop_id', $shop->id)
            ->where('product_id', $request->product_id)
            ->where('product_handle', $request->product_handle)
            ->latest()
            ->get()
            ->map(function ($review) {
                if ($review->review_file) {
                    $review->image_url = asset('storage/' . $review->review_file);
                }
                return $review;
            });
        return response()->json([
            'success' => true,
            'message' => 'Reviews fetched successfully.',
            'total_reviews' => $reviews->count(),
            'data' => $reviews,
        ])->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            ->header('Access-Control-Allow-Headers', '*');
    }
    public function store(StoreReviewRequest $request)
    {
        $filePath = null;

        if ($request->hasFile('review_file')) {
            $filePath = $request->file('review_file')->store('reviews', 'public');
        }

        $shop = Shop::where('shop', $request->shop)->first();
        if (!$shop) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid shop.'
            ], 404);
        }

        $review = Review::create([
            'product_id'    => $request->product_id,
            'product_handle'=> $request->product_handle,
            'name'          => $request->name,
            'email'         => $request->email,
            'rating'        => $request->rating,
            'review_title'  => $request->review_title,
            'review_body'  => $request->review_body,
            'review_file'   => $filePath,
            'recommend'     => $request->recommend,
            'shop_id'       => $shop->id
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Review submitted successfully.',
            'data'    => $review,
        ], 201);
    }
    public function reviews(Request $request)
    {
        $request->validate([
            'shop'           => 'required|string|max:255',
        ]);

        $shop = Shop::where('shop', $request->shop)->first();
        if (!$shop) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid shop.'
            ], 404);
        }
        $reviews = Review::where('shop_id', $shop->id)
            ->latest()
            ->get()
            ->map(function ($review) {
                if ($review->review_file) {
                    $review->image_url = asset('storage/' . $review->review_file);
                }
                return $review;
            });
        return response()->json([
            'success' => true,
            'message' => 'Reviews fetched successfully.',
            'total_reviews' => $reviews->count(),
            'data' => $reviews,
        ])->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            ->header('Access-Control-Allow-Headers', '*');
    }
}