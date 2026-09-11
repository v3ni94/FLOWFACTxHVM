<?php

declare(strict_types=1);

namespace App\Flowfact\Services;

use App\Flowfact\Client\Exceptions\FlowfactException;

/**
 * entity-service (flowfact-api.md Abschnitt 5.1).
 */
final class EntityService extends AbstractService
{
    public const string SERVICE = 'entity-service';

    /**
     * Legt eine Entität an und liefert die FLOWFACT-ID.
     *
     * Die Antwort kann laut SDK eine vollständige Entität oder nur die ID
     * sein (offener Punkt 7). Beide Formen werden akzeptiert; ohne ID
     * endet der Aufruf als Fehler, damit nie eine Entität ohne bekannte ID
     * zurückbleibt.
     *
     * @param  array<string, mixed>  $fields
     */
    public function create(string $schema, array $fields): string
    {
        $antwort = $this->client->post(self::SERVICE, '/schemas/{schema}', ['schema' => $schema], $fields, [], ['x-ff-version' => '2']);

        $id = $this->extrahiereId($antwort);

        if ($id === null) {
            throw new FlowfactException('FLOWFACT hat die Entität angelegt, aber keine ID zurückgegeben. Der nächste Lauf findet sie über die Objektnummer.');
        }

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $schema, string $entityId): array
    {
        $antwort = $this->client->get(self::SERVICE, '/schemas/{schema}/entities/{id}', ['schema' => $schema, 'id' => $entityId]);

        return is_array($antwort) ? $antwort : [];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>|null
     */
    public function patch(string $schema, string $entityId, array $fields): ?array
    {
        $antwort = $this->client->patch(self::SERVICE, '/schemas/{schema}/entities/{id}', ['schema' => $schema, 'id' => $entityId], $fields);

        return is_array($antwort) ? $antwort : null;
    }

    public function delete(string $schema, string $entityId): void
    {
        $this->client->delete(self::SERVICE, '/schemas/{schema}/entities/{id}', ['schema' => $schema, 'id' => $entityId]);
    }

    /**
     * Papierkorb eines Schemas (Smoke-Test Schritt 17).
     *
     * @return array<string, mixed>
     */
    public function recoveryEntities(string $schema, int $size = 50): array
    {
        $antwort = $this->client->get(self::SERVICE, '/recovery/entities', [], ['page' => 1, 'size' => $size, 'schema' => $schema]);

        return is_array($antwort) ? $antwort : [];
    }

    private function extrahiereId(mixed $antwort): ?string
    {
        if (is_string($antwort) && trim($antwort) !== '') {
            return trim($antwort, " \t\n\r\0\x0B\"");
        }

        if (is_array($antwort)) {
            foreach (['id', 'entityId'] as $schluessel) {
                if (isset($antwort[$schluessel]) && is_scalar($antwort[$schluessel]) && (string) $antwort[$schluessel] !== '') {
                    return (string) $antwort[$schluessel];
                }
            }

            if (isset($antwort['_metadata']['id']) && is_scalar($antwort['_metadata']['id'])) {
                return (string) $antwort['_metadata']['id'];
            }
        }

        return null;
    }
}
