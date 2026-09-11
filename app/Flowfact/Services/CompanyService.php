<?php

declare(strict_types=1);

namespace App\Flowfact\Services;

/**
 * company-service (flowfact-api.md Abschnitt 5.8).
 */
final class CompanyService extends AbstractService
{
    public const string SERVICE = 'company-service';

    /**
     * @return array<string, mixed>
     */
    public function company(string $companyId): array
    {
        $antwort = $this->client->get(self::SERVICE, '/company/{id}', ['id' => $companyId]);

        return is_array($antwort) ? $antwort : [];
    }
}
