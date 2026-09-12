@extends('layouts.app')

@section('title', 'Übersicht')

@section('content')
    <div class="page-header">
        <h1>Übersicht</h1>
        <p class="hint">Immobilien einfach erfassen und veröffentlichen</p>
        <div class="page-actions">
            @can('create', \App\Models\Listing::class)
                <form method="POST" action="{{ route('app.listings.create') }}">
                    @csrf
                    <input type="hidden" name="vermarktungsart" value="miete">
                    <input type="hidden" name="objektart" value="wohnung">
                    <button type="submit" class="btn btn-primary btn-lg">Müller FLOW starten</button>
                </form>
            @endcan
        </div>
    </div>

    @if ($schedulerWarnung)
        <div class="alert alert-warning">
            Hintergrundverarbeitung meldet sich seit {{ $schedulerVerzoegerungMinuten }} Minuten nicht.
        </div>
    @endif

    <div class="grid grid-3">
        <a href="{{ route('app.listings.index', ['bearbeiter' => $user->id, 'status' => 'entwurf']) }}" class="stat">
            <div class="stat-value">{{ $kennzahlen['meine_entwuerfe'] }}</div>
            <div class="stat-label">Meine Entwürfe</div>
        </a>
        <a href="{{ route('app.listings.index') }}" class="stat">
            <div class="stat-value">{{ $kennzahlen['alle_immobilien'] }}</div>
            <div class="stat-label">Alle Immobilien</div>
        </a>
        <a href="{{ route('app.listings.index', ['status' => 'veroeffentlicht']) }}" class="stat">
            <div class="stat-value">{{ $kennzahlen['veroeffentlichungen_aktiv'] }}</div>
            <div class="stat-label">Veröffentlichungen (aktiv)</div>
        </a>
        <a href="{{ route('app.listings.index') }}" class="stat">
            <div class="stat-value">{{ $kennzahlen['uebertragungsfehler'] }}</div>
            <div class="stat-label">Übertragungsfehler</div>
        </a>
    </div>

    <div class="card">
        <div class="card-title">Meine Entwürfe</div>
        <div class="card-body">
            @if ($meineEntwuerfe->isEmpty())
                <div class="empty-state">
                    <p>Sie haben noch keine Objekte in Bearbeitung.</p>
                </div>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Objektnummer</th>
                                <th>Titel</th>
                                <th>Bearbeitungsstatus</th>
                                <th>Übertragung</th>
                                <th>Geändert am</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($meineEntwuerfe as $objekt)
                                <tr>
                                    <td><a href="{{ route('app.listings.show', $objekt) }}">{{ $objekt->objektnummer }}</a></td>
                                    <td>{{ $objekt->interne_bezeichnung ?: ($objekt->titel ?? '–') }}</td>
                                    <td><span class="badge {{ $objekt->status->badgeClass() }}">{{ $objekt->status->label() }}</span></td>
                                    <td>
                                        @if ($objekt->flowfactLink)
                                            <span class="badge {{ $objekt->flowfactLink->sync_status->badgeClass() }}">{{ $objekt->flowfactLink->sync_status->label() }}</span>
                                        @else
                                            <span class="badge badge-neutral">{{ \App\Enums\SyncStatus::NichtUebertragen->label() }}</span>
                                        @endif
                                    </td>
                                    <td>{{ $objekt->updated_at?->format('d.m.Y H:i') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
