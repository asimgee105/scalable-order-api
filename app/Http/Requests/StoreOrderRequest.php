<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

        public function rules(): array
    {
        return [
            'items' => [
                'required',
                'array',
                'min:1',
            ],

            'items.*.product_id' => [
                'required',
                'integer',
                'distinct',
                'exists:products,id',
            ],

            'items.*.quantity' => [
                'required',
                'integer',
                'min:1',
            ],
            'idempotency_key' => [
                'required',
                'string',
                'max:100',
            ],
        ];
    }
protected function prepareForValidation(): void
{
    $this->merge([
        'idempotency_key' => $this->header('Idempotency-Key'),
    ]);
}
}