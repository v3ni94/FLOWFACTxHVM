@extends('layouts.app')

@section('title', 'Vermietung oder Verkauf')

@section('content')
    @include('app.listings.schritte._header')

    <div class="card">
        <div class="card-body">
            <form
                method="POST"
                action="{{ route('app.listings.step.store', ['listing' => $listing, 'schritt' => 1]) }}"
                data-autosave="{{ route('app.listings.step.autosave', ['listing' => $listing, 'schritt' => 1]) }}"
                class="stack"
            >
                @csrf

                <div class="stack">
                    <p class="eyebrow">Vermarktungsart</p>
                    <div class="kachel-grid">
                        @foreach (\App\Enums\Vermarktungsart::options() as $wert => $label)
                            <x-flow.kachel
                                name="vermarktungsart"
                                :value="$wert"
                                :label="$label"
                                :checked="old('vermarktungsart', $listing->vermarktungsart->value) === $wert"
                            />
                        @endforeach
                    </div>
                    @error('vermarktungsart')
                        <p class="error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="stack">
                    <p class="eyebrow">Objektart</p>
                    <div class="kachel-grid">
                        @foreach (\App\Enums\Objektart::options() as $wert => $label)
                            <x-flow.kachel
                                name="objektart"
                                :value="$wert"
                                :label="$label"
                                :checked="old('objektart', $listing->objektart->value) === $wert"
                                :reveals="$wert === 'gewerbe' ? '#gewerbe-unterart-feld' : null"
                            />
                        @endforeach
                    </div>
                    @error('objektart')
                        <p class="error">{{ $message }}</p>
                    @enderror

                    @if ($objektartHinweis)
                        <div class="alert alert-info">{{ $objektartHinweis }}</div>
                    @endif
                </div>

                <div id="gewerbe-unterart-feld" @if ($listing->objektart->value !== 'gewerbe') hidden @endif>
                    <x-flow.feld
                        name="gewerbe_unterart"
                        label="Gewerbe-Unterart"
                        type="select"
                        :value="$listing->gewerbe_unterart?->value"
                        :options="\App\Enums\GewerbeUnterart::options()"
                    />
                </div>

                <div class="grid grid-2">
                    <x-flow.feld
                        name="bearbeiter_user_id"
                        label="Zuständiger Mitarbeiter"
                        type="select"
                        required
                        :value="$listing->bearbeiter_user_id ?? auth()->id()"
                        :options="$benutzer->pluck('name', 'id')"
                    />

                    <x-flow.feld
                        name="ansprechpartner_user_id"
                        label="Öffentlicher Ansprechpartner"
                        type="select"
                        hint="Erscheint mit Name, Telefon und E-Mail im Inserat. Ohne Auswahl gilt der zuständige Mitarbeiter."
                        :value="$listing->ansprechpartner_user_id"
                        :options="$benutzer->pluck('name', 'id')"
                    />
                </div>

                <div class="stack">
                    <p class="eyebrow">Verfügbarkeit</p>
                    <div class="kachel-grid kachel-grid-sm">
                        @foreach (\App\Enums\VerfuegbarAbTyp::options() as $wert => $label)
                            <x-flow.kachel
                                name="verfuegbar_ab_typ"
                                :value="$wert"
                                :label="$label"
                                :checked="old('verfuegbar_ab_typ', $listing->verfuegbar_ab_typ?->value ?? 'sofort') === $wert"
                                :reveals="$wert === 'datum' ? '#verfuegbar-ab-datum-feld' : null"
                            />
                        @endforeach
                    </div>
                </div>

                <div id="verfuegbar-ab-datum-feld" @if (($listing->verfuegbar_ab_typ?->value ?? 'sofort') !== 'datum') hidden @endif>
                    <x-flow.feld
                        name="verfuegbar_ab_datum"
                        label="Verfügbar ab (Datum)"
                        type="date"
                        :value="optional($listing->verfuegbar_ab_datum)->format('Y-m-d')"
                    />
                </div>

                <x-flow.feld
                    name="nutzungsstatus"
                    label="Nutzungsstatus"
                    type="select"
                    :value="$listing->nutzungsstatus->value"
                    :options="\App\Enums\Nutzungsstatus::options()"
                />

                @include('app.listings.schritte._speichern')
            </form>
        </div>
    </div>
@endsection
