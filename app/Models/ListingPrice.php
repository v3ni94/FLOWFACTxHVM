<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Listing\PriceStructure;
use App\Enums\HeizkostenStruktur;
use App\Enums\ProvisionTyp;
use App\Enums\StellplatzModus;
use Database\Factories\ListingPriceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Preise, 1:1 zu Listing (Datenvertrag Abschnitt 2.3, Masterprompt-Abgleich B.2).
 *
 * heizkosten_struktur ist fachlich führend; das Flag
 * heizkosten_in_nebenkosten_enthalten und listings.heizkosten_versorgung
 * werden daraus abgeleitet (PriceStructure), damit RentCalculator
 * unverändert bleibt.
 *
 * @property int|null $kaltmiete_cent
 * @property int|null $nebenkosten_cent
 * @property int|null $heizkosten_cent
 * @property bool $heizkosten_in_nebenkosten_enthalten
 * @property HeizkostenStruktur|null $heizkosten_struktur
 * @property int|null $stellplatz_miete_cent
 * @property StellplatzModus $stellplatz_modus
 * @property bool|null $stellplatz_im_kaufpreis
 * @property int|null $kaufpreis_cent
 * @property int|null $stellplatz_kaufpreis_cent
 * @property ProvisionTyp $provision_typ
 * @property string|null $provision_text
 * @property bool $provision_bestaetigt
 */
class ListingPrice extends Model
{
    /** @use HasFactory<ListingPriceFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kaltmiete_cent' => 'integer',
            'nebenkosten_cent' => 'integer',
            'heizkosten_cent' => 'integer',
            'heizkosten_in_nebenkosten_enthalten' => 'boolean',
            'warmmiete_cent' => 'integer',
            'kaution_cent' => 'integer',
            'stellplatz_miete_cent' => 'integer',
            'kaufpreis_cent' => 'integer',
            'hausgeld_cent' => 'integer',
            'stellplatz_kaufpreis_cent' => 'integer',
            'mieteinnahmen_ist_cent' => 'integer',
            'provision_typ' => ProvisionTyp::class,
            'provision_bestaetigt' => 'boolean',
            'stellplatz_modus' => StellplatzModus::class,
            'stellplatz_im_kaufpreis' => 'boolean',
            'heizkosten_struktur' => HeizkostenStruktur::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ListingPrice $preis): void {
            // Prüfbericht 2026-09-12, Befund 13: die Provisionsbestätigung
            // gilt nur für den bestätigten Text. Ändert sich Provisionstext
            // oder -typ eines bereits bestehenden Datensatzes in einem
            // Speichervorgang, der "provision_bestaetigt" selbst nicht
            // ausdrücklich mitändert, verliert eine zuvor erteilte
            // Bestätigung ihre Gültigkeit (ein unverändert angehaktes
            // Kästchen bestätigt nicht automatisch einen neuen Text). Werden
            // Text und Bestätigung dagegen in einem Zug geändert (die
            // Oberfläche sendet beides zusammen), gilt das als bewusste,
            // neue Bestätigung. Auf einem neu angelegten Datensatz greift
            // die Rücksetzung nicht, da es dort noch keine frühere
            // Bestätigung gibt, die entwertet werden könnte.
            if ($preis->exists
                && ($preis->isDirty('provision_text') || $preis->isDirty('provision_typ'))
                && ! $preis->isDirty('provision_bestaetigt')) {
                $preis->provision_bestaetigt = false;
            }
        });

        static::saving(function (ListingPrice $preis): void {
            if ($preis->isDirty('heizkosten_struktur') && $preis->heizkosten_struktur !== null) {
                $preis->heizkosten_in_nebenkosten_enthalten = PriceStructure::heizkostenEnthalten($preis->heizkosten_struktur);

                return;
            }

            // Bisheriger Assistent: ändert sich nur das Flag, folgt eine bereits
            // gesetzte Struktur nach, sonst würde der ältere Stand überschrieben.
            if ($preis->isDirty('heizkosten_in_nebenkosten_enthalten') && $preis->heizkosten_struktur !== null) {
                $versorgung = $preis->listing?->heizkosten_versorgung;
                $struktur = PriceStructure::struktur($versorgung, (bool) $preis->heizkosten_in_nebenkosten_enthalten);

                if ($struktur !== null) {
                    $preis->heizkosten_struktur = $struktur;
                }
            }
        });

        static::saved(function (ListingPrice $preis): void {
            if (! $preis->wasChanged('heizkosten_struktur') || $preis->heizkosten_struktur === null) {
                return;
            }

            $listing = $preis->listing;
            $versorgung = PriceStructure::versorgung($preis->heizkosten_struktur);

            if ($listing !== null && $listing->heizkosten_versorgung !== $versorgung) {
                $listing->heizkosten_versorgung = $versorgung;
                $listing->save();
            }
        });
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}
