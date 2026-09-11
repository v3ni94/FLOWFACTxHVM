<?php

declare(strict_types=1);

namespace App\Flowfact\Services;

/**
 * user-service (flowfact-api.md Abschnitt 5.8). Dient dem Verbindungstest.
 */
final class UserService extends AbstractService
{
    public const string SERVICE = 'user-service';

    /**
     * @return array<string, mixed>
     */
    public function currentUser(): array
    {
        $antwort = $this->client->get(self::SERVICE, '/users/currentUser', [], [], ['x-ff-version' => '2']);

        return is_array($antwort) ? $antwort : [];
    }
}
