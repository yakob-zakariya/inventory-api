<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Category;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
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
     * Prices may change freely: documents keep their own copies of them.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'category_id' => ['sometimes', 'required', 'integer', Rule::exists(Category::class, 'id')],
            'sku' => ['sometimes', 'required', 'string', 'max:64', Rule::unique(Product::class)->ignore($this->route('product'))],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'unit' => ['sometimes', 'required', 'string', 'max:32'],
            'cost_price' => ['sometimes', 'required', ...Money::rules()],
            'selling_price' => ['sometimes', 'required', ...Money::rules()],
            'additional_cost' => ['sometimes', 'required', ...Money::rules()],
            'reorder_level' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
        ];
    }
}
