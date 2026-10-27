<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Role;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request. Sent permissions replace the role's.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:50', Rule::unique(Role::class, 'name')->ignore($this->route('role'))],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => StoreRoleRequest::permissionRules()['permissions.*'],
        ];
    }
}
