<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\ListingMedia;
use App\Services\Media\MediaUploadService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Liefert Mediendateien über eine signierte, an die Sitzung gebundene Route
 * aus (Datenvertrag Abschnitt 2.6, ADR-012). Die Datei liegt außerhalb des
 * Webroots und wird nie direkt verlinkt.
 */
class MediaStreamController extends Controller
{
    use AuthorizesRequests;

    public function show(ListingMedia $media, string $variante, MediaUploadService $service): StreamedResponse
    {
        abort_unless(in_array($variante, ['original', 'vorschau'], true), 404);

        $this->authorize('view', $media->listing);

        $pfad = $media->pfad;
        $contentType = $media->mime;

        if ($variante === 'vorschau') {
            $vorschauPfad = $service->vorschauPfad($media);

            if ($vorschauPfad !== null) {
                $pfad = $vorschauPfad;
                $contentType = 'image/jpeg';
            }
        }

        abort_unless(Storage::disk('media')->exists($pfad), 404);

        return Storage::disk('media')->response($pfad, $media->dateiname_original, [
            'Content-Type' => $contentType,
        ]);
    }
}
