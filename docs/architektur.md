# Architektur Müller FLOW

Stand: 11.09.2026. Anwendung unter flowfact.muellerhv.de für die Hausverwaltung Müller GmbH.
Fachlicher Datenvertrag: [datenvertrag.md](datenvertrag.md). FLOWFACT-API: [flowfact-api.md](flowfact-api.md). Connector-Entwurf: [connector.md](connector.md).
Offene Punkte und Annahmen: [offene-punkte.md](offene-punkte.md).

## 1. Produkt in drei Sätzen

Müller FLOW ist die schnelle Erfassungs- und Veröffentlichungsoberfläche vor FLOWFACT. Mitarbeiter erfassen
ein Miet- oder Kaufobjekt in acht kurzen Schritten, laden Bilder hoch, übernehmen einen Textvorschlag und
veröffentlichen das Objekt über die FLOWFACT-API auf den gewählten Portalen. Die Anwendung zeigt jederzeit
ehrlich, ob ein Objekt nur an FLOWFACT übertragen oder tatsächlich im Portal aktiv ist.

## 2. Technologiestack

| Baustein | Entscheidung | Begründung |
| --- | --- | --- |
| Laufzeit | PHP 8.3 oder neuer, Laravel 12 | Gleicher Stack wie Smart Abrechnen (HVM), erprobt auf IONOS Webhosting |
| Datenbank | MariaDB 10.11 oder neuer, Connection-Typ mariadb; Tests mit SQLite in-memory | Vorgabe des Hostings |
| Oberfläche | Blade, serverseitig gerendert, eigene CSS-Datei mit den HVM-Designtokens, kleine Vanilla-JS-Module | Kein Node-Build nötig, siehe ADR-002 |
| Warteschlange | Treiber database, Cron-getriebene kurze Läufe | Kein dauerhafter Prozess auf IONOS, siehe ADR-006 |
| HTTP-Client | Laravel HTTP-Client (Guzzle) mit Http::fake in Tests | Contract-Tests ohne echte API |
| KI-Texte | REST-Anbindung an die Anthropic Messages API, Modell konfigurierbar | Siehe ADR-009 |
| Qualität | PHPUnit 11, Laravel Pint; PHPStan Level 6 (Larastan) als Ziel vorgesehen, im aktuellen Stand noch nicht installiert | Wie Schwesterprojekt |
| Deployment | SFTP-Release-Layout mit atomarem Umschalten und Smoke-Test, GitHub Actions | Übernommen aus Smart Abrechnen |
| Zeitzone | Europe/Berlin | Siehe ADR-011 |

## 3. Architekturentscheidungen

### ADR-001: Laravel 12 in einer Instanz, Betriebsprofil IONOS Webhosting

Eine Laravel-Anwendung, Bereiche über Routenpräfixe: `/` Anmeldung, `/app` Anwendung, `/admin` Verwaltung,
`/wartung` geschützte Wartungsendpunkte für Hosting ohne Shellzugang. Keine Subdomain-Trennung, kein Docker
in Produktion, keine Node-Laufzeit auf dem Server. Composer-Abhängigkeiten werden vor dem Upload installiert.

### ADR-002: Kein Frontend-Build, eigene CSS mit HVM-Designtokens

Smart Abrechnen nutzt Tailwind 4 mit Vite. Für Müller FLOW wird bewusst darauf verzichtet: eine handgeschriebene
CSS-Datei `public/css/flow.css` trägt dieselben Tokens (Farben, Radien, Typografie) als CSS-Variablen, dazu
ein kleiner Komponentensatz (Button, Karte, Badge, Alert, Feld, Stepper, Tabelle, Seitenkopf).
Begründung: Das häufigste Betriebsfehlerbild des Schwesterprojekts ist ein fehlendes `public/build`. Ohne Build
entfällt dieses Risiko, CI und Deployment werden einfacher, und die Konsistenz zur HVM-Optik bleibt über die
Tokens gewahrt. JavaScript beschränkt sich auf Formularhilfen (Warmmiete live, Bildsortierung, Bestätigungsdialoge)
ohne Framework.

### ADR-003: Interne Daten sind auf Tabellenebene getrennt, der Mapper arbeitet mit Positivliste

Interne Felder liegen in `listing_internals`, nie in `listings`. Der Klasse `FlowfactPayloadMapper` steht nur
eine explizite Positivliste von Inseratsfeldern zur Verfügung. Ein Unit-Test befüllt alle internen Felder mit
Markerwerten und prüft, dass keiner im erzeugten Payload, in Protokolleinträgen oder in Bildtiteln erscheint.

### ADR-004: Zwei Statusachsen und ein Portalstatus je Portal

Bearbeitungsstatus, Übertragungsstatus und Portalstatus sind drei getrennte Felder mit eigenen Zustandsautomaten
(Datenvertrag Abschnitt 4). Der Portalstatus "aktiv" wird ausschließlich aus einer gelesenen Statusantwort von
FLOWFACT gesetzt. Nach jeder Veröffentlichungsanforderung plant die Anwendung eine Statusprüfung als Job; bis
zur Bestätigung zeigt die Oberfläche "Bestätigung ausstehend".

### ADR-005: Idempotente Übertragung über eine externe Referenz

Jedes Objekt trägt eine UUID, die als externe Referenz in der FLOWFACT-Entität hinterlegt wird. Der Ablauf einer
Übertragung ist immer: Lease setzen, Suche in FLOWFACT nach der UUID, bei Treffer verknüpfen und aktualisieren,
sonst anlegen, Entity-ID speichern, Inhalt-Hash speichern, Lease freigeben. Ein Abbruch nach dem Anlegebefehl
führt beim nächsten Lauf über die Suche zur bestehenden Entität. Parallele Läufe für dasselbe Objekt werden
durch die Lease (`sperre_bis`) verhindert. Bilder werden über ihre SHA-256-Prüfsumme und die gespeicherte
Multimedia-ID nur einmal hochgeladen.

### ADR-006: Datenbankgestützte Queue mit Cron-getriebenen kurzen Läufen

Übernommen aus Smart Abrechnen, aber mit dem Laravel-Standardworker statt eines eigenen Slice-Runners. Ein einziger
Cronjob ruft jede Minute `php artisan schedule:run` auf. Der Scheduler startet `queue:work database
--stop-when-empty --max-time=45 --tries=3 --backoff=30` ohne Überlappung, setzt ein Lebenszeichen im Cache
(`scheduler.last_run`, ausgewertet von `flow:check-config`) und startet `flow:portal-status` für die
Statusprüfung veröffentlichter Objekte. Jeder Job ist idempotent und wiederanlaufbar. Ist nur ein Fünf-Minuten-Takt
verfügbar, zeigt die Oberfläche die tatsächliche Verzögerung an. Übertragungen werden zusätzlich synchron aus der
Oberfläche gestartet, wenn der Benutzer es wünscht (Schaltfläche "Jetzt übertragen"), mit hartem Zeitlimit; bei
Zeitüberschreitung übernimmt die Queue. Für Hosting ohne Shellzugang existiert `POST /wartung/schedule` mit
eigenem Token.

### ADR-007: Zwei-Faktor-Authentifizierung ist optional

Die abhängigkeitsfreie TOTP-Implementierung aus Smart Abrechnen (RFC 6238, Base32, Wiederherstellungscodes,
Tests gegen die offiziellen Testvektoren) wird übernommen. Anders als dort gibt es keine erzwingende
Middleware. Jeder Benutzer kann 2FA in seinem Konto aktivieren und deaktivieren. Der Login prüft nur,
ob `two_factor_confirmed_at` gesetzt ist, und verlangt dann den Code. Ein Admin kann den Zweitfaktor eines
Benutzers zurücksetzen, aber nicht erzwingen. Benutzer ohne 2FA werden nie ausgesperrt.

### ADR-008: API-Token statt Benutzeranmeldung gegenüber FLOWFACT

Die FLOWFACT-API akzeptiert den Header `x-ff-api-token` (Quelle: offizielles SDK, siehe flowfact-api.md).
Müller FLOW speichert genau einen Token verschlüsselt in der Tabelle `settings` und sendet ihn nie an den
Browser, nie in Logs und nie in das Übertragungsprotokoll. Der Cognito-Anmeldeweg mit Benutzerpasswort wird
nicht implementiert. Ob der Tarif FLOWFACT Essential die Erzeugung eines API-Tokens erlaubt, ist am Konto zu
prüfen (offene-punkte.md). Ist das nicht der Fall, wird der Cognito-Weg als getrennter Authentifizierungsadapter
nachgerüstet, ohne dass sich der Rest des Connectors ändert.

### ADR-009: KI-Texte über eine Provider-Abstraktion, Modell konfigurierbar, Texte werden gespeichert

Objektbeschreibungen entstehen auf ausdrückliche Anforderung des Benutzers aus den strukturierten Feldern des
Objekts. Der Anbieter wird über das offizielle Anthropic-PHP-SDK angesprochen (Entscheidung nach der aktuellen
API-Referenz, um Abweichungen in Parametern und Antwortformen zu vermeiden), das Modell steht in der Konfiguration
und ist im Adminbereich änderbar (Standard claude-opus-5, alternativ claude-sonnet-5 oder claude-haiku-4-5). Jeder Vorschlag wird mit Modellkennung in `listing_texts` gespeichert; der Benutzer
übernimmt ihn ausdrücklich. Interne Felder gehen nie in den Prompt. In Tests ist der Provider immer `fake`.
Es gibt kein automatisches Regenerieren und keine Mehragenten-Orchestrierung im Produktbetrieb.

### ADR-010: Beträge in Cent, Preisberechnung serverseitig und deterministisch

Alle Beträge sind Ganzzahlen in Cent. Die Warmmiete wird bei jedem Speichern durch `RentCalculator` berechnet
und nie aus dem Formular übernommen. Die Regeln und Prüffälle stehen im Datenvertrag Abschnitt 3.

### ADR-011: Anwendungszeitzone Europe/Berlin

Von Anfang an `APP_TIMEZONE=Europe/Berlin`. Betroffen sind Objektnummern (Jahr), Verfügbarkeitsdaten und
Protokollzeitstempel.

### ADR-012: Medien außerhalb des Webroots, Auslieferung über signierte Routen

Uploads werden serverseitig auf Typ, Größe und Bildinhalt geprüft (finfo, getimagesize), unter neuem Namen
außerhalb von `public` gespeichert und über zeitlich begrenzt signierte Routen (30 Minuten) ausgeliefert, die
zusätzlich eine aktive Anmeldung voraussetzen. Die Signatur ist nicht an die Sitzung gebunden; da alle aktiven
Benutzer alle Objekte sehen dürfen (Datenvertrag Abschnitt 6), ist das fachlich ausreichend. Maximal
15 MB je Datei, 40 Dateien je Objekt. Bilder werden für die Vorschau mit GD verkleinert; die Übertragung an
FLOWFACT erfolgt mit dem Original in Portalgröße (längste Seite höchstens 2000 Pixel, JPEG Qualität 85).

### ADR-013: Ein Mandant

Die Anwendung arbeitet für genau eine anbietende Gesellschaft, die Hausverwaltung Müller GmbH. Firmendaten
für das Inserat stehen in `settings` (Schlüssel `firma.*`). Mandantenfähigkeit ist bewusst nicht vorgesehen.

### ADR-014: Übertragungsprotokoll als Pflichtbestandteil jeder API-Interaktion

Jeder Aufruf gegen FLOWFACT erzeugt einen Eintrag in `transfer_logs` über einen Guzzle-Middleware-Hook, nicht
durch manuelle Aufrufe im Fachcode. Token und interne Felder werden vor dem Speichern entfernt, Nutzdaten
werden auf 4 KB gekürzt.

## 4. Ordnerstruktur

```
app/
  Console/Commands/        flow:install, flow:check-config, flow:user:create, flow:portal-status, flow:flowfact:smoke, flow:flowfact:schema
  Domain/
    Listing/               Enums, RentCalculator, PriceStructure, CompletenessCheck, Befund, ListingStatusMachine,
                           ReleaseService, EnergyRequirements, Merkmale, ListingChangeTracker, ListingContentHasher,
                           ListingSnapshot, PublishableFields (Positivliste der Inseratsfelder)
    Numbering/             Vergabe der Objektnummer (Format MF-JJJJ-NNNN)
    Security/              TimeBasedOneTimePassword, Base32, RecoveryCodeGenerator (übernommen)
    Settings/              SettingsRepository (settings-Tabelle, verschlüsselte Werte)
  Flowfact/
    Client/                FlowfactClient, SettingsTokenProvider, TransferLogMiddleware, TokenScrubber, Exceptions
    Mapping/                FlowfactPayloadMapper (Positivliste), FieldCatalog, FieldMappingResolver
    Query/                 Flowdsl (Suchsyntax nach Objektnummer)
    Services/               SchemaService, EntityService, SearchService, MultimediaService, PortalService,
                           UserService, CompanyService
    Sync/                  PublishingService-Schnittstelle, FlowfactPublishingService, NullPublishingService,
                           ListingSyncService, MediaSyncService, PortalStatusTransition, ReleaseGuard, SyncLease,
                           Sync/Jobs/ (TransferListingJob, RefreshPortalStatusJob)
  Http/
    Controllers/Auth/      LoginController, InvitationController, ForgotPasswordController, ResetPasswordController,
                           TwoFactorChallengeController
    Controllers/Account/   AccountController, PasswordController, TwoFactorController
    Controllers/App/       DashboardController, ListingController (Übersicht, Anlage, Historie, Duplizieren),
                           Steps/ (Schritt1Controller bis Schritt8Controller, je StepHandler), ReviewController
                           (Prüfen und veröffentlichen), ListingMediaController, ListingTextController,
                           MediaStreamController
    Controllers/Admin/     UserController, FlowfactSettingsController, FlowfactMappingController,
                           KiSettingsController, DefaultsController
    Middleware/            SecurityHeaders, ForceHttps, EnsureRole
    Requests/               Formulare je Wizardschritt und je Adminformular
  Models/                  User, UserInvitation, Listing, ListingPrice, ListingEnergy, ListingInternal,
                           ListingMedia, ListingText, ListingChange, ListingFlowfactLink, ListingRelease,
                           ListingPortalPublication, ListingPortalStatusLog, TransferLog, KiUsage, Setting
  Policies/                ListingPolicy, UserPolicy
  Services/Ai/             TextGenerator/TextReviser-Schnittstellen, AnthropicTextGenerator, AnthropicTextReviser,
                           FakeTextGenerator, FakeTextReviser, PromptBuilder, HiddenAddressGuard, KiAnbieterResolver
  Services/Media/          MediaUploadService (Prüfung, Speicherung, Drehen, Verkleinerung)
database/migrations/
resources/views/           layouts, components/flow/*, auth, account, app (dashboard, listings/schritte, pruefen,
                           historie), admin
public/css/flow.css        Designtokens und Komponenten (kein Build)
public/js/flow.js          Formularhilfen ohne Framework
routes/web.php
tests/Unit, tests/Feature
docs/
bin/deploy-sftp.php        übernommen
.github/workflows/         ci.yml, deploy.yml
```

## 5. Sicherheitsmodell

| Bedrohung | Maßnahme |
| --- | --- |
| Abfluss des FLOWFACT-Tokens | Verschlüsselt gespeichert, nur serverseitig entschlüsselt, Anzeige nur "hinterlegt am", Protokollfilter |
| Interne Daten im Inserat | Tabellentrennung, Positivlisten-Mapper, Test mit Markerwerten (ADR-003) |
| Unbeabsichtigte Veröffentlichung | Nur aus Status bereit, Portalauswahl mit Bestätigungsdialog, Policy prüft Rolle, kein Veröffentlichen per GET |
| Dubletten in FLOWFACT | Externe Referenz, Suche vor Anlage, Lease (ADR-005) |
| Kontoübernahme | Argon2id-Hash, Ratenbegrenzung Login 5 je Minute je IP und Konto, optional 2FA, Sitzungsrotation, Secure-Cookies |
| Schadhafte Uploads | Typprüfung über Inhalt, Neubenennung, Speicherung außerhalb Webroot, keine Ausführung, Größenlimit |
| CSRF und Clickjacking | Laravel-CSRF, Sicherheitsheader global (CSP, frame-ancestors none, HSTS) |
| Wartungsendpunkte | GET oder POST mit eigenem Token je Endpunkt (Kopfzeile oder ?token=), Aktionen idempotent, Ratenbegrenzung |
| Falsche Client-IP hinter IONOS-Proxy | TRUSTED_PROXIES konfigurierbar, Standard für IONOS Webhosting `*` |

## 6. Betrieb auf IONOS Webhosting

Übernommen aus Smart Abrechnen (docs/betrieb/installation.md dort). Kurzfassung:

1. Release-Layout unter dem Deployziel: `shared/.env`, `shared/storage`, `releases/<name>`, `current`.
2. Document Root auf `current/public`. Ist das nicht möglich, greift die Wurzel-`.htaccess` mit Sperren vor Umschreibung.
3. Kein `Options`-Befehl in `.htaccess`.
4. Ein Cronjob `php current/artisan schedule:run` jede Minute, alternativ URL-Cronjob auf `/wartung/schedule` mit Token.
5. Nach jedem Deployment einmal `php current/artisan flow:install` (Migrationen, Caches, Prüfungen), idempotent.
6. `php current/artisan flow:check-config` prüft Datenbank, Speicher, Mail, Scheduler-Lebenszeichen und, falls Token hinterlegt, die FLOWFACT-Verbindung mit einem lesenden Aufruf.

Details: [betrieb/installation.md](betrieb/installation.md).

## 7. Teststrategie

| Ebene | Inhalt |
| --- | --- |
| Unit | RentCalculator (Prüffälle A bis F), CompletenessCheck, Statusautomaten, Mapper-Positivliste, TOTP gegen RFC-Testvektoren, Payload-Filter des Protokolls |
| Feature | Login mit und ohne 2FA, Rollen, Wizard-Schritte, Upload, Veröffentlichung nur aus bereit, Admin-Einstellungen |
| Contract | FlowfactClient gegen Http::fake mit aufgezeichneten Antwortformen: Anlegen, Suche, Aktualisieren, Bildupload, Veröffentlichen, Statusabfrage, Fehler 401, 409, 500, Zeitüberschreitung |
| Idempotenz | Zweifacher Lauf derselben Übertragung erzeugt genau einen Anlegeaufruf; Abbruch nach Anlegen führt beim Wiederholen zur Suche |
| Statische Analyse | Pint; PHPStan Level 6 vorgesehen, siehe Stack-Tabelle |

Tests laufen lokal und in CI gegen SQLite in-memory. CI führt die Migrationen zusätzlich gegen MariaDB 10.11
und 11.4 aus, vorwärts und rückwärts.

## 8. Umsetzungsphasen und Modelleinsatz (Ist-Stand 11.09.2026)

| Phase | Inhalt | Modell | Ergebnis |
| --- | --- | --- | --- |
| 0 | Recherche FLOWFACT-SDK und Konventionen des Schwesterprojekts, Architektur, Datenvertrag | Sonnet (Konventionen), Fable (SDK-Dokument, Verifikation, Architektur) | docs/flowfact-api.md mit 246 geprüften Aussagen |
| 1 | Anmeldung, optionale 2FA, Rollen, Benutzerverwaltung; Layout, Sicherheitsheader, Konsole, Wartung, Deployment | zwei Sonnet-Agenten parallel | 87 Tests |
| 2 | Datenmodell, Preislogik, Vollständigkeit, Statusautomaten, Nummernkreis, Einstellungen | Sonnet | 171 Tests |
| 3 | Erfassungsassistent, Medien, Texte, Dashboard (Sonnet) parallel zum FLOWFACT-Connector mit Adminbereich und Smoke-Test (Fable) | Sonnet und Fable | 354 Tests |
| 4 | KI-Texte mit dem Anthropic-SDK und Adminbereich (Sonnet) parallel zur kritischen Gesamtprüfung (Fable, nur lesend) | Sonnet und Fable | 390 Tests, Prüfbericht mit 19 Befunden |
| 5 | Behebung der Befunde: Connector, Sync, Jobs (Fable) parallel zu Assistent, Medien, Sitzung (Sonnet), Regressionstests aus den Nachweisen des Prüfers | Fable und Sonnet | 439 Tests |
| Welle 1 | Datenmodell und Domäne nach Masterprompt-Abgleich B.2, B.4, B.5, B.6 erweitert; Benutzerrollen, Rechte, Einladungen, Passwort-Reset | Sonnet und Fable | 559 Tests |
| Welle 2 | Neuer Erfassungsassistent mit acht Schritten und Autosave; Prüfen und veröffentlichen, Dashboard, Objektliste, Historie, Duplizieren | Sonnet und Fable | 625 Tests |
| Welle 3 | Connector: Freigabeversionen, Fall B, Deaktivierung je Portal, Job-Schutz, Konflikterkennung; KI-Überarbeitungen, Vorlagenmodus, Adressprüfung, Medien drehen und Kategorien | Sonnet und Fable | 713 Tests |
| Welle 4 | Dokumente (CLAUDE.md, Benutzer- und Adminanleitung, Backup und Restore, Fähigkeitsmatrix, Abnahmeprotokoll, Datenflüsse); kritische Prüfung und Behebung | Sonnet und Fable | 713 Tests (reine Dokumentationsarbeit, keine neuen Tests durch diesen Auftrag) |

Höchstens zwei Entwicklungsagenten gleichzeitig, getrennte Dateibereiche je Auftrag, Integration, Commits und
Stichprobenprüfung durch den Leitagenten. Ein erster Versuch, die SDK-Extraktion mit Sonnet als verschachteltes
JSON zu erzwingen, scheiterte an der Ausgabeformatierung und wurde durch ein Markdown-Dokument mit Verifikationsrunde
ersetzt.
