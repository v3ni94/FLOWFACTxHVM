<?php

declare(strict_types=1);

namespace App\Domain\Listing;

use App\Enums\Objektart;

/**
 * Ausstattungsmerkmale mit deutschen Bezeichnungen und der Zuordnung, für
 * welche Objektarten sie erfasst werden (Masterprompt-Abgleich B.2). Die
 * Werte je Merkmal sind dreiwertig (App\Enums\MerkmalWert).
 */
final class Merkmale
{
    /**
     * @var array<string, string>
     */
    public const array LABELS = [
        'balkon' => 'Balkon',
        'terrasse' => 'Terrasse',
        'garten' => 'Garten',
        'gartennutzung' => 'Gartenmitbenutzung',
        'aufzug' => 'Aufzug',
        'keller' => 'Keller',
        'abstellraum' => 'Abstellraum',
        'einbaukueche' => 'Einbauküche',
        'gaeste_wc' => 'Gäste-WC',
        'badewanne' => 'Badewanne',
        'dusche' => 'Dusche',
        'tageslichtbad' => 'Tageslichtbad',
        'fussbodenheizung' => 'Fußbodenheizung',
        'rollladen' => 'Rollläden',
        'moebliert' => 'Möbliert',
        'stufenlos' => 'Stufenlos erreichbar',
        'barrierearm' => 'Barrierearm',
        'rollstuhlgeeignet' => 'Rollstuhlgeeignet',
        'haustiere_erlaubt' => 'Haustiere erlaubt',
        'wg_geeignet' => 'WG-geeignet',
    ];

    /**
     * Objektarten, für die das Merkmal erfasst wird. Grundstücke und
     * Stellplätze haben keine Ausstattungsmerkmale.
     *
     * @var array<string, list<string>>
     */
    public const array OBJEKTARTEN = [
        'balkon' => ['wohnung', 'haus', 'mehrfamilienhaus'],
        'terrasse' => ['wohnung', 'haus', 'mehrfamilienhaus'],
        'garten' => ['wohnung', 'haus', 'mehrfamilienhaus'],
        'gartennutzung' => ['wohnung', 'mehrfamilienhaus'],
        'aufzug' => ['wohnung', 'mehrfamilienhaus', 'gewerbe'],
        'keller' => ['wohnung', 'haus', 'mehrfamilienhaus', 'gewerbe'],
        'abstellraum' => ['wohnung', 'haus', 'mehrfamilienhaus', 'gewerbe'],
        'einbaukueche' => ['wohnung', 'haus', 'mehrfamilienhaus', 'gewerbe'],
        'gaeste_wc' => ['wohnung', 'haus', 'mehrfamilienhaus', 'gewerbe'],
        'badewanne' => ['wohnung', 'haus', 'mehrfamilienhaus'],
        'dusche' => ['wohnung', 'haus', 'mehrfamilienhaus'],
        'tageslichtbad' => ['wohnung', 'haus', 'mehrfamilienhaus'],
        'fussbodenheizung' => ['wohnung', 'haus', 'mehrfamilienhaus', 'gewerbe'],
        'rollladen' => ['wohnung', 'haus', 'mehrfamilienhaus', 'gewerbe'],
        'moebliert' => ['wohnung', 'haus', 'mehrfamilienhaus', 'gewerbe'],
        'stufenlos' => ['wohnung', 'haus', 'mehrfamilienhaus', 'gewerbe'],
        'barrierearm' => ['wohnung', 'haus', 'mehrfamilienhaus', 'gewerbe'],
        'rollstuhlgeeignet' => ['wohnung', 'haus', 'mehrfamilienhaus', 'gewerbe'],
        'haustiere_erlaubt' => ['wohnung', 'haus', 'mehrfamilienhaus'],
        'wg_geeignet' => ['wohnung'],
    ];

    /**
     * Ältere Schlüssel aus dem Datenvertrag Abschnitt 2.2, die von neuen
     * Merkmalen abgelöst wurden. Schlüssel: neues Merkmal, Wert: alter Schlüssel.
     *
     * @var array<string, string>
     */
    public const array ALIASE = [
        'barrierearm' => 'barrierefrei',
    ];

    /**
     * @return list<string>
     */
    public static function schluessel(): array
    {
        return array_keys(self::LABELS);
    }

    public static function label(string $schluessel): string
    {
        return self::LABELS[$schluessel] ?? $schluessel;
    }

    public static function giltFuer(string $schluessel, Objektart $objektart): bool
    {
        return in_array($objektart->value, self::OBJEKTARTEN[$schluessel] ?? [], true);
    }

    /**
     * @return list<string>
     */
    public static function fuerObjektart(Objektart $objektart): array
    {
        return array_values(array_filter(
            self::schluessel(),
            fn (string $schluessel): bool => self::giltFuer($schluessel, $objektart),
        ));
    }
}
