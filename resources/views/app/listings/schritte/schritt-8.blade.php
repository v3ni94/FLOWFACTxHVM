@extends('layouts.app')

@section('title', 'Prüfen und Veröffentlichen')

@section('content')
    @include('app.listings.schritte._header')

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

    @if (! $vollstaendigkeit->istVollstaendig())
        <div class="card">
            <div class="card-title">Fehlende Angaben</div>
            <div class="card-body">
                <ul>
                    @foreach ($vollstaendigkeit->fehlend as $feld => $label)
                        <li>
                            <a href="{{ route('app.listings.step', ['listing' => $listing, 'schritt' => \App\Http\Controllers\App\Support\CompletenessFieldMap::schritt($feld)]) }}">{{ $label }}</a>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    <div class="card">
        <div class="card-title">Vorschau des Inserats</div>
        <div class="card-body stack">
            <h2>{{ $vorschau['titel'] ?? 'Ohne Titel' }}</h2>
            <p>{{ $vorschau['adresse'] }}</p>
            <p>{{ $vorschau['vermarktungsart']->label() }} &middot; {{ $vorschau['objektart']->label() }}</p>

            <dl class="kv">
                @if ($vorschau['wohnflaeche_qm'])
                    <dt>Wohnfläche</dt>
                    <dd>{{ $vorschau['wohnflaeche_qm'] }} m²</dd>
                @endif
                @if ($vorschau['zimmer'])
                    <dt>Zimmer</dt>
                    <dd>{{ $vorschau['zimmer'] }}</dd>
                @endif
                @if ($vorschau['preis'])
                    @if ($listing->istMiete())
                        <dt>Kaltmiete</dt>
                        <dd>{{ $vorschau['preis']->kaltmiete_cent !== null ? \App\Support\Money::format($vorschau['preis']->kaltmiete_cent) : '–' }}</dd>
                        <dt>Warmmiete</dt>
                        <dd>{{ $vorschau['preis']->warmmiete_cent !== null ? \App\Support\Money::format($vorschau['preis']->warmmiete_cent) : '–' }}</dd>
                    @else
                        <dt>Kaufpreis</dt>
                        <dd>{{ $vorschau['preis']->kaufpreis_cent !== null ? \App\Support\Money::format($vorschau['preis']->kaufpreis_cent) : '–' }}</dd>
                    @endif
                @endif
                @if ($vorschau['energie'] && $vorschau['energie']->status)
                    <dt>Energieausweis</dt>
                    <dd>{{ $vorschau['energie']->status->label() }}</dd>
                @endif
                <dt>Ansprechpartner</dt>
                <dd>
                    {{ $vorschau['ansprechpartner']?->name ?? '–' }}
                    @if ($vorschau['ansprechpartner']?->phone) &middot; {{ $vorschau['ansprechpartner']->phone }} @endif
                    @if ($vorschau['ansprechpartner']?->email) &middot; {{ $vorschau['ansprechpartner']->email }} @endif
                </dd>
                <dt>Anbieter</dt>
                <dd>
                    {{ $vorschau['firma']['name'] ?? 'Hausverwaltung Müller GmbH' }}
                    @if (! empty($vorschau['firma']['telefon'])) &middot; {{ $vorschau['firma']['telefon'] }} @endif
                    @if (! empty($vorschau['firma']['email'])) &middot; {{ $vorschau['firma']['email'] }} @endif
                </dd>
            </dl>

            @if ($vorschau['beschreibung_objekt'])
                <p>{{ $vorschau['beschreibung_objekt'] }}</p>
            @endif

            @if ($vorschau['medien']->isNotEmpty())
                <div class="thumb-grid">
                    @foreach ($vorschau['medien'] as $medium)
                        <div class="thumb">
                            @if (in_array($medium->mime, ['image/jpeg', 'image/png', 'image/webp'], true))
                                <img src="{{ URL::temporarySignedRoute('app.media.show', now()->addMinutes(30), ['media' => $medium->id, 'variante' => 'vorschau']) }}" alt="{{ $medium->titel ?? '' }}">
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="card-title">Veröffentlichen</div>
        <div class="card-body stack">
            @if (! $publishingConfigured)
                <div class="alert alert-warning">{{ \App\Flowfact\Sync\NullPublishingService::MELDUNG }}</div>
            @endif

            @if ($listing->status->value === 'entwurf')
                <form method="POST" action="{{ route('app.listings.status.bereit', $listing) }}">
                    @csrf
                    <button type="submit" class="btn btn-secondary">Als bereit markieren</button>
                </form>
            @endif

            <form method="POST" action="{{ route('app.listings.transfer', $listing) }}">
                @csrf
                <button type="submit" class="btn btn-secondary">An FLOWFACT übertragen</button>
            </form>

            <form method="POST" action="{{ route('app.listings.publish', $listing) }}" data-confirm="Objekt wirklich auf den ausgewählten Portalen veröffentlichen?" class="stack">
                @csrf

                @if ($vollstaendigkeit->hinweise !== [])
                    <div class="alert alert-warning">
                        <p>Bitte bestätigen Sie vor der Veröffentlichung:</p>
                        <div class="checkbox-group">
                            @foreach ($vollstaendigkeit->hinweise as $index => $hinweis)
                                <label class="field-inline">
                                    <input type="checkbox" name="hinweise_bestaetigt[]" value="{{ $index }}" required>
                                    <span>{{ $hinweis }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="checkbox-group @error('portale') has-error @enderror">
                    @forelse ($portale as $portal)
                        <label class="field-inline">
                            <input type="checkbox" name="portale[]" value="{{ $portal->id }}">
                            <span>{{ $portal->name }}</span>
                        </label>
                    @empty
                        <p class="hint">Es sind keine Portale verfügbar.</p>
                    @endforelse
                </div>
                @error('portale')<p class="error">{{ $message }}</p>@enderror

                <div class="cluster">
                    <button type="submit" class="btn btn-primary" @disabled(empty($portale))>Veröffentlichen</button>
                </div>
            </form>

            <form method="POST" action="{{ route('app.listings.withdraw', $listing) }}" data-confirm="Alle Portalveröffentlichungen dieses Objekts wirklich zurückziehen?">
                @csrf
                <button type="submit" class="btn btn-danger">Zurückziehen</button>
            </form>
        </div>
    </div>
@endsection
