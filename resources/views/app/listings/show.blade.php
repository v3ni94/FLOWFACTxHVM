@extends('layouts.app')

@section('title', $listing->objektnummer)

@section('content')
    <div class="page-header">
        <h1>{{ $listing->objektnummer }} &ndash; {{ $listing->titel ?? 'Ohne Titel' }}</h1>
        <div class="page-actions">
            <a href="{{ route('app.listings.step', ['listing' => $listing, 'schritt' => 1]) }}" class="btn btn-secondary">Bearbeiten</a>
            @if ($listing->status !== \App\Enums\ListingStatus::Archiviert)
                <form method="POST" action="{{ route('app.listings.archive', $listing) }}" data-confirm="Dieses Objekt wirklich archivieren?">
                    @csrf
                    <button type="submit" class="btn btn-danger">Archivieren</button>
                </form>
            @endif
        </div>
    </div>

    @if (session('warnungen'))
        @foreach (session('warnungen') as $warnung)
            <div class="alert alert-warning">{{ $warnung }}</div>
        @endforeach
    @endif

    <div class="grid grid-3">
        <div>
            <span class="eyebrow">Bearbeitungsstatus</span>
            <p><span class="badge {{ $listing->status->badgeClass() }}">{{ $listing->status->label() }}</span></p>
        </div>
        <div>
            <span class="eyebrow">Übertragungsstatus</span>
            <p>
                @if ($listing->flowfactLink)
                    <span class="badge {{ $listing->flowfactLink->sync_status->badgeClass() }}">{{ $listing->flowfactLink->sync_status->label() }}</span>
                @else
                    <span class="badge badge-neutral">{{ \App\Enums\SyncStatus::NichtUebertragen->label() }}</span>
                @endif
            </p>
        </div>
        <div>
            <span class="eyebrow">Portale</span>
            <p><span class="badge {{ \App\Http\Controllers\App\Support\PortalSummary::badgeClass($listing) }}">{{ \App\Http\Controllers\App\Support\PortalSummary::text($listing) }}</span></p>
        </div>
    </div>

    <div class="card">
        <div class="card-title">Objekt</div>
        <div class="card-body">
            <dl class="kv">
                <dt>Adresse</dt>
                <dd>{{ $listing->adresseKurz() ?: '–' }}</dd>
                <dt>Vermarktungsart</dt>
                <dd>{{ $listing->vermarktungsart->label() }}</dd>
                <dt>Objektart</dt>
                <dd>{{ $listing->objektart->label() }}</dd>
                <dt>Ansprechpartner</dt>
                <dd>{{ $listing->ansprechpartner?->name ?? '–' }}</dd>
                <dt>Erstellt von</dt>
                <dd>{{ $listing->erstelltVon?->name ?? '–' }}</dd>
                <dt>Zuletzt geändert</dt>
                <dd>{{ $listing->updated_at?->format('d.m.Y H:i') }}</dd>
            </dl>
        </div>
    </div>

    <div class="card">
        <div class="card-title">Vollständigkeit</div>
        <div class="card-body">
            @if ($vollstaendigkeit->istVollstaendig())
                <div class="alert alert-success">Das Objekt ist vollständig erfasst.</div>
            @else
                <div class="alert alert-info">
                    Für die Veröffentlichung fehlen noch:
                    <ul>
                        @foreach ($fehlendMitSchritt as $eintrag)
                            <li><a href="{{ route('app.listings.step', ['listing' => $listing, 'schritt' => $eintrag['schritt']]) }}">{{ $eintrag['label'] }}</a></li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="card-title">Bilder</div>
        <div class="card-body">
            @if ($listing->media->isEmpty())
                <div class="empty-state">Es wurden noch keine Bilder hochgeladen.</div>
            @else
                <div class="thumb-grid">
                    @foreach ($listing->media as $medium)
                        <div class="thumb">
                            @if (in_array($medium->mime, ['image/jpeg', 'image/png', 'image/webp'], true))
                                <img src="{{ URL::temporarySignedRoute('app.media.show', now()->addMinutes(30), ['media' => $medium->id, 'variante' => 'vorschau']) }}" alt="{{ $medium->titel ?? $medium->dateiname_original }}">
                            @endif
                            <div class="thumb-actions">
                                <span class="text-sekundaer">{{ $medium->typ->label() }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="card-title">Portalveröffentlichungen</div>
        <div class="card-body">
            @if (! $publishingConfigured)
                <div class="alert alert-warning">{{ \App\Flowfact\Sync\NullPublishingService::MELDUNG }}</div>
            @endif

            @if ($listing->portalPublications->isEmpty())
                <div class="empty-state">Es liegen noch keine Portalveröffentlichungen vor.</div>
            @else
                @foreach ($listing->portalPublications as $publication)
                    <div class="status-row">
                        <span class="badge {{ $publication->status->badgeClass() }}">{{ $publication->status->label() }}</span>
                        <span>{{ $publication->portal_name }}</span>
                        @if ($publication->letzter_fehler)
                            <span class="text-sekundaer">{{ $publication->letzter_fehler }}</span>
                        @endif
                        <time>{{ $publication->letzte_pruefung_at?->format('d.m.Y H:i') ?? 'Noch nicht geprüft' }}</time>
                    </div>
                @endforeach
            @endif

            <div class="cluster">
                <form method="POST" action="{{ route('app.listings.transfer', $listing) }}">
                    @csrf
                    <button type="submit" class="btn btn-secondary">Übertragen</button>
                </form>

                <form method="POST" action="{{ route('app.listings.withdraw', $listing) }}" data-confirm="Alle Portalveröffentlichungen dieses Objekts wirklich zurückziehen?">
                    @csrf
                    <button type="submit" class="btn btn-danger">Zurückziehen</button>
                </form>

                <a href="{{ route('app.listings.step', ['listing' => $listing, 'schritt' => 8]) }}" class="btn btn-primary">Veröffentlichen</a>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-title">Übertragungsprotokoll</div>
        <div class="card-body">
            @if ($listing->transferLogs->isEmpty())
                <div class="empty-state">Es liegen noch keine Protokolleinträge vor.</div>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Zeit</th>
                                <th>Aktion</th>
                                <th>Erfolgreich</th>
                                <th>Zusammenfassung</th>
                                <th>Dauer</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($listing->transferLogs as $log)
                                <tr>
                                    <td>{{ $log->created_at?->format('d.m.Y H:i:s') }}</td>
                                    <td>{{ $log->aktion }}</td>
                                    <td>
                                        @if ($log->erfolgreich)
                                            <span class="badge badge-success">Ja</span>
                                        @else
                                            <span class="badge badge-error">Nein</span>
                                        @endif
                                    </td>
                                    <td>{{ $log->zusammenfassung }}</td>
                                    <td>{{ $log->dauer_ms !== null ? $log->dauer_ms.' ms' : '–' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
