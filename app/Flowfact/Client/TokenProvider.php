<?php

declare(strict_types=1);

namespace App\Flowfact\Client;

/**
 * Liefert Token und optionale Company-ID für den FLOWFACT-Client
 * (docs/connector.md Abschnitt 1). Der Token verlässt diese Schicht nur als
 * HTTP-Header und nie in Protokolle, Meldungen oder Ansichten.
 */
interface TokenProvider
{
    public function token(): ?string;

    public function companyId(): ?string;

    /**
     * Übertragungsform des Tokens, einer der Werte aus TokenHeader.
     */
    public function tokenHeader(): string;
}
