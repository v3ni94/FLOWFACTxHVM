<?php

declare(strict_types=1);

namespace App\Flowfact\Services;

/**
 * portal-management-service (flowfact-api.md Abschnitt 5.4 und 6).
 */
final class PortalService extends AbstractService
{
    public const string SERVICE = 'portal-management-service';

    /**
     * @return list<array<string, mixed>>
     */
    public function portals(bool $ignoreInactive = true): array
    {
        $antwort = $this->client->get(self::SERVICE, '/portals', [], ['ignoreInactivePortals' => $ignoreInactive ? 'true' : 'false']);

        return is_array($antwort) ? array_values(array_filter($antwort, 'is_array')) : [];
    }

    /**
     * Ist-Zustand: auf welchen Portalen liegt das Objekt (mit onlineSince).
     *
     * @return list<array<string, mixed>>
     */
    public function estatePortals(string $estateId): array
    {
        $antwort = $this->client->get(self::SERVICE, '/estates/{id}/portals', ['id' => $estateId]);

        return is_array($antwort) ? array_values(array_filter($antwort, 'is_array')) : [];
    }

    /**
     * POST /publish. Ein leerer Körper bedeutet "angenommen, Ergebnis später"
     * (asynchrone Verarbeitung) und wird als null geliefert.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>|null
     */
    public function publish(array $request): ?array
    {
        $antwort = $this->client->post(self::SERVICE, '/publish', [], $request);

        return is_array($antwort) ? $antwort : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function estateSettings(string $estateId): array
    {
        $antwort = $this->client->get(self::SERVICE, '/portals/estates/{id}', ['id' => $estateId]);

        return is_array($antwort) ? array_values(array_filter($antwort, 'is_array')) : [];
    }

    public function deleteEstateLink(string $portalId, string $entityId): void
    {
        $this->client->delete(self::SERVICE, '/portals/{portalId}/estates/{id}', ['portalId' => $portalId, 'id' => $entityId]);
    }
}
