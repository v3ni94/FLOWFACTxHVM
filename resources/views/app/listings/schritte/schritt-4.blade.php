@extends('layouts.app')

@section('title', 'Preise')

@section('content')
    @include('app.listings.schritte._header')

    @php $preis = $listing->price; @endphp

    @if ($warmmieteVorschau)
        <div class="alert alert-info">
            Aktuelle Warmmiete: <strong>{{ \App\Support\Money::format($warmmieteVorschau['warmmieteCent']) }}</strong>
            @foreach ($warmmieteVorschau['hinweise'] as $hinweis)
                <br>{{ $hinweis }}
            @endforeach
        </div>
    @endif

    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('app.listings.step.store', ['listing' => $listing, 'schritt' => 4]) }}" class="stack">
                @csrf

                @if ($listing->istMiete())
                    <div data-warmmiete>
                        <div class="grid grid-3">
                            <div class="field @error('kaltmiete') has-error @enderror">
                                <label for="kaltmiete">Kaltmiete</label>
                                <input type="text" id="kaltmiete" name="kaltmiete" value="{{ old('kaltmiete', $preis?->kaltmiete_cent !== null ? \App\Support\Money::formatPlain($preis->kaltmiete_cent) : '') }}" placeholder="0,00">
                                @error('kaltmiete')<p class="error">{{ $message }}</p>@enderror
                            </div>

                            <div class="field @error('nebenkosten') has-error @enderror">
                                <label for="nebenkosten">Nebenkosten</label>
                                <input type="text" id="nebenkosten" name="nebenkosten" value="{{ old('nebenkosten', $preis?->nebenkosten_cent !== null ? \App\Support\Money::formatPlain($preis->nebenkosten_cent) : '') }}" placeholder="0,00">
                                @error('nebenkosten')<p class="error">{{ $message }}</p>@enderror
                            </div>

                            <div class="field @error('heizkosten') has-error @enderror">
                                <label for="heizkosten">Heizkosten</label>
                                <input type="text" id="heizkosten" name="heizkosten" value="{{ old('heizkosten', $preis?->heizkosten_cent !== null ? \App\Support\Money::formatPlain($preis->heizkosten_cent) : '') }}" placeholder="0,00">
                                @error('heizkosten')<p class="error">{{ $message }}</p>@enderror
                            </div>
                        </div>

                        <div class="checkbox-group">
                            <input type="hidden" name="heizkosten_in_nebenkosten_enthalten" value="0">
                            <label class="field-inline">
                                <input type="checkbox" name="heizkosten_in_nebenkosten_enthalten" value="1" @checked(old('heizkosten_in_nebenkosten_enthalten', $preis?->heizkosten_in_nebenkosten_enthalten))>
                                <span>Heizkosten sind bereits in den Nebenkosten enthalten</span>
                            </label>
                        </div>

                        <div class="field @error('heizkosten_versorgung') has-error @enderror">
                            <label for="heizkosten_versorgung">Heizkostenversorgung</label>
                            <select id="heizkosten_versorgung" name="heizkosten_versorgung" required>
                                <option value="">Bitte wählen</option>
                                @foreach (\App\Enums\HeizkostenVersorgung::options() as $value => $label)
                                    <option value="{{ $value }}" @selected(old('heizkosten_versorgung', $listing->heizkosten_versorgung?->value) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('heizkosten_versorgung')<p class="error">{{ $message }}</p>@enderror
                        </div>

                        <p class="hint">Vorschau (wird beim Speichern serverseitig neu berechnet): <span data-warmmiete-output>–</span></p>
                        <p class="hint" data-warmmiete-hinweis hidden></p>
                    </div>

                    <div class="grid grid-2">
                        <div class="field @error('kaution') has-error @enderror">
                            <label for="kaution">Kaution</label>
                            <input type="text" id="kaution" name="kaution" value="{{ old('kaution', $preis?->kaution_cent !== null ? \App\Support\Money::formatPlain($preis->kaution_cent) : '') }}" placeholder="0,00">
                            @error('kaution')<p class="error">{{ $message }}</p>@enderror
                        </div>

                        <div class="field @error('stellplatz_miete') has-error @enderror">
                            <label for="stellplatz_miete">Stellplatzmiete</label>
                            <input type="text" id="stellplatz_miete" name="stellplatz_miete" value="{{ old('stellplatz_miete', $preis?->stellplatz_miete_cent !== null ? \App\Support\Money::formatPlain($preis->stellplatz_miete_cent) : '') }}" placeholder="0,00">
                            @error('stellplatz_miete')<p class="error">{{ $message }}</p>@enderror
                        </div>
                    </div>
                @else
                    <div class="grid grid-2">
                        <div class="field @error('kaufpreis') has-error @enderror">
                            <label for="kaufpreis">Kaufpreis</label>
                            <input type="text" id="kaufpreis" name="kaufpreis" value="{{ old('kaufpreis', $preis?->kaufpreis_cent !== null ? \App\Support\Money::formatPlain($preis->kaufpreis_cent) : '') }}" placeholder="0,00">
                            @error('kaufpreis')<p class="error">{{ $message }}</p>@enderror
                        </div>

                        <div class="field @error('hausgeld') has-error @enderror">
                            <label for="hausgeld">Hausgeld</label>
                            <input type="text" id="hausgeld" name="hausgeld" value="{{ old('hausgeld', $preis?->hausgeld_cent !== null ? \App\Support\Money::formatPlain($preis->hausgeld_cent) : '') }}" placeholder="0,00">
                            @error('hausgeld')<p class="error">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <div class="grid grid-2">
                        <div class="field @error('stellplatz_kaufpreis') has-error @enderror">
                            <label for="stellplatz_kaufpreis">Stellplatz Kaufpreis</label>
                            <input type="text" id="stellplatz_kaufpreis" name="stellplatz_kaufpreis" value="{{ old('stellplatz_kaufpreis', $preis?->stellplatz_kaufpreis_cent !== null ? \App\Support\Money::formatPlain($preis->stellplatz_kaufpreis_cent) : '') }}" placeholder="0,00">
                            @error('stellplatz_kaufpreis')<p class="error">{{ $message }}</p>@enderror
                        </div>

                        <div class="field @error('mieteinnahmen_ist') has-error @enderror">
                            <label for="mieteinnahmen_ist">Ist-Mieteinnahmen (Jahr)</label>
                            <input type="text" id="mieteinnahmen_ist" name="mieteinnahmen_ist" value="{{ old('mieteinnahmen_ist', $preis?->mieteinnahmen_ist_cent !== null ? \App\Support\Money::formatPlain($preis->mieteinnahmen_ist_cent) : '') }}" placeholder="0,00">
                            @error('mieteinnahmen_ist')<p class="error">{{ $message }}</p>@enderror
                        </div>
                    </div>
                @endif

                <div class="grid grid-2">
                    <div class="field @error('provision_typ') has-error @enderror">
                        <label for="provision_typ">Provision</label>
                        <select id="provision_typ" name="provision_typ" required>
                            @foreach (\App\Enums\ProvisionTyp::options() as $value => $label)
                                <option value="{{ $value }}" @selected(old('provision_typ', $preis?->provision_typ?->value) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('provision_typ')<p class="error">{{ $message }}</p>@enderror
                    </div>

                    <div class="field @error('provision_text') has-error @enderror">
                        <label for="provision_text">Provisionstext</label>
                        <input type="text" id="provision_text" name="provision_text" value="{{ old('provision_text', $preis?->provision_text) }}" placeholder="z. B. 3,57 % inkl. MwSt.">
                        @error('provision_text')<p class="error">{{ $message }}</p>@enderror
                    </div>
                </div>

                @include('app.listings.schritte._speichern')
            </form>
        </div>
    </div>
@endsection
