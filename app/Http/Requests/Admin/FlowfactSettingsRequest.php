<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Flowfact\Sync\ListingSyncService;
use App\Http\Controllers\Admin\FlowfactSettingsController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FlowfactSettingsRequest extends FormRequest
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
            'company_id' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9\-_.]*$/'],
            'schema_miete' => ['nullable', 'string', 'max:150', 'regex:/^[A-Za-z0-9\-_.]*$/'],
            'schema_kauf' => ['nullable', 'string', 'max:150', 'regex:/^[A-Za-z0-9\-_.]*$/'],
            // Prüfbericht 2026-09-12, Befund 14
            'konfliktverhalten' => ['nullable', 'string', Rule::in([ListingSyncService::KONFLIKT_ABBRECHEN, ListingSyncService::KONFLIKT_UEBERSCHREIBEN])],
            'leere_felder_loeschen' => ['nullable', 'string', Rule::in([FlowfactSettingsController::LEERE_FELDER_AN, FlowfactSettingsController::LEERE_FELDER_AUS])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'company_id.regex' => 'Die Company-ID darf nur Buchstaben, Ziffern, Bindestrich, Unterstrich und Punkt enthalten.',
            'schema_miete.regex' => 'Der Schemaname darf nur Buchstaben, Ziffern, Bindestrich, Unterstrich und Punkt enthalten.',
            'schema_kauf.regex' => 'Der Schemaname darf nur Buchstaben, Ziffern, Bindestrich, Unterstrich und Punkt enthalten.',
            'konfliktverhalten.in' => 'Das Konfliktverhalten muss abbrechen oder ueberschreiben sein.',
            'leere_felder_loeschen.in' => 'Die Löschsemantik muss an oder aus sein.',
        ];
    }
}
