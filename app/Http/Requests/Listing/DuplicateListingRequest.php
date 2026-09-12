<?php

declare(strict_types=1);

namespace App\Http\Requests\Listing;

use App\Models\Listing;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Duplizieren eines Objekts (Masterprompt Abschnitt 24, Masterprompt-Abgleich
 * B.3). Die Aktion nimmt keine Formularwerte entgegen, nur die Berechtigung
 * wird geprüft.
 */
class DuplicateListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Listing $listing */
        $listing = $this->route('listing');

        return $this->user()?->can('duplicate', Listing::class) && $this->user()?->can('view', $listing);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
