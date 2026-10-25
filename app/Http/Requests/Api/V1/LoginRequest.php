<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class LoginRequest extends FormRequest
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
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Name for the new token: the client's device name, else its User-Agent, else "api".
     */
    public function tokenName(): string
    {
        return $this->validated('device_name')
            ?? (Str::limit((string) $this->userAgent(), 255, '') ?: 'api');
    }
}
