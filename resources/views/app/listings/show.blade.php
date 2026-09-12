@extends('layouts.app')

@section('title', 'Objektdetail')

@section('content')
    <div class="page-header">
        <h1>{{ $listing->interne_bezeichnung ?: ($listing->titel ?: $listing->objektnummer) }}</h1>
        <div class="page-actions">
            <a href="{{ route('app.listings.step', ['listing' => $listing, 'schritt' => 1]) }}" class="btn btn-secondary">Bearbeiten</a>
            <a href="{{ route('app.listings.review', $listing) }}" class="btn btn-secondary">Prüfen und veröffentlichen</a>
            <a href="{{ route('app.listings.history', $listing) }}" class="btn btn-ghost">Historie</a>
            @can('duplicate', \App\Models\Listing::class)
                <form method="POST" action="{{ route('app.listings.duplicate', $listing) }}">
                    @csrf
                    <button type="submit" class="btn btn-ghost">Duplizieren</button>
                </form>
            @endcan
            @can('archive', $listing)
                @if ($listing->status->value !== 'archiviert')
                    <form method="POST" action="{{ route('app.listings.archive', $listing) }}" data-confirm="Dieses Objekt wirklich archivieren?">
                        @csrf
                        <button type="submit" class="btn btn-danger">Archivieren</button>
                    </form>
                @endif
            @endcan
        </div>
    </div>

    @if (session('nicht_kopiert'))
        <div class="alert alert-info">
            Beim Duplizieren nicht übernommen: {{ implode(', ', session('nicht_kopiert')) }}.
        </div>
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
            <p><span class="badge {{ $portalBadge }}">{{ $portalText }}</span></p>
        </div>
    </div>

    <div class="card">
        <div class="card-title">Übersicht</div>
        <div class="card-body">
            <dl class="kv">
                <dt>Objektnummer</dt>
                <dd>{{ $listing->objektnummer }}</dd>
                <dt>Vermarktungsart</dt>
                <dd>{{ $listing->vermarktungsart->label() }}</dd>
                <dt>Objektart</dt>
                <dd>{{ $listing->objektart->label() }}</dd>
                <dt>Adresse</dt>
                <dd>{{ $listing->adresseKurz() ?: '–' }}</dd>
                <dt>Bearbeiter</dt>
                <dd>{{ $listing->bearbeiter?->name ?? '–' }}</dd>
                <dt>Ansprechpartner</dt>
                <dd>{{ $listing->ansprechpartner?->name ?? '–' }}</dd>
                <dt>Erstellt von</dt>
                <dd>{{ $listing->erstelltVon?->name ?? '–' }}</dd>
                <dt>Letzte Änderung</dt>
                <dd>{{ $listing->updated_at?->format('d.m.Y H:i') }}</dd>
            </dl>
        </div>
    </div>

    <div class="card">
        <div class="card-title">Vollständigkeit</div>
        <div class="card-body">
            @if ($vollstaendigkeit->istVollstaendig())
                <div class="alert alert-success">Das Objekt ist vollständig und kann veröffentlicht werden.</div>
            @else
                <ul>
                    @foreach ($fehlendMitSchritt as $eintrag)
                        <li><a href="{{ route('app.listings.step', ['listing' => $listing, 'schritt' => $eintrag['schritt']]) }}">{{ $eintrag['label'] }}</a></li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="card-title">Medien</div>
        <div class="card-body">
            @if ($listing->media->isNotEmpty())
                <div class="thumb-grid">
                    @foreach ($listing->media as $medium)
                        <div class="thumb">
                            @if (in_array($medium->mime, ['image/jpeg', 'image/png', 'image/webp'], true))
                                <img src="{{ URL::temporarySignedRoute('app.media.show', now()->addMinutes(30), ['media' => $medium->id, 'variante' => 'vorschau']) }}" alt="{{ $medium->titel ?? '' }}" class="rot-{{ $medium->rotation }}">
                            @endif
                            <div class="thumb-actions">
                                <span class="badge badge-neutral">{{ $medium->typ->label() }}</span>
                                @if (! $medium->freigegeben)
                                    <span class="badge badge-warning">Nicht freigegeben</span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="empty-state">Es sind noch keine Medien hochgeladen.</div>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="card-title">Portalveröffentlichungen</div>
        <div class="card-body">
            @if ($listing->portalPublications->isNotEmpty())
                @foreach ($listing->portalPublications as $publikation)
                    <div class="status-row">
                        <span class="badge {{ $publikation->status->badgeClass() }}">{{ $publikation->status->label() }}</span>
                        <span>{{ $publikation->portal_name }}</span>
                        @if ($publikation->letzter_fehler)
                            <span class="hint">{{ $publikation->letzter_fehler }}</span>
                        @endif
                    </div>
                @endforeach
            @else
                <p class="hint">Dieses Objekt wurde noch auf keinem Portal veröffentlicht.</p>
            @endif

            @if ($listing->portalStatusLogs->isNotEmpty())
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Portal</th>
                                <th>Status</th>
                                <th>Nachweisquelle</th>
                                <th>Zeitpunkt</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($listing->portalStatusLogs as $log)
                                <tr>
                                    <td>{{ $log->portal_id }}</td>
                                    <td><span class="badge {{ $log->nach_status->badgeClass() }}">{{ $log->nach_status->label() }}</span></td>
                                    <td>{{ $log->nachweis_quelle }}</td>
                                    <td>{{ $log->nachweis_at?->format('d.m.Y H:i') ?? $log->created_at?->format('d.m.Y H:i') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="card-title">Übertragungsprotokoll (letzte 20)</div>
        <div class="card-body">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Zeitpunkt</th>
                            <th>Aktion</th>
                            <th>Richtung</th>
                            <th>Erfolgreich</th>
                            <th>Zusammenfassung</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($listing->transferLogs as $log)
                            <tr>
                                <td>{{ $log->created_at?->format('d.m.Y H:i') }}</td>
                                <td>{{ $log->aktion }}</td>
                                <td>{{ $log->richtung->value }}</td>
                                <td><span class="badge {{ $log->erfolgreich ? 'badge-success' : 'badge-error' }}">{{ $log->erfolgreich ? 'Ja' : 'Nein' }}</span></td>
                                <td>{{ $log->zusammenfassung }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5">Noch keine Übertragungen protokolliert.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
