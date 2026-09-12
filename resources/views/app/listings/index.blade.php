@extends('layouts.app')

@section('title', 'Objekte')

@section('content')
    <div class="page-header">
        <h1>Objekte</h1>
        <div class="page-actions">
            @can('create', \App\Models\Listing::class)
                <form method="POST" action="{{ route('app.listings.create') }}" class="cluster">
                    @csrf
                    <select name="vermarktungsart">
                        @foreach ($vermarktungsartOptionen as $wert => $label)
                            <option value="{{ $wert }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <select name="objektart">
                        @foreach ($objektartOptionen as $wert => $label)
                            <option value="{{ $wert }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn btn-primary">Neues Objekt</button>
                </form>
            @endcan
        </div>
    </div>

    <form method="GET" action="{{ route('app.listings.index') }}" class="card">
        <div class="card-body grid grid-3">
            <div class="field">
                <label for="suche">Suche</label>
                <input type="search" id="suche" name="suche" value="{{ $filter['suche'] }}" placeholder="Objektnummer, Titel, Adresse, Ort">
            </div>
            <div class="field">
                <label for="status">Bearbeitungsstatus</label>
                <select id="status" name="status">
                    <option value="">Alle</option>
                    @foreach ($statusOptionen as $wert => $label)
                        <option value="{{ $wert }}" @selected($filter['status'] === $wert)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="vermarktungsart">Vermarktungsart</label>
                <select id="vermarktungsart" name="vermarktungsart">
                    <option value="">Alle</option>
                    @foreach ($vermarktungsartOptionen as $wert => $label)
                        <option value="{{ $wert }}" @selected($filter['vermarktungsart'] === $wert)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="bearbeiter">Bearbeiter</label>
                <select id="bearbeiter" name="bearbeiter">
                    <option value="">Alle</option>
                    @foreach ($bearbeiterOptionen as $id => $name)
                        <option value="{{ $id }}" @selected((string) $filter['bearbeiter'] === (string) $id)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="cluster">
                <button type="submit" class="btn btn-secondary">Filtern</button>
                <a href="{{ route('app.listings.index') }}" class="btn btn-ghost">Zurücksetzen</a>
            </div>
        </div>
    </form>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Bild</th>
                    <th>Objekt</th>
                    <th>Vermarktungsart</th>
                    <th>Objektart</th>
                    <th>Ort</th>
                    <th>Preis</th>
                    <th>Bearbeiter</th>
                    <th>Status</th>
                    <th>FLOWFACT</th>
                    <th>Portale</th>
                    <th>Letzte Änderung</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($listings as $listing)
                    @php $titelbild = $listing->media->first(); @endphp
                    <tr>
                        <td>
                            @if ($titelbild)
                                <img src="{{ URL::temporarySignedRoute('app.media.show', now()->addMinutes(30), ['media' => $titelbild->id, 'variante' => 'vorschau']) }}" alt="" class="thumb-sm">
                            @else
                                <span class="badge badge-neutral">Kein Bild</span>
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('app.listings.show', $listing) }}">{{ $listing->interne_bezeichnung ?: ($listing->titel ?: $listing->objektnummer) }}</a>
                            <p class="hint">{{ $listing->objektnummer }}</p>
                        </td>
                        <td>{{ $listing->vermarktungsart->label() }}</td>
                        <td>{{ $listing->objektart->label() }}</td>
                        <td>{{ $listing->ort }}</td>
                        <td>
                            @if ($listing->price)
                                @if ($listing->istMiete())
                                    {{ $listing->price->warmmiete_cent !== null ? \App\Support\Money::format($listing->price->warmmiete_cent) : '–' }}
                                @else
                                    {{ $listing->price->kaufpreis_cent !== null ? \App\Support\Money::format($listing->price->kaufpreis_cent) : '–' }}
                                @endif
                            @else
                                –
                            @endif
                        </td>
                        <td>{{ $listing->bearbeiter?->name ?? '–' }}</td>
                        <td><span class="badge {{ $listing->status->badgeClass() }}">{{ $listing->status->label() }}</span></td>
                        <td>
                            @if ($listing->flowfactLink)
                                <span class="badge {{ $listing->flowfactLink->sync_status->badgeClass() }}">{{ $listing->flowfactLink->sync_status->label() }}</span>
                            @else
                                <span class="badge badge-neutral">{{ \App\Enums\SyncStatus::NichtUebertragen->label() }}</span>
                            @endif
                        </td>
                        <td><span class="badge {{ \App\Http\Controllers\App\Support\PortalSummary::badgeClass($listing) }}">{{ \App\Http\Controllers\App\Support\PortalSummary::text($listing) }}</span></td>
                        <td>{{ $listing->updated_at?->format('d.m.Y H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="empty-state">Keine Objekte gefunden.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $listings->links() }}
@endsection
