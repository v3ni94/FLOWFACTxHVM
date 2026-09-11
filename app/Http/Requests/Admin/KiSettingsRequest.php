<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class KiSettingsRequest extends FormRequest
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
            'provider' => ['required', 'string', Rule::in(['fake', 'anthropic'])],
            'modell' => ['required', 'string', Rule::in(array_keys((array) config('ai.models')))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'provider.required' => 'Bitte wählen Sie einen Anbieter.',
            'provider.in' => 'Der gewählte Anbieter ist unbekannt.',
            'modell.required' => 'Bitte wählen Sie ein Modell.',
            'modell.in' => 'Das gewählte Modell ist unbekannt.',
        ];
    }
}
