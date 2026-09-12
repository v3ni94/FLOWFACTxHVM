@extends('layouts.app')

@section('title', 'Preise und Heizung')

@section('content')
    @php
        $preis = $listing->price;
        $euro = fn (?int $cent) => $cent === null ? '' : \App\Support\Money::formatPlain($cent);
        $kautionHinweisObjektarten = ['wohnung', 'haus', 'mehrfamilienhaus'];
    @endphp

    @include('app.listings.schritte._header')

    <div class="grid grid-2" @if ($listing->istMiete()) data-warmmiete @endif>
        <div class="card">
            <div class="card-body">
                <form
                    method="POST"
                    action="{{ route('app.listings.step.store', ['listing' => $listing, 'schritt' => 4]) }}"
                    data-autosave="{{ route('app.listings.step.autosave', ['listing' => $listing, 'schritt' => 4]) }}"
                    class="stack"
                >
                    @csrf

                    @if ($listing->istMiete())
                        <div class="grid grid-2">
                            <x-flow.feld name="kaltmiete" label="Kaltmiete" :value="old('kaltmiete', $euro($preis?->kaltmiete_cent))" placeholder="0,00" />
                            <x-flow.feld name="nebenkosten" label="Nebenkosten (Betriebskosten)" :value="old('nebenkosten', $euro($preis?->nebenkosten_cent))" placeholder="0,00" />
                        </div>

                        <div class="stack">
                            <p class="eyebrow">Heizkosten</p>
                            <div class="kachel-grid">
                                <x-flow.kachel
                                    name="heizkosten_struktur"
                                    value="enthalten"
                                    label="In den Nebenkosten enthalten"
                                    :checked="old('heizkosten_struktur', $struktur?->value) === 'enthalten'"
                                    reveals="#heizkosten-betrag-feld"
                                />
                                <x-flow.kachel
                                    name="heizkosten_struktur"
                                    value="zusaetzlich"
                                    label="Zusätzlich an den Vermieter"
                                    :checked="old('heizkosten_struktur', $struktur?->value) === 'zusaetzlich'"
                                    reveals="#heizkosten-betrag-feld"
                                />
                                <x-flow.kachel
                                    name="heizkosten_struktur"
                                    value="eigener_vertrag"
                                    label="Mieter schließt eigenen Versorgungsvertrag"
                                    :checked="old('heizkosten_struktur', $struktur?->value) === 'eigener_vertrag'"
                                />
                            </div>
                        </div>

                        <div id="heizkosten-betrag-feld" @if (($struktur?->value ?? 'zusaetzlich') === 'eigener_vertrag') hidden @endif>
                            <x-flow.feld name="heizkosten" label="Heizkosten" :value="old('heizkosten', $euro($preis?->heizkosten_cent))" placeholder="0,00" />
                        </div>

                        <x-flow.feld
                            name="kaution"
                            label="Kaution"
                            :value="old('kaution', $euro($preis?->kaution_cent))"
                            placeholder="0,00"
                            :hint="in_array($listing->objektart->value, $kautionHinweisObjektarten, true) ? 'Bei Wohnraum höchstens drei Nettokaltmieten, gesetzliche Regelung zu prüfen.' : null"
                        />

                        <div class="stack">
                            <p class="eyebrow">Stellplatz</p>
                            <div class="kachel-grid">
                                @foreach (\App\Enums\StellplatzModus::options() as $wert => $label)
                                    <x-flow.kachel
                                        name="stellplatz_modus"
                                        :value="$wert"
                                        :label="$label"
                                        :checked="old('stellplatz_modus', $preis?->stellplatz_modus?->value ?? 'keiner') === $wert"
                                        :reveals="in_array($wert, ['optional', 'pflicht_zusaetzlich'], true) ? '#stellplatz-miete-feld' : null"
                                    />
                                @endforeach
                            </div>
                        </div>

                        <div id="stellplatz-miete-feld" @if (! in_array($preis?->stellplatz_modus?->value, ['optional', 'pflicht_zusaetzlich'], true)) hidden @endif>
                            <x-flow.feld name="stellplatz_miete" label="Stellplatzmiete" :value="old('stellplatz_miete', $euro($preis?->stellplatz_miete_cent))" placeholder="0,00" />
                        </div>
                    @else
                        <x-flow.feld name="kaufpreis" label="Kaufpreis" :value="old('kaufpreis', $euro($preis?->kaufpreis_cent))" placeholder="0,00" />

                        @if ($listing->objektart->value === 'wohnung')
                            <x-flow.feld name="hausgeld" label="Hausgeld" :value="old('hausgeld', $euro($preis?->hausgeld_cent))" placeholder="0,00" />
                        @endif

                        @if ($listing->nutzungsstatus->value === 'vermietet')
                            <x-flow.feld name="mieteinnahmen_ist" label="Ist-Mieteinnahmen (Jahresbetrag)" :value="old('mieteinnahmen_ist', $euro($preis?->mieteinnahmen_ist_cent))" placeholder="0,00" />
                        @endif

                        <div class="stack">
                            <p class="eyebrow">Stellplatz</p>
                            <div class="kachel-grid">
                                <x-flow.kachel name="stellplatz_modus" value="keiner" label="Kein Stellplatz" :checked="old('stellplatz_modus', $preis?->stellplatz_modus?->value ?? 'keiner') === 'keiner'" />
                                <x-flow.kachel name="stellplatz_modus" value="pflicht_enthalten" label="Im Kaufpreis enthalten" :checked="old('stellplatz_modus', $preis?->stellplatz_modus?->value) === 'pflicht_enthalten'" />
                                <x-flow.kachel name="stellplatz_modus" value="pflicht_zusaetzlich" label="Zusätzlich zum Kaufpreis" :checked="old('stellplatz_modus', $preis?->stellplatz_modus?->value) === 'pflicht_zusaetzlich'" reveals="#stellplatz-kaufpreis-feld" />
                            </div>
                        </div>

                        <div id="stellplatz-kaufpreis-feld" @if ($preis?->stellplatz_modus?->value !== 'pflicht_zusaetzlich') hidden @endif>
                            <x-flow.feld name="stellplatz_kaufpreis" label="Stellplatzkaufpreis" :value="old('stellplatz_kaufpreis', $euro($preis?->stellplatz_kaufpreis_cent))" placeholder="0,00" />
                        </div>

                        <x-flow.feld
                            name="heizkosten_versorgung"
                            label="Heizkostenversorgung"
                            type="select"
                            :value="$listing->heizkosten_versorgung?->value"
                            :options="\App\Enums\HeizkostenVersorgung::options()"
                        />
                    @endif

                    <div class="stack">
                        <p class="eyebrow">Provision</p>
                        <div class="grid grid-2">
                            <x-flow.feld name="provision_typ" label="Provisionsart" type="select" :value="$preis?->provision_typ?->value ?? 'provisionsfrei'" :options="\App\Enums\ProvisionTyp::options()" />
                            <x-flow.feld name="provision_text" label="Provisionstext" :value="$preis?->provision_text" placeholder="z. B. 3,57 % inkl. MwSt." />
                        </div>
                        <label class="field-inline">
                            <input type="checkbox" name="provision_bestaetigt" value="1" @checked($preis?->provision_bestaetigt)>
                            <span>Provisionsangabe geprüft und bestätigt</span>
                        </label>
                    </div>

                    <div class="stack">
                        <p class="eyebrow">Heizung</p>
                        <div class="grid grid-2">
                            <x-flow.feld name="heizungsart" label="Heizungssystem" type="select" :value="$listing->heizungsart?->value" :options="['' => '– Bitte wählen –'] + \App\Enums\Heizungsart::options()" />
                            <x-flow.feld name="energietraeger" label="Energieträger" type="select" :value="$listing->energietraeger?->value" :options="['' => '– Bitte wählen –'] + \App\Enums\Energietraeger::options()" />
                            <x-flow.feld name="heizung_waermeabgabe" label="Wärmeabgabe" type="select" :value="$listing->heizung_waermeabgabe?->value" :options="\App\Enums\Waermeabgabe::options()" />
                            <x-flow.feld name="heizung_warmwasser" label="Warmwasserbereitung" type="select" :value="$listing->heizung_warmwasser?->value" :options="\App\Enums\Warmwasserbereitung::options()" />
                        </div>
                    </div>

                    @include('app.listings.schritte._speichern')
                </form>
            </div>
        </div>

        @if ($listing->istMiete())
            <div class="card card-canvas">
                <div class="card-title">Zusammenfassung</div>
                <div class="card-body">
                    <dl class="kv">
                        <dt>Kaltmiete</dt>
                        <dd>{{ \App\Support\Money::format($preis?->kaltmiete_cent ?? 0) }}</dd>
                        <dt>Betriebskosten</dt>
                        <dd>{{ \App\Support\Money::format($preis?->nebenkosten_cent ?? 0) }}</dd>
                        <dt>Heizkosten</dt>
                        <dd>{{ $preis?->heizkosten_cent !== null ? \App\Support\Money::format($preis->heizkosten_cent) : '– siehe Hinweis –' }}</dd>
                        <dt>Gesamt an Vermieter</dt>
                        <dd data-warmmiete-output>{{ \App\Support\Money::format($preis?->warmmiete_cent ?? 0) }}</dd>
                    </dl>
                    <p class="hint" data-warmmiete-hinweis>{{ $struktur?->value === 'eigener_vertrag' ? 'Miete einschließlich Betriebskosten, zuzüglich separat zu zahlender Heizkosten.' : '' }}</p>
                    <p class="hint" data-warmmiete-stellplatz @if (! in_array($preis?->stellplatz_modus?->value, ['optional', 'pflicht_zusaetzlich'], true)) hidden @endif>
                        Stellplatz zusätzlich: <span data-warmmiete-stellplatz-output>{{ \App\Support\Money::format($preis?->stellplatz_miete_cent ?? 0) }}</span>
                    </p>
                </div>
            </div>
        @endif
    </div>
@endsection
