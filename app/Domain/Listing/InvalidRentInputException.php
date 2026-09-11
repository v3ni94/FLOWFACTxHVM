<?php

declare(strict_types=1);

namespace App\Domain\Listing;

use InvalidArgumentException;

/**
 * Wird geworfen, wenn die Eingaben zur Warmmietenberechnung gegen die Regeln
 * aus Datenvertrag Abschnitt 3 verstoßen.
 */
class InvalidRentInputException extends InvalidArgumentException {}
