<?php

declare(strict_types=1);

namespace App\Http\Requests\Listing;

use App\Models\Listing;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Deaktivierung ausgewählter Portale auf der Prüfseite (Masterprompt
 * Abschnitt 24, Masterprompt-Abgleich B.6). Ohne Auswahl werden alle noch
 * offenen Publikationen (angefordert, aktiv, fehler, unbekannt)
 * zurückgezogen (Prüfbericht 2026-09-11, Befund 1).
 */
class ReviewWithdrawRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Listing $listing */
        $listing = $this->route('listing');

        return $this->user()?->can('withdraw', $listing) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'portale' => ['sometimes', 'array'],
            'portale.*' => ['string'],
        ];
    }
}
