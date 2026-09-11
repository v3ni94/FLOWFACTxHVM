# Müller FLOW

Erfassungs- und Veröffentlichungsoberfläche vor FLOWFACT für die Hausverwaltung Müller GmbH.
Mitarbeiter erfassen ein Miet- oder Kaufobjekt in acht Schritten, laden Bilder hoch, übernehmen einen
Textvorschlag und veröffentlichen das Objekt über die FLOWFACT-API auf den gewählten Portalen. Die Anwendung
zeigt jederzeit getrennt, ob ein Objekt nur an FLOWFACT übertragen oder tatsächlich im Portal aktiv ist.

Ziel-Domain: flowfact.muellerhv.de. Betrieb auf IONOS Webhosting, PHP 8.3, MariaDB.

## Dokumente

| Dokument | Inhalt |
| --- | --- |
| [docs/datenvertrag.md](docs/datenvertrag.md) | Felder, Preislogik mit Heizkostenregeln, drei Statusachsen, Erfassungsschritte, Berechtigungen |
| [docs/architektur.md](docs/architektur.md) | Stack, Architekturentscheidungen, Sicherheitsmodell, Betrieb, Teststrategie |
| [docs/connector.md](docs/connector.md) | Entwurf des FLOWFACT-Connectors: Idempotenz, Feldzuordnung, Veröffentlichung, Statusprüfung |
| [docs/flowfact-api.md](docs/flowfact-api.md) | Aus dem offiziellen FLOWFACT-SDK belegte API-Endpunkte mit Quellenangaben, offene Punkte, Smoke-Test |
| [docs/ui-klassen.md](docs/ui-klassen.md) | Klassenvertrag für alle Ansichten (kein Frontend-Build) |
| [docs/betrieb/installation.md](docs/betrieb/installation.md) | Installation und Betrieb auf IONOS Webhosting |
| [docs/offene-punkte.md](docs/offene-punkte.md) | Klärungspunkte für den Betreiber und getroffene Annahmen |
| docs/pruefbericht-*.md | Ergebnisse der kritischen Prüfung vor Übergabe |

## Technischer Stack

PHP 8.3 oder neuer, Laravel 12, MariaDB 10.11 oder neuer (Tests mit SQLite in-memory), Blade mit eigener CSS-Datei
und Vanilla-JS ohne Build-Schritt, Datenbankwarteschlange mit Cron-Aufruf, offizielles Anthropic-PHP-SDK für
Textvorschläge, PHPUnit 11, Laravel Pint.

## Lokale Entwicklung

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite          # DB_CONNECTION=sqlite, DB_DATABASE=<absoluter Pfad> in .env
php artisan migrate --seed              # Seeder setzt nur fehlende Einstellungen (Firmendaten)
php artisan flow:user:create admin@example.invalid --name="Admin" --role=admin
php artisan serve
```

Prüfungen vor jedem Commit:

```bash
composer check        # Pint und Testsuite
php artisan test
vendor/bin/pint --test
```

## Konsolenbefehle

| Befehl | Zweck |
| --- | --- |
| `flow:install` | Idempotente Inbetriebnahme: Prüfungen, Migrationen, Caches. Nach jedem Deployment einmal ausführen |
| `flow:check-config` | Betriebsprüfung: Datenbank, Speicher, Mail, Warteschlange, Scheduler-Lebenszeichen, FLOWFACT-Verbindung |
| `flow:user:create {email}` | Benutzer anlegen (Rolle admin oder mitarbeiter) |
| `flow:flowfact:smoke` | Smoke-Test gegen das FLOWFACT-Konto, nur lesend; mit `--write` Anlage eines TEST-Objekts ohne Portalveröffentlichung |
| `flow:flowfact:schema` | Estate-Schemata des Kontos lesen, mit `--schema=` Felder und fehlende Zuordnungen anzeigen |
| `flow:portal-status` | Portalstatus veröffentlichter Objekte nachlesen (läuft alle fünf Minuten über den Scheduler) |

Scheduler: ein Cronjob pro Minute `php artisan schedule:run`, alternativ `POST /wartung/schedule` mit Header
`X-Cron-Token` (siehe Installationsanleitung).

## Bereiche der Anwendung

| Pfad | Bereich | Rolle |
| --- | --- | --- |
| `/login`, `/two-factor/challenge` | Anmeldung, optionaler Zweitfaktor | alle |
| `/app/dashboard` | Kennzahlen, letzte Objekte, Hinweis bei ausgefallener Hintergrundverarbeitung | alle |
| `/app/objekte` | Objektübersicht, Erfassungsassistent in acht Schritten, Detailseite mit Statusachsen und Protokoll | alle |
| `/account` | Passwort, Zweitfaktor aktivieren oder deaktivieren, Wiederherstellungscodes | alle |
| `/admin/users` | Benutzerverwaltung | admin |
| `/admin/flowfact` | API-Token, Verbindungstest, Schemaauswahl, Feld- und Codezuordnung, Protokoll | admin |
| `/admin/ki` | KI-Anbieter, Modell, Schlüssel, Verbrauch | admin |

## Verbindliche Regeln

1. Interne Daten (Tabelle `listing_internals`) werden nie übertragen. Der Mapper arbeitet mit einer Positivliste, ein Test mit Markerwerten belegt es.
2. Entwürfe können nicht veröffentlicht werden. Veröffentlichung ist eine bestätigte Benutzeraktion mit Portalauswahl.
3. Wiederholte Übertragungen erzeugen keine Dubletten: Suche nach der Objektnummer vor jeder Anlage, sofortiges Speichern der FLOWFACT-ID, Lease gegen parallele Läufe.
4. "An FLOWFACT übertragen" ist nie "im Portal aktiv". Der Portalstatus aktiv wird nur nach Rücklesen aus FLOWFACT gesetzt.
5. Heizkosten werden nie doppelt gerechnet. Die Warmmiete wird ausschließlich serverseitig berechnet.
6. Der FLOWFACT-Token und der KI-Schlüssel werden verschlüsselt gespeichert und erscheinen in keinem Protokoll, keiner Ansicht und keiner Fehlermeldung.

## Vor dem Livegang

Die Punkte in [docs/offene-punkte.md](docs/offene-punkte.md) und der Smoke-Test in
[docs/flowfact-api.md](docs/flowfact-api.md) Abschnitt 10 sind mit dem echten FLOWFACT-Konto abzuarbeiten.
Eine Veröffentlichung auf Portalen erfolgt erst nach Freigabe durch die Geschäftsführung.
