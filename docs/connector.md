# FLOWFACT-Connector, Entwurf

Stand: 12.09.2026 (Welle 3: Freigabeversionen, Fall B, Deaktivierung, Job-Schutz, Konflikterkennung). Verbindliche
Vorgabe für die Umsetzung in `app/Flowfact`. Grundlage sind die bestätigten
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
    FlowfactPayloadMapper.php   ListingSnapshot (Freigabeversion) -> Werteform, ausschließlich aus PublishableFields;
                                map(Listing) bildet die Momentaufnahme aus dem Live-Stand (Vorschau, Tests)
  Sync/
    SyncLease.php               Lease auf listing_flowfact_links.sperre_bis und sperre_token, verhindert parallele Läufe
    ListingSyncService.php      Ablauf Übertragung (Abschnitt 3) auf Basis der jüngsten ListingRelease, Ergebnisobjekt SyncResult
    MediaSyncService.php        Medienabgleich aus medien_json der Freigabe: Bilder (mit Drehung), Dokumente, Reihenfolge, Titel, Löschen
    PortalStatusTransition.php  einzige Stelle für Statuswechsel einer Portalveröffentlichung, schreibt listing_portal_status_logs
    ReleaseGuard.php            Schutz vor veralteten Freigaben in Jobs (jüngste Version, archiviert, Deaktivierung nach Freigabe)
    PublishingService.php       Interface für die Oberfläche: publish, withdraw, refreshStatus, portals
    FlowfactPublishingService.php  Umsetzung des Interfaces (Fall A, B, C; Deaktivierungsstatus)
    NullPublishingService.php   Rückgabe "nicht konfiguriert", solange kein Token hinterlegt ist
    Jobs/TransferListingJob.php, Jobs/RefreshPortalStatusJob.php  tragen die release_id
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
Es muss eine Freigabeversion (`listing_releases`, Masterprompt-Abgleich B.6) existieren; ohne Freigabe endet der
Lauf mit "Keine Freigabe vorhanden. Bitte im Schritt Prüfen und veröffentlichen freigeben." Zusätzlich muss das
Objekt die Vollständigkeitsprüfung (`CompletenessCheck`) bestehen, sonst endet der Lauf mit `fehlgeschlagen` und
der Liste der fehlenden Felder (Prüfbericht 2026-09-11, Befund 3).

Grundsatz (Masterprompt Abschnitt 19, 23): Übertragung und Veröffentlichung arbeiten ausschließlich mit der
jüngsten Freigabeversion (`ReleaseService::latest`), nie mit dem Live-Stand. Der Payload entsteht mit
`FlowfactPayloadMapper::mapSnapshot()` aus `payload_json`, die Medien aus `medien_json` (nur freigegebene, mit
Reihenfolge, Titel und Drehung), die Portale aus `portale_json`. Änderungen nach der Freigabe erreichen FLOWFACT erst
mit der nächsten Freigabe; die Oberfläche zeigt sie als "Unveröffentlichte Änderungen".

Führendes System (Masterprompt Abschnitt 23): Müller FLOW führt für die zugeordneten Felder (Abschnitt 4),
FLOWFACT für alles andere (nicht zugeordnete Felder, Kontakte, Aktivitäten, Portalkonfiguration). Der Connector
schreibt nur zugeordnete Felder und löscht nur, was er selbst gesendet hat.

0. Payload aus der Freigabe bilden (ohne API-Aufruf). Ist der Payload gesperrt (Objektart Stellplatz/Garage ohne
   FLOWFACT-Code), endet der Lauf mit "Objektart Stellplatz/Garage wird lokal erfasst; die Übertragung ist erst nach
   Ermittlung des FLOWFACT-Codes möglich" (Abschnitt 4.3, Einstellung `flowfact.codezuordnung`).
1. Lease setzen (`sperre_bis = now + 3 Minuten`, `sperre_token` = Zufallswert). Ist eine Lease aktiv, endet der Lauf
   mit `SyncResult::busy()`. Freigabe und Verlängerung sind bedingte UPDATEs auf das eigene Token; ein Lauf, der
   seine Lease überschritten hat, kann die Lease eines Nachfolgers nicht löschen (Befund 7). Während des
   Medienabgleichs wird die Lease nach jedem übertragenen Medium verlängert (Herzschlag).
2. `sync_status = uebertragung_laeuft`.
3. Schema bestimmen: `flowfact.schema_miete` oder `flowfact.schema_kauf` aus den Einstellungen, anhand der
   Vermarktungsart der Freigabeversion. Fehlt es, endet der Lauf mit einem klaren Konfigurationsfehler (kein Anlegen
   mit geratenem Schema). Ist bereits eine Entität bekannt (`flowfact_entity_id` gesetzt), gilt das im Link
   gespeicherte `flowfact_schema`. Weicht das abgeleitete Schema davon ab, endet der Lauf mit "Die Vermarktungsart
   wurde nach der Übertragung geändert. Bitte das Objekt in FLOWFACT manuell prüfen oder ein neues Objekt anlegen."
   Es wird nichts angelegt (Befund 3).
4. Entität finden:
   a) Ist `flowfact_entity_id` gesetzt: `GET entity-service/schemas/{schema}/entities/{id}`. Bei 404 wird die ID verworfen und mit b) fortgesetzt. Die Antwort liefert `_metadata.lastModifiedTimestamp` für Schritt 6.
   b) Sonst Suche: `POST search-service/schemas/{schema}` mit Flowdsl `HASFIELDWITHVALUE identifier EQUALS objektnummer`, Größe 2. Treffer werden zusätzlich exakt auf Gleichheit des Feldwerts geprüft. Ein Treffer: ID übernehmen. Mehr als ein Treffer: `sync_status = fehlgeschlagen` mit Meldung "Mehrere Objekte mit dieser Nummer in FLOWFACT, bitte manuell klären", kein Anlegen.
5. Inhaltsänderung bestimmen: `uebertragener_inhalt_hash` des Links gegen `inhalt_hash` der Freigabeversion.
6. Anlegen oder Aktualisieren:
   a) Keine Entität: `POST entity-service/schemas/{schema}` mit `x-ff-version: 2`. Antwort kann Entität oder nur ID sein; beides wird verarbeitet. ID sofort speichern, bevor irgendetwas anderes passiert. `_metadata.lastModifiedTimestamp` (ersatzweise `_metadata.timestamp`) der Antwort wird als `flowfact_last_modified` gespeichert; fehlt beides, entsteht eine Warnung.
   b) Entität vorhanden und Inhalt geändert (oder `--force`): Konflikterkennung. Weicht der in Schritt 4 gelesene Änderungszeitpunkt vom gespeicherten `flowfact_last_modified` ab, wurde das Objekt in FLOWFACT seit der letzten Übertragung geändert. Einstellung `flowfact.konfliktverhalten` `abbrechen` (Standard): Lauf endet mit "In FLOWFACT wurde das Objekt seit der letzten Übertragung geändert (Zeitpunkt). Bitte prüfen und erneut freigeben." und `sync_status = fehlgeschlagen`, kein PATCH. `ueberschreiben`: PATCH mit Warnung. Ohne gespeicherten oder ohne gelieferten Zeitpunkt findet kein Vergleich statt. Dann `PATCH entity-service/schemas/{schema}/entities/{id}` mit den Feldern; der Zeitpunkt aus der PATCH-Antwort wird gespeichert, bei leerem Körper per GET nachgelesen. Lokal geleerte, zugeordnete Felder werden als `{ "values": [] }` gesendet, aber nur mit Absicht (Abschnitt 4).
7. Medien abgleichen (MediaSyncService) auf Basis von `medien_json`, Befunde 4 und 6, Masterprompt Abschnitt 14:
   a) Vorgemerkte Löschungen (`listing_media_deletions`) sowie Medien mit `flowfact_multimedia_id`, die nicht mehr in der Freigabe stehen (aus dem Inserat genommen, Freigabe entzogen, Dokument ohne Freigabe) oder deren Drehung sich gegenüber der zuletzt übertragenen Version geändert hat: `DELETE /items/{id}`, lokale ID leeren.
   b) Für jedes freigegebene Medium ohne `flowfact_multimedia_id`: Dateiname deterministisch `<listing uuid>-<media id>-<erste 12 Hex der SHA-256>[-r<Drehung>].<ext>`. Einmal je Lauf und Kategorie `GET /items/entities/{id}?contentCategory=IMAGE|DOCUMENT` lesen; ein Item mit passendem Dateinamen wird übernommen statt erneut hochgeladen. Sonst Presigned-URL holen, Binärdatei per PUT hochladen, Item registrieren (mit `title` aus der Freigabe), ID und `flowfact_titel` speichern, Lease verlängern. Bilder und Grundrisse werden mit eingebrannter Drehung (0, 90, 180, 270 Grad im Uhrzeigersinn) in Portalgröße neu kodiert; die Ausgabe enthält kein EXIF (Test mit APP1-Segment). Dokumente und Energieausweise gehen unverändert in die Dokumentkategorie des Albums; fehlt sie, entsteht die Warnung "Dokument ... wurde nicht übertragen: das FLOWFACT-Album hat keine Kategorie für Dokumente."
   c) Weicht der Titel der Freigabe von `flowfact_titel` ab: `PATCH /items/{id}` mit JSON-Patch (`replace /title`, bei leerem Titel `remove /title`), danach `flowfact_titel` nachführen.
   d) Reihenfolge der Bilder über `PUT /assigned/...` setzen, Titelbild an Position 0: nach jedem Upload, nach jeder Löschung und immer, wenn sich der Inhalts-Hash geändert hat.
8. Abschluss: `uebertragener_inhalt_hash` = Hash der Freigabe, `release_id` = übertragene Version,
   `letzte_uebertragung_at`, `sync_status = uebertragen`, Lease freigeben.
9. Fehler: Bei AuthenticationException `fehlgeschlagen` mit Meldung "Token ungültig oder Rechte fehlen", keine automatische Wiederholung. Bei RateLimitException Job mit Verzögerung neu einreihen. Bei Server- oder Transportfehler bis zu drei Versuche mit Backoff 30, 120, 300 Sekunden; danach `fehlgeschlagen`. In jedem Fehlerfall Lease freigeben und `letzter_fehler` setzen (ohne Token, gekürzt).

Zeitüberschreitung nach einem `POST` zum Anlegen: Die Antwort ist unbekannt, die Entität kann existieren. Beim
nächsten Lauf greift Schritt 4b und findet sie über die Objektnummer. Ein zweites Anlegen ist damit
ausgeschlossen, solange die Suche funktioniert. Funktioniert die Suche nicht (Fehler statt leerem Ergebnis), wird
nicht angelegt, sondern der Lauf endet als fehlgeschlagen.

Der synchrone Weg aus der Oberfläche ("In FLOWFACT speichern", "Jetzt veröffentlichen") führt denselben Service aus,
mit Zeitlimit 25 Sekunden für den Gesamtlauf; Medienuploads, die darüber hinausgehen, laufen als Job weiter. Der
Job-Pfad (`TransferListingJob`) arbeitet mit einem Zeitlimit von 150 Sekunden unterhalb der Lease von 3 Minuten und
reiht den Rest ebenfalls erneut ein (Befund 7).

Jobs und Freigabeversionen (Masterprompt Abschnitt 19, 24; `ReleaseGuard`): `TransferListingJob` und
`RefreshPortalStatusJob` tragen die `release_id`, mit der sie eingereiht wurden. Vor jeder Handlung prüfen sie, ob
die Version noch die jüngste ist (sonst Eintrag "Veraltete Freigabe übersprungen" im Übertragungsprotokoll, kein
API-Aufruf) und ob das Objekt nicht archiviert ist. Ein `TransferListingJob` mit `veroeffentlichen = true`
(Fortsetzung einer Veröffentlichung nach dem Zeitlimit beim Medienupload) fordert die Veröffentlichung für die
Portale der Freigabe erst an, wenn die Übertragung vollständig ist und nach der Freigabe keine Deaktivierung
angefordert wurde (`zurueckgezogen_at` oder Status `deaktivierung_angefordert` jünger als `freigegeben_at`); sonst
"Deaktivierung nach der Freigabe angefordert, Veröffentlichung übersprungen".

## 4. Feldzuordnung

Quelle jeder Zuordnung ist ausschließlich `PublishableFields` (Positivliste). Der Mapper erhält das Listing und
liefert die Werteform `{ feld: { values: [wert] } }`. Ein Feld ohne Zuordnung wird ausgelassen und in
`SyncResult::warnungen` gemeldet ("Keine FLOWFACT-Zuordnung für Nebenkosten"), damit die Oberfläche es anzeigen kann.

Geleerte Felder (Prüfbericht 2026-09-11, Befund 5; Masterprompt Abschnitt 23, Löschung nur mit Absicht):
Zugeordnete Felder, deren Wert in der Freigabe leer ist, liefert der Mapper in `MappedPayload::leereFelder`. Beim
Anlegen werden sie ausgelassen. Beim PATCH sendet der Sync `{ "values": [] }` ausschließlich für Felder, die mit der
zuletzt übertragenen Freigabeversion (`listing_flowfact_links.release_id`, ersatzweise die Vorgängerversion)
tatsächlich gesendet wurden und in der aktuellen Freigabe leer sind (`MappedPayload::loeschungenBeschraenktAuf`).
Felder, die Müller FLOW nie gesendet hat, werden nie gelöscht; ohne frühere Freigabe gibt es keine Löschbefehle.
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
| objektart, gewerbe_unterart | estatetype | Code, siehe 4.3 (Gewerbe: Unterart bestimmt den Code) |
| nutzungsstatus | let | `vermietet` -> `true`, `leerstehend` -> `false`, `anderweitig_belegt` und `unbekannt` werden nicht gesendet |
| status (immer) | status | `active` |
| strasse, hausnummer, plz, ort, land | addresses | `{ type: "private", street: "Straße Nr", zipcode, city, country: "Deutschland" }`; bei `adress_freigabe = nur_plz_ort` wird das Portal-Flag `showAddress = false` im Publish-Request gesetzt, die Adresse selbst (mit Straße) wird trotzdem übertragen; ob FLOWFACT die Straße dann verbirgt, ist am Konto zu prüfen (Smoke-Test Zeile 15a) |
| kaufpreis_cent | purchaseprice | Euro als Zahl mit zwei Dezimalstellen |
| kaltmiete_cent | rent | Euro als Zahl |
| wohnflaeche_qm | livingarea | Zahl |
| grundstuecksflaeche_qm | plotarea | Zahl |
| gewerbeflaeche_qm | commercialarea | Zahl (Vorrang) |
| nutzflaeche_qm | commercialarea | Zahl, nur wenn keine Gewerbefläche erfasst ist |
| zimmer | rooms | Zahl |
| schlafzimmer | numberbedrooms | Zahl |
| badezimmer | numberbathrooms | Zahl |
| etage | floor | Zahl |
| etagen_gesamt | no_of_floors | Zahl |
| baujahr | yearofconstruction | Zahl |
| zustand | condition | Code, siehe 4.3 |
| energie.effizienzklasse | energyefficienceclass | Code 01 bis 09 |
| stellplatz_typ | parking | Code, siehe 4.3 |
| ausstattung.aufzug | elevator | bool, dreiwertig: `ja` -> true, `nein` -> false, `unbekannt` -> nicht gesendet |
| ausstattung.balkon | balconyavailable | bool, dreiwertig |
| ausstattung.keller | cellar | bool, dreiwertig |
| ausstattung.gaeste_wc | guesttoilet | bool, dreiwertig |
| ausstattung.barrierearm (liest den älteren Schlüssel barrierefrei) | barrierfree | bool, dreiwertig; Zielfeld am Konto verifizieren |
| ausstattung.* übrige Merkmale | ohne bestätigten Standard | über flowfact.feldzuordnung setzbar |
| modernisierungsjahr, heizung_waermeabgabe, heizung_warmwasser, einbaukueche_mitvermietet, adresszusatz, stadtteil | ohne bestätigten Standard | Warnung, bis die Zuordnung am Konto ermittelt ist |
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
| objektart mehrfamilienhaus | | `02MFH` | bestätigt (SDK) |
| objektart gewerbe ohne Unterart | | `06B` | bestätigt (Bürofläche als Standard, änderbar) |
| gewerbe_unterart buero | | `06B` | bestätigt (SDK) |
| gewerbe_unterart laden | | `05L` | bestätigt (SDK) |
| gewerbe_unterart lager | | offen | Warnung "Code für Lagerfläche am Konto ermitteln", estatetype entfällt |
| gewerbe_unterart sonstiges | | offen | Warnung, estatetype entfällt |
| objektart grundstueck | | `03BE` | bestätigt |
| objektart stellplatz | | offen | im SDK-Auszug kein Code; ohne Eintrag `objektart.stellplatz` in flowfact.codezuordnung wird ein Stellplatz nicht übertragen (Meldung in Abschnitt 3, Schritt 0) |
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

Statusachse je Portal (`PortalStatus`, Masterprompt-Abgleich B.6): `nicht_veroeffentlicht`, `angefordert`, `aktiv`,
`fehler`, `unbekannt`, `manuelle_freigabe_erforderlich` (Fall B), `deaktivierung_angefordert`,
`deaktivierung_bestaetigt`; `zurueckgezogen` bleibt für ältere Zeilen lesbar und wird wie
`deaktivierung_bestaetigt` behandelt. Jeder Statuswechsel läuft über `PortalStatusTransition::apply(publication,
neu, nachweisQuelle, release, user)` und schreibt einen unveränderlichen Nachweis nach `listing_portal_status_logs`
(`von_status`, `nach_status`, `nachweis_quelle`, `nachweis_at`, `release_id`, `user_id`) und aktualisiert
`letzte_pruefung_at` (Masterprompt Abschnitt 21). Nachweisquellen: "POST /publish angefordert", "POST /publish
Antwort", "POST /publish nicht autorisiert (HTTP 401/403)", "POST /publish Antwort portalsWithoutAccessRights", "POST
/publish Fehler", "POST /publish OFFLINE angefordert", "GET /estates/{id}/portals onlineSince", "GET
/estates/{id}/portals ohne Eintrag", "Zeitablauf ohne Rücklesen", "manuell".

1. Die Oberfläche ruft `PublishingService::portals()` und zeigt nur Portale mit `authenticated = true`.
2. `publish(Listing, portalIds, User)` prüft: Status `bereit` oder `zurueckgezogen` oder `veroeffentlicht`, Policy,
   Vollständigkeit, jüngste Freigabeversion (sonst "Keine Freigabe vorhanden ..."). Die übergebenen Portale müssen
   eine Teilmenge von `portale_json` der Freigabe sein, sonst "Die gewählten Portale sind nicht Teil der jüngsten
   Freigabe (...)". Erfolgreiche Übertragung der Freigabe (`sync_status = uebertragen` und Hash der Freigabe), sonst
   wird zuerst übertragen. Bleibt die Medienübertragung nach dem synchronen Zeitlimit offen
   (`sync_status = geaendert_seit_uebertragung`), wird nicht veröffentlicht; der eingereihte `TransferListingJob`
   trägt die Freigabe und fordert die Veröffentlichung nach Abschluss selbst an (Abschnitt 3, ReleaseGuard).
   Für jedes Portal: Publikation mit `release_id`, Wechsel nach `angefordert`, dann `POST /publish` mit
   `targetStatus ONLINE` und `showAddress` aus der Adressfreigabe der Freigabe. Ein leerer 2xx-Körper bleibt
   `angefordert`. Ein Körper mit `errors` für dieses Objekt setzt `fehler` mit der übersetzten Meldung,
   `successFullyTransfered` setzt `aktiv` erst nach Rücklesen, `successfullyScheduled` bleibt `angefordert`. Ein
   Portal zählt erst dann als angefordert, wenn `POST /publish` ohne Ausnahme beantwortet und nicht als Fehler
   ausgewertet wurde. Wurde kein Portal erfolgreich angefordert, bleibt `listings.status` unverändert (Befund 1).
   Fall B (Masterprompt Abschnitt 20): Antwortet `POST /publish` mit 401 oder 403, obwohl Übertragung und Portalliste
   funktioniert haben, oder nennt die Antwort das Portal unter `portalsWithoutAccessRights`, fehlt dem API-Benutzer
   das Veröffentlichungsrecht. Die Publikation wird `manuelle_freigabe_erforderlich` mit `letzter_fehler` = "Objekt
   ist vollständig in FLOWFACT vorbereitet. Portalveröffentlichung in FLOWFACT abschließen."; das Ergebnis ist
   `ok = true` mit dieser Meldung als Warnung (weder Fehler noch Erfolg), `angefordert = 0`, `nurManuelleFreigabe()`
   liefert true; der Bearbeitungsstatus bleibt `bereit`. Ein späteres Rücklesen mit `onlineSince` (Abschluss in
   FLOWFACT) setzt `aktiv` und das Objekt auf `veroeffentlicht`.
   Fall C: Die Ergebnisse je Portal sind getrennt; die Meldung listet sie auf ("ImmoScout24: angefordert, Immowelt
   (OpenImmo): manuelle Freigabe erforderlich"), `PublishResult::jePortal` enthält sie maschinenlesbar.
3. `RefreshPortalStatusJob` liest `GET /estates/{id}/portals`. Ein Eintrag mit dem Portal und `onlineSince` setzt
   `aktiv` und `bestaetigt_at`, auch aus `manuelle_freigabe_erforderlich` und aus älteren zurückgezogenen Zeilen;
   bei `deaktivierung_angefordert` bleibt der Status bis zur Bestätigung. Fehlt der Eintrag bei einer Anforderung,
   die älter als 30 Minuten ist, wird der Status `unbekannt` mit Hinweis "Status nicht ermittelbar, in FLOWFACT
   prüfen". Der Scheduler (`flow:portal-status`) läuft alle fünf Minuten für Objekte mit `angefordert` oder
   `deaktivierung_angefordert`, stündlich für `aktiv` und `manuelle_freigabe_erforderlich`, und prüft veröffentlichte
   Objekte ohne offene Publikation, damit Altbestände zurückgeführt werden.
4. `withdraw` (Masterprompt Abschnitt 24) setzt `deaktivierung_angefordert` mit `zurueckgezogen_at` (auch für
   Publikationen in `fehler`, `unbekannt` und `manuelle_freigabe_erforderlich`), sendet `targetStatus OFFLINE`
   und setzt nach Rücklesen ohne Eintrag oder ohne `onlineSince` `deaktivierung_bestaetigt`. Eine reine
   Fehlerpublikation, die nie online war und für die FLOWFACT keinen Eintrag kennt, wird lokal auf
   `nicht_veroeffentlicht` zurückgesetzt (Befund 1; der vorherige Status stammt aus dem Nachweis). Antwortet
   `POST /publish` für OFFLINE mit 401 oder 403, bleibt `deaktivierung_angefordert` mit dem Hinweis "Die
   Deaktivierung konnte über die Schnittstelle nicht ausgelöst werden (keine Berechtigung). Bitte das Portal in
   FLOWFACT offline nehmen." stehen.
5. `listings.status` wird nur über `ListingStatusMachine` geändert: nach erster erfolgreicher Anforderung
   `veroeffentlicht`. Beim Rücklesen gilt (Befund 1): Solange eine Publikation `angefordert`, `aktiv` oder
   `deaktivierung_angefordert` ist, bleibt `veroeffentlicht`. Sind alle Publikationen `fehler`, `unbekannt`,
   `manuelle_freigabe_erforderlich`, `deaktivierung_bestaetigt` (oder ältere `zurueckgezogen`) oder
   `nicht_veroeffentlicht`, wechselt das Objekt nach `zurueckgezogen`, wenn mindestens ein Portal nach bestätigter
   Aktivität deaktiviert wurde, sonst nach `bereit`. Ist ein Portal aktiv, während das Objekt bereit oder
   zurückgezogen ist, wird es veröffentlicht.

Hinweis zur Oberfläche: `ReviewController::publish` setzt den Bearbeitungsstatus bei `ok = true` selbst auf
`veroeffentlicht`. Im Fall B ist `angefordert = 0`; der Controller sollte `PublishResult::nurManuelleFreigabe()`
auswerten und den Wechsel dann unterlassen. Bis dahin führt das nächste Rücklesen (`flow:portal-status`, spätestens
nach fünf Minuten) ein Objekt ohne offene Publikation nach `bereit` zurück.

## 6. Smoke-Test am echten Konto

`php artisan flow:flowfact:smoke` führt die Schritte 1 bis 5 aus flowfact-api.md Abschnitt 10 aus (nur lesend) und
druckt je Schritt Erwartung, Ergebnis und Statuscode. Mit `--write` folgen die Schritte 6 bis 17 mit einem Objekt,
dessen `identifier` mit `TEST-` beginnt, und einem kleinen erzeugten Testbild. Der Payload des Testobjekts entsteht
wie im Betrieb über `FlowfactPayloadMapper::mapSnapshot()` aus einer Wegwerf-Momentaufnahme (ListingSnapshot im
Speicher, kein Datensatz in `listings` oder `listing_releases`), mit Adressfreigabe "nur PLZ und Ort"; Zeile 15a des
Protokolls dokumentiert, dass die Straße im Payload steht und `showAddress = false` wäre (am Konto zu prüfen).
Schritt 7 und 8 melden, ob FLOWFACT `_metadata.lastModifiedTimestamp` liefert (Konflikterkennung). Ein
`POST /publish` ist im Befehl nicht enthalten und kann nicht über Optionen aktiviert werden; Fall B, Deaktivierung
und Rücklesen sind daher nur mit `Http::fake` simuliert (Zeile 15b, docs/faehigkeitsmatrix.md). Der Befehl schreibt
ein Protokoll nach `storage/logs/flowfact-smoke-<datum>.md`, das als Nachweis für die offenen Punkte in
flowfact-api.md dient.

## 7. Einstellungen

| Schlüssel | Art | Bedeutung |
| --- | --- | --- |
| flowfact.api_token | Secret | API-Token, nur schreibbar, Anzeige "hinterlegt am" |
| flowfact.company_id | Text | optional |
| flowfact.schema_miete, flowfact.schema_kauf | Text | konkrete Schemanamen des Kontos, Auswahl aus `flow:flowfact:schema` |
| flowfact.feldzuordnung | JSON | Überschreibung der Feldnamen |
| flowfact.codezuordnung | JSON | Überschreibung der Codes |
| flowfact.leere_felder_loeschen | Bool | Standard `true`: geleerte Felder beim PATCH als leere Werteliste senden, nur für zuvor gesendete Felder (Abschnitt 4) |
| flowfact.konfliktverhalten | Text | `abbrechen` (Standard): Lauf endet mit Fehler, wenn die FLOWFACT-Entität seit der letzten Übertragung geändert wurde; `ueberschreiben`: zugeordnete Felder werden mit Warnung überschrieben (Abschnitt 3, Schritt 6b). `flow:check-config` zeigt den Wert |
| flowfact.album_<schema> | JSON | `{ album, bilder, dokumente }`, einmal aus `GET /albums/schemas/{schema}` ermittelt; ohne `dokumente` werden Dokumente nicht übertragen (Warnung) |
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
| Regression/01 bis 07 | Regressionstests zum Prüfbericht 2026-09-11: Befund 1 (Status bleibt bereit, Rückweg aus veröffentlicht), 3 (Schemawechsel, Vollständigkeit), 4 (Medienänderungen), 5 (Löschsemantik im PATCH, nur für zuvor gesendete Felder), 6 (idempotenter Bildupload), 7 (Lease mit Token, Herzschlag, deterministisch mit eingefrorener Uhr und Zählern) |
| Regression/08 Freigabeversion | Payload und Medien aus der Freigabe, spätere Live-Änderungen bleiben unberücksichtigt, Link und Publikation tragen release_id |
| Regression/09 Veralteter Job | Job mit veralteter Freigabe schreibt "Veraltete Freigabe übersprungen" ohne API-Aufruf, archivierte Objekte, Rückzug nach der Freigabe verhindert POST /publish ONLINE durch den alten Job |
| Regression/10 Fall B | 401/403 auf /publish und portalsWithoutAccessRights setzen manuelle_freigabe_erforderlich, Objekt bleibt bereit, Rücklesen mit onlineSince setzt aktiv; Ergebnis je Portal in der Meldung; jeder Statuswechsel schreibt einen Nachweis mit Quelle, Zeitpunkt, Freigabe und Benutzer |
| Regression/11 Deaktivierung | deaktivierung_angefordert und deaktivierung_bestaetigt je Portal, ältere zurueckgezogen-Zeilen, 403 bei OFFLINE, flow:portal-status prüft angeforderte Deaktivierungen |
| Regression/12 Konflikt | Abbruch bei fremder Änderung, Überschreiben per Einstellung, Speichern des Zeitpunkts nach Anlegen und PATCH, Nachlesen bei leerer PATCH-Antwort, Anzeige in flow:check-config |
| Regression/13 Dokumente | freigegebene Dokumente und Energieausweise unverändert in die Dokumentkategorie, Warnung ohne Kategorie, Löschung nach entzogener Freigabe |
| Regression/14 Objektartcodes | 02MFH, 03BE, Gewerbe-Unterarten, Lagerwarnung, let, dreiwertige Merkmale mit altem Schlüssel, Adressfreigabe, Stellplatz ohne Code wird abgewiesen und mit Code übertragen |
| Unit/ImageResizerTest | Drehung 90/180/270 vor dem Verkleinern, Ausgabe ohne EXIF-APP1-Segment |
| Console/SchedulerTest, Console/CheckConfigCommandTest | Queue-Worker im Vordergrund, Warteschlangenprüfung meldet gestaute Jobs (Befund 8) |

Alle Tests laufen gegen `Http::fake()` mit Antwortformen aus flowfact-api.md; kein Test ruft die echte API auf.
