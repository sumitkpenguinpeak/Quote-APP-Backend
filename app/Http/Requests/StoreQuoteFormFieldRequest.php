<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreQuoteFormFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:190'],
            'field_type' => ['required', 'in:text,textarea,email,phone,number,select,checkbox,radio,file,date'],
            'options' => ['nullable', 'array'],
            'placeholder' => ['nullable', 'string', 'max:190'],
            'is_required' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
