<?php

declare(strict_types=1);

namespace App\Http\Requests\Listing;

use App\Models\Listing;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Gebäudedaten aus vorhandenem Objekt übernehmen" (Masterprompt-Abgleich
 * B.1 Schritt 2): übernimmt Gebäudedaten eines anderen Objekts in leere
 * Felder dieses Objekts, überschreibt nie vorhandene Werte.
 */
class Step2UebernahmeRequest extends FormRequest
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
        /** @var Listing $listing */
        $listing = $this->route('listing');

        return [
            'quelle_listing_id' => [
                'required',
                Rule::exists('listings', 'id')->whereNot('id', $listing->id),
            ],
        ];
    }
}
