<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Settings\SettingsRepository;
use Illuminate\Database\Seeder;

/**
 * Setzt die nicht geheimen Standardeinstellungen (Datenvertrag Abschnitt 2.11),
 * ausschließlich dort, wo noch kein Wert hinterlegt ist. Darf gefahrlos in
 * Produktion laufen, weil bereits gesetzte Werte nie überschrieben werden.
 * Erfindet keine Kontaktdaten der Gesellschaft.
 */
class SettingsSeeder extends Seeder
{
    public function run(SettingsRepository $settings): void
    {
        $standardwerte = [
            'firma.name' => 'Hausverwaltung Müller GmbH',
            'firma.strasse' => 'Rheinpromenade 13',
            'firma.plz' => '40789',
            'firma.ort' => 'Monheim am Rhein',
            'firma.registergericht' => 'Amtsgericht Düsseldorf',
            'firma.hrb' => 'HRB 104762',
            'firma.geschaeftsfuehrer' => 'Timo Müller',
            'firma.website' => 'www.muellerhv.de',
            'firma.telefon' => '',
            'firma.email' => '',
            'flowfact.stage' => 'production',
            'flowfact.schema_miete' => '',
            'flowfact.schema_kauf' => '',
            'ki.provider' => 'fake',
            'ki.modell' => '',
        ];

        foreach ($standardwerte as $schluessel => $wert) {
            if ($settings->get($schluessel) === null) {
                $settings->set($schluessel, $wert);
            }
        }
    }
}
