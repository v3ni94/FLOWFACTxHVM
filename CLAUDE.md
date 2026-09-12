# Müller FLOW, Projektregeln für Claude Code

Anwendung der Hausverwaltung Müller GmbH zur schnellen Erfassung und Veröffentlichung von Immobilien über
FLOWFACT. Ziel-Domain flowfact.muellerhv.de, Betrieb auf IONOS Webhosting mit PHP 8.3 und MariaDB.

## Verbindliche Dokumente (in dieser Reihenfolge lesen)

1. `docs/masterprompt-abgleich.md`: Abgleich mit dem Masterprompt, Auftrag der zweiten Iteration, Abschnitt B ergänzt den Datenvertrag.
2. `docs/datenvertrag.md`: Felder, Preislogik, Statusachsen, Berechtigungen.
3. `docs/architektur.md`: Stack, Architekturentscheidungen, Sicherheitsmodell, Betrieb.
4. `docs/connector.md` und `docs/flowfact-api.md`: FLOWFACT-Anbindung; Endpunkte nur aus flowfact-api.md verwenden, nie erfinden.
5. `docs/ui-klassen.md`: einzige erlaubte CSS-Klassen und Datenattribute; kein Inline-Style, kein Inline-Script (CSP).
6. `docs/pruefbericht-*.md`: Befunde der kritischen Prüfungen und ihre Behebung.

## Unverhandelbare Regeln

- Interne Daten (`listing_internals`) gelangen nie in Payload, Vorschau, Prompt oder Protokoll. Der Mapper liest nur `App\Domain\Listing\PublishableFields`.
- Entwürfe werden nie veröffentlicht. Veröffentlichung nur aus der Ansicht "Prüfen und veröffentlichen" mit Freigabeversion (`listing_releases`).
- Portalstatus "aktiv" wird nur nach Rücklesen aus FLOWFACT gesetzt. "In FLOWFACT gespeichert" ist nie "im Portal aktiv".
- Vor jeder Anlage in FLOWFACT wird nach der Objektnummer gesucht; die FLOWFACT-ID wird sofort gespeichert; Lease gegen parallele Läufe.
- Heizkosten werden nie doppelt gerechnet; die Warmmiete berechnet ausschließlich `RentCalculator`.
- Beträge sind Ganzzahlen in Cent. Zeitzone Europe/Berlin.
- Token und Schlüssel (FLOWFACT, KI) liegen verschlüsselt in `settings`, erscheinen nie in Logs, Ansichten oder Fehlermeldungen.
- Keine Gedankenstriche in deutschen Texten. Formelle Anrede "Sie".
- Nichts erfinden: keine Endpunkte, keine Rechtsangaben, keine Testergebnisse gegen das echte Konto (siehe `docs/faehigkeitsmatrix.md`).

## Technik

- PHP 8.3-kompatibel schreiben (lokal läuft 8.4; keine 8.4-Syntax). Composer-Plattform ist auf 8.3 festgelegt.
- Kein Frontend-Build: `public/css/flow.css`, `public/js/flow.js`. Blade-Komponenten unter `resources/views/components/flow`.
- Tests mit SQLite in-memory: `php artisan optimize:clear && php artisan test`; Codestil `vendor/bin/pint --test`. CI führt zusätzlich MariaDB-Migrationen vor und zurück aus.
- Assistent: `App\Http\Controllers\App\Steps\Schritt{n}Controller` implementieren `StepHandler` (show, store, autosave); Autosave löst nie eine Übertragung aus.
- Connector: `app/Flowfact`; alle API-Aufrufe über `FlowfactClient`, jeder Aufruf erzeugt einen `transfer_logs`-Eintrag.
- KI-Texte: `App\Services\Ai` mit dem offiziellen Anthropic-PHP-SDK; Fake-Umsetzungen sind der Vorlagenmodus ohne externe Aufrufe.

## Arbeitsweise mit Agenten

Höchstens zwei Subagenten gleichzeitig, getrennte Dateibereiche je Auftrag, Integration und Commit durch den
Leitagenten. Nach zwei erfolglosen Korrekturversuchen Strategie oder Modell wechseln. Keine Verbrauchswerte oder
Eurobeträge erfinden. Vor jedem Push die Testsuite ausführen, nach jedem Push das CI-Ergebnis prüfen.
