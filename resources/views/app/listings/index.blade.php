@extends('layouts.app')

@section('title', 'Objekte')

@section('content')
    <div class="page-header">
        <h1>Objekte</h1>
    </div>

    <div class="card">
        <div class="card-title">Neues Objekt anlegen</div>
        <div class="card-body">
            <form method="POST" action="{{ route('app.listings.create') }}" class="cluster">
                @csrf

                <div class="field @error('vermarktungsart') has-error @enderror">
                    <label for="neu-vermarktungsart">Vermarktungsart</label>
                    <select id="neu-vermarktungsart" name="vermarktungsart" required>
                        @foreach (\App\Enums\Vermarktungsart::options() as $value => $label)
                            <option value="{{ $value }}" @selected(old('vermarktungsart') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('vermarktungsart')
                        <p class="error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field @error('objektart') has-error @enderror">
                    <label for="neu-objektart">Objektart</label>
                    <select id="neu-objektart" name="objektart" required>
                        @foreach ($objektartOptionen as $value => $label)
                            <option value="{{ $value }}" @selected(old('objektart') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('objektart')
                        <p class="error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn btn-primary">Entwurf anlegen</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="GET" action="{{ route('app.listings.index') }}" class="cluster">
                <div class="field">
                    <label for="filter-status">Bearbeitungsstatus</label>
                    <select id="filter-status" name="status" data-autosubmit>
                        <option value="">Alle</option>
                        @foreach ($statusOptionen as $value => $label)
                            <option value="{{ $value }}" @selected($filter['status'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="field">
                    <label for="filter-vermarktungsart">Vermarktungsart</label>
                    <select id="filter-vermarktungsart" name="vermarktungsart" data-autosubmit>
                        <option value="">Alle</option>
                        @foreach ($vermarktungsartOptionen as $value => $label)
                            <option value="{{ $value }}" @selected($filter['vermarktungsart'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="field">
                    <label for="filter-suche">Suche</label>
                    <input type="search" id="filter-suche" name="suche" value="{{ $filter['suche'] }}" placeholder="Objektnummer, Titel, Ort">
                </div>

                <div class="field">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn btn-secondary">Filtern</button>
                </div>
            </form>
        </div>
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Objektnummer</th>
                    <th>Titel</th>
                    <th>Ort</th>
                    <th>Vermarktungsart</th>
                    <th>Bearbeitungsstatus</th>
                    <th>Übertragung</th>
                    <th>Portale</th>
                    <th>Geändert am</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($listings as $listing)
                    <tr>
                        <td><a href="{{ route('app.listings.show', $listing) }}">{{ $listing->objektnummer }}</a></td>
                        <td>{{ $listing->titel ?? '–' }}</td>
                        <td>{{ $listing->ort ?? '–' }}</td>
                        <td>{{ $listing->vermarktungsart->label() }}</td>
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
                    <tr>
                        <td colspan="8">
                            <div class="empty-state">Es sind noch keine Objekte angelegt.</div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="cluster">
        {{ $listings->links() }}
    </div>
@endsection
