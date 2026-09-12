<?php

declare(strict_types=1);

namespace App\Http\Requests\Listing;

use App\Enums\AdressFreigabe;
use App\Models\Listing;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Schritt 2: Adresse und Lage (Masterprompt-Abgleich B.1 Schritt 2, B.2).
 *
 * hausnummer ist bewusst freier Text (Beispiele "12a" oder "12 bis 14",
 * Masterprompt-Abgleich B.1). Die internen Angaben (listing_internals) sind
 * nie Teil der Übertragung (ADR-003) und werden hier mitvalidiert, weil sie
 * auf derselben Seite erfasst werden.
 */
class Step2AdresseRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Listing $listing */
        $listing = $this->route('listing');

        return $this->user()?->can('update', $listing) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->adressRegeln(false), $this->interneRegeln(false));
    }

    /**
     * @return array<string, mixed>
     */
    public static function autosaveRegeln(): array
    {
        $instanz = new self;

        return array_merge($instanz->adressRegeln(true), $instanz->interneRegeln(true));
    }

    /**
     * @return array<string, mixed>
     */
    private function adressRegeln(bool $autosave): array
    {
        $praefix = $autosave ? ['sometimes', 'nullable'] : ['nullable'];

        return [
            'strasse' => array_merge($praefix, ['string', 'max:255']),
            'hausnummer' => array_merge($praefix, ['string', 'max:30']),
            'adresszusatz' => array_merge($praefix, ['string', 'max:255']),
            'plz' => array_merge($praefix, ['string', 'max:5', 'regex:/^\d{4,5}$/']),
            'ort' => array_merge($praefix, ['string', 'max:255']),
            'stadtteil' => array_merge($praefix, ['string', 'max:255']),
            'land' => array_merge($praefix, ['string', 'size:2']),
            'adress_freigabe' => array_merge($praefix, [Rule::enum(AdressFreigabe::class)]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function interneRegeln(bool $autosave): array
    {
        $kurz = $autosave ? ['sometimes', 'nullable', 'string', 'max:255'] : ['nullable', 'string', 'max:255'];
        $lang = $autosave ? ['sometimes', 'nullable', 'string', 'max:4000'] : ['nullable', 'string', 'max:4000'];

        return [
            'gebaeudebezeichnung' => $kurz,
            'einheitsnummer' => $kurz,
            'lage_im_gebaeude' => $kurz,
            'verwaltungsobjekt_referenz' => $kurz,
            'eigentuemer_name' => $kurz,
            'eigentuemer_kontakt' => $lang,
            'interne_notizen' => $lang,
            'schluessel_hinweis' => $lang,
            'besichtigung_intern' => $lang,
            'kalkulation_notiz' => $lang,
        ];
    }
}
