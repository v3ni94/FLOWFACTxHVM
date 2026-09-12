@extends('layouts.app')

@section('title', 'Prüfen und Veröffentlichen')

@section('content')
    <div class="page-header">
        <h1>{{ $listing->objektnummer }}: Prüfen und Veröffentlichen</h1>
        <div class="page-actions">
            <a href="{{ route('app.listings.show', $listing) }}" class="btn btn-ghost">Zur Übersicht</a>
            <a href="{{ route('app.listings.step', ['listing' => $listing, 'schritt' => 1]) }}" class="btn btn-secondary">Zurück zu den Schritten</a>
        </div>
    </div>

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
                @if ($listing->hatUnveroeffentlichteAenderungen())
                    <span class="badge badge-warning">Unveröffentlichte Änderungen</span>
                @endif
            </p>
        </div>
        <div>
            <span class="eyebrow">Portale</span>
            <p><span class="badge {{ $portalBadge }}">{{ $portalText }}</span></p>
        </div>
    </div>

    <div class="card">
        <div class="card-title">Vorschau der übertragenen Inhalte, die Darstellung je Portal kann abweichen</div>
        <div class="card-body stack">
            @if ($vorschau['bilder']->isNotEmpty())
                <div class="thumb-grid">
                    @foreach ($vorschau['bilder'] as $bild)
                        <div class="thumb">
                            <img src="{{ URL::temporarySignedRoute('app.media.show', now()->addMinutes(30), ['media' => $bild['id'], 'variante' => 'vorschau']) }}" alt="{{ $bild['titel'] ?? '' }}">
                            @if ($vorschau['titelbild'] && $bild['id'] === $vorschau['titelbild']['id'])
                                <span class="badge badge-info">Titelbild</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            @else
                <div class="alert alert-warning">Es ist noch kein Bild für das Inserat freigegeben.</div>
            @endif

            <h2>{{ $vorschau['titel'] ?? 'Ohne Überschrift' }}</h2>
            <p>
                @if ($vorschau['adresse']['freigabe']->adresseAnzeigen())
                    {{ trim($vorschau['adresse']['strasse'].' '.$vorschau['adresse']['hausnummer']) }}@if($vorschau['adresse']['adresszusatz']), {{ $vorschau['adresse']['adresszusatz'] }}@endif,
                @endif
                {{ trim($vorschau['adresse']['plz'].' '.$vorschau['adresse']['ort']) }}
                @if ($vorschau['adresse']['stadtteil']) ({{ $vorschau['adresse']['stadtteil'] }}) @endif
            </p>
            <p>{{ $vorschau['vermarktungsart']->label() }} &middot; {{ $vorschau['objektart']->label() }}</p>

            <dl class="kv">
                @if ($vorschau['flaechen']['wohnflaeche_qm'])
                    <dt>Wohnfläche</dt>
                    <dd>{{ $vorschau['flaechen']['wohnflaeche_qm'] }} m²</dd>
                @endif
                @if ($vorschau['flaechen']['nutzflaeche_qm'])
                    <dt>Nutzfläche</dt>
                    <dd>{{ $vorschau['flaechen']['nutzflaeche_qm'] }} m²</dd>
                @endif
                @if ($vorschau['flaechen']['gewerbeflaeche_qm'])
                    <dt>Gewerbefläche</dt>
                    <dd>{{ $vorschau['flaechen']['gewerbeflaeche_qm'] }} m²</dd>
                @endif
                @if ($vorschau['flaechen']['grundstuecksflaeche_qm'])
                    <dt>Grundstücksfläche</dt>
                    <dd>{{ $vorschau['flaechen']['grundstuecksflaeche_qm'] }} m²</dd>
                @endif
                @if ($vorschau['flaechen']['zimmer'])
                    <dt>Zimmer</dt>
                    <dd>{{ $vorschau['flaechen']['zimmer'] }}</dd>
                @endif

                @if ($vorschau['preis'])
                    @if ($vorschau['preis']['vermarktungsart']->value === 'miete')
                        <dt>Kaltmiete</dt>
                        <dd>{{ $vorschau['preis']['kaltmiete_cent'] !== null ? \App\Support\Money::format($vorschau['preis']['kaltmiete_cent']) : '–' }}</dd>
                        <dt>Warmmiete</dt>
                        <dd>{{ $vorschau['preis']['warmmiete_cent'] !== null ? \App\Support\Money::format($vorschau['preis']['warmmiete_cent']) : '–' }}</dd>
                        @if ($vorschau['preis']['kaution_cent'])
                            <dt>Kaution</dt>
                            <dd>{{ \App\Support\Money::format($vorschau['preis']['kaution_cent']) }}@if($vorschau['preis']['kaution_monatsmieten']) ({{ $vorschau['preis']['kaution_monatsmieten'] }} Monatsmieten) @endif</dd>
                        @endif
                        @if ($vorschau['preis']['stellplatz_hinweis'])
                            <dt>Stellplatz</dt>
                            <dd>{{ $vorschau['preis']['stellplatz_hinweis'] }}@if($vorschau['preis']['stellplatz_miete_cent']) &middot; {{ \App\Support\Money::format($vorschau['preis']['stellplatz_miete_cent']) }} @endif</dd>
                        @endif
                    @else
                        <dt>Kaufpreis</dt>
                        <dd>{{ $vorschau['preis']['kaufpreis_cent'] !== null ? \App\Support\Money::format($vorschau['preis']['kaufpreis_cent']) : '–' }}</dd>
                        @if ($vorschau['preis']['hausgeld_cent'])
                            <dt>Hausgeld</dt>
                            <dd>{{ \App\Support\Money::format($vorschau['preis']['hausgeld_cent']) }}</dd>
                        @endif
                        @if ($vorschau['preis']['stellplatz_kaufpreis_cent'])
                            <dt>Stellplatz</dt>
                            <dd>{{ \App\Support\Money::format($vorschau['preis']['stellplatz_kaufpreis_cent']) }}@if($vorschau['preis']['stellplatz_im_kaufpreis']) (im Kaufpreis enthalten) @endif</dd>
                        @endif
                    @endif
                    @if ($vorschau['preis']['provision_typ'])
                        <dt>Provision</dt>
                        <dd>{{ $vorschau['preis']['provision_typ'] === 'provisionsfrei' ? 'Provisionsfrei' : $vorschau['preis']['provision_text'] }}</dd>
                    @endif
                @endif

                @if ($vorschau['energie'])
                    <dt>Energieausweis</dt>
                    <dd>
                        {{ $vorschau['energie']['status']->label() }}
                        @if ($vorschau['energie']['ausweistyp']) &middot; {{ \App\Enums\Ausweistyp::from($vorschau['energie']['ausweistyp'])->label() }} @endif
                        @if ($vorschau['energie']['kennwert_kwh']) &middot; {{ $vorschau['energie']['kennwert_kwh'] }} kWh/(m²·a) @endif
                        @if ($vorschau['energie']['effizienzklasse']) &middot; Klasse {{ $vorschau['energie']['effizienzklasse'] }} @endif
                    </dd>
                @endif

                <dt>Ansprechpartner</dt>
                <dd>
                    {{ $vorschau['ansprechpartner']['name'] ?? '–' }}
                    @if ($vorschau['ansprechpartner']['phone'] ?? null) &middot; {{ $vorschau['ansprechpartner']['phone'] }} @endif
                    @if ($vorschau['ansprechpartner']['email'] ?? null) &middot; {{ $vorschau['ansprechpartner']['email'] }} @endif
                </dd>
            </dl>

            @if ($vorschau['merkmale'] !== [])
                <div class="cluster">
                    @foreach ($vorschau['merkmale'] as $merkmal)
                        <span class="badge badge-neutral">{{ $merkmal }}</span>
                    @endforeach
                </div>
            @endif

            @foreach ($vorschau['texte'] as $text)
                @if ($text)
                    <p>{{ $text }}</p>
                @endif
            @endforeach

            @if ($vorschau['unterlagen']->isNotEmpty())
                <div>
                    <span class="eyebrow">Freigegebene Unterlagen</span>
                    <ul>
                        @foreach ($vorschau['unterlagen'] as $unterlage)
                            <li>{{ $unterlage['titel'] ?? $unterlage['typ'] }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>

    @if ($adressLeck !== [])
        <div class="alert alert-error">
            Die Adressfreigabe erlaubt nur PLZ und Ort, folgende Texte enthalten dennoch Straße oder Hausnummer und blockieren die Veröffentlichung: {{ implode(', ', $adressLeck) }}. Bitte in Schritt 7 oder 8 korrigieren.
        </div>
    @endif

    <div class="grid grid-2">
        @foreach (\App\Enums\PruefEbene::cases() as $ebene)
            <div class="card">
                <div class="card-title">{{ $ebene->label() }}</div>
                <div class="card-body">
                    @php $gruppe = $befunde->get($ebene->value, collect()); @endphp
                    @forelse ($gruppe as $befund)
                        <div class="status-row">
                            <span class="badge {{ $befund->art->badgeClass() }}">{{ $befund->art->label() }}</span>
                            <a href="{{ route('app.listings.step', ['listing' => $listing, 'schritt' => $befund->schritt]) }}">{{ $befund->meldung }}</a>
                        </div>
                    @empty
                        <p class="hint">Keine Befunde.</p>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>

    <div class="card">
        <div class="card-title">Wo soll das Objekt veröffentlicht werden?</div>
        <div class="card-body stack">
            @if (! $portaleKonfiguriert)
                <div class="alert alert-warning">{{ \App\Flowfact\Sync\NullPublishingService::MELDUNG }}</div>
            @endif

            {{-- Prüfbericht 2026-09-12, Befund 17: Leser sehen serverseitig
                 stets 403 auf diese Aktionen; das Formular selbst bleibt
                 ihnen verborgen. --}}
            @can('publish', $listing)
                <form method="POST" action="{{ route('app.listings.publish', $listing) }}" class="stack">
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

                    <label class="field-inline">
                        <input type="checkbox" name="alle_portale" value="1">
                        <span>Alle verfügbaren Portale auswählen</span>
                    </label>

                    <div class="checkbox-group @error('portale') has-error @enderror">
                        @forelse ($portale as $portal)
                            <label class="field-inline">
                                <input type="checkbox" name="portale[]" value="{{ $portal->id }}" @checked(in_array($portal->id, $vorausgewaehltePortale, true))>
                                <span>{{ $portal->name }}</span>
                            </label>
                        @empty
                            <p class="hint">Es sind keine Portale verfügbar.</p>
                        @endforelse
                    </div>
                    @error('portale')<p class="error">{{ $message }}</p>@enderror

                    <div class="cluster">
                        <button type="submit" class="btn btn-primary" @disabled(empty($portale) || ! $vollstaendigkeit->istVollstaendig() || $adressLeck !== [])>JETZT VERÖFFENTLICHEN</button>
                    </div>
                </form>
            @endcan
        </div>
    </div>

    <div class="card">
        <div class="card-title">Aktionen</div>
        <div class="card-body cluster">
            <a href="{{ route('app.listings.step', ['listing' => $listing, 'schritt' => 8]) }}" class="btn btn-ghost">Als Entwurf speichern</a>

            @if ($listing->status->value === 'entwurf')
                <form method="POST" action="{{ route('app.listings.status.bereit', $listing) }}">
                    @csrf
                    <button type="submit" class="btn btn-secondary">Als bereit markieren</button>
                </form>
            @endif

            {{-- Prüfbericht 2026-09-12, Befund 10: "In FLOWFACT speichern"
                 verlangt dasselbe Recht wie das Veröffentlichen. --}}
            @can('publish', $listing)
                <form method="POST" action="{{ route('app.listings.transfer', $listing) }}">
                    @csrf
                    <button type="submit" class="btn btn-secondary" @disabled(! $vollstaendigkeit->istVollstaendig())>In FLOWFACT speichern</button>
                </form>
            @endcan
        </div>
    </div>

    @if ($aktuellePublikationen->isNotEmpty())
        <div class="card">
            <div class="card-title">Auf ausgewählten Portalen deaktivieren</div>
            <div class="card-body stack">
                @can('withdraw', $listing)
                <form method="POST" action="{{ route('app.listings.withdraw', $listing) }}" data-confirm="Die ausgewählten Portalveröffentlichungen wirklich deaktivieren?" class="stack">
                    @csrf
                    <div class="checkbox-group">
                        @foreach ($aktuellePublikationen as $publikation)
                            <label class="field-inline">
                                <input type="checkbox" name="portale[]" value="{{ $publikation->portal_id }}" @checked($publikation->status->istOffen() || in_array($publikation->status->value, ['fehler', 'unbekannt'], true))>
                                <span>{{ $publikation->portal_name }} &ndash; <span class="badge {{ $publikation->status->badgeClass() }}">{{ $publikation->status->label() }}</span></span>
                            </label>
                        @endforeach
                    </div>
                    <div class="cluster">
                        <button type="submit" class="btn btn-danger">Auf ausgewählten Portalen deaktivieren</button>
                    </div>
                </form>
                @endcan

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
                            @forelse ($listing->portalStatusLogs as $log)
                                <tr>
                                    <td>{{ $log->portal_id }}</td>
                                    <td><span class="badge {{ $log->nach_status->badgeClass() }}">{{ $log->nach_status->label() }}</span></td>
                                    <td>{{ $log->nachweis_quelle }}</td>
                                    <td>{{ $log->nachweis_at?->format('d.m.Y H:i') ?? $log->created_at?->format('d.m.Y H:i') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4">Noch keine Statuswechsel protokolliert.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    @if (auth()->user()->isAdmin() && $listing->energy && $listing->energy->status?->normalisiert() === \App\Enums\EnergieausweisStatus::AusnahmeZuPruefen && ! $listing->energy->ausnahmeBestaetigt())
        <div class="card">
            <div class="card-title">Ausnahme von der Energieausweispflicht bestätigen</div>
            <div class="card-body stack">
                <div class="alert alert-info">{{ \App\Domain\Listing\EnergyRequirements::MELDUNG_AUSNAHME_OFFEN }}</div>
                <form method="POST" action="{{ route('app.listings.energy-exception.confirm', $listing) }}" class="stack">
                    @csrf
                    <div class="field @error('ausnahme_begruendung') has-error @enderror">
                        <label for="ausnahme_begruendung">Begründung</label>
                        <textarea id="ausnahme_begruendung" name="ausnahme_begruendung" rows="3" required>{{ old('ausnahme_begruendung') }}</textarea>
                        @error('ausnahme_begruendung')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <div class="cluster">
                        <button type="submit" class="btn btn-primary">Ausnahme bestätigen</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
@endsection
