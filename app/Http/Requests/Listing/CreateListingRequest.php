<?php

declare(strict_types=1);

namespace App\Http\Requests\Listing;

use App\Enums\Objektart;
use App\Enums\Vermarktungsart;
use App\Models\Listing;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Kleine Anlageform auf der Objektübersicht (Datenvertrag Abschnitt 5,
 * Schritt 1): legt einen Entwurf mit Vermarktungs- und Objektart an, alles
 * Weitere erfolgt im Erfassungsassistenten.
 */
class CreateListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Listing::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'vermarktungsart' => ['required', Rule::enum(Vermarktungsart::class)],
            'objektart' => ['required', Rule::enum(Objektart::class)],
        ];
    }
}
