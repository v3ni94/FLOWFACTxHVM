<?php

declare(strict_types=1);

namespace App\Flowfact\Client\Exceptions;

/**
 * 404: Die angesprochene Ressource existiert in FLOWFACT nicht (mehr).
 */
final class NotFoundException extends FlowfactException {}
