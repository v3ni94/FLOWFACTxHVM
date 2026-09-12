@extends('layouts.app')

@section('title', 'KI-Texte')

@section('content')
    <div class="page-header">
        <h1>KI-Texte</h1>
    </div>

    <div class="stack">
        @if ($vorlagenmodusAktiv)
            <div class="alert alert-warning" role="status">Vorlagenmodus aktiv, keine externen Aufrufe.</div>
        @endif

        <div class="grid grid-2">
            <div class="card">
                <div class="card-title">API-Schlüssel</div>
                <div class="card-body stack">
                    @if ($apiKeyHinterlegt)
                        <div class="status-row">
                            <span class="badge badge-success">Hinterlegt</span>
                            <span>hinterlegt am {{ $apiKeyHinterlegtAt?->format('d.m.Y H:i') ?? 'unbekannt' }}</span>
                        </div>
                        <p class="hint">Der Schlüssel wird verschlüsselt gespeichert und aus Sicherheitsgründen nie angezeigt. Zum Austausch geben Sie einen neuen Schlüssel ein.</p>
                    @else
                        <div class="alert alert-warning" role="status">Es ist kein API-Schlüssel hinterlegt. Es werden nur Platzhaltertexte erzeugt.</div>
                    @endif

                    <form method="POST" action="{{ route('admin.ki.key.store') }}" class="stack">
                        @csrf
                        <div class="field @error('api_key') has-error @enderror">
                            <label for="api_key">{{ $apiKeyHinterlegt ? 'Neuen API-Schlüssel hinterlegen' : 'API-Schlüssel' }}</label>
                            <input type="password" id="api_key" name="api_key" autocomplete="off" required>
                            <p class="hint">Der Schlüssel wird nur gespeichert, nicht zurückgelesen.</p>
                            @error('api_key')
                                <p class="error">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="cluster">
                            <button type="submit" class="btn btn-primary">Schlüssel speichern</button>
                        </div>
                    </form>

                    @if ($apiKeyHinterlegt)
                        <form method="POST" action="{{ route('admin.ki.key.destroy') }}" data-confirm="Den API-Schlüssel wirklich entfernen? Danach werden nur noch Platzhaltertexte erzeugt.">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger btn-sm">Schlüssel entfernen</button>
                        </form>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-title">Verbindung</div>
                <div class="card-body stack">
                    @if ($verbindungGeprueftAt !== null)
                        <div class="kv">
                            <dt>Zuletzt geprüft</dt>
                            <dd>{{ $verbindungGeprueftAt->format('d.m.Y H:i') }}</dd>
                            <dt>Ergebnis</dt>
                            <dd>{{ $verbindungErgebnis ?? 'unbekannt' }}</dd>
                        </div>
                    @else
                        <p class="hint">Die Verbindung wurde noch nicht geprüft.</p>
                    @endif

                    <form method="POST" action="{{ route('admin.ki.test') }}">
                        @csrf
                        <button type="submit" class="btn btn-secondary" @disabled(! $apiKeyHinterlegt)>Verbindung testen</button>
                    </form>
                    <p class="hint">Sendet eine kurze Testanfrage an den KI-Anbieter. Es werden keine Objektdaten übertragen.</p>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-title">Anbieter und Modell</div>
            <div class="card-body stack">
                <form method="POST" action="{{ route('admin.ki.settings.update') }}" class="stack">
                    @csrf
                    <div class="grid grid-2">
                        <div class="field @error('provider') has-error @enderror">
                            <label for="provider">Anbieter</label>
                            <select id="provider" name="provider">
                                <option value="fake" @selected(old('provider', $provider) === 'fake')>Platzhalter ohne externe Aufrufe</option>
                                <option value="anthropic" @selected(old('provider', $provider) === 'anthropic')>Anthropic</option>
                            </select>
                            <p class="hint">Ohne hinterlegten Schlüssel wird auch bei Anbieter "Anthropic" der Platzhalter verwendet.</p>
                            @error('provider')
                                <p class="error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="field @error('modell') has-error @enderror">
                            <label for="modell">Modell</label>
                            <select id="modell" name="modell">
                                @foreach ($modelle as $wert => $eintrag)
                                    <option value="{{ $wert }}" @selected(old('modell', $modell) === $wert)>
                                        {{ $eintrag['label'] }} ({{ number_format($eintrag['preis_input_je_million_usd'], 2, ',', '.') }} USD Eingabe / {{ number_format($eintrag['preis_output_je_million_usd'], 2, ',', '.') }} USD Ausgabe je Million Token)
                                    </option>
                                @endforeach
                            </select>
                            @error('modell')
                                <p class="error">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                    <div class="cluster">
                        <button type="submit" class="btn btn-primary">Einstellungen speichern</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-title">Verbrauch der letzten 30 Tage</div>
            <div class="card-body">
                <p class="hint">Schätzung nach Listenpreis in USD, keine abgerechneten Werte.</p>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Modell</th>
                                <th>Aufrufe</th>
                                <th>Eingabetoken</th>
                                <th>Ausgabetoken</th>
                                <th>Geschätzte Kosten (USD)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($nutzungProModell as $zeile)
                                <tr>
                                    <td>{{ $zeile['modell'] }}</td>
                                    <td>{{ $zeile['aufrufe'] }}</td>
                                    <td>{{ number_format($zeile['input_tokens'], 0, ',', '.') }}</td>
                                    <td>{{ number_format($zeile['output_tokens'], 0, ',', '.') }}</td>
                                    <td>{{ number_format($zeile['geschaetzte_kosten_usd'], 2, ',', '.') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5">
                                        <div class="empty-state">Noch kein Verbrauch in den letzten 30 Tagen.</div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-title">Verbrauch nach Zweck (letzte 30 Tage)</div>
            <div class="card-body">
                <p class="hint">Entwurf: neue Textvorschläge. Überarbeitung: kürzer, sachlicher, sprachlich.</p>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Zweck</th>
                                <th>Aufrufe</th>
                                <th>Eingabetoken</th>
                                <th>Ausgabetoken</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($nutzungProZweck as $zeile)
                                <tr>
                                    <td>{{ $zeile['label'] }}</td>
                                    <td>{{ $zeile['aufrufe'] }}</td>
                                    <td>{{ number_format($zeile['input_tokens'], 0, ',', '.') }}</td>
                                    <td>{{ number_format($zeile['output_tokens'], 0, ',', '.') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4">
                                        <div class="empty-state">Noch kein Verbrauch in den letzten 30 Tagen.</div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-title">Letzte Aufrufe</div>
            <div class="card-body">
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Zeitpunkt</th>
                                <th>Modell</th>
                                <th>Ergebnis</th>
                                <th>Eingabetoken</th>
                                <th>Ausgabetoken</th>
                                <th>Dauer</th>
                                <th>Objekt</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($letzteNutzungen as $eintrag)
                                <tr>
                                    <td>{{ $eintrag->created_at?->format('d.m.Y H:i:s') }}</td>
                                    <td>{{ $eintrag->modell }}</td>
                                    <td>
                                        @if ($eintrag->erfolgreich)
                                            <span class="badge badge-success">OK</span>
                                        @else
                                            <span class="badge badge-error">Fehler</span>
                                            <br><span class="hint">{{ $eintrag->fehler }}</span>
                                        @endif
                                    </td>
                                    <td>{{ number_format($eintrag->input_tokens, 0, ',', '.') }}</td>
                                    <td>{{ number_format($eintrag->output_tokens, 0, ',', '.') }}</td>
                                    <td>{{ $eintrag->dauer_ms !== null ? $eintrag->dauer_ms.' ms' : '-' }}</td>
                                    <td>{{ $eintrag->listing_id !== null ? '#'.$eintrag->listing_id : '-' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7">
                                        <div class="empty-state">Noch keine Aufrufe protokolliert.</div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
