<?php

declare(strict_types=1);

namespace App\Http\Requests\Listing;

use App\Enums\Zustand;
use App\Models\Listing;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Schritt 3: Flächen und Objektdaten (Masterprompt-Abgleich B.1 Schritt 3).
 *
 * Flächen und Zimmer kommen als deutsch formatierte Zeichenketten herein
 * ("72,50"); ein leeres Feld bedeutet unbekannt, nie 0 (B.1). Plausibilität
 * (Baujahr 1800 bis laufendes Jahr plus 3, Flächen größer 0) ist ein
 * Validierungsfehler nur bei unmöglichen Werten, nicht bei fehlenden Angaben.
 */
class Step3FlaechenRequest extends FormRequest
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
        return $this->regeln(false);
    }

    /**
     * @return array<string, mixed>
     */
    public static function autosaveRegeln(): array
    {
        return (new self)->regeln(true);
    }

    /**
     * @return array<string, mixed>
     */
    private function regeln(bool $autosave): array
    {
        $aktuellesJahr = (int) now()->format('Y');
        $flaeche = array_merge($this->praefix($autosave), ['string', self::dezimalRegel(9999999.99)]);
        $zimmerRegel = array_merge($this->praefix($autosave), ['string', self::dezimalRegel(999.5)]);
        $ganzzahl = array_merge($this->praefix($autosave), ['integer', 'min:0', 'max:200']);

        return [
            'wohnflaeche_qm' => $flaeche,
            'nutzflaeche_qm' => $flaeche,
            'gewerbeflaeche_qm' => $flaeche,
            'grundstuecksflaeche_qm' => $flaeche,
            'zimmer' => $zimmerRegel,
            'schlafzimmer' => $ganzzahl,
            'badezimmer' => $ganzzahl,
            'etage' => array_merge($this->praefix($autosave), ['integer', 'min:-9', 'max:200']),
            'etagen_gesamt' => $ganzzahl,
            'baujahr' => array_merge($this->praefix($autosave), ['integer', 'min:1800', 'max:'.($aktuellesJahr + 3)]),
            'modernisierungsjahr' => array_merge($this->praefix($autosave), ['integer', 'min:1800', 'max:'.($aktuellesJahr + 3)]),
            'zustand' => array_merge($this->praefix($autosave), [Rule::enum(Zustand::class)]),
        ];
    }

    /**
     * @return list<string>
     */
    private function praefix(bool $autosave): array
    {
        return $autosave ? ['sometimes', 'nullable'] : ['nullable'];
    }

    /**
     * Deutsch formatierte Dezimalzahl größer 0 (leer bleibt erlaubt, 0 ist
     * unmöglich und wird abgelehnt, Masterprompt-Abgleich B.1 Schritt 3).
     */
    public static function dezimalRegel(float $max): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($max): void {
            if ($value === null || $value === '') {
                return;
            }

            $wert = self::parseDezimal((string) $value);

            if ($wert === null) {
                $fail('Bitte eine Zahl im Format 72,50 eingeben.');

                return;
            }

            if ($wert <= 0) {
                $fail('Der Wert muss größer als 0 sein. Bitte das Feld leer lassen, wenn der Wert unbekannt ist.');

                return;
            }

            if ($wert > $max) {
                $fail('Der Wert ist unplausibel hoch.');
            }
        };
    }

    public static function parseDezimal(?string $wert): ?float
    {
        if ($wert === null || trim($wert) === '') {
            return null;
        }

        $bereinigt = str_replace('.', '', trim($wert));
        $bereinigt = str_replace(',', '.', $bereinigt);

        return is_numeric($bereinigt) ? (float) $bereinigt : null;
    }
}
