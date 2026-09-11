<?php

declare(strict_types=1);

namespace App\Flowfact\Sync;

final readonly class PortalInfo
{
    public function __construct(
        public string $id,
        public string $name,
        public string $type,
        public bool $authenticated,
    ) {}
}
