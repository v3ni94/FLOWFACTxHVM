@extends('layouts.app')

@section('title', 'FLOWFACT')

@section('content')
    <div class="page-header">
        <h1>FLOWFACT-Anbindung</h1>
    </div>

    <div class="stack">
        <div class="grid grid-2">
            <div class="card">
                <div class="card-title">API-Token</div>
                <div class="card-body stack">
                    @if ($tokenHinterlegt)
                        <div class="status-row">
                            <span class="badge badge-success">Hinterlegt</span>
                            <span>hinterlegt am {{ $tokenHinterlegtAt?->format('d.m.Y H:i') ?? 'unbekannt' }}</span>
                        </div>
                        <p class="hint">Der Token wird verschlüsselt gespeichert und aus Sicherheitsgründen nie angezeigt. Zum Austausch geben Sie einen neuen Token ein.</p>
                    @else
                        <div class="alert alert-warning" role="status">Es ist kein API-Token hinterlegt. Übertragungen und Veröffentlichungen sind nicht möglich.</div>
                    @endif

                    <form method="POST" action="{{ route('admin.flowfact.token.store') }}" class="stack">
                        @csrf
                        <div class="field @error('api_token') has-error @enderror">
                            <label for="api_token">{{ $tokenHinterlegt ? 'Neuen API-Token hinterlegen' : 'API-Token' }}</label>
                            <input type="password" id="api_token" name="api_token" autocomplete="off" required>
                            <p class="hint">Der Token wird nur gespeichert, nicht zurückgelesen.</p>
                            @error('api_token')
                                <p class="error">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="cluster">
                            <button type="submit" class="btn btn-primary">Token speichern</button>
                        </div>
                    </form>

                    @if ($tokenHinterlegt)
                        <form method="POST" action="{{ route('admin.flowfact.token.destroy') }}" data-confirm="Den API-Token wirklich entfernen? Übertragungen sind danach nicht mehr möglich.">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger btn-sm">Token entfernen</button>
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

                    <form method="POST" action="{{ route('admin.flowfact.test') }}">
                        @csrf
                        <button type="submit" class="btn btn-secondary" @disabled(! $tokenHinterlegt)>Verbindung testen</button>
                    </form>
                    <p class="hint">Ruft den aktuellen API-Benutzer ab (user-service). Es werden keine Daten geschrieben.</p>

                    <form method="POST" action="{{ route('admin.flowfact.diagnose') }}">
                        @csrf
                        <button type="submit" class="btn btn-secondary" @disabled(! $tokenHinterlegt)>Diagnose ausführen</button>
                    </form>
                    <p class="hint">Sechs lesende Aufrufe mit unterschiedlichen Kopfzeilen. Zeigt die Antwort von FLOWFACT gekürzt an, ohne Token.</p>

                    @if (session('diagnose'))
                        <div class="table-wrap">
                            <table class="table">
                                <thead>
                                    <tr><th>Aufruf</th><th>HTTP</th><th>Antwort</th></tr>
                                </thead>
                                <tbody>
                                    @foreach (session('diagnose') as $zeile)
                                        <tr>
                                            <td>{{ $zeile['beschreibung'] }}<br><small>{{ $zeile['aufruf'] }}</small></td>
                                            <td>
                                                @if ($zeile['status'] === null)
                                                    <span class="badge badge-neutral">keine Antwort</span>
                                                @elseif ($zeile['status'] < 300)
                                                    <span class="badge badge-success">{{ $zeile['status'] }}</span>
                                                @else
                                                    <span class="badge badge-error">{{ $zeile['status'] }}</span>
                                                @endif
                                            </td>
                                            <td><code>{{ $zeile['antwort'] }}</code></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-title">Konto und Schemata</div>
            <div class="card-body stack">
                <form method="POST" action="{{ route('admin.flowfact.settings.update') }}" class="stack">
                    @csrf
                    <div class="grid grid-3">
                        <div class="field @error('company_id') has-error @enderror">
                            <label for="company_id">Company-ID (optional)</label>
                            <input type="text" id="company_id" name="company_id" value="{{ old('company_id', $companyId) }}">
                            <p class="hint">Nur setzen, wenn die API den Header x-ff-company-id verlangt.</p>
                            @error('company_id')
                                <p class="error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="field @error('schema_miete') has-error @enderror">
                            <label for="schema_miete">Schema für Mietobjekte</label>
                            @if ($schemata !== [])
                                <select id="schema_miete" name="schema_miete">
                                    <option value="">Bitte wählen</option>
                                    @foreach ($schemata as $schema)
                                        <option value="{{ $schema['name'] }}" @selected(old('schema_miete', $schemaMiete) === $schema['name'])>{{ $schema['caption'] }} ({{ $schema['name'] }})</option>
                                    @endforeach
                                    @if ($schemaMiete !== null && ! in_array($schemaMiete, array_column($schemata, 'name'), true))
                                        <option value="{{ $schemaMiete }}" selected>{{ $schemaMiete }} (nicht in der Liste)</option>
                                    @endif
                                </select>
                            @else
                                <input type="text" id="schema_miete" name="schema_miete" value="{{ old('schema_miete', $schemaMiete) }}">
                            @endif
                            @error('schema_miete')
                                <p class="error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="field @error('schema_kauf') has-error @enderror">
                            <label for="schema_kauf">Schema für Kaufobjekte</label>
                            @if ($schemata !== [])
                                <select id="schema_kauf" name="schema_kauf">
                                    <option value="">Bitte wählen</option>
                                    @foreach ($schemata as $schema)
                                        <option value="{{ $schema['name'] }}" @selected(old('schema_kauf', $schemaKauf) === $schema['name'])>{{ $schema['caption'] }} ({{ $schema['name'] }})</option>
                                    @endforeach
                                    @if ($schemaKauf !== null && ! in_array($schemaKauf, array_column($schemata, 'name'), true))
                                        <option value="{{ $schemaKauf }}" selected>{{ $schemaKauf }} (nicht in der Liste)</option>
                                    @endif
                                </select>
                            @else
                                <input type="text" id="schema_kauf" name="schema_kauf" value="{{ old('schema_kauf', $schemaKauf) }}">
                            @endif
                            @error('schema_kauf')
                                <p class="error">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <p class="eyebrow">Übertragungsverhalten</p>
                    <div class="grid grid-2">
                        <div class="field @error('konfliktverhalten') has-error @enderror">
                            <label for="konfliktverhalten">Konfliktverhalten</label>
                            <select id="konfliktverhalten" name="konfliktverhalten">
                                <option value="{{ \App\Flowfact\Sync\ListingSyncService::KONFLIKT_ABBRECHEN }}" @selected(old('konfliktverhalten', $konfliktverhalten) === \App\Flowfact\Sync\ListingSyncService::KONFLIKT_ABBRECHEN)>Abbrechen (Standard): Übertragung endet mit Fehler, wenn das Objekt in FLOWFACT seit der letzten Übertragung geändert wurde</option>
                                <option value="{{ \App\Flowfact\Sync\ListingSyncService::KONFLIKT_UEBERSCHREIBEN }}" @selected(old('konfliktverhalten', $konfliktverhalten) === \App\Flowfact\Sync\ListingSyncService::KONFLIKT_UEBERSCHREIBEN)>Überschreiben: zugeordnete Felder werden mit Warnung überschrieben</option>
                            </select>
                            <p class="hint">Müller FLOW führt die zugeordneten Felder, FLOWFACT alles andere. Bei einem Konflikt wurde die Entität in FLOWFACT seit der letzten Übertragung geändert.</p>
                            @error('konfliktverhalten')
                                <p class="error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="field @error('leere_felder_loeschen') has-error @enderror">
                            <label for="leere_felder_loeschen">Geleerte Felder in FLOWFACT löschen</label>
                            <select id="leere_felder_loeschen" name="leere_felder_loeschen">
                                <option value="{{ \App\Http\Controllers\Admin\FlowfactSettingsController::LEERE_FELDER_AN }}" @selected(old('leere_felder_loeschen', $leereFelderLoeschen) === \App\Http\Controllers\Admin\FlowfactSettingsController::LEERE_FELDER_AN)>An (Standard): zuvor gesendete, jetzt leere Felder werden in FLOWFACT geleert</option>
                                <option value="{{ \App\Http\Controllers\Admin\FlowfactSettingsController::LEERE_FELDER_AUS }}" @selected(old('leere_felder_loeschen', $leereFelderLoeschen) === \App\Http\Controllers\Admin\FlowfactSettingsController::LEERE_FELDER_AUS)>Aus: leere Felder bleiben in FLOWFACT unverändert</option>
                            </select>
                            <p class="hint">Betrifft nur Felder, die Müller FLOW selbst gesendet hat. Ausschalten, falls das Konto die leere Werteliste anders auswertet.</p>
                            @error('leere_felder_loeschen')
                                <p class="error">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="cluster">
                        <button type="submit" class="btn btn-primary">Einstellungen speichern</button>
                    </div>
                </form>

                <form method="POST" action="{{ route('admin.flowfact.schemas.load') }}" class="cluster">
                    @csrf
                    <button type="submit" class="btn btn-secondary" @disabled(! $tokenHinterlegt)>Schemata laden</button>
                    <span class="hint">Liest die Estate-Schemata des Kontos samt Feldern (schema-service). Ohne geladene Schemata sind die Zielfelder unten als Freitext einzugeben.</span>
                </form>
            </div>
        </div>

        <form method="POST" action="{{ route('admin.flowfact.mapping.update') }}" class="stack">
            @csrf

            <div class="card">
                <div class="card-title">Feldzuordnung</div>
                <div class="card-body stack">
                    <p class="hint">Quelle ist ausschließlich die Positivliste der Inseratsfelder. Interne Felder können keine Zuordnung erhalten. Ein leeres Zielfeld schaltet das Feld ab.</p>

                    @if ($errors->has('felder.*'))
                        <div class="alert alert-error" role="alert">{{ $errors->first('felder.*') }}</div>
                    @endif

                    @foreach (['listing' => 'Objekt', 'price' => 'Preise', 'energy' => 'Energieausweis'] as $bereich => $bereichLabel)
                        <p class="eyebrow">{{ $bereichLabel }}</p>
                        <div class="table-wrap">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Eigenes Feld</th>
                                        <th>Standard</th>
                                        <th>Zielfeld in FLOWFACT</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($felder as $feld => $eintrag)
                                        @continue($eintrag['bereich'] !== $bereich)
                                        @php
                                            $alteFelder = old('felder');
                                            $ziel = is_array($alteFelder) && array_key_exists($feld, $alteFelder) ? (string) $alteFelder[$feld] : $eintrag['ziel'];
                                            $status = \App\Flowfact\Mapping\FieldMappingResolver::zuordnungsstatus($ziel, $schemaFelder);
                                        @endphp
                                        <tr>
                                            <td>{{ $eintrag['label'] }}<br><span class="hint">{{ $feld }}</span></td>
                                            <td>{{ $eintrag['standard'] ?? 'ohne bestätigten Standard' }}</td>
                                            <td>
                                                <div class="field">
                                                    <label for="feld-{{ $loop->parent->index }}-{{ $loop->index }}" class="visually-hidden">Zielfeld für {{ $eintrag['label'] }}</label>
                                                    @if ($schemaFelder !== [])
                                                        <select id="feld-{{ $loop->parent->index }}-{{ $loop->index }}" name="felder[{{ $feld }}]">
                                                            <option value="">nicht übertragen</option>
                                                            @if ($ziel !== null && $ziel !== '' && ! array_key_exists($ziel, $schemaFelder))
                                                                <option value="{{ $ziel }}" selected>{{ $ziel }} (nicht im Schema)</option>
                                                            @endif
                                                            @foreach ($schemaFelder as $name => $property)
                                                                <option value="{{ $name }}" @selected($ziel === $name)>{{ $name }} ({{ $property['type'] }}, {{ $property['caption'] }})</option>
                                                            @endforeach
                                                        </select>
                                                    @else
                                                        <input type="text" id="feld-{{ $loop->parent->index }}-{{ $loop->index }}" name="felder[{{ $feld }}]" value="{{ $ziel }}">
                                                    @endif
                                                </div>
                                            </td>
                                            <td>
                                                @if ($status === \App\Flowfact\Mapping\FieldMappingResolver::STATUS_ZUGEORDNET)
                                                    <span class="badge badge-success">{{ $status }}</span>
                                                @elseif ($status === \App\Flowfact\Mapping\FieldMappingResolver::STATUS_FEHLT)
                                                    <span class="badge badge-neutral">{{ $status }}</span>
                                                @else
                                                    <span class="badge badge-error">{{ $status }}</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="card">
                <div class="card-title">Codezuordnung</div>
                <div class="card-body stack">
                    <p class="hint">FLOWFACT-Codes je Auswahlwert. Nicht bestätigte Codes sind markiert und am Konto zu prüfen (flow:flowfact:schema). Ein leerer Code lässt den Wert bei der Übertragung aus und erzeugt eine Warnung.</p>

                    @if ($errors->has('codes.*'))
                        <div class="alert alert-error" role="alert">{{ $errors->first('codes.*') }}</div>
                    @endif

                    <div class="grid grid-2">
                        @foreach ($codes as $gruppe => $werte)
                            <div class="card card-canvas">
                                <div class="card-title">{{ \App\Flowfact\Mapping\FieldCatalog::gruppenLabel($gruppe) }}</div>
                                <div class="card-body">
                                    <div class="table-wrap">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>Wert</th>
                                                    <th>Code</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($werte as $wert => $eintrag)
                                                    <tr>
                                                        <td>{{ $eintrag['label'] }}</td>
                                                        <td>
                                                            <div class="field">
                                                                <label for="code-{{ $loop->parent->index }}-{{ $loop->index }}" class="visually-hidden">Code für {{ $eintrag['label'] }}</label>
                                                                <input type="text" id="code-{{ $loop->parent->index }}-{{ $loop->index }}" name="codes[{{ $gruppe }}.{{ $wert }}]" value="{{ is_array(old('codes')) && array_key_exists($gruppe.'.'.$wert, old('codes')) ? old('codes')[$gruppe.'.'.$wert] : $eintrag['code'] }}">
                                                            </div>
                                                        </td>
                                                        <td>
                                                            @if ($eintrag['code'] === null)
                                                                <span class="badge badge-warning">offen</span>
                                                            @elseif ($eintrag['bestaetigt'] && $eintrag['code'] === $eintrag['standard'])
                                                                <span class="badge badge-success">bestätigt</span>
                                                            @else
                                                                <span class="badge badge-warning">zu verifizieren</span>
                                                            @endif
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="cluster">
                        <button type="submit" class="btn btn-primary">Zuordnung speichern</button>
                    </div>
                </div>
            </div>
        </form>

        <div class="card">
            <div class="card-title">Übertragungsprotokoll (letzte 30 Aufrufe)</div>
            <div class="card-body">
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Zeitpunkt</th>
                                <th>Aktion</th>
                                <th>HTTP</th>
                                <th>Ergebnis</th>
                                <th>Dauer</th>
                                <th>Objekt</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($logs as $log)
                                <tr>
                                    <td>{{ $log->created_at?->format('d.m.Y H:i:s') }}</td>
                                    <td>{{ $log->aktion }}<br><span class="hint">{{ $log->zusammenfassung }}</span></td>
                                    <td>{{ $log->http_status ?? '-' }}</td>
                                    <td>
                                        @if ($log->erfolgreich)
                                            <span class="badge badge-success">OK</span>
                                        @else
                                            <span class="badge badge-error">Fehler</span>
                                        @endif
                                    </td>
                                    <td>{{ $log->dauer_ms !== null ? $log->dauer_ms.' ms' : '-' }}</td>
                                    <td>{{ $log->listing_id !== null ? '#'.$log->listing_id : '-' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6">
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
