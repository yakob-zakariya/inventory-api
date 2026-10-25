<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Product;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockAdjustmentRequest extends FormRequest
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
     *
     * Quantity is signed: positive adds stock (e.g. opening stock), negative removes it.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', Rule::exists(Product::class, 'id')->withoutTrashed()],
            'quantity' => ['required', 'integer', 'not_in:0', 'between:-1000000,1000000'],
            'note' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity.not_in' => 'The quantity must not be zero.',
            'note.required' => 'A reason is required for every stock adjustment.',
        ];
    }
}
