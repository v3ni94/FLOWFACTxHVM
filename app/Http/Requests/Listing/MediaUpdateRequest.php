<?php

declare(strict_types=1);

namespace App\Http\Requests\Listing;

use App\Models\Listing;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Titel und Inseratsfreigabe eines einzelnen Mediums (Datenvertrag
 * Abschnitt 2.6).
 */
class MediaUpdateRequest extends FormRequest
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
            'titel' => ['nullable', 'string', 'max:255'],
            'im_inserat' => ['sometimes', 'boolean'],
        ];
    }
}
