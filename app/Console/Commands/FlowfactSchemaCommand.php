<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Settings\SettingsRepository;
use App\Flowfact\Client\Exceptions\FlowfactException;
use App\Flowfact\Client\FlowfactClient;
use App\Flowfact\Mapping\FieldMappingResolver;
use App\Flowfact\Services\SchemaService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Command\Command as CommandAlias;

/**
 * flow:flowfact:schema
 *
 * Zeigt die Estate-Schemata des Kontos; mit --schema=<name> die Properties
 * des Schemas mit Typ und Caption sowie je eigenem Feld: zugeordnet,
 * Zuordnung fehlt, Zielfeld nicht im Schema (docs/connector.md 4.2). Das
 * Ergebnis wird als JSON in flowfact.schema_cache_<name> abgelegt, damit der
 * Adminbereich die Auswahl der Zielfelder anbieten kann.
 */
class FlowfactSchemaCommand extends Command
{
    protected $signature = 'flow:flowfact:schema {--schema= : Konkreter Schemaname, dessen Felder gelistet werden}';

    protected $description = 'Listet die Estate-Schemata des FLOWFACT-Kontos und prüft die Feldzuordnung.';

    public function handle(FlowfactClient $client, SchemaService $schemata, FieldMappingResolver $resolver, SettingsRepository $settings): int
    {
        if (! $client->isConfigured()) {
            $this->error('Kein FLOWFACT-Token hinterlegt. Bitte den Token im Adminbereich unter FLOWFACT eintragen.');

            return CommandAlias::FAILURE;
        }

        $schemaName = $this->option('schema');

        try {
            if (! is_string($schemaName) || trim($schemaName) === '') {
                return $this->listeSchemata($schemata);
            }

            return $this->zeigeSchema(trim($schemaName), $schemata, $resolver, $settings);
        } catch (FlowfactException $exception) {
            $this->error('FLOWFACT-Fehler: '.$exception->getMessage());

            return CommandAlias::FAILURE;
        }
    }

    private function listeSchemata(SchemaService $schemata): int
    {
        $liste = $schemata->estateSchemas();

        if ($liste === []) {
            $this->warn('Das Konto liefert keine Estate-Schemata (Gruppe estates).');

            return CommandAlias::SUCCESS;
        }

        $this->table(['Schema', 'Bezeichnung'], array_map(fn (array $s): array => [$s['name'], $s['caption']], $liste));
        $this->line('Details mit: php artisan flow:flowfact:schema --schema=<name>');

        return CommandAlias::SUCCESS;
    }

    private function zeigeSchema(string $name, SchemaService $schemata, FieldMappingResolver $resolver, SettingsRepository $settings): int
    {
        $schema = $schemata->schema($name);
        $properties = SchemaService::properties($schema);

        if ($properties === []) {
            $this->warn(sprintf('Das Schema "%s" liefert keine Properties.', $name));
        }

        $settings->set('flowfact.schema_cache_'.$name, [
            'name' => $name,
            'geladen_at' => Carbon::now()->toIso8601String(),
            'properties' => $properties,
        ]);

        $this->info(sprintf('Schema "%s": %d Properties', $name, count($properties)));
        $this->table(
            ['Feld', 'Typ', 'Caption'],
            array_map(fn (string $feld, array $p): array => [$feld, $p['type'], $p['caption']], array_keys($properties), $properties),
        );

        $this->newLine();
        $this->info('Zuordnung der eigenen Inseratsfelder');

        $zeilen = [];

        foreach ($resolver->felder() as $feld => $eintrag) {
            $zeilen[] = [$feld, $eintrag['label'], $eintrag['ziel'] ?? '', FieldMappingResolver::zuordnungsstatus($eintrag['ziel'], $properties)];
        }

        $this->table(['Eigenes Feld', 'Bezeichnung', 'Zielfeld', 'Status'], $zeilen);

        return CommandAlias::SUCCESS;
    }
}
