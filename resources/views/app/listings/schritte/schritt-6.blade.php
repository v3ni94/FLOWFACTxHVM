@extends('layouts.app')

@section('title', 'Texte')

@section('content')
    @include('app.listings.schritte._header')

    @if (app(\App\Services\Ai\TextGenerator::class)->modell() === 'fake')
        <div class="alert alert-info" role="status">Kein KI-Anbieter konfiguriert. Die Vorschläge sind Platzhaltertexte.</div>
    @endif

    <div class="card">
        <div class="card-title">Textvorschlag erzeugen</div>
        <div class="card-body">
            <form method="POST" action="{{ route('app.listings.texts.generate', $listing) }}" class="stack">
                @csrf

                <div class="checkbox-group">
                    @foreach (\App\Enums\TextFeld::options() as $value => $label)
                        <label class="field-inline">
                            <input type="checkbox" name="felder[]" value="{{ $value }}">
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
                <p class="hint">Ohne Auswahl werden alle noch leeren Felder vorgeschlagen.</p>

                <div class="cluster">
                    <button type="submit" class="btn btn-secondary">Textvorschlag erzeugen</button>
                </div>
            </form>
        </div>
    </div>

    @if ($textvorschlaege->isNotEmpty())
        <div class="card">
            <div class="card-title">Offene Textvorschläge</div>
            <div class="card-body stack">
                @foreach (\App\Enums\TextFeld::cases() as $feld)
                    @foreach ($textvorschlaege->get($feld->value, []) as $vorschlag)
                        <div class="card card-canvas">
                            <div class="card-title">{{ $feld->label() }}</div>
                            <div class="card-body">
                                <p>{{ $vorschlag->inhalt }}</p>
                                <p class="hint">Modell: {{ $vorschlag->modell }}</p>
                                <form method="POST" action="{{ route('app.listings.texts.accept', ['listing' => $listing, 'text' => $vorschlag]) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-secondary btn-sm">Übernehmen</button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                @endforeach
            </div>
        </div>
    @endif

    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('app.listings.step.store', ['listing' => $listing, 'schritt' => 6]) }}" class="stack">
                @csrf

                <div class="field @error('titel') has-error @enderror">
                    <label for="titel">Titel</label>
                    <input type="text" id="titel" name="titel" maxlength="100" value="{{ old('titel', $listing->titel) }}">
                    @error('titel')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field @error('beschreibung_objekt') has-error @enderror">
                    <label for="beschreibung_objekt">Beschreibung Objekt</label>
                    <textarea id="beschreibung_objekt" name="beschreibung_objekt" rows="6">{{ old('beschreibung_objekt', $listing->beschreibung_objekt) }}</textarea>
                    @error('beschreibung_objekt')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field @error('beschreibung_ausstattung') has-error @enderror">
                    <label for="beschreibung_ausstattung">Beschreibung Ausstattung</label>
                    <textarea id="beschreibung_ausstattung" name="beschreibung_ausstattung" rows="4">{{ old('beschreibung_ausstattung', $listing->beschreibung_ausstattung) }}</textarea>
                    @error('beschreibung_ausstattung')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field @error('beschreibung_lage') has-error @enderror">
                    <label for="beschreibung_lage">Beschreibung Lage</label>
                    <textarea id="beschreibung_lage" name="beschreibung_lage" rows="4">{{ old('beschreibung_lage', $listing->beschreibung_lage) }}</textarea>
                    @error('beschreibung_lage')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field @error('beschreibung_sonstiges') has-error @enderror">
                    <label for="beschreibung_sonstiges">Beschreibung Sonstiges</label>
                    <textarea id="beschreibung_sonstiges" name="beschreibung_sonstiges" rows="4">{{ old('beschreibung_sonstiges', $listing->beschreibung_sonstiges) }}</textarea>
                    @error('beschreibung_sonstiges')<p class="error">{{ $message }}</p>@enderror
                </div>

                @include('app.listings.schritte._speichern')
            </form>
        </div>
    </div>
@endsection
