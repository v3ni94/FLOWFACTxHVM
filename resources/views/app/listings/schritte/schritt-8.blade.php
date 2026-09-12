@extends('layouts.app')

@section('title', 'Beschreibungen')

@section('content')
    @include('app.listings.schritte._header')

    @php
        $felder = [
            'beschreibung_objekt' => 'Beschreibung Objekt',
            'beschreibung_ausstattung' => 'Beschreibung Ausstattung',
            'beschreibung_lage' => 'Beschreibung Lage',
            'beschreibung_sonstiges' => 'Beschreibung Sonstiges',
        ];
    @endphp

    <div class="card">
        <div class="card-body cluster">
            <form method="POST" action="{{ route('app.listings.texts.generate', $listing) }}">
                @csrf
                <button type="submit" class="btn btn-primary">Textentwürfe erstellen</button>
            </form>
            <p class="hint">Erzeugt Entwürfe für alle noch leeren Beschreibungsfelder. Vorhandene Texte bleiben unverändert.</p>
        </div>
    </div>

    <form method="POST" action="{{ route('app.listings.step.store', ['listing' => $listing, 'schritt' => 8]) }}" class="stack" data-autosave="{{ route('app.listings.step.autosave', ['listing' => $listing, 'schritt' => 8]) }}">
        @csrf

        @foreach ($felder as $spalte => $label)
            <div class="card">
                <div class="card-title cluster">
                    <span>{{ $label }}</span>
                    @if ($pruefbeduerftig[$spalte] ?? false)
                        <span class="badge badge-warning">Prüfbedürftig: Daten haben sich seit der letzten Übernahme geändert</span>
                    @endif
                </div>
                <div class="card-body stack">
                    @if ($adressWarnung[$spalte] ?? false)
                        <div class="alert alert-error">Dieser Text enthält Straße oder Hausnummer, obwohl die Adressfreigabe nur PLZ und Ort erlaubt. Bitte entfernen Sie die Angabe vor der Veröffentlichung.</div>
                    @endif

                    <div class="field @error($spalte) has-error @enderror">
                        <label for="{{ $spalte }}">{{ $label }}</label>
                        <textarea id="{{ $spalte }}" name="{{ $spalte }}" rows="6">{{ old($spalte, $listing->{$spalte}) }}</textarea>
                        <p class="hint">{{ mb_strlen((string) old($spalte, $listing->{$spalte})) }} Zeichen.</p>
                        @error($spalte)<p class="error">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="card-footer cluster">
                    <button type="submit" formaction="{{ route('app.listings.texts.generate', $listing) }}" name="felder[]" value="{{ $spalte }}" formmethod="POST" class="btn btn-secondary btn-sm">Neu erstellen</button>

                    @foreach ([['kuerzer', 'Kürzer'], ['sachlicher', 'Sachlicher'], ['sprachlich', 'Sprachlich verbessern']] as [$anweisung, $anweisungLabel])
                        <form method="POST" action="{{ route('app.listings.texts.revise', $listing) }}">
                            @csrf
                            <input type="hidden" name="feld" value="{{ $spalte }}">
                            <input type="hidden" name="text" value="{{ $listing->{$spalte} }}">
                            <input type="hidden" name="anweisung" value="{{ $anweisung }}">
                            <button type="submit" class="btn btn-ghost btn-sm" @disabled(empty($listing->{$spalte}))>{{ $anweisungLabel }}</button>
                        </form>
                    @endforeach
                </div>
            </div>

            @if (($textvorschlaege[$spalte] ?? null) && $textvorschlaege[$spalte]->isNotEmpty())
                <div class="grid grid-2">
                    @foreach ($textvorschlaege[$spalte] as $vorschlag)
                        <div class="card card-canvas">
                            <div class="card-title">
                                {{ $vorschlag->modell === 'fake' ? 'Vorlagenentwurf (ohne KI)' : 'Vorschlag ('.$vorschlag->modell.')' }}
                                @if ($vorschlag->anweisung)
                                    <span class="badge badge-info">{{ ucfirst($vorschlag->anweisung) }}</span>
                                @endif
                            </div>
                            <div class="card-body">
                                <p>{{ $vorschlag->inhalt }}</p>
                            </div>
                            <div class="card-footer">
                                <form method="POST" action="{{ route('app.listings.texts.accept', ['listing' => $listing, 'text' => $vorschlag]) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-secondary btn-sm">Übernehmen</button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        @endforeach

        @include('app.listings.schritte._speichern')
    </form>
@endsection
