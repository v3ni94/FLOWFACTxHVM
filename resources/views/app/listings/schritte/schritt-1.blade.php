@extends('layouts.app')

@section('title', 'Grunddaten')

@section('content')
    @include('app.listings.schritte._header')

    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('app.listings.step.store', ['listing' => $listing, 'schritt' => 1]) }}" class="stack">
                @csrf

                <div class="grid grid-2">
                    <div class="field @error('vermarktungsart') has-error @enderror">
                        <label for="vermarktungsart">Vermarktungsart</label>
                        <select id="vermarktungsart" name="vermarktungsart" required>
                            @foreach (\App\Enums\Vermarktungsart::options() as $value => $label)
                                <option value="{{ $value }}" @selected(old('vermarktungsart', $listing->vermarktungsart->value) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('vermarktungsart')
                            <p class="error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field @error('objektart') has-error @enderror">
                        <label for="objektart">Objektart</label>
                        <select id="objektart" name="objektart" required>
                            @foreach (\App\Enums\Objektart::options() as $value => $label)
                                <option value="{{ $value }}" @selected(old('objektart', $listing->objektart->value) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('objektart')
                            <p class="error">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="field @error('strasse') has-error @enderror">
                    <label for="strasse">Straße</label>
                    <input type="text" id="strasse" name="strasse" value="{{ old('strasse', $listing->strasse) }}">
                    @error('strasse')
                        <p class="error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="grid grid-3">
                    <div class="field @error('hausnummer') has-error @enderror">
                        <label for="hausnummer">Hausnummer</label>
                        <input type="text" id="hausnummer" name="hausnummer" value="{{ old('hausnummer', $listing->hausnummer) }}">
                        @error('hausnummer')
                            <p class="error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field @error('plz') has-error @enderror">
                        <label for="plz">Postleitzahl</label>
                        <input type="text" id="plz" name="plz" value="{{ old('plz', $listing->plz) }}">
                        @error('plz')
                            <p class="error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field @error('ort') has-error @enderror">
                        <label for="ort">Ort</label>
                        <input type="text" id="ort" name="ort" value="{{ old('ort', $listing->ort) }}">
                        @error('ort')
                            <p class="error">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="field @error('land') has-error @enderror">
                    <label for="land">Land</label>
                    <input type="text" id="land" name="land" value="{{ old('land', $listing->land ?? 'DE') }}" maxlength="2">
                    @error('land')
                        <p class="error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="checkbox-group">
                    <input type="hidden" name="adresse_im_inserat_anzeigen" value="0">
                    <label class="field-inline">
                        <input type="checkbox" name="adresse_im_inserat_anzeigen" value="1" @checked(old('adresse_im_inserat_anzeigen', $listing->adresse_im_inserat_anzeigen ?? true))>
                        <span>Vollständige Adresse im Inserat anzeigen</span>
                    </label>
                    <p class="hint">Bei Nein werden nur Postleitzahl und Ort übertragen.</p>
                </div>

                @include('app.listings.schritte._speichern')
            </form>
        </div>
    </div>
@endsection
