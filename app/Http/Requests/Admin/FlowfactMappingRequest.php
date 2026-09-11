<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class FlowfactMappingRequest extends FormRequest
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
            'felder' => ['nullable', 'array'],
            'felder.*' => ['nullable', 'string', 'max:150', 'regex:/^[A-Za-z0-9_\-.]*$/'],
            'codes' => ['nullable', 'array'],
            'codes.*' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9_\-.+]*$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'felder.*.regex' => 'FLOWFACT-Feldnamen dürfen nur Buchstaben, Ziffern, Unterstrich, Bindestrich und Punkt enthalten.',
            'codes.*.regex' => 'FLOWFACT-Codes dürfen nur Buchstaben, Ziffern, Unterstrich, Bindestrich, Punkt und Plus enthalten.',
        ];
    }
}
