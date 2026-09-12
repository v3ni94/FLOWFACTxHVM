<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Steps;

use App\Models\Listing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Vertrag für die acht Schritte des Erfassungsassistenten (docs/masterprompt-abgleich.md B.1).
 *
 * Jeder Schritt ist eine eigene Klasse Schritt{n}Controller in diesem Namespace.
 * Der StepDispatcher löst sie über die Schrittnummer auf. So können mehrere
 * Agenten Schritte unabhängig entwickeln, ohne dieselbe Datei zu verändern.
 */
interface StepHandler
{
    /** Formular des Schritts anzeigen. */
    public function show(Request $request, Listing $listing): View;

    /**
     * Vollständiges Speichern per Formular-POST. Erwartet einen Redirect
     * (Weiter, Zurück oder derselbe Schritt) mit Flash-Meldung.
     */
    public function store(Request $request, Listing $listing): RedirectResponse;

    /**
     * Autosave per PATCH mit JSON-Körper. Antwort:
     * { "ok": true, "gespeichert_at": "HH:MM:SS", "fehlend": [...], "hinweise": [...] }
     * oder bei Validierungsfehlern HTTP 422 mit { "ok": false, "errors": {...} }.
     * Autosave löst niemals eine Übertragung oder Veröffentlichung aus.
     */
    public function autosave(Request $request, Listing $listing): JsonResponse;
}
