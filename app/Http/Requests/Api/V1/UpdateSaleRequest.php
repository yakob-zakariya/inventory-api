<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\EditableFields;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSaleRequest extends FormRequest
{
    use EditableFields;

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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return list<string>
     */
    protected function editableFields(): array
    {
        return ['note'];
    }
}
