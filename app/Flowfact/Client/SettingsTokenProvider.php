<?php

declare(strict_types=1);

namespace App\Flowfact\Client;

use App\Domain\Settings\SettingsRepository;

/**
 * Liest flowfact.api_token (Secret) und flowfact.company_id aus den
 * Einstellungen (Datenvertrag Abschnitt 2.11, ADR-008).
 */
final class SettingsTokenProvider implements TokenProvider
{
    public const string TOKEN_KEY = 'flowfact.api_token';

    public const string COMPANY_KEY = 'flowfact.company_id';

    public function __construct(
        private readonly SettingsRepository $settings,
    ) {}

    public function token(): ?string
    {
        $token = $this->settings->getSecret(self::TOKEN_KEY);

        return is_string($token) && trim($token) !== '' ? trim($token) : null;
    }

    public function companyId(): ?string
    {
        $companyId = $this->settings->get(self::COMPANY_KEY);

        return is_string($companyId) && trim($companyId) !== '' ? trim($companyId) : null;
    }
}
