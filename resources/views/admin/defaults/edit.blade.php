@extends('layouts.app')

@section('title', 'Vorgaben')

@section('content')
    <div class="page-header">
        <h1>Vorgaben</h1>
    </div>

    <form method="POST" action="{{ route('admin.defaults.update') }}" class="stack">
        @csrf

        <div class="grid grid-2">
            <div class="card">
                <div class="card-title">Land und Währung</div>
                <div class="card-body stack">
                    <div class="field @error('land') has-error @enderror">
                        <label for="land">Land (ISO-Code)</label>
                        <input type="text" id="land" name="land" maxlength="2" value="{{ old('land', $land) }}">
                        @error('land')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <div class="field">
                        <label>Währung</label>
                        <input type="text" value="{{ $waehrung }}" disabled>
                        <p class="hint">Nur Anzeige, Beträge werden immer in Euro geführt (Datenvertrag Abschnitt 1).</p>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-title">Standardansprechpartner</div>
                <div class="card-body stack">
                    <div class="field @error('ansprechpartner_user_id') has-error @enderror">
                        <label for="ansprechpartner_user_id">Ansprechpartner</label>
                        <select id="ansprechpartner_user_id" name="ansprechpartner_user_id">
                            <option value="">Kein Standardansprechpartner</option>
                            @foreach ($benutzer as $benutzerZeile)
                                <option value="{{ $benutzerZeile->id }}" @selected((int) old('ansprechpartner_user_id', (string) $ansprechpartnerId) === $benutzerZeile->id)>{{ $benutzerZeile->name }}</option>
                            @endforeach
                        </select>
                        <p class="hint">Wird bei neu angelegten Objekten als Vorschlag für den öffentlichen Ansprechpartner verwendet.</p>
                        @error('ansprechpartner_user_id')<p class="error">{{ $message }}</p>@enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-title">Interne Bezeichnung</div>
            <div class="card-body stack">
                <div class="field @error('interne_bezeichnung_muster') has-error @enderror">
                    <label for="interne_bezeichnung_muster">Muster</label>
                    <input type="text" id="interne_bezeichnung_muster" name="interne_bezeichnung_muster" value="{{ old('interne_bezeichnung_muster', $interneBezeichnungMuster) }}">
                    <p class="hint">Platzhalter: {{ implode(', ', $platzhalter) }}. Wird in Schritt 7 als Vorschlag verwendet.</p>
                    @error('interne_bezeichnung_muster')<p class="error">{{ $message }}</p>@enderror
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-title">Portalvorauswahl je Vermarktungsart</div>
            <div class="card-body stack">
                @if (! $portaleKonfiguriert)
                    <div class="alert alert-warning">FLOWFACT ist nicht konfiguriert. Bitte hinterlegen Sie zuerst den API-Token im Adminbereich, um Portale auszuwählen.</div>
                @else
                    <div class="grid grid-2">
                        <div class="stack">
                            <span class="eyebrow">Miete</span>
                            <div class="checkbox-group">
                                @forelse ($portale as $portal)
                                    <label class="field-inline">
                                        <input type="checkbox" name="portale_miete[]" value="{{ $portal->id }}" @checked(in_array($portal->id, old('portale_miete', $portaleMiete), true))>
                                        <span>{{ $portal->name }}</span>
                                    </label>
                                @empty
                                    <p class="hint">Keine Portale verfügbar.</p>
                                @endforelse
                            </div>
                        </div>
                        <div class="stack">
                            <span class="eyebrow">Kauf</span>
                            <div class="checkbox-group">
                                @forelse ($portale as $portal)
                                    <label class="field-inline">
                                        <input type="checkbox" name="portale_kauf[]" value="{{ $portal->id }}" @checked(in_array($portal->id, old('portale_kauf', $portaleKauf), true))>
                                        <span>{{ $portal->name }}</span>
                                    </label>
                                @empty
                                    <p class="hint">Keine Portale verfügbar.</p>
                                @endforelse
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-title">Neutrale Textbausteine für "Beschreibung Sonstiges"</div>
            <div class="card-body stack">
                <div class="field">
                    <label for="textbausteine_sonstiges">Ein Satz je Zeile</label>
                    <textarea id="textbausteine_sonstiges" name="textbausteine_sonstiges" rows="5">{{ old('textbausteine_sonstiges', implode("\n", $textbausteine)) }}</textarea>
                    <p class="hint">Diese Sätze stehen in Schritt 8 als geprüfte Bausteine zur Auswahl, ohne KI-Erzeugung.</p>
                </div>
            </div>
        </div>

        <div class="cluster">
            <button type="submit" class="btn btn-primary">Vorgaben speichern</button>
        </div>
    </form>
@endsection
