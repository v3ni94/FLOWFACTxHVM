<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class FlowfactTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'api_token' => ['required', 'string', 'min:8', 'max:4096'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'api_token.required' => 'Bitte geben Sie den API-Token ein.',
            'api_token.min' => 'Der API-Token ist zu kurz.',
        ];
    }
}
