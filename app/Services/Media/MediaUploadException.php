<?php

declare(strict_types=1);

namespace App\Services\Media;

use RuntimeException;

/**
 * Wird bei einer abgelehnten Mediendatei geworfen (Größe, Typ, Dublette,
 * Anzahl je Objekt). Die Meldung ist bereits benutzertauglich formuliert
 * (Datenvertrag Abschnitt 2.6, ADR-012).
 */
final class MediaUploadException extends RuntimeException {}
