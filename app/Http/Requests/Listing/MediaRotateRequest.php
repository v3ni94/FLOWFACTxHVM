<?php

declare(strict_types=1);

namespace App\Http\Requests\Listing;

use App\Models\Listing;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Dreht ein Bild oder einen Grundriss um 90 Grad (Masterprompt-Abgleich B.1
 * Schritt 6, B.2). Die Drehung wird nur als Metadatum in listing_media
 * gespeichert (0, 90, 180, 270); die Pixeldrehung beim Export übernimmt der
 * Connector (Welle 3).
 */
class MediaRotateRequest extends FormRequest
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
            'richtung' => ['sometimes', Rule::in(['links', 'rechts'])],
        ];
    }
}
