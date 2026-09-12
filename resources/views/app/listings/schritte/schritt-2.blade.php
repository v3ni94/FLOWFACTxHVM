@extends('layouts.app')

@section('title', 'Adresse und Lage')

@section('content')
    @include('app.listings.schritte._header')

    @if ($moeglicheQuellen->isNotEmpty())
        <div class="card card-canvas">
            <div class="card-body">
                <form method="POST" action="{{ route('app.listings.step.store', ['listing' => $listing, 'schritt' => 2]) }}" class="cluster">
                    @csrf
                    <input type="hidden" name="aktion" value="uebernehmen">
                    <div class="field field-grow">
                        <label for="feld-quelle_listing_id">Gebäudedaten aus vorhandenem Objekt übernehmen</label>
                        <select id="feld-quelle_listing_id" name="quelle_listing_id">
                            @foreach ($moeglicheQuellen as $quelle)
                                <option value="{{ $quelle->id }}">{{ $quelle->objektnummer }} – {{ $quelle->adresseKurz() }}</option>
                            @endforeach
                        </select>
                        <p class="hint">Übernimmt Straße, Hausnummer, PLZ, Ort, Stadtteil, Baujahr, Etagen gesamt und die Heizungsfelder nur in leere Felder dieses Objekts.</p>
                    </div>
                    <button type="submit" class="btn btn-secondary" formnovalidate>Übernehmen</button>
                </form>
            </div>
        </div>
    @endif

    <div class="card">
        <div class="card-body">
            <form
                method="POST"
                action="{{ route('app.listings.step.store', ['listing' => $listing, 'schritt' => 2]) }}"
                data-autosave="{{ route('app.listings.step.autosave', ['listing' => $listing, 'schritt' => 2]) }}"
                class="stack"
            >
                @csrf

                <x-flow.feld name="strasse" label="Straße" :value="$listing->strasse" />

                <div class="grid grid-3">
                    <x-flow.feld name="hausnummer" label="Hausnummer" :value="$listing->hausnummer" hint="Freier Text, z. B. 12a oder 12 bis 14." />
                    <x-flow.feld name="adresszusatz" label="Adresszusatz" :value="$listing->adresszusatz" />
                    <x-flow.feld name="stadtteil" label="Stadtteil" :value="$listing->stadtteil" />
                </div>

                <div class="grid grid-3">
                    <x-flow.feld name="plz" label="Postleitzahl" :value="$listing->plz" />
                    <x-flow.feld name="ort" label="Ort" :value="$listing->ort" />
                    <x-flow.feld name="land" label="Land" :value="$listing->land ?? $standardLand" maxlength="2" />
                </div>

                <div class="stack">
                    <p class="eyebrow">Adressfreigabe im Inserat</p>
                    <div class="kachel-grid">
                        @foreach (\App\Enums\AdressFreigabe::options() as $wert => $label)
                            <x-flow.kachel
                                name="adress_freigabe"
                                :value="$wert"
                                :label="$label"
                                :checked="old('adress_freigabe', $listing->adress_freigabe->value) === $wert"
                            />
                        @endforeach
                    </div>
                    <p class="hint">Bei "Nur PLZ und Ort" werden Straße und Hausnummer nicht im Inserat und nicht in erzeugten Texten verwendet.</p>
                </div>

                <div class="card card-canvas">
                    <div class="card-body">
                        <button type="button" class="collapsible-summary" data-toggle="#interne-angaben" aria-expanded="false">
                            <span>Interne Angaben (nicht Teil des Inserats)</span>
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M6 9l6 6 6-6" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </button>
                        <div id="interne-angaben" class="collapsible-body stack" hidden>
                            <p class="hint">Diese Felder gehen nie an FLOWFACT oder an Portale.</p>

                            <div class="grid grid-2">
                                <x-flow.feld name="gebaeudebezeichnung" label="Gebäudebezeichnung" :value="$listing->internal?->gebaeudebezeichnung" />
                                <x-flow.feld name="einheitsnummer" label="Einheitsnummer" :value="$listing->internal?->einheitsnummer" />
                            </div>

                            <x-flow.feld name="lage_im_gebaeude" label="Lage im Gebäude" :value="$listing->internal?->lage_im_gebaeude" />
                            <x-flow.feld name="verwaltungsobjekt_referenz" label="Bezug zum Verwaltungsbestand" :value="$listing->internal?->verwaltungsobjekt_referenz" />
                            <x-flow.feld name="eigentuemer_name" label="Eigentümer" :value="$listing->internal?->eigentuemer_name" />
                            <x-flow.feld name="eigentuemer_kontakt" label="Eigentümerkontakt" type="textarea" rows="2" :value="$listing->internal?->eigentuemer_kontakt" />
                            <x-flow.feld name="interne_notizen" label="Interne Notizen" type="textarea" rows="3" :value="$listing->internal?->interne_notizen" />
                            <x-flow.feld name="schluessel_hinweis" label="Schlüsselhinweis" type="textarea" rows="2" :value="$listing->internal?->schluessel_hinweis" />
                            <x-flow.feld name="besichtigung_intern" label="Hinweise zur Besichtigung (intern)" type="textarea" rows="2" :value="$listing->internal?->besichtigung_intern" />
                            <x-flow.feld name="kalkulation_notiz" label="Kalkulationsnotiz" type="textarea" rows="2" :value="$listing->internal?->kalkulation_notiz" />
                        </div>
                    </div>
                </div>

                @include('app.listings.schritte._speichern')
            </form>
        </div>
    </div>
@endsection
