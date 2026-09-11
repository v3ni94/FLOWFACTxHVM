# FLOWFACT-Connector, Entwurf

Stand: 11.09.2026. Verbindliche Vorgabe für die Umsetzung in `app/Flowfact`. Grundlage sind die bestätigten
API-Erkenntnisse in [flowfact-api.md](flowfact-api.md) und die Statusachsen in [datenvertrag.md](datenvertrag.md)
Abschnitt 4. Was am echten Konto noch zu prüfen ist, steht in flowfact-api.md Abschnitt 9; der Connector ist so
gebaut, dass diese Punkte ohne Codeänderung über Einstellungen nachjustiert werden können.

## 1. Komponenten

```
app/Flowfact/
  Client/
    FlowfactClient.php          HTTP-Zugriff je Service, Header, Zeitlimits, Fehlerklassen, Protokollierung
    TokenProvider.php           Interface: token(): ?string, companyId(): ?string
    SettingsTokenProvider.php   liest flowfact.api_token (Secret) und flowfact.company_id aus SettingsRepository
    TransferLogRecorder.php     schreibt je Aufruf einen TransferLog-Eintrag, bereinigt Token und kürzt Nutzdaten
    Exceptions/                 FlowfactException, AuthenticationException, NotFoundException,
                                ValidationException, RateLimitException, ServerException, TransportException
  Query/
    Flowdsl.php                 erzeugt das bestätigte Flowdsl-JSON (HASFIELDWITHVALUE mit EQUALS, ENTITYID, fetch)
  Services/
    EntityService.php           create, get, patch, delete (entity-service, x-ff-version 2 bei create und Suche)
    SchemaService.php           estateSchemas(), schema(name) (schema-service v2)
    SearchService.php           findByField(index, field, value, size) (search-service)
    MultimediaService.php       albums, presignedUrl, uploadBinary, registerItem, items, setAssignments, deleteItem
    PortalService.php           portals, estatePortals, publish, estateSettings, deleteEstateLink
    UserService.php             currentUser (x-ff-version 2)
    CompanyService.php          company(id)
  Mapping/
    FieldCatalog.php            Standardzuordnung eigener Felder auf FLOWFACT-Feldnamen und Enum-Codes
    FieldMappingResolver.php    verbindet FieldCatalog mit der Einstellung flowfact.feldzuordnung (Überschreibung)
    FlowfactPayloadMapper.php   Listing -> Werteform, ausschließlich aus PublishableFields (Positivliste)
  Sync/
    SyncLease.php               Lease auf listing_flowfact_links.sperre_bis und sperre_token, verhindert parallele Läufe
    ListingSyncService.php      Ablauf Übertragung (Abschnitt 3), Ergebnisobjekt SyncResult
    MediaSyncService.php        Bildupload je Medium, Reihenfolge, Titel, Löschen entfernter und ausgeblendeter Bilder
    PublishingService.php       Interface für die Oberfläche: publish, withdraw, refreshStatus, portals
    FlowfactPublishingService.php  Umsetzung des Interfaces
    NullPublishingService.php   Rückgabe "nicht konfiguriert", solange kein Token hinterlegt ist
    Jobs/TransferListingJob.php, Jobs/RefreshPortalStatusJob.php
  Console/
    FlowfactSmokeCommand.php    flow:flowfact:smoke, Reihenfolge aus flowfact-api.md Abschnitt 10
    FlowfactSchemaCommand.php   flow:flowfact:schema, zeigt Kontoschemata und fehlende Zuordnungen
    PortalStatusCommand.php     flow:portal-status, Statusprüfung veröffentlichter Objekte (Scheduler alle 5 Minuten)
```

Der Container bindet `PublishingService` auf `FlowfactPublishingService`, wenn ein Token hinterlegt ist, sonst
auf `NullPublishingService`. Die Oberfläche spricht nur das Interface an.

## 2. HTTP-Client

| Regel | Umsetzung |
| --- | --- |
| Basis-URL | `config('flowfact.base_url')` plus `/<service-name>` plus Pfad |
| Header | `x-ff-api-token`, `Accept-Language: de`, `Accept: application/json`; `x-ff-version` nur wo dokumentiert; `x-ff-company-id` nur wenn gesetzt |
| Zeitlimit | `config('flowfact.timeout_seconds')`, Standard 20 s; Bildupload 60 s |
| Wiederholung im Client | Nur lesende GET-Aufrufe einmal bei Zeitüberschreitung oder 5xx. Schreibende Aufrufe nie automatisch wiederholen; Wiederholung erfolgt auf Job-Ebene über den idempotenten Ablauf in Abschnitt 3 |
| Fehlerklassen | 401 und 403 AuthenticationException, 404 NotFoundException, 400 und 422 ValidationException mit Antwortkörper, 429 RateLimitException mit Retry-After, 5xx ServerException, Verbindungsfehler TransportException |
| Protokoll | Jeder Aufruf erzeugt genau einen TransferLog-Eintrag: aktion (Service und Pfadmuster ohne IDs), Methode, Status, Dauer, gekürzte Nutzdaten (4 KB), Listing-Kontext, Benutzer. Der Token wird vor dem Speichern entfernt, auch aus Fehlermeldungen |
| Antwort | JSON dekodiert; leerer 2xx-Körper wird als `null` zurückgegeben und ist kein Fehler |

## 3. Ablauf einer Übertragung (ListingSyncService)

Voraussetzung: `listings.status` ist `bereit`, `veroeffentlicht` oder `zurueckgezogen`. Entwürfe werden abgelehnt.
Zusätzlich muss das Objekt in jedem Status die Vollständigkeitsprüfung (`CompletenessCheck`) bestehen, sonst endet
der Lauf mit `fehlgeschlagen` und der Liste der fehlenden Felder (Prüfbericht 2026-09-11, Befund 3).

1. Lease setzen (`sperre_bis = now + 3 Minuten`, `sperre_token` = Zufallswert). Ist eine Lease aktiv, endet der Lauf
   mit `SyncResult::busy()`. Freigabe und Verlängerung sind bedingte UPDATEs auf das eigene Token; ein Lauf, der
   seine Lease überschritten hat, kann die Lease eines Nachfolgers nicht löschen (Befund 7). Während des
   Medienabgleichs wird die Lease nach jedem übertragenen Bild verlängert (Herzschlag).
2. `sync_status = uebertragung_laeuft`.
3. Schema bestimmen: `flowfact.schema_miete` oder `flowfact.schema_kauf` aus den Einstellungen. Fehlt es, endet der
   Lauf mit einem klaren Konfigurationsfehler (kein Anlegen mit geratenem Schema). Ist bereits eine Entität bekannt
   (`flowfact_entity_id` gesetzt), gilt das im Link gespeicherte `flowfact_schema`. Weicht das aus der aktuellen
   Vermarktungsart abgeleitete Schema davon ab, endet der Lauf mit "Die Vermarktungsart wurde nach der Übertragung
   geändert. Bitte das Objekt in FLOWFACT manuell prüfen oder ein neues Objekt anlegen." Es wird nichts angelegt
   (Befund 3).
4. Entität finden:
   a) Ist `flowfact_entity_id` gesetzt: `GET entity-service/schemas/{schema}/entities/{id}`. Bei 404 wird die ID verworfen und mit b) fortgesetzt.
   b) Sonst Suche: `POST search-service/schemas/{schema}` mit Flowdsl `HASFIELDWITHVALUE identifier EQUALS objektnummer`, Größe 2. Treffer werden zusätzlich exakt auf Gleichheit des Feldwerts geprüft. Ein Treffer: ID übernehmen. Mehr als ein Treffer: `sync_status = fehlgeschlagen` mit Meldung "Mehrere Objekte mit dieser Nummer in FLOWFACT, bitte manuell klären", kein Anlegen.
5. Payload erzeugen (Abschnitt 4). Hash der Nutzdaten mit `ListingContentHasher`.
6. Anlegen oder Aktualisieren:
   a) Keine Entität: `POST entity-service/schemas/{schema}` mit `x-ff-version: 2`. Antwort kann Entität oder nur ID sein; beides wird verarbeitet. ID sofort speichern, bevor irgendetwas anderes passiert.
   b) Entität vorhanden: `PATCH entity-service/schemas/{schema}/entities/{id}` mit den Feldern. Unverändertem Hash entsprechend darf der PATCH übersprungen werden, wenn `uebertragener_inhalt_hash` gleich ist und `--force` nicht gesetzt ist. Lokal geleerte, zugeordnete Felder werden als `{ "values": [] }` gesendet (Abschnitt 4, Befund 5).
7. Medien abgleichen (MediaSyncService), Befunde 4 und 6:
   a) Vorgemerkte Löschungen (`listing_media_deletions`) und Medien, die aus dem Inserat genommen wurden (`im_inserat = false`) oder Dokumente sind, aber noch eine `flowfact_multimedia_id` tragen: `DELETE /items/{id}`, lokale ID leeren.
   b) Für jedes Medium mit `im_inserat = true` (Bild, Grundriss) ohne `flowfact_multimedia_id`: Dateiname deterministisch `<listing uuid>-<media id>-<erste 12 Hex der SHA-256>.<ext>`. Einmal je Lauf `GET /items/entities/{id}?contentCategory=IMAGE` lesen; ein Item mit passendem Dateinamen wird übernommen (ID und Titel gespeichert) statt erneut hochgeladen. Sonst Presigned-URL holen, Binärdatei per PUT mit `Content-Type` hochladen, Item registrieren (mit `title`), ID und `flowfact_titel` speichern, Lease verlängern.
   c) Weicht `listing_media.titel` von `flowfact_titel` ab: `PATCH /items/{id}` mit JSON-Patch (`replace /title`, bei leerem Titel `remove /title`), danach `flowfact_titel` nachführen.
   d) Reihenfolge über `PUT /assigned/...` setzen, Titelbild an Position 0: nach jedem Upload, nach jeder Löschung und immer, wenn sich der Inhalts-Hash geändert hat (Reihenfolge und Titelbild sind Teil des Hashes).
8. Abschluss: `uebertragener_inhalt_hash`, `letzte_uebertragung_at`, `sync_status = uebertragen`, Lease freigeben.
9. Fehler: Bei AuthenticationException `fehlgeschlagen` mit Meldung "Token ungültig oder Rechte fehlen", keine automatische Wiederholung. Bei RateLimitException Job mit Verzögerung neu einreihen. Bei Server- oder Transportfehler bis zu drei Versuche mit Backoff 30, 120, 300 Sekunden; danach `fehlgeschlagen`. In jedem Fehlerfall Lease freigeben und `letzter_fehler` setzen (ohne Token, gekürzt).

Zeitüberschreitung nach einem `POST` zum Anlegen: Die Antwort ist unbekannt, die Entität kann existieren. Beim
nächsten Lauf greift Schritt 4b und findet sie über die Objektnummer. Ein zweites Anlegen ist damit
ausgeschlossen, solange die Suche funktioniert. Funktioniert die Suche nicht (Fehler statt leerem Ergebnis), wird
nicht angelegt, sondern der Lauf endet als fehlgeschlagen.

Der synchrone Weg aus der Oberfläche ("Jetzt übertragen") führt denselben Service aus, mit Zeitlimit 25 Sekunden für
den Gesamtlauf; Bilduploads, die darüber hinausgehen, laufen als Job weiter. Der Job-Pfad (`TransferListingJob`)
arbeitet mit einem Zeitlimit von 150 Sekunden unterhalb der Lease von 3 Minuten und reiht den Rest ebenfalls erneut
ein (Befund 7).

## 4. Feldzuordnung

Quelle jeder Zuordnung ist ausschließlich `PublishableFields` (Positivliste). Der Mapper erhält das Listing und
liefert die Werteform `{ feld: { values: [wert] } }`. Ein Feld ohne Zuordnung wird ausgelassen und in
`SyncResult::warnungen` gemeldet ("Keine FLOWFACT-Zuordnung für Nebenkosten"), damit die Oberfläche es anzeigen kann.

Geleerte Felder (Prüfbericht 2026-09-11, Befund 5): Zugeordnete Felder, deren lokaler Wert leer ist, liefert der
Mapper in `MappedPayload::leereFelder`. Beim Anlegen werden sie ausgelassen. Beim PATCH sendet der Sync sie als
`{ "values": [] }`, damit FLOWFACT den alten Wert löscht (Baujahr, Zustand, Stellplatz, Kaution und so weiter).
Die Einstellung `flowfact.leere_felder_loeschen` (Standard `true`) schaltet das ab, falls das Konto die leere
Werteliste anders interpretiert; die genaue Serversemantik ist am Konto zu verifizieren (flowfact-api.md
Abschnitt 9, Punkt 29).

Heizkosten (Befund 13): Ist `heizkosten_in_nebenkosten_enthalten` gesetzt, wird `heizkosten_cent` auch bei
vorhandener Zuordnung nicht separat übertragen; der Mapper meldet die Warnung "Heizkosten sind in den Nebenkosten
enthalten und werden nicht separat übertragen" und führt das Zielfeld unter den zu löschenden Feldern.

### 4.1 Standardzuordnung (FieldCatalog), aus dem SDK bestätigte Feldnamen

| Eigenes Feld | FLOWFACT-Feld | Wert |
| --- | --- | --- |
| titel | headline | Text |
| objektnummer | identifier | Text |
| objektart, vermarktungsart | estatetype | Code, siehe 4.3 |
| status (immer) | status | `active` |
| strasse, hausnummer, plz, ort, land | addresses | `{ type: "private", street: "Straße Nr", zipcode, city, country: "Deutschland" }`; bei `adresse_im_inserat_anzeigen = false` wird das Portal-Flag `showAddress = false` gesetzt, die Adresse selbst wird trotzdem übertragen |
| kaufpreis_cent | purchaseprice | Euro als Zahl mit zwei Dezimalstellen |
| kaltmiete_cent | rent | Euro als Zahl |
| wohnflaeche_qm | livingarea | Zahl |
| grundstuecksflaeche_qm | plotarea | Zahl |
| nutzflaeche_qm | commercialarea | Zahl |
| zimmer | rooms | Zahl |
| schlafzimmer | numberbedrooms | Zahl |
| badezimmer | numberbathrooms | Zahl |
| etage | floor | Zahl |
| etagen_gesamt | no_of_floors | Zahl |
| baujahr | yearofconstruction | Zahl |
| zustand | condition | Code, siehe 4.3 |
| energie.effizienzklasse | energyefficienceclass | Code 01 bis 09 |
| stellplatz_typ | parking | Code, siehe 4.3 |
| ausstattung.aufzug | elevator | bool |
| ausstattung.balkon | balconyavailable | bool |
| ausstattung.keller | cellar | bool |
| ausstattung.gaeste_wc | guesttoilet | bool |
| ausstattung.barrierefrei | barrierfree | bool |
| beschreibung_objekt, beschreibung_ausstattung, beschreibung_lage, beschreibung_sonstiges | ohne bestätigten Standard | müssen über flowfact.feldzuordnung gesetzt werden, Vorschlag nach Schemaabfrage |
| nebenkosten_cent, heizkosten_cent, heizkosten_in_nebenkosten_enthalten, warmmiete_cent, kaution_cent, stellplatz_miete_cent, hausgeld_cent, stellplatz_kaufpreis_cent, provision_text, verfuegbar_ab, heizungsart, energietraeger, energie.* außer Klasse | ohne bestätigten Standard | wie oben |

Felder aus `listing_internals` haben keinen Eintrag und können keinen bekommen: der Mapper akzeptiert nur Schlüssel
aus `PublishableFields`. Ein Test befüllt alle internen Felder mit Markerwerten und prüft Payload und Protokoll.

### 4.2 Überschreibung über Einstellungen

`flowfact.feldzuordnung` ist ein JSON-Objekt `{ "eigenes_feld": "flowfact_feld" | null }`. `null` schaltet ein Feld
ab. Der Befehl `flow:flowfact:schema` liest `GET schema-service/v2/schemas?group=estates` und
`GET schema-service/v2/schemas/{schema}?extensions=all`, listet alle Properties des Kontoschemas mit Typ und
Caption und markiert für jedes eigene Feld: zugeordnet, Zuordnung fehlt, Zielfeld im Schema nicht vorhanden. Der
Adminbereich zeigt dieselbe Tabelle und erlaubt die Pflege der Zuordnung mit Auswahl aus den Schemafeldern.

### 4.3 Codes

| Eigenes Feld | Wert | FLOWFACT-Code | Status |
| --- | --- | --- | --- |
| objektart wohnung | | `01ETAG` | bestätigt (Etagenwohnung als Standard, änderbar) |
| objektart haus | | `02EFH` | bestätigt |
| objektart gewerbe | | `06B` | bestätigt (Bürofläche als Standard, änderbar) |
| objektart grundstueck | | `03BE` | bestätigt |
| objektart stellplatz | | offen | im SDK-Auszug kein Code, über Schemaabfrage ermitteln |
| zustand erstbezug | | `01` | bestätigt |
| zustand neuwertig | | `03` | bestätigt |
| zustand modernisiert | | `05` | bestätigt |
| zustand gepflegt | | `07` | bestätigt |
| zustand renovierungsbeduerftig | | `08` | bestätigt |
| zustand projektiert | | `10` | bestätigt |
| zustand saniert | | offen, Vorschlag `06` (renoviert) | zu verifizieren |
| effizienzklasse A+ bis H | | `01` bis `09` | bestätigt |
| stellplatz garage | | `2` | bestätigt |
| stellplatz aussenstellplatz | | `3` | bestätigt |
| stellplatz carport | | `4` | bestätigt |
| stellplatz duplex | | `5` | bestätigt |
| stellplatz tiefgarage | | `7` | bestätigt |
| stellplatz keiner | | `1` | bestätigt |

Die Codetabellen liegen in `FieldCatalog` und sind über `flowfact.codezuordnung` (JSON) überschreibbar.

## 5. Veröffentlichung und Portalstatus

1. Die Oberfläche ruft `PublishingService::portals()` und zeigt nur Portale mit `authenticated = true`.
2. `publish(Listing, portalIds, User)` prüft: Status `bereit` oder `zurueckgezogen` oder `veroeffentlicht`, Policy,
   Vollständigkeit, erfolgreiche Übertragung (`sync_status = uebertragen`, sonst wird zuerst übertragen). Bleibt
   die Bildübertragung nach dem synchronen Zeitlimit offen (`sync_status = geaendert_seit_uebertragung`, Job
   eingereiht), wird nicht veröffentlicht; die Meldung bittet um erneutes Veröffentlichen nach Abschluss der
   Hintergrundübertragung (Befund 15).
   Für jedes Portal: Eintrag in `listing_portal_publications` mit `angefordert`, dann `POST /publish` mit
   `targetStatus ONLINE` und `showAddress` aus dem Listing. Ein leerer 2xx-Körper bleibt `angefordert`. Ein Körper
   mit `errors` für dieses Objekt setzt `fehler` mit der übersetzten Meldung, `successFullyTransfered` setzt
   `aktiv` erst nach Rücklesen, `successfullyScheduled` bleibt `angefordert`. Ein Portal zählt erst dann als
   angefordert, wenn `POST /publish` ohne Ausnahme beantwortet und nicht als Fehler ausgewertet wurde. Wurde kein
   Portal erfolgreich angefordert, bleibt `listings.status` unverändert (Befund 1).
3. `RefreshPortalStatusJob` liest `GET /estates/{id}/portals`. Ein Eintrag mit dem Portal und `onlineSince` setzt
   `aktiv` und `bestaetigt_at`. Fehlt der Eintrag bei einer Anforderung, die älter als 30 Minuten ist, wird der
   Status `unbekannt` mit Hinweis "Status nicht ermittelbar, in FLOWFACT prüfen". Der Job läuft alle fünf Minuten
   für Objekte mit `angefordert` und stündlich für `aktiv`.
4. `withdraw` sendet `targetStatus OFFLINE` (auch für Publikationen in `fehler` und `unbekannt`), setzt
   `zurueckgezogen` nach Rücklesen; bis dahin bleibt der alte Status mit Hinweis "Rückzug angefordert". Eine reine
   Fehlerpublikation, für die FLOWFACT beim Rücklesen keinen Eintrag kennt, wird lokal auf `nicht_veroeffentlicht`
   zurückgesetzt.
5. `listings.status` wird nur über `ListingStatusMachine` geändert: nach erster erfolgreicher Anforderung
   `veroeffentlicht`. Beim Rücklesen gilt (Befund 1): Solange eine Publikation `angefordert` oder `aktiv` ist,
   bleibt `veroeffentlicht`. Sind alle Publikationen `fehler`, `unbekannt`, `zurueckgezogen` oder
   `nicht_veroeffentlicht`, wechselt das Objekt nach `zurueckgezogen`, wenn mindestens ein Portal nach bestätigter
   Aktivität zurückgezogen wurde, sonst nach `bereit`. Der Übergang `veroeffentlicht -> bereit` ist in der
   Statusmaschine nur ohne offene Publikation zulässig. `flow:portal-status` prüft deshalb auch veröffentlichte
   Objekte ohne offene Publikation, damit Altbestände zurückgeführt werden.

## 6. Smoke-Test am echten Konto

`php artisan flow:flowfact:smoke` führt die Schritte 1 bis 5 aus flowfact-api.md Abschnitt 10 aus (nur lesend) und
druckt je Schritt Erwartung, Ergebnis und Statuscode. Mit `--write` folgen die Schritte 6 bis 17 mit einem Objekt,
dessen `identifier` mit `TEST-` beginnt, und einem kleinen erzeugten Testbild. Ein `POST /publish` ist im Befehl
nicht enthalten und kann nicht über Optionen aktiviert werden. Der Befehl schreibt ein Protokoll nach
`storage/logs/flowfact-smoke-<datum>.md`, das als Nachweis für die offenen Punkte in flowfact-api.md dient.

## 7. Einstellungen

| Schlüssel | Art | Bedeutung |
| --- | --- | --- |
| flowfact.api_token | Secret | API-Token, nur schreibbar, Anzeige "hinterlegt am" |
| flowfact.company_id | Text | optional |
| flowfact.schema_miete, flowfact.schema_kauf | Text | konkrete Schemanamen des Kontos, Auswahl aus `flow:flowfact:schema` |
| flowfact.feldzuordnung | JSON | Überschreibung der Feldnamen |
| flowfact.codezuordnung | JSON | Überschreibung der Codes |
| flowfact.leere_felder_loeschen | Bool | Standard `true`: geleerte Felder beim PATCH als leere Werteliste senden (Abschnitt 4) |
| flowfact.token_hinterlegt_at | Datum | Anzeige im Adminbereich |
| flowfact.verbindung_geprueft_at, flowfact.verbindung_ergebnis | Text | letzter Verbindungstest über `currentUser` |

## 8. Tests

| Test | Inhalt |
| --- | --- |
| FlowfactClientTest | Header, Basis-URL, Fehlerklassen je Statuscode, leerer Körper, Token nie im Protokoll, Kürzung |
| FlowdslTest | JSON entspricht dem bestätigten Format |
| FlowfactPayloadMapperTest | Werteform, Codes, Adresse, Positivliste, Markerwerte interner Felder erscheinen nirgends, fehlende Zuordnung erzeugt Warnung |
| ListingSyncServiceTest | Anlegen, Aktualisieren, Suche findet vorhandene Entität, Zeitüberschreitung nach Anlegen führt beim zweiten Lauf zur Suche und nicht zum zweiten Anlegen, Mehrfachtreffer stoppt, Lease blockiert zweiten Lauf, Entwurf wird abgelehnt, Auth-Fehler ohne Wiederholung |
| MediaSyncServiceTest | Upload-Kette, kein zweiter Upload bei vorhandener ID, Reihenfolge, Löschen |
| FlowfactPublishingServiceTest | Nur bereit oder zurückgezogen, Portalauswahl, leerer Körper bleibt angefordert, errors setzt fehler, Rücklesen setzt aktiv, kein aktiv ohne Rücklesen |
| RefreshPortalStatusJobTest | aktiv nach Rücklesen, unbekannt nach 30 Minuten ohne Eintrag |
| SmokeCommandTest | Befehl ohne Token bricht sauber ab, `--write` erzeugt Identifier mit TEST-, kein publish-Aufruf möglich |
| Regression/* | Regressionstests zum Prüfbericht 2026-09-11: Befund 1 (Status bleibt bereit, Rückweg aus veröffentlicht), 3 (Schemawechsel, Vollständigkeit), 4 (Medienänderungen), 5 (Löschsemantik im PATCH), 6 (idempotenter Bildupload), 7 (Lease mit Token, Herzschlag) |
| Console/SchedulerTest, Console/CheckConfigCommandTest | Queue-Worker im Vordergrund, Warteschlangenprüfung meldet gestaute Jobs (Befund 8) |

Alle Tests laufen gegen `Http::fake()` mit Antwortformen aus flowfact-api.md; kein Test ruft die echte API auf.
