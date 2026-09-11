@extends('layouts.app')

@section('title', 'Flächen und Ausstattung')

@section('content')
    @include('app.listings.schritte._header')

    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('app.listings.step.store', ['listing' => $listing, 'schritt' => 2]) }}" class="stack">
                @csrf

                <div class="grid grid-3">
                    @if ($listing->objektart->value !== 'gewerbe')
                        <div class="field @error('wohnflaeche_qm') has-error @enderror">
                            <label for="wohnflaeche_qm">Wohnfläche (m²)</label>
                            <input type="text" id="wohnflaeche_qm" name="wohnflaeche_qm" value="{{ old('wohnflaeche_qm', $listing->wohnflaeche_qm) }}">
                            @error('wohnflaeche_qm')<p class="error">{{ $message }}</p>@enderror
                        </div>
                    @endif

                    @if ($listing->objektart->value === 'gewerbe')
                        <div class="field @error('nutzflaeche_qm') has-error @enderror">
                            <label for="nutzflaeche_qm">Nutzfläche (m²)</label>
                            <input type="text" id="nutzflaeche_qm" name="nutzflaeche_qm" value="{{ old('nutzflaeche_qm', $listing->nutzflaeche_qm) }}">
                            @error('nutzflaeche_qm')<p class="error">{{ $message }}</p>@enderror
                        </div>
                    @endif

                    @if (in_array($listing->objektart->value, ['haus', 'grundstueck'], true))
                        <div class="field @error('grundstuecksflaeche_qm') has-error @enderror">
                            <label for="grundstuecksflaeche_qm">Grundstücksfläche (m²)</label>
                            <input type="text" id="grundstuecksflaeche_qm" name="grundstuecksflaeche_qm" value="{{ old('grundstuecksflaeche_qm', $listing->grundstuecksflaeche_qm) }}">
                            @error('grundstuecksflaeche_qm')<p class="error">{{ $message }}</p>@enderror
                        </div>
                    @endif

                    <div class="field @error('zimmer') has-error @enderror">
                        <label for="zimmer">Zimmer</label>
                        <input type="text" id="zimmer" name="zimmer" value="{{ old('zimmer', $listing->zimmer) }}">
                        @error('zimmer')<p class="error">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="grid grid-3">
                    <div class="field @error('schlafzimmer') has-error @enderror">
                        <label for="schlafzimmer">Schlafzimmer</label>
                        <input type="number" id="schlafzimmer" name="schlafzimmer" value="{{ old('schlafzimmer', $listing->schlafzimmer) }}" min="0">
                        @error('schlafzimmer')<p class="error">{{ $message }}</p>@enderror
                    </div>

                    <div class="field @error('badezimmer') has-error @enderror">
                        <label for="badezimmer">Badezimmer</label>
                        <input type="number" id="badezimmer" name="badezimmer" value="{{ old('badezimmer', $listing->badezimmer) }}" min="0">
                        @error('badezimmer')<p class="error">{{ $message }}</p>@enderror
                    </div>

                    <div class="field @error('baujahr') has-error @enderror">
                        <label for="baujahr">Baujahr</label>
                        <input type="number" id="baujahr" name="baujahr" value="{{ old('baujahr', $listing->baujahr) }}">
                        @error('baujahr')<p class="error">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="grid grid-3">
                    <div class="field @error('etage') has-error @enderror">
                        <label for="etage">Etage</label>
                        <input type="number" id="etage" name="etage" value="{{ old('etage', $listing->etage) }}">
                        @error('etage')<p class="error">{{ $message }}</p>@enderror
                    </div>

                    <div class="field @error('etagen_gesamt') has-error @enderror">
                        <label for="etagen_gesamt">Etagen gesamt</label>
                        <input type="number" id="etagen_gesamt" name="etagen_gesamt" value="{{ old('etagen_gesamt', $listing->etagen_gesamt) }}" min="0">
                        @error('etagen_gesamt')<p class="error">{{ $message }}</p>@enderror
                    </div>

                    <div class="field @error('zustand') has-error @enderror">
                        <label for="zustand">Zustand</label>
                        <select id="zustand" name="zustand">
                            <option value="">Bitte wählen</option>
                            @foreach (\App\Enums\Zustand::options() as $value => $label)
                                <option value="{{ $value }}" @selected(old('zustand', $listing->zustand?->value) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('zustand')<p class="error">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="grid grid-3">
                    <div class="field @error('ausstattungsqualitaet') has-error @enderror">
                        <label for="ausstattungsqualitaet">Ausstattungsqualität</label>
                        <select id="ausstattungsqualitaet" name="ausstattungsqualitaet">
                            <option value="">Bitte wählen</option>
                            @foreach (\App\Enums\Ausstattungsqualitaet::options() as $value => $label)
                                <option value="{{ $value }}" @selected(old('ausstattungsqualitaet', $listing->ausstattungsqualitaet?->value) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('ausstattungsqualitaet')<p class="error">{{ $message }}</p>@enderror
                    </div>

                    <div class="field @error('heizungsart') has-error @enderror">
                        <label for="heizungsart">Heizungsart</label>
                        <select id="heizungsart" name="heizungsart">
                            <option value="">Bitte wählen</option>
                            @foreach (\App\Enums\Heizungsart::options() as $value => $label)
                                <option value="{{ $value }}" @selected(old('heizungsart', $listing->heizungsart?->value) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('heizungsart')<p class="error">{{ $message }}</p>@enderror
                    </div>

                    <div class="field @error('energietraeger') has-error @enderror">
                        <label for="energietraeger">Energieträger</label>
                        <select id="energietraeger" name="energietraeger">
                            <option value="">Bitte wählen</option>
                            @foreach (\App\Enums\Energietraeger::options() as $value => $label)
                                <option value="{{ $value }}" @selected(old('energietraeger', $listing->energietraeger?->value) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('energietraeger')<p class="error">{{ $message }}</p>@enderror
                    </div>
                </div>

                @if ($listing->istMiete())
                    <div class="field @error('heizkosten_versorgung') has-error @enderror">
                        <label for="heizkosten_versorgung">Heizkostenversorgung</label>
                        <select id="heizkosten_versorgung" name="heizkosten_versorgung">
                            <option value="">Bitte wählen</option>
                            @foreach (\App\Enums\HeizkostenVersorgung::options() as $value => $label)
                                <option value="{{ $value }}" @selected(old('heizkosten_versorgung', $listing->heizkosten_versorgung?->value) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="hint">Kann bei Bedarf auch in Schritt 4 (Preise) gewählt werden.</p>
                        @error('heizkosten_versorgung')<p class="error">{{ $message }}</p>@enderror
                    </div>
                @endif

                <div class="grid grid-2">
                    <div class="field @error('verfuegbar_ab_typ') has-error @enderror">
                        <label for="verfuegbar_ab_typ">Verfügbar ab</label>
                        <select id="verfuegbar_ab_typ" name="verfuegbar_ab_typ">
                            <option value="">Bitte wählen</option>
                            @foreach (\App\Enums\VerfuegbarAbTyp::options() as $value => $label)
                                <option value="{{ $value }}" @selected(old('verfuegbar_ab_typ', $listing->verfuegbar_ab_typ?->value) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('verfuegbar_ab_typ')<p class="error">{{ $message }}</p>@enderror
                    </div>

                    <div class="field @error('verfuegbar_ab_datum') has-error @enderror">
                        <label for="verfuegbar_ab_datum">Verfügbar ab (Datum)</label>
                        <input type="date" id="verfuegbar_ab_datum" name="verfuegbar_ab_datum" value="{{ old('verfuegbar_ab_datum', $listing->verfuegbar_ab_datum?->format('Y-m-d')) }}">
                        @error('verfuegbar_ab_datum')<p class="error">{{ $message }}</p>@enderror
                    </div>
                </div>

                @php $ausstattung = old('ausstattung', $listing->ausstattung ?? []); @endphp
                <div class="field">
                    <label>Ausstattung</label>
                    <div class="checkbox-group">
                        @foreach ([
                            'balkon' => 'Balkon', 'terrasse' => 'Terrasse', 'garten' => 'Garten', 'keller' => 'Keller',
                            'aufzug' => 'Aufzug', 'einbaukueche' => 'Einbauküche', 'gaeste_wc' => 'Gäste-WC',
                            'barrierefrei' => 'Barrierefrei', 'moebliert' => 'Möbliert', 'wg_geeignet' => 'WG-geeignet',
                            'haustiere_erlaubt' => 'Haustiere erlaubt',
                        ] as $schluessel => $label)
                            <label class="field-inline">
                                <input type="hidden" name="ausstattung[{{ $schluessel }}]" value="0">
                                <input type="checkbox" name="ausstattung[{{ $schluessel }}]" value="1" @checked($ausstattung[$schluessel] ?? false)>
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="grid grid-2">
                    <div class="field @error('stellplatz_typ') has-error @enderror">
                        <label for="stellplatz_typ">Stellplatz</label>
                        <select id="stellplatz_typ" name="stellplatz_typ">
                            <option value="">Bitte wählen</option>
                            @foreach (\App\Enums\StellplatzTyp::options() as $value => $label)
                                <option value="{{ $value }}" @selected(old('stellplatz_typ', $listing->stellplatz_typ?->value) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('stellplatz_typ')<p class="error">{{ $message }}</p>@enderror
                    </div>

                    <div class="field @error('stellplatz_anzahl') has-error @enderror">
                        <label for="stellplatz_anzahl">Anzahl Stellplätze</label>
                        <input type="number" id="stellplatz_anzahl" name="stellplatz_anzahl" value="{{ old('stellplatz_anzahl', $listing->stellplatz_anzahl) }}" min="0">
                        @error('stellplatz_anzahl')<p class="error">{{ $message }}</p>@enderror
                    </div>
                </div>

                @include('app.listings.schritte._speichern')
            </form>
        </div>
    </div>
@endsection
