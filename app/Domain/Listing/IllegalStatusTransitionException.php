<?php

declare(strict_types=1);

namespace App\Domain\Listing;

use RuntimeException;

/**
 * Wird geworfen, wenn ein Statuswechsel gegen die Übergänge aus
 * Datenvertrag Abschnitt 4.1 verstößt oder die Vollständigkeitsprüfung vor
 * "bereit" nicht bestanden wurde.
 */
class IllegalStatusTransitionException extends RuntimeException {}
