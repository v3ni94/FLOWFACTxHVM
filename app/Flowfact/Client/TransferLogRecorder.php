<?php

declare(strict_types=1);

namespace App\Flowfact\Client;

use App\Enums\TransferRichtung;
use App\Models\Listing;
use App\Models\TransferLog;
use App\Models\User;

/**
 * Schreibt je API-Aufruf genau einen Eintrag in transfer_logs
 * (Datenvertrag Abschnitt 2.10, ADR-014, docs/connector.md Abschnitt 2).
 *
 * Token werden vor dem Speichern entfernt, Nutzdaten auf log_body_limit
 * Byte gekürzt. Interne Felder können hier nicht auftauchen, weil der Mapper
 * sie nie liest (Positivliste, ADR-003).
 */
final class TransferLogRecorder
{
    public function __construct(
        private readonly TokenScrubber $scrubber,
        private readonly int $bodyLimit = 4096,
    ) {}

    public function record(
        string $aktion,
        string $methode,
        string $url,
        mixed $requestBody,
        ?int $status,
        ?string $responseBody,
        int $dauerMs,
        bool $erfolgreich,
        string $zusammenfassung,
        ?Listing $listing = null,
        ?User $user = null,
        ?string $fehler = null,
    ): TransferLog {
        $details = [
            'methode' => $methode,
            'url' => $this->scrubber->scrub($url),
            'request' => $this->kuerzen($this->serialisiere($requestBody)),
            'response' => $this->kuerzen($this->scrubber->scrub($responseBody)),
        ];

        if ($fehler !== null) {
            $details['fehler'] = $this->kuerzen($this->scrubber->scrub($fehler));
        }

        return TransferLog::query()->create([
            'listing_id' => $listing?->id,
            'user_id' => $user?->id,
            'aktion' => mb_substr((string) $this->scrubber->scrub($aktion), 0, 255),
            'richtung' => TransferRichtung::Ausgehend,
            'http_status' => $status,
            'erfolgreich' => $erfolgreich,
            'zusammenfassung' => mb_substr((string) $this->scrubber->scrub($zusammenfassung), 0, 255),
            'details' => $details,
            'dauer_ms' => max(0, $dauerMs),
        ]);
    }

    private function serialisiere(mixed $body): ?string
    {
        if ($body === null) {
            return null;
        }

        if (is_string($body)) {
            return $this->scrubber->scrub($body);
        }

        $json = json_encode($this->scrubber->scrubArray((array) $body), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? '[nicht serialisierbar]' : $json;
    }

    private function kuerzen(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        if (strlen($text) <= $this->bodyLimit) {
            return $text;
        }

        return mb_strcut($text, 0, $this->bodyLimit).' [gekürzt]';
    }
}
