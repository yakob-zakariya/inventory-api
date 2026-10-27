<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Permission;
use App\Models\Role;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50', Rule::unique(Role::class, 'name')],
            ...self::permissionRules(),
        ];
    }

    /**
     * A list of known permission names, each at most once.
     *
     * @return array<string, array<mixed>>
     */
    public static function permissionRules(): array
    {
        return [
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in(Permission::values())],
        ];
    }
}
