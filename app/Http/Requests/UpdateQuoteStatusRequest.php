<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateQuoteStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:pending,read,update,approved,rejected,converted,cancelled'],
            'merchant_note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}