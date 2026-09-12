<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SyncStatus;
use Database\Factories\ListingFlowfactLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FLOWFACT-Verknüpfung, 1:1 zu Listing (Datenvertrag Abschnitt 2.8).
 *
 * release_id: zuletzt erfolgreich übertragene Freigabeversion (B.6).
 * flowfact_last_modified: _metadata.lastModifiedTimestamp der FLOWFACT-Entität
 * nach dem letzten Anlegen oder Aktualisieren (Konflikterkennung, Masterprompt
 * Abschnitt 23), als Zeichenkette wie geliefert.
 * gesendete_felder_json: FLOWFACT-Feldnamen, die mit der letzten Übertragung
 * tatsächlich mit Wert gesendet wurden; nur diese dürfen später geleert
 * werden (Prüfbericht 2026-09-12, Befund 5).
 * flowfact_status: zuletzt gesendeter Entitätsstatus, active oder inactive
 * (Prüfbericht 2026-09-12, Befund 10).
 *
 * @property int $listing_id
 * @property string|null $flowfact_entity_id
 * @property string|null $flowfact_schema
 * @property SyncStatus $sync_status
 * @property string|null $letzter_fehler
 * @property string|null $uebertragener_inhalt_hash
 * @property int|null $release_id
 * @property string|null $flowfact_last_modified
 * @property list<string>|null $gesendete_felder_json
 * @property string|null $flowfact_status
 * @property string|null $sperre_token
 */
class ListingFlowfactLink extends Model
{
    /** @use HasFactory<ListingFlowfactLinkFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sync_status' => SyncStatus::class,
            'letzte_uebertragung_at' => 'datetime',
            'sperre_bis' => 'datetime',
            'gesendete_felder_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public const string STATUS_AKTIV = 'active';

    public const string STATUS_INAKTIV = 'inactive';

    /**
     * FLOWFACT-Feldnamen der letzten Übertragung (Befund 5). Zeilen aus der
     * Zeit vor dieser Spalte liefern null; der Aufrufer greift dann auf die
     * Vorgängerversion zurück.
     *
     * @return list<string>|null
     */
    public function gesendeteFelder(): ?array
    {
        $felder = $this->gesendete_felder_json;

        if (! is_array($felder)) {
            return null;
        }

        return array_values(array_unique(array_map('strval', array_filter($felder, 'is_scalar'))));
    }

    /**
     * Eigenes Feld => FLOWFACT-Feld der letzten Übertragung (Befund 5).
     * Ältere Listen ohne Schlüssel liefern eine leere Zuordnung.
     *
     * @return array<string, string>
     */
    public function gesendeteZuordnung(): array
    {
        $felder = $this->gesendete_felder_json;

        if (! is_array($felder)) {
            return [];
        }

        $zuordnung = [];

        foreach ($felder as $quelle => $ziel) {
            if (is_string($quelle) && is_scalar($ziel)) {
                $zuordnung[$quelle] = (string) $ziel;
            }
        }

        return $zuordnung;
    }

    /**
     * Ob die Entität zuletzt mit einem inaktiven Status gesendet wurde
     * (Befund 10). Ältere Zeilen ohne Wert wurden immer aktiv gesendet.
     */
    public function inaktivGesendet(): bool
    {
        return $this->flowfact_status === self::STATUS_INAKTIV;
    }

    /**
     * Zuletzt erfolgreich übertragene Freigabeversion.
     *
     * @return BelongsTo<ListingRelease, $this>
     */
    public function release(): BelongsTo
    {
        return $this->belongsTo(ListingRelease::class, 'release_id');
    }
}
