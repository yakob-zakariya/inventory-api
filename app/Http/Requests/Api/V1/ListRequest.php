<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A paginated list: `per_page` (1 to 100, default the model's 10) and an optional `search`.
 * List requests with their own filters extend this class and merge parent::rules().
 */
class ListRequest extends FormRequest
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
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    /**
     * Rows per page, or null for the model's default.
     */
    public function perPage(): ?int
    {
        return $this->validated('per_page');
    }
}
