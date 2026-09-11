<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Geldbeträge als Ganzzahl in Cent (Datenvertrag Abschnitt 1, ADR-010).
 *
 * Anzeige immer im Format 1.234,56 EUR. Diese Klasse ist die einzige Stelle,
 * die zwischen Cent-Ganzzahlen und der deutschen Textdarstellung umrechnet.
 */
final class Money
{
    /**
     * Formatiert Cent als "1.234,56 EUR".
     */
    public static function format(int $cent): string
    {
        return self::formatPlain($cent).' EUR';
    }

    /**
     * Formatiert Cent als "1.234,56" ohne Einheit.
     */
    public static function formatPlain(int $cent): string
    {
        return number_format($cent / 100, 2, ',', '.');
    }

    /**
     * Wandelt eine deutsch formatierte Eingabe in Cent um.
     *
     * Akzeptiert "1.234,56", "1234,56", "1234" und "1.234". Ein einzelner
     * Punkt gilt nur dann als Tausendertrennzeichen, wenn ihm genau drei
     * Ziffern folgen und kein Komma vorkommt, sonst wird die Eingabe als
     * ungültig zurückgewiesen, um Verwechslung mit einem Dezimalpunkt
     * auszuschließen.
     */
    public static function parse(string $input): ?int
    {
        $bereinigt = trim($input);

        if ($bereinigt === '') {
            return null;
        }

        $negativ = false;

        if (str_starts_with($bereinigt, '-')) {
            $negativ = true;
            $bereinigt = substr($bereinigt, 1);
        }

        if (! preg_match('/^\d{1,3}(\.\d{3})*(,\d{1,2})?$|^\d+(,\d{1,2})?$/', $bereinigt)) {
            return null;
        }

        if (str_contains($bereinigt, ',')) {
            [$ganzzahlteil, $nachkomma] = explode(',', $bereinigt, 2);
            $ganzzahlteil = str_replace('.', '', $ganzzahlteil);
            $nachkomma = str_pad($nachkomma, 2, '0', STR_PAD_RIGHT);
        } else {
            $ganzzahlteil = $bereinigt;
            $nachkomma = '00';

            // Ein einzelner Punkt mit genau drei folgenden Ziffern gilt als
            // Tausendertrennzeichen, mehrere Punkte ebenso.
            if (substr_count($ganzzahlteil, '.') >= 1) {
                $teile = explode('.', $ganzzahlteil);
                $ersterTeil = array_shift($teile);

                foreach ($teile as $teil) {
                    if (strlen($teil) !== 3) {
                        return null;
                    }
                }

                $ganzzahlteil = $ersterTeil.implode('', $teile);
            }
        }

        if ($ganzzahlteil === '' || ! ctype_digit($ganzzahlteil)) {
            return null;
        }

        $cent = ((int) $ganzzahlteil) * 100 + (int) $nachkomma;

        return $negativ ? -$cent : $cent;
    }
}
