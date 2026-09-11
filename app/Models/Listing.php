<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Numbering\ObjektnummerGenerator;
use App\Enums\Ausstattungsqualitaet;
use App\Enums\Energietraeger;
use App\Enums\HeizkostenVersorgung;
use App\Enums\Heizungsart;
use App\Enums\ListingStatus;
use App\Enums\Objektart;
use App\Enums\StellplatzTyp;
use App\Enums\VerfuegbarAbTyp;
use App\Enums\Vermarktungsart;
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
            'zustand' => Zustand::class,
            'ausstattungsqualitaet' => Ausstattungsqualitaet::class,
            'heizungsart' => Heizungsart::class,
            'energietraeger' => Energietraeger::class,
            'heizkosten_versorgung' => HeizkostenVersorgung::class,
            'verfuegbar_ab_typ' => VerfuegbarAbTyp::class,
            'verfuegbar_ab_datum' => 'date',
            'stellplatz_typ' => StellplatzTyp::class,
            'status' => ListingStatus::class,
            'ausstattung' => 'array',
            'adresse_im_inserat_anzeigen' => 'boolean',
            'wohnflaeche_qm' => 'decimal:2',
            'nutzflaeche_qm' => 'decimal:2',
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
