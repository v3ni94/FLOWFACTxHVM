<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ListingStatus;
use App\Enums\PortalStatus;
use App\Flowfact\Sync\FlowfactPublishingService;
use App\Flowfact\Sync\Jobs\RefreshPortalStatusJob;
use App\Flowfact\Sync\PublishingService;
use App\Models\Listing;
use App\Models\ListingPortalPublication;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Throwable;

/**
 * flow:portal-status
 *
 * Statusprüfung veröffentlichter Objekte (docs/connector.md Abschnitt 5,
 * Punkt 3). Läuft alle fünf Minuten über den Scheduler: Objekte mit
 * "angefordert" oder "deaktivierung_angefordert" bei jedem Lauf, Objekte mit
 * "aktiv" oder "manuelle_freigabe_erforderlich" nur, wenn die letzte Prüfung
 * älter als 60 Minuten ist (Fall B: Abschluss in FLOWFACT wird über
 * onlineSince erkannt). Angeforderte Veröffentlichungen ohne Rücklesen nach
 * 30 Minuten werden "unbekannt".
 *
 * Zusätzlich (Prüfbericht 2026-09-11, Befund 1) werden veröffentlichte
 * Objekte geprüft, deren Publikationen sämtlich gescheitert, unbekannt oder
 * zurückgezogen sind; das Rücklesen führt sie über die Statusmaschine nach
 * bereit beziehungsweise zurueckgezogen zurück.
 */
class PortalStatusCommand extends Command
{
    protected $signature = 'flow:portal-status';

    protected $description = 'Liest den Portalstatus veröffentlichter Objekte aus FLOWFACT nach.';

    public function handle(PublishingService $service, FlowfactPublishingService $flowfact): int
    {
        $intervall = (int) config('flowfact.portal_status.aktiv_pruefintervall_minuten', 60);
        $grenze = Carbon::now()->subMinutes($intervall);

        $listingIds = ListingPortalPublication::query()
            ->where(function ($query) use ($grenze): void {
                $query->whereIn('status', [PortalStatus::Angefordert->value, PortalStatus::DeaktivierungAngefordert->value])
                    ->orWhere(function ($aktiv) use ($grenze): void {
                        $aktiv->whereIn('status', [PortalStatus::Aktiv->value, PortalStatus::ManuelleFreigabeErforderlich->value])
                            ->where(function ($pruefung) use ($grenze): void {
                                $pruefung->whereNull('letzte_pruefung_at')->orWhere('letzte_pruefung_at', '<', $grenze);
                            });
                    });
            })
            ->distinct()
            ->pluck('listing_id');

        $ohneOffenePublikation = Listing::query()
            ->where('status', ListingStatus::Veroeffentlicht->value)
            ->whereHas('portalPublications')
            ->whereDoesntHave('portalPublications', function ($query): void {
                $query->whereIn('status', [PortalStatus::Angefordert->value, PortalStatus::Aktiv->value, PortalStatus::DeaktivierungAngefordert->value]);
            })
            ->pluck('id');

        $listingIds = $listingIds->merge($ohneOffenePublikation)->unique()->values();

        if ($listingIds->isEmpty()) {
            $this->line('Keine Objekte zu prüfen.');

            return CommandAlias::SUCCESS;
        }

        if (! $service->isConfigured()) {
            $markiert = 0;

            foreach (Listing::query()->whereIn('id', $listingIds)->get() as $listing) {
                $markiert += $flowfact->markiereUeberfaellige($listing);
            }

            $this->warn(sprintf('FLOWFACT ist nicht konfiguriert, kein Rücklesen möglich. %d überfällige Anforderung(en) als unbekannt markiert.', $markiert));

            return CommandAlias::SUCCESS;
        }

        $fehler = 0;

        foreach ($listingIds as $listingId) {
            try {
                (new RefreshPortalStatusJob((int) $listingId))->handle($service);
            } catch (Throwable $exception) {
                $fehler++;
                $this->error(sprintf('Objekt %d: %s', (int) $listingId, $exception->getMessage()));
            }
        }

        $this->info(sprintf('%d Objekt(e) geprüft, %d Fehler.', $listingIds->count(), $fehler));

        return $fehler === 0 ? CommandAlias::SUCCESS : CommandAlias::FAILURE;
    }
}
