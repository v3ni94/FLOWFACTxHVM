@extends('layouts.app')

@section('title', 'Flächen und Objektdaten')

@section('content')
    @php
        $objektart = $listing->objektart;
        $format = fn ($wert) => $wert === null ? '' : number_format((float) $wert, 2, ',', '.');
    @endphp

    @include('app.listings.schritte._header')

    <div class="card">
        <div class="card-body">
            <form
                method="POST"
                action="{{ route('app.listings.step.store', ['listing' => $listing, 'schritt' => 3]) }}"
                data-autosave="{{ route('app.listings.step.autosave', ['listing' => $listing, 'schritt' => 3]) }}"
                class="stack"
            >
                @csrf

                <div class="grid grid-2">
                    @if ($objektart->benoetigt('wohnflaeche'))
                        <x-flow.feld name="wohnflaeche_qm" label="Wohnfläche (m²)" :value="old('wohnflaeche_qm', $format($listing->wohnflaeche_qm))" hint="Format 72,50. Leer lassen, wenn unbekannt." />
                    @endif
                    @if ($objektart->benoetigt('nutzflaeche'))
                        <x-flow.feld name="nutzflaeche_qm" label="Nutzfläche (m²)" :value="old('nutzflaeche_qm', $format($listing->nutzflaeche_qm))" />
                    @endif
                    @if ($objektart->benoetigt('gewerbeflaeche'))
                        <x-flow.feld name="gewerbeflaeche_qm" label="Gewerbefläche (m²)" :value="old('gewerbeflaeche_qm', $format($listing->gewerbeflaeche_qm))" />
                    @endif
                    @if ($objektart->benoetigt('grundstueck'))
                        <x-flow.feld name="grundstuecksflaeche_qm" label="Grundstücksfläche (m²)" :value="old('grundstuecksflaeche_qm', $format($listing->grundstuecksflaeche_qm))" />
                    @endif
                </div>

                @if ($objektart->benoetigt('zimmer'))
                    <div class="grid grid-3">
                        <x-flow.feld name="zimmer" label="Zimmer" :value="old('zimmer', $format($listing->zimmer))" hint="Halbe Zimmer erlaubt, z. B. 3,5." />
                        <x-flow.feld name="schlafzimmer" label="Schlafzimmer" type="number" min="0" max="99" :value="$listing->schlafzimmer" />
                        <x-flow.feld name="badezimmer" label="Badezimmer" type="number" min="0" max="99" :value="$listing->badezimmer" />
                    </div>
                @endif

                @if ($objektart->benoetigt('etage'))
                    <div class="grid grid-2">
                        <x-flow.feld name="etage" label="Etage" type="number" min="-9" max="200" :value="$listing->etage" />
                        <x-flow.feld name="etagen_gesamt" label="Etagen gesamt" type="number" min="0" max="200" :value="$listing->etagen_gesamt" />
                    </div>
                @else
                    <x-flow.feld name="etagen_gesamt" label="Etagen gesamt" type="number" min="0" max="200" :value="$listing->etagen_gesamt" />
                @endif

                <div class="grid grid-3">
                    <x-flow.feld name="baujahr" label="Baujahr" type="number" min="1800" :max="now()->year + 3" :value="$listing->baujahr" />
                    <x-flow.feld name="modernisierungsjahr" label="Modernisierungsjahr" type="number" min="1800" :max="now()->year + 3" :value="$listing->modernisierungsjahr" />
                    <x-flow.feld name="zustand" label="Zustand" type="select" :value="$listing->zustand?->value" :options="['' => '– Bitte wählen –'] + \App\Enums\Zustand::options()" />
                </div>

                @include('app.listings.schritte._speichern')
            </form>
        </div>
    </div>
@endsection
