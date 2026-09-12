@extends('layouts.app')

@section('title', 'Ausstattung und Energieausweis')

@section('content')
    @php
        $barriereSchluessel = array_values(array_intersect(['stufenlos', 'barrierearm', 'rollstuhlgeeignet'], $merkmalSchluessel));
        $sonstigeSchluessel = array_values(array_diff($merkmalSchluessel, $barriereSchluessel));
        $energy = $listing->energy;
        $statusWert = old('energieausweis_status', $energy?->status?->normalisiert()->value);
        $einbaukuecheSichtbar = $listing->merkmal('einbaukueche')->value === 'ja' && $listing->istMiete();
    @endphp

    @include('app.listings.schritte._header')

    <div class="card">
        <div class="card-body">
            <form
                method="POST"
                action="{{ route('app.listings.step.store', ['listing' => $listing, 'schritt' => 5]) }}"
                data-autosave="{{ route('app.listings.step.autosave', ['listing' => $listing, 'schritt' => 5]) }}"
                class="stack"
            >
                @csrf

                @if ($sonstigeSchluessel !== [])
                    <div class="stack">
                        <p class="eyebrow">Ausstattung</p>
                        @foreach ($sonstigeSchluessel as $schluessel)
                            <x-flow.dreiwert
                                :name="'merkmal_'.$schluessel"
                                :value="old('merkmal_'.$schluessel, $listing->merkmal($schluessel)->value)"
                                :label="\App\Domain\Listing\Merkmale::label($schluessel)"
                            />
                        @endforeach
                    </div>
                @endif

                @if ($einbaukuecheSichtbar)
                    <label class="field-inline">
                        <input type="hidden" name="einbaukueche_mitvermietet" value="0">
                        <input type="checkbox" name="einbaukueche_mitvermietet" value="1" @checked($listing->einbaukueche_mitvermietet)>
                        <span>Einbauküche wird mitvermietet</span>
                    </label>
                @endif

                <div class="grid grid-2">
                    <x-flow.feld
                        name="stellplatz_typ"
                        label="Stellplatztyp"
                        type="select"
                        :value="$listing->stellplatz_typ?->value"
                        :options="['' => '– Bitte wählen –'] + \App\Enums\StellplatzTyp::options()"
                    />
                    <x-flow.feld name="stellplatz_anzahl" label="Anzahl Stellplätze" type="number" min="0" max="99" :value="$listing->stellplatz_anzahl" />
                </div>

                @if ($barriereSchluessel !== [])
                    <div class="stack">
                        <p class="eyebrow">Barrierefreiheit</p>
                        <p class="hint">Ein Aufzug allein bedeutet nicht barrierefrei. Bitte die folgenden Merkmale einzeln angeben.</p>
                        @foreach ($barriereSchluessel as $schluessel)
                            <x-flow.dreiwert
                                :name="'merkmal_'.$schluessel"
                                :value="old('merkmal_'.$schluessel, $listing->merkmal($schluessel)->value)"
                                :label="\App\Domain\Listing\Merkmale::label($schluessel)"
                            />
                        @endforeach
                    </div>
                @endif

                @if ($energieausweisRelevant)
                    <div class="stack">
                        <p class="eyebrow">Energieausweis</p>

                        <div class="kachel-grid">
                            @foreach (\App\Enums\EnergieausweisStatus::options() as $wert => $label)
                                <x-flow.kachel
                                    name="energieausweis_status"
                                    :value="$wert"
                                    :label="$label"
                                    :checked="$statusWert === $wert"
                                    :reveals="$wert === 'vorhanden' ? '#energieausweis-details' : null"
                                />
                            @endforeach
                        </div>

                        <div class="alert alert-info">
                            <ul>
                                @foreach ($energieregeln as $regel)
                                    <li>{{ $regel }}</li>
                                @endforeach
                            </ul>
                        </div>

                        <div id="energieausweis-details" class="stack" @if ($statusWert !== 'vorhanden') hidden @endif>
                            <div class="grid grid-2">
                                <x-flow.feld name="ausweistyp" label="Ausweisart" type="select" :value="$energy?->ausweistyp?->value" :options="['' => '– Bitte wählen –'] + \App\Enums\Ausweistyp::options()" />
                                <x-flow.feld name="effizienzklasse" label="Effizienzklasse" type="select" :value="$energy?->effizienzklasse?->value" :options="['' => '– Bitte wählen –'] + \App\Enums\Effizienzklasse::options()" />
                            </div>
                            <div class="grid grid-2">
                                <x-flow.feld name="ausstellungsdatum" label="Ausstellungsdatum" type="date" :value="optional($energy?->ausstellungsdatum)->format('Y-m-d')" />
                                <x-flow.feld name="gueltig_bis" label="Gültig bis" type="date" :value="optional($energy?->gueltig_bis)->format('Y-m-d')" />
                            </div>
                            <div class="grid grid-2">
                                <x-flow.feld name="kennwert_kwh" label="Energiekennwert (kWh/m²a)" type="number" step="0.1" min="0" :value="$energy?->kennwert_kwh" />
                                @if ($listing->objektart->value === 'gewerbe')
                                    <x-flow.feld name="kennwert_strom_kwh" label="Kennwert Strom (kWh/m²a)" type="number" step="0.1" min="0" :value="$energy?->kennwert_strom_kwh" />
                                @endif
                            </div>
                            @if ($listing->objektart->istWohnobjekt())
                                <x-flow.feld name="baujahr_anlage" label="Baujahr laut Ausweis" type="number" min="1800" :max="now()->year + 3" :value="$energy?->baujahr_anlage" />
                            @endif
                            <label class="field-inline">
                                <input type="hidden" name="enthaelt_warmwasser" value="0">
                                <input type="checkbox" name="enthaelt_warmwasser" value="1" @checked($energy?->enthaelt_warmwasser)>
                                <span>Kennwert enthält Warmwasser</span>
                            </label>
                        </div>

                        @if ($statusWert === 'ausnahme_zu_pruefen')
                            <x-flow.feld
                                name="ausnahme_begruendung"
                                label="Begründung der Ausnahme"
                                type="textarea"
                                rows="3"
                                :value="$energy?->ausnahme_begruendung"
                                hint="Die Bestätigung durch einen Administrator erfolgt auf der Seite Prüfen und veröffentlichen."
                            />
                        @endif
                    </div>
                @endif

                @include('app.listings.schritte._speichern')
            </form>
        </div>
    </div>
@endsection
