<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Listing\ListingContentHasher;
use App\Domain\Listing\Merkmale;
use App\Domain\Listing\PriceStructure;
use App\Domain\Numbering\ObjektnummerGenerator;
use App\Enums\AdressFreigabe;
use App\Enums\Ausstattungsqualitaet;
use App\Enums\Energietraeger;
use App\Enums\GewerbeUnterart;
use App\Enums\HeizkostenVersorgung;
use App\Enums\Heizungsart;
use App\Enums\ListingStatus;
use App\Enums\MerkmalWert;
use App\Enums\Nutzungsstatus;
use App\Enums\Objektart;
use App\Enums\StellplatzTyp;
use App\Enums\VerfuegbarAbTyp;
use App\Enums\Vermarktungsart;
use App\Enums\Waermeabgabe;
use App\Enums\Warmwasserbereitung;
use App\Enums\Zustand;
use Database\Factories\ListingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * Objekt (Datenvertrag Abschnitt 2.2).
 *
 * @property int $id
 * @property string $uuid
 * @property string $objektnummer
 * @property Vermarktungsart $vermarktungsart
 * @property Objektart $objektart
 * @property string|null $titel
 * @property string|null $strasse
 * @property string|null $hausnummer
 * @property string|null $plz
 * @property string|null $ort
 * @property ListingStatus $status
 * @property GewerbeUnterart|null $gewerbe_unterart
 * @property Nutzungsstatus $nutzungsstatus
 * @property AdressFreigabe $adress_freigabe
 * @property bool $adresse_im_inserat_anzeigen
 * @property array<string, mixed>|null $ausstattung
 * @property HeizkostenVersorgung|null $heizkosten_versorgung
 * @property ListingRelease|null $latestRelease
 */
class Listing extends Model
{
    /** @use HasFactory<ListingFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vermarktungsart' => Vermarktungsart::class,
            'objektart' => Objektart::class,
            'gewerbe_unterart' => GewerbeUnterart::class,
            'nutzungsstatus' => Nutzungsstatus::class,
            'adress_freigabe' => AdressFreigabe::class,
            'zustand' => Zustand::class,
            'ausstattungsqualitaet' => Ausstattungsqualitaet::class,
            'heizungsart' => Heizungsart::class,
            'energietraeger' => Energietraeger::class,
            'heizkosten_versorgung' => HeizkostenVersorgung::class,
            'heizung_waermeabgabe' => Waermeabgabe::class,
            'heizung_warmwasser' => Warmwasserbereitung::class,
            'einbaukueche_mitvermietet' => 'boolean',
            'verfuegbar_ab_typ' => VerfuegbarAbTyp::class,
            'verfuegbar_ab_datum' => 'date',
            'stellplatz_typ' => StellplatzTyp::class,
            'status' => ListingStatus::class,
            'ausstattung' => 'array',
            'adresse_im_inserat_anzeigen' => 'boolean',
            'wohnflaeche_qm' => 'decimal:2',
            'nutzflaeche_qm' => 'decimal:2',
            'gewerbeflaeche_qm' => 'decimal:2',
            'grundstuecksflaeche_qm' => 'decimal:2',
            'zimmer' => 'decimal:1',
            'inhalt_geaendert_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Listing $listing): void {
            if (empty($listing->uuid)) {
                $listing->uuid = (string) Str::uuid();
            }

            if (empty($listing->objektnummer)) {
                $listing->objektnummer = app(ObjektnummerGenerator::class)->next();
            }

            if ($listing->getAttribute('nutzungsstatus') === null) {
                $listing->nutzungsstatus = Nutzungsstatus::Unbekannt;
            }
        });

        // Adressfreigabe und das ältere Flag adresse_im_inserat_anzeigen
        // bleiben in beide Richtungen konsistent (Masterprompt-Abgleich B.2):
        // der neue Assistent schreibt adress_freigabe, der bisherige das Flag.
        static::saving(function (Listing $listing): void {
            if (! $listing->exists) {
                // Beim Anlegen gewinnt das ausdrücklich gesetzte Feld; sind beide
                // gesetzt, führt adress_freigabe.
                $attribute = $listing->getAttributes();

                if (($attribute['adress_freigabe'] ?? null) !== null) {
                    $listing->adresse_im_inserat_anzeigen = $listing->adress_freigabe->adresseAnzeigen();
                } elseif (array_key_exists('adresse_im_inserat_anzeigen', $attribute) && $attribute['adresse_im_inserat_anzeigen'] !== null) {
                    $listing->adress_freigabe = AdressFreigabe::ausAnzeigen((bool) $listing->adresse_im_inserat_anzeigen);
                } else {
                    $listing->adress_freigabe = AdressFreigabe::Vollstaendig;
                    $listing->adresse_im_inserat_anzeigen = true;
                }

                return;
            }

            if ($listing->isDirty('adress_freigabe') && $listing->adress_freigabe !== null) {
                $listing->adresse_im_inserat_anzeigen = $listing->adress_freigabe->adresseAnzeigen();

                return;
            }

            if ($listing->isDirty('adresse_im_inserat_anzeigen')) {
                $listing->adress_freigabe = AdressFreigabe::ausAnzeigen((bool) $listing->adresse_im_inserat_anzeigen);
            }
        });

        // Ändert sich die Heizkostenversorgung (z. B. Schritt 2 des bisherigen
        // Assistenten), folgt eine bereits gesetzte Heizkostenstruktur nach.
        static::saved(function (Listing $listing): void {
            if (! $listing->wasChanged('heizkosten_versorgung') || $listing->heizkosten_versorgung === null) {
                return;
            }

            $preis = $listing->price;

            if ($preis === null || $preis->heizkosten_struktur === null) {
                return;
            }

            $struktur = PriceStructure::struktur($listing->heizkosten_versorgung, (bool) $preis->heizkosten_in_nebenkosten_enthalten);

            if ($struktur !== null && $struktur !== $preis->heizkosten_struktur) {
                $preis->heizkosten_struktur = $struktur;
                $preis->save();
            }
        });
    }

    /**
     * @return HasOne<ListingPrice, $this>
     */
    public function price(): HasOne
    {
        return $this->hasOne(ListingPrice::class);
    }

    /**
     * @return HasOne<ListingEnergy, $this>
     */
    public function energy(): HasOne
    {
        return $this->hasOne(ListingEnergy::class);
    }

    /**
     * @return HasOne<ListingInternal, $this>
     */
    public function internal(): HasOne
    {
        return $this->hasOne(ListingInternal::class);
    }

    /**
     * @return HasOne<ListingFlowfactLink, $this>
     */
    public function flowfactLink(): HasOne
    {
        return $this->hasOne(ListingFlowfactLink::class);
    }

    /**
     * @return HasMany<ListingMedia, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(ListingMedia::class)->orderBy('sortierung');
    }

    /**
     * @return HasMany<ListingText, $this>
     */
    public function texts(): HasMany
    {
        return $this->hasMany(ListingText::class);
    }

    /**
     * @return HasMany<ListingPortalPublication, $this>
     */
    public function portalPublications(): HasMany
    {
        return $this->hasMany(ListingPortalPublication::class);
    }

    /**
     * @return HasMany<TransferLog, $this>
     */
    public function transferLogs(): HasMany
    {
        return $this->hasMany(TransferLog::class)->latest();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function ansprechpartner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ansprechpartner_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function erstelltVon(): BelongsTo
    {
        return $this->belongsTo(User::class, 'erstellt_von_user_id');
    }

    /**
     * Zuständiger Mitarbeiter (Masterprompt-Abgleich B.1 Schritt 1). Die
     * Spalte bearbeiter_user_id legt die Migration der Benutzerverwaltung an.
     *
     * @return BelongsTo<User, $this>
     */
    public function bearbeiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bearbeiter_user_id');
    }

    /**
     * @return HasMany<ListingRelease, $this>
     */
    public function releases(): HasMany
    {
        return $this->hasMany(ListingRelease::class)->orderByDesc('version');
    }

    /**
     * Jüngste Freigabeversion (Masterprompt-Abgleich B.6).
     *
     * @return HasOne<ListingRelease, $this>
     */
    public function latestRelease(): HasOne
    {
        return $this->hasOne(ListingRelease::class)->ofMany('version', 'max');
    }

    /**
     * @return HasMany<ListingChange, $this>
     */
    public function changes(): HasMany
    {
        return $this->hasMany(ListingChange::class)->latest('id');
    }

    /**
     * @return HasMany<ListingPortalStatusLog, $this>
     */
    public function portalStatusLogs(): HasMany
    {
        return $this->hasMany(ListingPortalStatusLog::class)->latest('id');
    }

    /**
     * "Unveröffentlichte Änderungen" (Masterprompt-Abgleich B.6): der
     * Live-Hash weicht vom Hash der jüngsten Freigabeversion ab. Ohne
     * Freigabeversion gibt es keinen Vergleichsstand, dann false.
     */
    public function hatUnveroeffentlichteAenderungen(): bool
    {
        $release = $this->latestRelease()->first();

        if ($release === null) {
            return false;
        }

        return (new ListingContentHasher)->hash($this) !== $release->inhalt_hash;
    }

    /**
     * Dreiwertiges Ausstattungsmerkmal (Masterprompt-Abgleich B.2). Liest
     * ältere boolesche Werte (true = ja, false = nein) ebenso wie die neuen
     * Zeichenketten; fehlende Schlüssel gelten als unbekannt. Für umbenannte
     * Merkmale greift der ältere Schlüssel (Merkmale::ALIASE).
     */
    public function merkmal(string $schluessel): MerkmalWert
    {
        $werte = is_array($this->ausstattung) ? $this->ausstattung : [];

        if (array_key_exists($schluessel, $werte)) {
            return MerkmalWert::aus($werte[$schluessel]);
        }

        $alias = Merkmale::ALIASE[$schluessel] ?? null;

        if ($alias !== null && array_key_exists($alias, $werte)) {
            return MerkmalWert::aus($werte[$alias]);
        }

        return MerkmalWert::Unbekannt;
    }

    /**
     * Alle zur Objektart passenden Merkmale mit ihrem dreiwertigen Wert.
     *
     * @return array<string, MerkmalWert>
     */
    public function merkmale(): array
    {
        $ergebnis = [];

        foreach (Merkmale::fuerObjektart($this->objektart) as $schluessel) {
            $ergebnis[$schluessel] = $this->merkmal($schluessel);
        }

        return $ergebnis;
    }

    public function istMiete(): bool
    {
        return $this->vermarktungsart === Vermarktungsart::Miete;
    }

    public function istKauf(): bool
    {
        return $this->vermarktungsart === Vermarktungsart::Kauf;
    }

    /**
     * Kurzadresse im Format "Straße Hausnummer, PLZ Ort".
     */
    public function adresseKurz(): string
    {
        $strasseTeil = trim(sprintf('%s %s', $this->strasse ?? '', $this->hausnummer ?? ''));
        $ortTeil = trim(sprintf('%s %s', $this->plz ?? '', $this->ort ?? ''));

        return trim(implode(', ', array_filter([$strasseTeil, $ortTeil], fn (string $teil): bool => $teil !== '')));
    }

    /**
     * Markiert das Objekt als inhaltlich geändert (Grundlage für den
     * Vergleich mit dem zuletzt übertragenen Stand, Datenvertrag 2.2).
     */
    public function markContentChanged(): void
    {
        $this->inhalt_geaendert_at = now();
    }

    /**
     * @param  Builder<Listing>  $query
     * @return Builder<Listing>
     */
    public function scopeAktiv(Builder $query): Builder
    {
        return $query->where('status', '!=', ListingStatus::Archiviert->value);
    }
}
