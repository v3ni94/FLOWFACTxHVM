@extends('layouts.app')

@section('title', 'Übersicht')

@section('content')
    <div class="page-header">
        <h1>Übersicht</h1>
        <div class="page-actions">
            <a href="{{ route('app.listings.index') }}" class="btn btn-primary">Neues Objekt</a>
        </div>
    </div>

    @if ($schedulerWarnung)
        <div class="alert alert-warning">
            Hintergrundverarbeitung meldet sich seit {{ $schedulerVerzoegerungMinuten }} Minuten nicht.
        </div>
    @endif

    <div class="grid grid-3">
        <div class="stat">
            <div class="stat-value">{{ $kennzahlen['entwuerfe'] }}</div>
            <div class="stat-label">Entwürfe</div>
        </div>
        <div class="stat">
            <div class="stat-value">{{ $kennzahlen['bereit'] }}</div>
            <div class="stat-label">Bereit</div>
        </div>
        <div class="stat">
            <div class="stat-value">{{ $kennzahlen['veroeffentlicht'] }}</div>
            <div class="stat-label">Veröffentlicht</div>
        </div>
        <div class="stat">
            <div class="stat-value">{{ $kennzahlen['uebertragungsfehler'] }}</div>
            <div class="stat-label">Übertragungsfehler</div>
        </div>
    </div>

    <div class="card">
        <div class="card-title">Zuletzt geänderte Objekte</div>
        <div class="card-body">
            @if ($letzteObjekte->isEmpty())
                <div class="empty-state">
                    <p>Es sind noch keine Objekte angelegt.</p>
                    <a href="{{ route('app.listings.index') }}" class="btn btn-primary">Neues Objekt</a>
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
                            @foreach ($letzteObjekte as $objekt)
                                <tr>
                                    <td><a href="{{ route('app.listings.show', $objekt) }}">{{ $objekt->objektnummer }}</a></td>
                                    <td>{{ $objekt->titel ?? '–' }}</td>
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
