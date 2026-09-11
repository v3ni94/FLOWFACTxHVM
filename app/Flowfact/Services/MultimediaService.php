<?php

declare(strict_types=1);

namespace App\Flowfact\Services;

/**
 * multimedia-service (flowfact-api.md Abschnitt 5.3 und 8).
 */
final class MultimediaService extends AbstractService
{
    public const string SERVICE = 'multimedia-service';

    /**
     * @return list<array<string, mixed>>
     */
    public function albums(string $schema): array
    {
        $antwort = $this->client->get(self::SERVICE, '/albums/schemas/{schema}', ['schema' => $schema]);

        return is_array($antwort) ? array_values(array_filter($antwort, 'is_array')) : [];
    }

    /**
     * @return array{presignedUrl: string, itemLink: string}
     */
    public function presignedUrl(string $schema, string $entityId, string $contentType, string $fileName, int $fileSize): array
    {
        $antwort = $this->client->get(
            self::SERVICE,
            '/items/schemas/{schema}/entities/{id}/presigned-url',
            ['schema' => $schema, 'id' => $entityId],
            ['contentType' => $contentType, 'fileName' => $fileName, 'fileSize' => $fileSize],
        );

        return [
            'presignedUrl' => is_array($antwort) ? (string) ($antwort['presignedUrl'] ?? '') : '',
            'itemLink' => is_array($antwort) ? (string) ($antwort['itemLink'] ?? '') : '',
        ];
    }

    public function uploadBinary(string $presignedUrl, string $content, string $contentType): void
    {
        $this->client->uploadBinary($presignedUrl, $content, $contentType);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed> Das angelegte MultimediaItem
     */
    public function registerItem(string $schema, string $entityId, array $body): array
    {
        $antwort = $this->client->post(self::SERVICE, '/items/schemas/{schema}/entities/{id}', ['schema' => $schema, 'id' => $entityId], $body);

        if (is_array($antwort) && isset($antwort['multimediaItem']) && is_array($antwort['multimediaItem'])) {
            return $antwort['multimediaItem'];
        }

        return is_array($antwort) ? $antwort : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function items(string $entityId, ?string $contentCategory = null): array
    {
        $query = $contentCategory !== null ? ['contentCategory' => $contentCategory] : [];
        $antwort = $this->client->get(self::SERVICE, '/items/entities/{id}', ['id' => $entityId], $query);

        return is_array($antwort) ? array_values(array_filter($antwort, 'is_array')) : [];
    }

    /**
     * Setzt die komplette Zuordnung eines Albums inklusive Sortierung.
     *
     * @param  array<string, list<array{multimedia: array<string, mixed>, sorting: int}>>  $assignments
     */
    public function setAssignments(string $schema, string $entityId, string $albumName, array $assignments): void
    {
        $this->client->put(
            self::SERVICE,
            '/assigned/schemas/{schema}/entities/{id}',
            ['schema' => $schema, 'id' => $entityId],
            ['assignments' => $assignments],
            ['albumName' => $albumName, 'short' => 'false'],
        );
    }

    public function deleteItem(string|int $itemId): void
    {
        $this->client->delete(self::SERVICE, '/items/{id}', ['id' => $itemId]);
    }

    /**
     * Ändert Eigenschaften eines Items über JSON-Patch (flowfact-api.md
     * Abschnitt 8), z. B. den Titel.
     *
     * @param  list<array{op: string, path: string, value?: mixed}>  $jsonPatch
     * @return array<string, mixed> Das geänderte MultimediaItem
     */
    public function patchItem(string|int $itemId, array $jsonPatch): array
    {
        $antwort = $this->client->patch(self::SERVICE, '/items/{id}', ['id' => $itemId], $jsonPatch);

        if (is_array($antwort) && isset($antwort['multimediaItem']) && is_array($antwort['multimediaItem'])) {
            return $antwort['multimediaItem'];
        }

        return is_array($antwort) ? $antwort : [];
    }
}
