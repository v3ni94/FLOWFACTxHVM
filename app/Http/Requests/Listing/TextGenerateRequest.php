<?php

declare(strict_types=1);

namespace App\Http\Requests\Listing;

use App\Enums\TextFeld;
use App\Models\Listing;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Anforderung eines Textvorschlags in Schritt 6 (Datenvertrag Abschnitt 2.7,
 * ADR-009). Ohne Auswahl werden alle noch leeren Felder vorgeschlagen.
 */
class TextGenerateRequest extends FormRequest
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
        return [
            'felder' => ['sometimes', 'array'],
            'felder.*' => [Rule::enum(TextFeld::class)],
        ];
    }
}
