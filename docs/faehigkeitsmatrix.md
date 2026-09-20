# Fähigkeitsmatrix FLOWFACT-Connector

Stand: 12.09.2026 (Welle 3). Masterprompt Abschnitt 22: Jede Funktion des Connectors ist hier mit ihrer
offiziellen Quelle, der bestätigten Schnittstelle, der erforderlichen Berechtigung und dem tatsächlichen Teststatus
aufgeführt. Die Matrix ist ehrlich: Bisher wurde keine Funktion gegen ein echtes FLOWFACT-Konto ausgeführt. Alle
Teststatus lauten "simuliert mit Http::fake" (Antwortformen aus [flowfact-api.md](flowfact-api.md), abgeleitet
aus dem SDK `@flowfact/api-services` 85.1.9) oder "nicht getestet". Sobald `php artisan flow:flowfact:smoke`
(lesend) und `--write` am Konto gelaufen sind, werden die Spalten Teststatus und Bekannte Einschränkung je Zeile
mit Datum und Protokollpfad (`storage/logs/flowfact-smoke-<datum>.md`) nachgetragen.

Legende Teststatus: simuliert mit Http::fake (automatisierter Test gegen gefälschte Antworten, kein echter
Aufruf), gegen FLOWFACT getestet (mit Datum und Protokoll), nicht getestet (weder simuliert noch real).

Offizielle Quelle: developers.flowfact.com ist aus der Entwicklungsumgebung nicht abrufbar (Masterprompt-Abgleich
Abschnitt 33). Ersatzquelle ist das SDK; die Zeilenangaben stehen in flowfact-api.md. "Berechtigung" nennt die
Rechte des API-Benutzers, soweit aus dem SDK ableitbar; die tatsächliche Rechteprüfung des Kontos ist offen
(flowfact-api.md Abschnitt 9, Punkt 21 und 32).

| Funktion | Offizielle Quelle | Bestätigte Schnittstelle (Methode und Pfad) | Erforderliche Berechtigung (soweit bekannt) | Teststatus | Bekannte Einschränkung |
| --- | --- | --- | --- | --- | --- |
| Authentifizierung: Token-Tausch | developers.flowfact.com/api "How to use the FLOWFACT-API", SDK APIClient.js:51-58 (flowfact-api.md 3.1, 3.4, Punkt 1 und 5) | `GET admin-token-service/public/adminUser/authenticate` mit Header `token: <Zugangsschlüssel>` | gültiger Zugangsschlüssel (API-Zugänge) | am echten Konto ausprobiert 21.09.2026 (siehe Punkt 1); Verbindungstest mit dem getauschten Cognito-Token am Konto der Hausverwaltung Müller GmbH noch ausstehend | `App\Flowfact\Client\CognitoTokenCache`, isoliert getestet mit Http::fake (CognitoTokenCacheTest); Zugangsschlüssel wird nie protokolliert |
| Authentifizierung: Aufrufe mit Cognito-Token | SDK: src/http/APIClient.js:51-55 (flowfact-api.md 3.1, 3.2) | Header `cognitoToken`, `Accept-Language: de`, optional `x-ff-company-id` bei jedem Aufruf | gültiges, getauschtes Cognito-Token | simuliert mit Http::fake (FlowfactClientTest, TokenLeakTest); Ob der Header extern für alle Services akzeptiert wird, am Konto noch zu bestätigen (Abschnitt 9, Punkt 2) | Cognito-Token wird nie protokolliert; ungetauschte Kopfzeilenformen bleiben als manuelle Diagnose erhalten (Adminbereich) |
| Aktueller Benutzer (Verbindungstest) | SDK: UsersV2Controller.js:9, :33 (flowfact-api.md 5.8, 10 Schritt 1) | `GET user-service/users/currentUser` mit `x-ff-version: 2` | lesender Zugriff des Tokens | simuliert mit Http::fake (SmokeCommandTest, Adminbereich Verbindungstest) | `companyId` und `type = API` erwartet, am Konto zu bestätigen |
| Schemata lesen | SDK: SchemaServiceV2.js:28-43, :109-111 (5.2) | `GET schema-service/v2/schemas?group=estates`, `GET schema-service/v2/schemas/{schema}?extensions=all` | lesender Zugriff | simuliert mit Http::fake (SchemaCommandTest, SmokeCommandTest) | Konkrete Schemanamen und Pflichtfelder des Kontos unbekannt (Punkt 8); Zuordnung über `flow:flowfact:schema` und Einstellungen |
| Objekt anlegen (Estate) | SDK: EntityService.js:65-76 (5.1, 4.3) | `POST entity-service/schemas/{schema}` mit `x-ff-version: 2`, Werteform `{ feld: { values: [...] } }` | Schreibrecht auf das Estate-Schema | simuliert mit Http::fake (ListingSyncServiceTest, Regression08, 12, 14) | Antwortform Entität oder nur ID (Punkt 7); Payload nur aus der Freigabeversion; Stellplatz ohne Code wird nicht angelegt |
| Objekt aktualisieren (Estate) | SDK: EntityService.js:264-270 (4.4 a, 5.1) | `PATCH entity-service/schemas/{schema}/entities/{id}` mit Werteform, `{ values: [] }` für gezielt geleerte Felder | Schreibrecht | simuliert mit Http::fake (ListingSyncServiceTest, Regression03, 04, 05, 12) | Löschsemantik leerer Wertelisten offen (Punkt 29); Konflikterkennung über `lastModifiedTimestamp` (Punkt 30, 31) |
| Suche nach Objektnummer (identifier) | SDK: SearchService.js:119-138, node-flowdsl (5.6, 7) | `POST search-service/schemas/{schema}?page=1&size=2&withCount=true` mit Flowdsl `HASFIELDWITHVALUE identifier EQUALS` | Leserecht auf den Suchindex | simuliert mit Http::fake (ListingSyncServiceTest, FlowdslTest) | Flowdsl-Form und Operator EQUALS am Konto zu bestätigen (Punkt 13, 14); Papierkorbeinträge (Punkt 15) |
| Vorsignierte Upload-URL | SDK: ItemsController.js:128-142 (5.3, 8 Schritt 2) | `GET multimedia-service/items/schemas/{schema}/entities/{id}/presigned-url?contentType=&fileName=&fileSize=` | Schreibrecht Multimedia | simuliert mit Http::fake (MediaSyncServiceTest, Regression06, 13) | SDK verwendet hart `house_purchase` im Pfad (Punkt 17); Binärupload per PUT an S3 nicht aus dem SDK belegt (Punkt 16) |
| Medium registrieren (Bild, Dokument) | SDK: ItemsController.js:149-167 (8 Schritt 4) | `POST multimedia-service/items/schemas/{schema}/entities/{id}` mit `contentType`, `fileName`, `fileSize`, `itemLink`, `title`, `albumAssignments` | Schreibrecht Multimedia | simuliert mit Http::fake (MediaSyncServiceTest, Regression04, 06, 08, 13) | Album- und Kategorienamen kontospezifisch (Punkt 18); Dokumentkategorie muss `DOCUMENT` erlauben (Punkt 35); Titeländerung per JSON-Patch (Punkt 29) |
| Medienreihenfolge und Titelbild | SDK: AlbumAssignmentController.js:64-80 (8 Schritt 5) | `PUT multimedia-service/assigned/schemas/{schema}/entities/{id}?albumName=&short=false` mit `assignments` und `sorting` | Schreibrecht Multimedia | simuliert mit Http::fake (MediaSyncServiceTest, Regression04) | Titelbild als `sorting = 0` ist Annahme (Punkt 19) |
| Medium löschen | SDK: ItemsController.js:170-182 (8, Löschen) | `DELETE multimedia-service/items/{id}` | Schreibrecht Multimedia | simuliert mit Http::fake (MediaSyncServiceTest, Regression04, 13) | keine bekannte |
| Portale des Kontos lesen | SDK: PortalController.js:11-30 (5.4, 6 Schritt 1) | `GET portal-management-service/portals?ignoreInactivePortals=true` | Leserecht Portalverwaltung | simuliert mit Http::fake (FlowfactPublishingServiceTest, SmokeCommandTest) | nur `authenticated = true` wird angeboten; Portalanmeldung ausschließlich in der FLOWFACT-Oberfläche (Punkt 25) |
| Portalveröffentlichung ONLINE | SDK: PublishController.js:12-29, Types 57-122 (5.4, 6 Schritt 5) | `POST portal-management-service/publish` mit `portalId`, `portalType`, `publishType MANUAL`, `entries[{ entityId, schema, targetStatus ONLINE, showAddress }]` | Veröffentlichungsrecht des API-Benutzers (ACP); fehlt es: Fall B | simuliert mit Http::fake (FlowfactPublishingServiceTest, Regression01, 08, 09, 10) | Nicht im Smoke-Test enthalten (keine reale Veröffentlichung eines Testobjekts); 401/403 und `portalsWithoutAccessRights` werden als `manuelle_freigabe_erforderlich` behandelt (Punkt 21, 32); leerer Körper bei asynchroner Verarbeitung (Punkt 22); `showAddress` bei "nur PLZ und Ort" (Punkt 33) |
| Portalveröffentlichung OFFLINE (Deaktivierung) | SDK: Types Zeile 38 `targetStatus OFFLINE` (6 Schritt 7) | `POST portal-management-service/publish` mit `targetStatus OFFLINE` | Veröffentlichungsrecht | simuliert mit Http::fake (FlowfactPublishingServiceTest, Regression01, 09, 11) | Nicht im Smoke-Test; Status `deaktivierung_angefordert` bis zur Bestätigung durch Rücklesen; 401/403 lässt die Anforderung mit Hinweis stehen |
| Publikationsstatus rücklesen | SDK: PortalEstateController.js:11-24, Types 147-159 (5.4, 6 Schritt 4) | `GET portal-management-service/estates/{id}/portals` (`portalId`, `onlineSince`, `lastUpdate`, `publishBlockedUntil`) | Leserecht Portalverwaltung | simuliert mit Http::fake (RefreshPortalStatusJobTest, Regression01, 10, 11) | Einzige Quelle für `aktiv` und `deaktivierung_bestaetigt`; Einheit von `onlineSince` und Bedeutung von `publishBlockedUntil` offen (Punkt 23); Verhalten nach OFFLINE (Punkt 34) |
| Objekt löschen (Estate) | SDK: EntityService.js:224-230 (5.1) | `DELETE entity-service/schemas/{schema}/entities/{id}` | Löschrecht | simuliert mit Http::fake (SmokeCommandTest, nur für das TEST-Objekt) | Der Connector löscht im Betrieb keine Objekte (Archivieren bleibt lokal); nur der Smoke-Test räumt sein Testobjekt auf |
| Papierkorb (Recovery) | SDK: EntityService.js:492-506 (5.1, 10 Schritt 17) | `GET entity-service/recovery/entities?page=1&size=&schema=` | Leserecht | simuliert mit Http::fake (SmokeCommandTest) | Nur Nachweis des Löschverhaltens im Smoke-Test; ob die Suche Papierkorbeinträge ausschließt, ist offen (Punkt 15) |

## Nicht gegen FLOWFACT getestete Ablaufteile

Die folgenden Abläufe bestehen aus den oben genannten Schnittstellen und sind ausschließlich mit `Http::fake`
simuliert:

- Freigabeversionen: Übertragung und Veröffentlichung aus der jüngsten `ListingRelease`, Schutz alter Jobs
  (Regression08, Regression09).
- Fall B: `manuelle_freigabe_erforderlich` bei 401/403 oder `portalsWithoutAccessRights`, späteres Rücklesen mit
  `onlineSince` setzt `aktiv` (Regression10).
- Deaktivierung je Portal: `deaktivierung_angefordert`, `deaktivierung_bestaetigt` (Regression11).
- Konflikterkennung über `_metadata.lastModifiedTimestamp` mit Einstellung `flowfact.konfliktverhalten`
  (Regression12).
- Löschsemantik mit Absicht: `{ values: [] }` nur für zuvor gesendete Felder (Regression05).
- Medien: Drehung und EXIF-Entfernung (ImageResizerTest), Dokumente in der Dokumentkategorie (Regression13).

## Nachtrag nach dem Smoke-Test

Vorgehen: `php artisan flow:flowfact:smoke` (lesend), danach mit Freigabe der Geschäftsführung `--write`. Je
Zeile der Matrix wird der Teststatus auf "gegen FLOWFACT getestet am TT.MM.JJJJ (Protokoll ...)" gesetzt oder die
Einschränkung ergänzt. Eine reale Portalveröffentlichung wird erst nach Klärung der Punkte 21 bis 25 und 32 bis 34
in flowfact-api.md Abschnitt 9 und nach Freigabe durch die Geschäftsführung ausgeführt.
