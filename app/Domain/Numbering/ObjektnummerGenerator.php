<?php

declare(strict_types=1);

namespace App\Domain\Numbering;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Vergibt die lesbare Objektnummer im Format MF-JJJJ-NNNN
 * (Datenvertrag Abschnitt 2.2), fortlaufend je Kalenderjahr.
 *
 * Die Vergabe läuft in einer Transaktion mit einer Zeilensperre auf den
 * Jahreszähler, damit zwei gleichzeitige Anfragen nie dieselbe Nummer
 * erhalten.
 */
final class ObjektnummerGenerator
{
    public function next(?int $jahr = null): string
    {
        $jahr ??= (int) now()->format('Y');

        $naechsteNummer = DB::transaction(function () use ($jahr): int {
            $zeile = DB::table('objektnummer_counters')
                ->where('jahr', $jahr)
                ->lockForUpdate()
                ->first();

            if ($zeile === null) {
                $this->legeJahrAn($jahr);

                $zeile = DB::table('objektnummer_counters')
                    ->where('jahr', $jahr)
                    ->lockForUpdate()
                    ->first();
            }

            $naechsteNummer = (int) $zeile->letzte_nummer + 1;

            DB::table('objektnummer_counters')
                ->where('jahr', $jahr)
                ->update(['letzte_nummer' => $naechsteNummer]);

            return $naechsteNummer;
        });

        return sprintf('MF-%04d-%04d', $jahr, $naechsteNummer);
    }

    /**
     * Legt die Zählerzeile für ein Jahr an. Läuft ein zweiter Prozess
     * gleichzeitig in dieselbe Anlage, wird der Eindeutigkeitsfehler des
     * Primärschlüssels stillschweigend geschluckt, weil die anschließende
     * Sperre in next() ohnehin die dann bereits vorhandene Zeile liest.
     */
    private function legeJahrAn(int $jahr): void
    {
        try {
            DB::table('objektnummer_counters')->insert([
                'jahr' => $jahr,
                'letzte_nummer' => 0,
            ]);
        } catch (QueryException) {
            // Zeile wurde zwischenzeitlich von einem parallelen Prozess angelegt.
        }
    }
}
