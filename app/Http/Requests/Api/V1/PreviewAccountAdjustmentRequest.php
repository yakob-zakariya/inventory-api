<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The same body as a reconciliation, but the reason may still be empty: the dialog shows the
 * difference while the user is typing the counted amount, before writing the reason.
 */
class PreviewAccountAdjustmentRequest extends StoreAccountAdjustmentRequest
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
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
