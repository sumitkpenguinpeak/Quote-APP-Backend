<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreQuoteMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:3000'],
            'sender_type' => ['required', 'in:merchant,customer'],
            'attachments' => ['nullable', 'array'],
            'attachments.*.file_name' => ['required_with:attachments', 'string', 'max:255'],
            'attachments.*.file_path' => ['required_with:attachments', 'string', 'max:500'],
            'attachments.*.mime_type' => ['nullable', 'string', 'max:100'],
            'attachments.*.file_size' => ['nullable', 'integer'],
        ];
    }
}
