@extends('layouts.app')

@section('title', 'Überschrift und interne Bezeichnung')

@section('content')
    @include('app.listings.schritte._header')

    <div class="grid grid-2">
        <div class="card">
            <div class="card-title">Überschrift</div>
            <div class="card-body stack">
                <form method="POST" action="{{ route('app.listings.step.store', ['listing' => $listing, 'schritt' => 7]) }}" class="stack" data-autosave="{{ route('app.listings.step.autosave', ['listing' => $listing, 'schritt' => 7]) }}">
                    @csrf

                    <div class="field @error('titel') has-error @enderror">
                        <label for="titel">Überschrift (höchstens 100 Zeichen)</label>
                        <input type="text" id="titel" name="titel" maxlength="100" value="{{ old('titel', $listing->titel) }}" required>
                        @error('titel')<p class="error">{{ $message }}</p>@enderror
                    </div>

                    <div class="field @error('interne_bezeichnung') has-error @enderror">
                        <label for="interne_bezeichnung">Interne Bezeichnung</label>
                        <input type="text" id="interne_bezeichnung" name="interne_bezeichnung" maxlength="255" value="{{ old('interne_bezeichnung', $listing->interne_bezeichnung) }}" placeholder="{{ $interneBezeichnungVorschlag }}">
                        <p class="hint">Vorschlag nach dem hinterlegten Muster: {{ $interneBezeichnungVorschlag !== '' ? $interneBezeichnungVorschlag : 'keine Angaben vorhanden' }}. Nur intern sichtbar, nie Teil des Inserats.</p>
                        @error('interne_bezeichnung')<p class="error">{{ $message }}</p>@enderror
                    </div>

                    @include('app.listings.schritte._speichern')
                </form>

                @if ($titelVorschlaege !== [])
                    <div class="field">
                        <label>Vorschläge aus den erfassten Daten</label>
                        <div class="cluster">
                            @foreach ($titelVorschlaege as $vorschlag)
                                <form method="POST" action="{{ route('app.listings.step.store', ['listing' => $listing, 'schritt' => 7]) }}">
                                    @csrf
                                    <input type="hidden" name="titel" value="{{ $vorschlag }}">
                                    <input type="hidden" name="interne_bezeichnung" value="{{ $listing->interne_bezeichnung }}">
                                    <button type="submit" class="btn btn-secondary btn-sm">{{ $vorschlag }}</button>
                                </form>
                            @endforeach
                        </div>
                        <p class="hint">Auswahl übernimmt den Vorschlag unmittelbar als Überschrift.</p>
                    </div>
                @endif
            </div>
        </div>

        <div class="card card-canvas">
            <div class="card-title">Technische Referenz</div>
            <div class="card-body stack">
                <dl class="kv">
                    <dt>Objektnummer</dt>
                    <dd>{{ $listing->objektnummer }}</dd>
                    <dt>UUID</dt>
                    <dd>{{ $listing->uuid }}</dd>
                </dl>
                <p class="hint">Diese Referenz wird beim Anlegen vergeben und ändert sich nie, auch nicht beim Duplizieren.</p>
            </div>
        </div>
    </div>
@endsection
