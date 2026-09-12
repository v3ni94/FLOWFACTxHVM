<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Ausweistyp;
use App\Enums\Effizienzklasse;
use App\Enums\EnergieausweisStatus;
use Database\Factories\ListingEnergyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Energieausweis, 1:1 zu Listing (Datenvertrag Abschnitt 2.4, Masterprompt-Abgleich B.2, B.5).
 *
 * @property EnergieausweisStatus|null $status
 * @property Ausweistyp|null $ausweistyp
 * @property string|null $kennwert_kwh
 * @property string|null $kennwert_strom_kwh
 * @property Effizienzklasse|null $effizienzklasse
 * @property int|null $baujahr_anlage
 * @property string|null $ausnahme_begruendung
 * @property Carbon|null $ausnahme_bestaetigt_at
 * @property string|null $ausnahme_bestaetigt_hash
 */
class ListingEnergy extends Model
{
    /** @use HasFactory<ListingEnergyFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => EnergieausweisStatus::class,
            'ausweistyp' => Ausweistyp::class,
            'ausstellungsdatum' => 'date',
            'kennwert_kwh' => 'decimal:1',
            'kennwert_strom_kwh' => 'decimal:1',
            'effizienzklasse' => Effizienzklasse::class,
            'baujahr_anlage' => 'integer',
            'gueltig_bis' => 'date',
            'enthaelt_warmwasser' => 'boolean',
            'ausnahme_bestaetigt_at' => 'datetime',
        ];
    }

    /**
     * Maßgeblicher Status, ältere Werte auf die neuen abgebildet.
     */
    public function statusNormalisiert(): ?EnergieausweisStatus
    {
        return $this->status?->normalisiert();
    }

    protected static function booted(): void
    {
        static::saving(function (ListingEnergy $energie): void {
            // Prüfbericht 2026-09-12, Befund 3: ändert sich Status oder
            // Begründung der Ausnahme in einem Speichervorgang, der die
            // Bestätigung selbst nicht ausdrücklich mitändert (das ist bei
            // Schritt 5 des Assistenten immer der Fall, dort werden die
            // Bestätigungsfelder nie gesetzt), verliert eine zuvor erteilte
            // Adminbestätigung ihre Gültigkeit. Nur ReviewController::
            // confirmEnergyException setzt "ausnahme_bestaetigt_von_user_id"
            // selbst und darf begruendung und Bestätigung gemeinsam setzen
            // (auf den Zeitpunkt allein wird bewusst nicht abgestellt: zwei
            // aufeinanderfolgende now()-Aufrufe können auf die gleiche
            // Sekunde runden und blieben dann fälschlich "unverändert"). Auf
            // einem neu angelegten Datensatz greift die Rücksetzung nicht.
            if ($energie->exists
                && ($energie->isDirty('status') || $energie->isDirty('ausnahme_begruendung'))
                && ! $energie->isDirty('ausnahme_bestaetigt_von_user_id')) {
                $energie->ausnahme_bestaetigt_at = null;
                $energie->ausnahme_bestaetigt_von_user_id = null;
                $energie->ausnahme_bestaetigt_hash = null;
            }
        });
    }

    /**
     * Ob die Ausnahme von der Ausweispflicht bestätigt ist: Zeitpunkt und
     * Begründung liegen vor (Masterprompt-Abgleich B.5). Trägt der
     * Datensatz zusätzlich einen Bestätigungshash (Prüfbericht 2026-09-12,
     * Befund 3, gesetzt von ReviewController::confirmEnergyException), muss
     * er zur aktuellen Begründung passen; das schützt zusätzlich zur
     * Rücksetzung in booted() gegen eine Begründung, die nach der
     * Bestätigung unbemerkt verändert wurde, ohne dass die Rücksetzung
     * gegriffen hat.
     */
    public function ausnahmeBestaetigt(): bool
    {
        $begruendung = trim((string) $this->ausnahme_begruendung);

        if ($this->ausnahme_bestaetigt_at === null || $begruendung === '') {
            return false;
        }

        if ($this->ausnahme_bestaetigt_hash === null) {
            return true;
        }

        return hash_equals($this->ausnahme_bestaetigt_hash, self::hashBegruendung($begruendung));
    }

    /**
     * Hash der Begründung, an den die Adminbestätigung gebunden wird
     * (Prüfbericht 2026-09-12, Befund 3).
     */
    public static function hashBegruendung(string $begruendung): string
    {
        return hash('sha256', trim($begruendung));
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function ausnahmeBestaetigtVon(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ausnahme_bestaetigt_von_user_id');
    }
}
