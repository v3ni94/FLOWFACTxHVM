<?php

declare(strict_types=1);

namespace App\Http\Requests\Listing;

use App\Flowfact\Sync\PublishingService;
use App\Models\Listing;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Veröffentlichungsanforderung auf der Prüfseite (Masterprompt Abschnitt 17
 * bis 20, Masterprompt-Abgleich B.4): mindestens ein Portal, alle
 * bestätigungspflichtigen Hinweise werden separat vom Controller geprüft, da
 * ihre Zahl je Objekt wechselt.
 */
class ReviewPublishRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Listing $listing */
        $listing = $this->route('listing');

        return $this->user()?->can('publish', $listing) ?? false;
    }

    /**
     * "Alle verfügbaren Portale auswählen" ohne JavaScript (Masterprompt
     * Abschnitt 18): ist die Sammelauswahl gesetzt, gelten unabhängig von den
     * einzeln angehakten Kästchen alle verfügbaren Portale als ausgewählt.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->boolean('alle_portale')) {
            return;
        }

        $publishingService = app(PublishingService::class);

        if (! $publishingService->isConfigured()) {
            return;
        }

        $this->merge([
            'portale' => array_map(fn ($portal): string => $portal->id, $publishingService->portals()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'portale' => ['required', 'array', 'min:1'],
            'portale.*' => ['required', 'string'],
            'hinweise_bestaetigt' => ['sometimes', 'array'],
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
