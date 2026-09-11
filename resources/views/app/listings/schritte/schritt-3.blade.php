@extends('layouts.app')

@section('title', 'Energieausweis')

@section('content')
    @include('app.listings.schritte._header')

    @php $energy = $listing->energy; @endphp

    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('app.listings.step.store', ['listing' => $listing, 'schritt' => 3]) }}" class="stack">
                @csrf

                <div class="field @error('status') has-error @enderror">
                    <label for="status">Status</label>
                    <select id="status" name="status" data-toggle="#energie-details">
                        <option value="">Bitte wählen</option>
                        @foreach (\App\Enums\EnergieausweisStatus::options() as $value => $label)
                            <option value="{{ $value }}" @selected(old('status', $energy?->status?->value) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('status')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="grid grid-3">
                    <div class="field @error('ausweistyp') has-error @enderror">
                        <label for="ausweistyp">Ausweistyp</label>
                        <select id="ausweistyp" name="ausweistyp">
                            <option value="">Bitte wählen</option>
                            @foreach (\App\Enums\Ausweistyp::options() as $value => $label)
                                <option value="{{ $value }}" @selected(old('ausweistyp', $energy?->ausweistyp?->value) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('ausweistyp')<p class="error">{{ $message }}</p>@enderror
                    </div>

                    <div class="field @error('kennwert_kwh') has-error @enderror">
                        <label for="kennwert_kwh">Kennwert (kWh/(m²·a))</label>
                        <input type="text" id="kennwert_kwh" name="kennwert_kwh" value="{{ old('kennwert_kwh', $energy?->kennwert_kwh) }}">
                        @error('kennwert_kwh')<p class="error">{{ $message }}</p>@enderror
                    </div>

                    <div class="field @error('effizienzklasse') has-error @enderror">
                        <label for="effizienzklasse">Effizienzklasse</label>
                        <select id="effizienzklasse" name="effizienzklasse">
                            <option value="">Bitte wählen</option>
                            @foreach (\App\Enums\Effizienzklasse::options() as $value => $label)
                                <option value="{{ $value }}" @selected(old('effizienzklasse', $energy?->effizienzklasse?->value) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('effizienzklasse')<p class="error">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="grid grid-2">
                    <div class="field @error('baujahr_anlage') has-error @enderror">
                        <label for="baujahr_anlage">Baujahr der Heizungsanlage</label>
                        <input type="number" id="baujahr_anlage" name="baujahr_anlage" value="{{ old('baujahr_anlage', $energy?->baujahr_anlage) }}">
                        @error('baujahr_anlage')<p class="error">{{ $message }}</p>@enderror
                    </div>

                    <div class="field @error('gueltig_bis') has-error @enderror">
                        <label for="gueltig_bis">Gültig bis</label>
                        <input type="date" id="gueltig_bis" name="gueltig_bis" value="{{ old('gueltig_bis', $energy?->gueltig_bis?->format('Y-m-d')) }}">
                        @error('gueltig_bis')<p class="error">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="checkbox-group">
                    <input type="hidden" name="enthaelt_warmwasser" value="0">
                    <label class="field-inline">
                        <input type="checkbox" name="enthaelt_warmwasser" value="1" @checked(old('enthaelt_warmwasser', $energy?->enthaelt_warmwasser))>
                        <span>Warmwasser ist im Verbrauchswert enthalten</span>
                    </label>
                    <p class="hint">Nur bei Verbrauchsausweis relevant.</p>
                </div>

                @include('app.listings.schritte._speichern')
            </form>
        </div>
    </div>
@endsection
