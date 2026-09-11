<?php

declare(strict_types=1);

namespace App\Http\Requests\Listing;

use App\Models\Listing;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Veröffentlichungsanforderung in Schritt 8 (Datenvertrag Abschnitt 4.3,
 * Abschnitt 5.8): erfordert mindestens ein ausgewähltes Portal.
 */
class PublishRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Listing $listing */
        $listing = $this->route('listing');

        return $this->user()?->can('publish', $listing) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'portale' => ['required', 'array', 'min:1'],
            'portale.*' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'portale.required' => 'Bitte wählen Sie mindestens ein Portal aus.',
            'portale.min' => 'Bitte wählen Sie mindestens ein Portal aus.',
        ];
    }
}
