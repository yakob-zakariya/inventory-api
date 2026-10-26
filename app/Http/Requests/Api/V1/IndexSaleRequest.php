<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\DocumentStatus;
use App\Enums\PaymentStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class IndexSaleRequest extends ListRequest
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
            'customer_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', Rule::enum(DocumentStatus::class)],
            'payment_status' => ['sometimes', Rule::enum(PaymentStatus::class)],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }
}
