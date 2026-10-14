<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreReviewRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    } 

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'product_id'     => 'required|string|max:255',
            'product_handle' => 'required|string|max:255',
            'name'           => 'required|string|max:255',
            'email'          => 'required|email|max:255',
            'rating'         => 'required|integer|between:1,5',
            'review_title'   => 'required|string|max:255',
            'review_body'    => 'required|string',
            'review_file'    => 'nullable|file|mimes:jpg,jpeg,png,mp4,mov,pdf|max:10240',
            'recommend'      => 'nullable|boolean',
            'shop'           => 'required|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'rating.between' => 'Rating must be between 1 and 5.',
            'shop_id.exists' => 'Invalid Shop.',
        ];
    }
}