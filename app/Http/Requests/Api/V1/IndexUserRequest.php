<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Role;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class IndexUserRequest extends ListRequest
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
            'role' => ['sometimes', 'string', Rule::exists(Role::class, 'name')],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
