<?php

declare(strict_types=1);

namespace App\Flowfact\Services;

use App\Flowfact\Client\FlowfactClient;
use App\Models\Listing;
use App\Models\User;

/**
 * Gemeinsame Basis der Service-Klassen: hält den Client und liefert eine
 * Kopie mit Listing- und Benutzerkontext für das Übertragungsprotokoll.
 */
abstract class AbstractService
{
    public function __construct(
        protected readonly FlowfactClient $client,
    ) {}

    public function scoped(?Listing $listing, ?User $user = null): static
    {
        return new static($this->client->withListing($listing)->withUser($user));
    }

    public function client(): FlowfactClient
    {
        return $this->client;
    }
}
