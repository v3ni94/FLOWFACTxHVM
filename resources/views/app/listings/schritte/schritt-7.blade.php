@extends('layouts.app')

@section('title', 'Intern')

@section('content')
    @include('app.listings.schritte._header')

    @php $internal = $listing->internal; @endphp

    <div class="card">
        <div class="card-body stack">
            <div class="alert alert-warning">
                Diese Angaben sind intern und werden nie an FLOWFACT oder Portale übertragen.
            </div>

            <form method="POST" action="{{ route('app.listings.step.store', ['listing' => $listing, 'schritt' => 7]) }}" class="stack">
                @csrf

                <div class="field @error('eigentuemer_name') has-error @enderror">
                    <label for="eigentuemer_name">Eigentümer</label>
                    <input type="text" id="eigentuemer_name" name="eigentuemer_name" value="{{ old('eigentuemer_name', $internal?->eigentuemer_name) }}">
                    @error('eigentuemer_name')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field @error('eigentuemer_kontakt') has-error @enderror">
                    <label for="eigentuemer_kontakt">Kontakt Eigentümer</label>
                    <textarea id="eigentuemer_kontakt" name="eigentuemer_kontakt" rows="3">{{ old('eigentuemer_kontakt', $internal?->eigentuemer_kontakt) }}</textarea>
                    @error('eigentuemer_kontakt')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field @error('verwaltungsobjekt_referenz') has-error @enderror">
                    <label for="verwaltungsobjekt_referenz">Verwaltungsobjekt-Referenz</label>
                    <input type="text" id="verwaltungsobjekt_referenz" name="verwaltungsobjekt_referenz" value="{{ old('verwaltungsobjekt_referenz', $internal?->verwaltungsobjekt_referenz) }}">
                    @error('verwaltungsobjekt_referenz')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field @error('interne_notizen') has-error @enderror">
                    <label for="interne_notizen">Interne Notizen</label>
                    <textarea id="interne_notizen" name="interne_notizen" rows="4">{{ old('interne_notizen', $internal?->interne_notizen) }}</textarea>
                    @error('interne_notizen')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field @error('schluessel_hinweis') has-error @enderror">
                    <label for="schluessel_hinweis">Schlüsselhinweis</label>
                    <textarea id="schluessel_hinweis" name="schluessel_hinweis" rows="2">{{ old('schluessel_hinweis', $internal?->schluessel_hinweis) }}</textarea>
                    @error('schluessel_hinweis')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field @error('besichtigung_intern') has-error @enderror">
                    <label for="besichtigung_intern">Besichtigung (intern)</label>
                    <textarea id="besichtigung_intern" name="besichtigung_intern" rows="3">{{ old('besichtigung_intern', $internal?->besichtigung_intern) }}</textarea>
                    @error('besichtigung_intern')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field @error('kalkulation_notiz') has-error @enderror">
                    <label for="kalkulation_notiz">Kalkulationsnotiz</label>
                    <textarea id="kalkulation_notiz" name="kalkulation_notiz" rows="3">{{ old('kalkulation_notiz', $internal?->kalkulation_notiz) }}</textarea>
                    @error('kalkulation_notiz')<p class="error">{{ $message }}</p>@enderror
                </div>

                @include('app.listings.schritte._speichern')
            </form>
        </div>
    </div>
@endsection
