<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\StockMovementType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class IndexStockMovementRequest extends ListRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'product_id' => ['sometimes', 'integer'],
            'type' => ['sometimes', Rule::enum(StockMovementType::class)],
        ];
    }
}
