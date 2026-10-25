<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Category;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
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
     * The SKU must be unique among soft-deleted products too, matching the database index.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', Rule::exists(Category::class, 'id')],
            'sku' => ['required', 'string', 'max:64', Rule::unique(Product::class)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'unit' => ['required', 'string', 'max:32'],
            'cost_price' => ['required', ...Money::rules()],
            'selling_price' => ['required', ...Money::rules()],
            'reorder_level' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
        ];
    }
}
