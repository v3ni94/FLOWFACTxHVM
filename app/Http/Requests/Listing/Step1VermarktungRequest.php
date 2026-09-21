<?php

declare(strict_types=1);

namespace App\Http\Requests\Listing;

use App\Enums\GewerbeUnterart;
use App\Enums\Nutzungsstatus;
use App\Enums\Objektart;
use App\Enums\UserRole;
use App\Enums\VerfuegbarAbTyp;
use App\Enums\Vermarktungsart;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Schritt 1: Vermietung oder Verkauf (Masterprompt-Abgleich B.1 Schritt 1,
 * B.2). bearbeiter_user_id ist Pflicht (B.3: Grundlage der
 * Bearbeitungsrechte), alle übrigen Felder dürfen als Entwurf leer bleiben.
 */
class Step1VermarktungRequest extends FormRequest
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
            'vermarktungsart' => ['required', Rule::enum(Vermarktungsart::class)],
            'objektart' => ['required', Rule::enum(Objektart::class)],
            'flowfact_schema' => ['nullable', 'string', 'max:150'],
            'gewerbe_unterart' => [
                $this->input('objektart') === Objektart::Gewerbe->value ? 'required' : 'nullable',
                Rule::enum(GewerbeUnterart::class),
            ],
            'bearbeiter_user_id' => ['required', self::bearbeiterRegel()],
            'ansprechpartner_user_id' => ['nullable', 'exists:users,id'],
            'verfuegbar_ab_typ' => ['nullable', Rule::enum(VerfuegbarAbTyp::class)],
            'verfuegbar_ab_datum' => [
                $this->input('verfuegbar_ab_typ') === VerfuegbarAbTyp::Datum->value ? 'required' : 'nullable',
                'date',
            ],
            'nutzungsstatus' => ['nullable', Rule::enum(Nutzungsstatus::class)],
        ];
    }

    /**
     * Autosave darf jedes Feld weglassen (Masterprompt-Abgleich B.1: Autosave
     * validiert wie store(), aber alle Felder optional).
     *
     * @return array<string, mixed>
     */
    public static function autosaveRegeln(): array
    {
        return [
            'vermarktungsart' => ['sometimes', 'nullable', Rule::enum(Vermarktungsart::class)],
            'objektart' => ['sometimes', 'nullable', Rule::enum(Objektart::class)],
            'flowfact_schema' => ['sometimes', 'nullable', 'string', 'max:150'],
            'gewerbe_unterart' => ['sometimes', 'nullable', Rule::enum(GewerbeUnterart::class)],
            'bearbeiter_user_id' => ['sometimes', 'nullable', self::bearbeiterRegel()],
            'ansprechpartner_user_id' => ['sometimes', 'nullable', 'exists:users,id'],
            'verfuegbar_ab_typ' => ['sometimes', 'nullable', Rule::enum(VerfuegbarAbTyp::class)],
            'verfuegbar_ab_datum' => ['sometimes', 'nullable', 'date'],
            'nutzungsstatus' => ['sometimes', 'nullable', Rule::enum(Nutzungsstatus::class)],
        ];
    }

    private static function bearbeiterRegel(): Exists
    {
        return Rule::exists('users', 'id')->where(
            fn ($query) => $query->where('is_active', true)->whereIn('role', [UserRole::Admin->value, UserRole::Mitarbeiter->value])
        );
    }

    /**
     * Auswahlliste für bearbeiter_user_id und ansprechpartner_user_id:
     * aktive Administratoren und Mitarbeiter (Masterprompt-Abgleich B.1
     * Schritt 1).
     *
     * @return Collection<int, User>
     */
    public static function auswaehlbareBenutzer(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereIn('role', [UserRole::Admin->value, UserRole::Mitarbeiter->value])
            ->orderBy('name')
            ->get();
    }
}
