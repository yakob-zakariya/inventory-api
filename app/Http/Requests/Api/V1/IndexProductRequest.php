<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class IndexProductRequest extends ListRequest
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
            'category_id' => ['sometimes', 'integer'],
            'stock' => ['sometimes', Rule::in(['low', 'out'])],
            'sort' => ['sometimes', Rule::in(['name', 'stock', '-stock'])],
        ];
    }
}
