<?php

declare(strict_types=1);

namespace App\Flowfact\Services;

/**
 * schema-service v2 (flowfact-api.md Abschnitt 5.2).
 */
final class SchemaService extends AbstractService
{
    public const string SERVICE = 'schema-service';

    /**
     * Konkrete Estate-Schemata des Kontos (Gruppe estates).
     *
     * @return list<array{name: string, caption: string}>
     */
    public function estateSchemas(): array
    {
        $antwort = $this->client->get(self::SERVICE, '/v2/schemas', [], ['group' => 'estates', 'size' => 100, 'page' => 1]);

        $eintraege = is_array($antwort) ? ($antwort['entries'] ?? []) : [];
        $schemata = [];

        foreach ($eintraege as $eintrag) {
            if (! is_array($eintrag) || ! isset($eintrag['name']) || ! is_string($eintrag['name'])) {
                continue;
            }

            $schemata[] = [
                'name' => $eintrag['name'],
                'caption' => self::caption($eintrag['captions'] ?? null, $eintrag['name']),
            ];
        }

        return $schemata;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(string $name): array
    {
        $antwort = $this->client->get(self::SERVICE, '/v2/schemas/{schema}', ['schema' => $name], ['extensions' => 'all']);

        return is_array($antwort) ? $antwort : [];
    }

    /**
     * Properties eines Schemas als flache Liste für Mapper und Adminbereich.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, array{type: string, caption: string}>
     */
    public static function properties(array $schema): array
    {
        $properties = [];

        foreach (($schema['properties'] ?? []) as $feld => $definition) {
            if (! is_string($feld) || ! is_array($definition)) {
                continue;
            }

            $properties[$feld] = [
                'type' => (string) ($definition['type'] ?? ''),
                'caption' => self::caption($definition['captions'] ?? null, $feld),
            ];
        }

        ksort($properties);

        return $properties;
    }

    public static function caption(mixed $captions, string $fallback): string
    {
        if (is_array($captions)) {
            if (isset($captions['de']) && is_string($captions['de'])) {
                return $captions['de'];
            }

            foreach ($captions as $wert) {
                if (is_string($wert) && $wert !== '') {
                    return $wert;
                }
            }
        }

        if (is_string($captions) && $captions !== '') {
            return $captions;
        }

        return $fallback;
    }
}
