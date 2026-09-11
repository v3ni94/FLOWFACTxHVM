# Datenvertrag Müller FLOW

Stand: 11.09.2026. Verbindliche fachliche Vorgabe für alle Module. Änderungen an diesem Dokument
erfolgen nur durch den Leitagenten oder die Geschäftsführung.

Zweck der Anwendung: Mitarbeiter der Hausverwaltung Müller GmbH erfassen ein Miet- oder Kaufobjekt
in wenigen Schritten, laden Bilder hoch, erzeugen oder schreiben die Inseratstexte und veröffentlichen
das Objekt über FLOWFACT auf den ausgewählten Portalen. Die Anwendung ersetzt nicht FLOWFACT, sie ist
die schnelle Erfassungs- und Veröffentlichungsoberfläche davor.

## 1. Grundsätze

1. Interne Daten und Inseratsdaten sind getrennt gespeichert. Der FLOWFACT-Mapper liest ausschließlich
   aus einer Positivliste von Inseratsfeldern. Interne Felder können technisch nicht in die Übertragung
   gelangen. Ein Test belegt das.
2. Ein Objekt hat zwei voneinander unabhängige Statusachsen: den Bearbeitungsstatus (lokal) und den
   Übertragungsstatus (FLOWFACT). Zusätzlich gibt es je Portal einen Veröffentlichungsstatus.
   "An FLOWFACT übertragen" bedeutet nie "im Portal aktiv".
3. Entwürfe können nicht veröffentlicht werden. Veröffentlichung ist immer eine explizite, bestätigte
   Benutzeraktion mit Portalauswahl.
4. Jede Wiederholung einer Übertragung ist idempotent. Ein Objekt erhält in FLOWFACT höchstens eine Entität.
5. Beträge werden in Cent als Ganzzahl gespeichert. Anzeige im Format 1.234,56 EUR.
6. Preisberechnungen sind rein serverseitig und deterministisch. Heizkosten werden nie doppelt gezählt.
7. Freigegebene Texte werden gespeichert und wiederverwendet. Kein Text wird bei Seitenaufruf neu erzeugt.

## 2. Entitäten

### 2.1 Benutzer (users)

| Feld | Typ | Bemerkung |
| --- | --- | --- |
| id | bigint | |
| name | string | Anzeigename |
| email | string, unique | Login |
| password | hash | Argon2id oder bcrypt nach Laravel-Standard |
| role | enum: admin, mitarbeiter | Admin verwaltet Benutzer, FLOWFACT-Zugang und Portale |
| two_factor_secret | encrypted, nullable | Nur gesetzt, wenn Benutzer 2FA aktiviert hat |
| two_factor_confirmed_at | datetime, nullable | 2FA ist optional, nie verpflichtend |
| two_factor_recovery_codes | encrypted json, nullable | |
| phone | string, nullable | Für Ansprechpartner im Inserat |
| is_active | bool | Gesperrte Benutzer können sich nicht anmelden |
| last_login_at | datetime, nullable | |

### 2.2 Objekt (listings)

Vermarktungs- und Objektart bestimmen, welche Preisfelder Pflicht sind.

| Feld | Typ | Pflicht | Bemerkung |
| --- | --- | --- | --- |
| id | bigint | | |
| uuid | uuid, unique | ja | Externe Referenz, wird in FLOWFACT hinterlegt (Dublettenschutz) |
| objektnummer | string, unique | ja | Lesbare Nummer, Format MF-JJJJ-NNNN, automatisch vergeben |
| vermarktungsart | enum: miete, kauf | ja | |
| objektart | enum: wohnung, haus, gewerbe, stellplatz, grundstueck | ja | |
| titel | string(100) | ja vor Veröffentlichung | Portale begrenzen Titel, 100 Zeichen ist sicher |
| strasse | string | ja | |
| hausnummer | string | ja | |
| plz | string(5) | ja | |
| ort | string | ja | |
| land | string(2) | ja | Standard DE |
| adresse_im_inserat_anzeigen | bool | | Standard ja. Bei nein wird nur PLZ und Ort übertragen |
| wohnflaeche_qm | decimal(8,2), nullable | ja bei wohnung, haus | |
| nutzflaeche_qm | decimal(8,2), nullable | ja bei gewerbe | |
| grundstuecksflaeche_qm | decimal(10,2), nullable | ja bei haus, grundstueck | |
| zimmer | decimal(4,1), nullable | ja bei wohnung, haus | Halbe Zimmer erlaubt |
| schlafzimmer | smallint, nullable | | |
| badezimmer | smallint, nullable | | |
| etage | smallint, nullable | | |
| etagen_gesamt | smallint, nullable | | |
| baujahr | smallint, nullable | | Plausibilität 1800 bis laufendes Jahr plus 3 |
| zustand | enum, nullable | | erstbezug, neuwertig, gepflegt, renovierungsbeduerftig, modernisiert, saniert, projektiert |
| ausstattungsqualitaet | enum, nullable | | einfach, normal, gehoben, luxus |
| heizungsart | enum, nullable | | zentralheizung, etagenheizung, fussbodenheizung, fernwaerme, ofenheizung, waermepumpe |
| energietraeger | enum, nullable | | gas, oel, strom, fernwaerme, holz, pellets, waermepumpe, solar, sonstiges |
| heizkosten_versorgung | enum: zentral, dezentral | ja bei miete | Siehe Abschnitt 3 |
| verfuegbar_ab_typ | enum: sofort, nach_vereinbarung, datum | ja | |
| verfuegbar_ab_datum | date, nullable | ja bei typ datum | |
| ausstattung | json | | Schlüssel: balkon, terrasse, garten, keller, aufzug, einbaukueche, gaeste_wc, barrierefrei, moebliert, wg_geeignet, haustiere_erlaubt. Werte bool |
| stellplatz_typ | enum, nullable | | garage, tiefgarage, aussenstellplatz, carport, duplex, keiner |
| stellplatz_anzahl | smallint, nullable | | |
| beschreibung_objekt | text, nullable | ja vor Veröffentlichung | |
| beschreibung_ausstattung | text, nullable | | |
| beschreibung_lage | text, nullable | | |
| beschreibung_sonstiges | text, nullable | | |
| ansprechpartner_user_id | fk users | ja vor Veröffentlichung | Name, Telefon, E-Mail gehen ins Inserat |
| status | enum | ja | Siehe Abschnitt 4.1 |
| erstellt_von_user_id | fk users | ja | |
| created_at, updated_at | | | |
| inhalt_geaendert_at | datetime | | Wird bei jeder Änderung eines Inseratsfeldes gesetzt, Grundlage für "geändert seit Übertragung" |

### 2.3 Preise (listing_prices), 1:1 zu listings

Alle Beträge in Cent, nullable wo nicht anwendbar.

| Feld | Miete | Kauf | Bemerkung |
| --- | --- | --- | --- |
| kaltmiete_cent | Pflicht | leer | |
| nebenkosten_cent | Pflicht | leer | Betriebskosten. Ob Heizkosten darin enthalten sind, sagt das Flag |
| heizkosten_cent | optional | leer | Nur bei heizkosten_versorgung zentral erfassbar |
| heizkosten_in_nebenkosten_enthalten | Pflicht (bool) | leer | true: nebenkosten_cent enthält bereits die Heizkosten |
| warmmiete_cent | berechnet | leer | Nie manuell setzbar, siehe Abschnitt 3 |
| kaution_cent | optional | leer | Anzeige zusätzlich als "x Monatsmieten", wenn ganzzahliges Vielfaches der Kaltmiete |
| stellplatz_miete_cent | optional | leer | Wird nicht in die Warmmiete eingerechnet, separates Portalfeld |
| kaufpreis_cent | leer | Pflicht | |
| hausgeld_cent | leer | optional | Nur bei objektart wohnung sinnvoll |
| stellplatz_kaufpreis_cent | leer | optional | |
| mieteinnahmen_ist_cent | leer | optional | Jahresbetrag |
| provision_typ | Pflicht | Pflicht | enum: provisionsfrei, provisionspflichtig |
| provision_text | optional | Pflicht bei provisionspflichtig | Freitext, z. B. "3,57 % inkl. MwSt." |

### 2.4 Energieausweis (listing_energy), 1:1 zu listings

| Feld | Typ | Bemerkung |
| --- | --- | --- |
| status | enum: liegt_vor, nicht_erforderlich, in_erstellung | Pflicht vor Veröffentlichung |
| ausweistyp | enum: bedarf, verbrauch, nullable | Pflicht bei liegt_vor |
| kennwert_kwh | decimal(6,1), nullable | Pflicht bei liegt_vor |
| effizienzklasse | enum A+ bis H, nullable | |
| baujahr_anlage | smallint, nullable | |
| gueltig_bis | date, nullable | |
| enthaelt_warmwasser | bool, nullable | Nur bei verbrauch relevant |

### 2.5 Interne Daten (listing_internals), 1:1 zu listings, NIE Teil der Übertragung

| Feld | Typ | Bemerkung |
| --- | --- | --- |
| eigentuemer_name | string, nullable | Auftraggeber |
| eigentuemer_kontakt | text, nullable | |
| verwaltungsobjekt_referenz | string, nullable | Bezug zum Verwaltungsbestand (WEG, Mietobjekt) |
| interne_notizen | text, nullable | |
| schluessel_hinweis | text, nullable | |
| besichtigung_intern | text, nullable | |
| kalkulation_notiz | text, nullable | |

### 2.6 Medien (listing_media)

| Feld | Typ | Bemerkung |
| --- | --- | --- |
| listing_id | fk | |
| typ | enum: bild, grundriss, dokument | Dokumente werden nicht an Portale übertragen, solange nicht ausdrücklich freigegeben |
| dateiname_original | string | |
| pfad | string | Speicherung außerhalb des Webroots, Auslieferung über signierte Route |
| mime | string | Erlaubt: image/jpeg, image/png, image/webp, application/pdf |
| groesse_bytes | int | Maximal 15 MB je Datei |
| breite, hoehe | int, nullable | |
| sortierung | smallint | Titelbild ist sortierung 0 |
| titel | string, nullable | |
| im_inserat | bool | Standard true bei bild und grundriss |
| flowfact_multimedia_id | string, nullable | Nach Upload gesetzt |
| pruefsumme_sha256 | string | Für Dublettenschutz beim Bildupload |

### 2.7 Texte (listing_texts), Historie der KI- und Handtexte

| Feld | Typ | Bemerkung |
| --- | --- | --- |
| listing_id | fk | |
| feld | enum: titel, beschreibung_objekt, beschreibung_ausstattung, beschreibung_lage, beschreibung_sonstiges | |
| quelle | enum: ki, manuell | |
| modell | string, nullable | Modellkennung bei ki |
| inhalt | text | |
| uebernommen | bool | true, wenn dieser Text in das Objekt übernommen wurde |
| created_by_user_id | fk | |

### 2.8 FLOWFACT-Verknüpfung (listing_flowfact_links), 1:1 zu listings

| Feld | Typ | Bemerkung |
| --- | --- | --- |
| flowfact_entity_id | string, nullable | Gesetzt nach erfolgreichem Anlegen oder Wiederfinden |
| flowfact_schema | string | Verwendetes Schema in FLOWFACT |
| sync_status | enum | Siehe Abschnitt 4.2 |
| letzte_uebertragung_at | datetime, nullable | |
| letzter_fehler | text, nullable | Ohne Zugangsdaten, gekürzt |
| uebertragener_inhalt_hash | string, nullable | Hash der zuletzt übertragenen Nutzdaten |
| sperre_bis | datetime, nullable | Lease gegen parallele Übertragungen |

### 2.9 Portalveröffentlichungen (listing_portal_publications)

| Feld | Typ | Bemerkung |
| --- | --- | --- |
| listing_id | fk | |
| portal_id | string | Kennung aus FLOWFACT |
| portal_name | string | Anzeigename |
| status | enum | Siehe Abschnitt 4.3 |
| angefordert_at | datetime, nullable | |
| bestaetigt_at | datetime, nullable | Zeitpunkt, an dem FLOWFACT den Status aktiv gemeldet hat |
| zurueckgezogen_at | datetime, nullable | |
| letzte_pruefung_at | datetime, nullable | |
| letzter_fehler | text, nullable | |

### 2.10 Übertragungsprotokoll (transfer_logs)

| Feld | Typ | Bemerkung |
| --- | --- | --- |
| listing_id | fk, nullable | |
| user_id | fk, nullable | Auslöser, null bei Cron |
| aktion | string | z. B. entity.create, entity.update, media.upload, portal.publish, portal.status |
| richtung | enum: ausgehend, eingehend | |
| http_status | smallint, nullable | |
| erfolgreich | bool | |
| zusammenfassung | string | Kurztext für die Oberfläche |
| details | json, nullable | Gekürzte Nutzdaten ohne Token, ohne interne Felder |
| dauer_ms | int, nullable | |
| idempotenzschluessel | string, nullable | |

### 2.11 Einstellungen (settings), Schlüssel-Wert, verschlüsselt wo nötig

| Schlüssel | Bemerkung |
| --- | --- |
| flowfact.api_token | Verschlüsselt. Nur Admin. Wird nie protokolliert oder angezeigt, nur "hinterlegt am". |
| flowfact.company_id | Optional, falls die API es verlangt |
| flowfact.stage | production oder development |
| flowfact.schema_miete, flowfact.schema_kauf | Schemanamen der Zielentität |
| ki.provider, ki.modell | Konfigurierbares Modell für Objektbeschreibungen |
| firma.* | Firmendaten für das Inserat (Name, Anschrift, Telefon, E-Mail) |

## 3. Preislogik Miete

Eingaben: kaltmiete_cent (K), nebenkosten_cent (N), heizkosten_cent (H, optional),
heizkosten_in_nebenkosten_enthalten (E), heizkosten_versorgung (V).

Regeln:

1. V = dezentral: Der Mieter schließt selbst einen Versorgungsvertrag. H muss leer oder 0 sein,
   E ist false. Warmmiete W = K + N. Im Inserat erscheint der Hinweis "Heizkosten werden direkt mit dem
   Versorger abgerechnet und sind nicht enthalten."
2. V = zentral und E = true: N enthält die Heizkosten bereits. W = K + N. H darf zur Information erfasst
   werden, wird aber nicht addiert. Validierung: H darf nicht größer als N sein.
3. V = zentral und E = false: W = K + N + H. Fehlt H, wird W = K + N berechnet und das Objekt erhält
   einen Hinweis "Heizkosten nicht angegeben", der vor Veröffentlichung bestätigt werden muss.
4. Stellplatzmiete und Kaution gehen nie in W ein.
5. W wird bei jedem Speichern serverseitig neu berechnet und nie aus dem Formular übernommen.

Prüffälle, die als Tests vorliegen müssen:

| Fall | K | N | H | E | V | Erwartet W |
| --- | --- | --- | --- | --- | --- | --- |
| A | 80.000 | 20.000 | 10.000 | false | zentral | 110.000 |
| B | 80.000 | 30.000 | 10.000 | true | zentral | 110.000 |
| C | 80.000 | 20.000 | leer | false | zentral | 100.000 plus Hinweis |
| D | 80.000 | 15.000 | leer | false | dezentral | 95.000 plus Versorgerhinweis |
| E | 80.000 | 15.000 | 5.000 | false | dezentral | Validierungsfehler |
| F | 80.000 | 20.000 | 25.000 | true | zentral | Validierungsfehler (H größer N) |

## 4. Statusachsen

### 4.1 Bearbeitungsstatus (listings.status)

| Status | Bedeutung | Übergänge |
| --- | --- | --- |
| entwurf | In Erfassung, unvollständig erlaubt | nach bereit, wenn Vollständigkeitsprüfung bestanden; nach archiviert |
| bereit | Vollständig, noch nicht veröffentlicht | nach entwurf bei Änderung eines Pflichtfelds; nach veroeffentlicht durch Veröffentlichungsaktion; nach archiviert |
| veroeffentlicht | Mindestens eine Portalveröffentlichung angefordert oder aktiv | nach zurueckgezogen; nach archiviert nur nach Rückzug |
| zurueckgezogen | Alle Portalveröffentlichungen beendet, Objekt bleibt in FLOWFACT | nach bereit; nach archiviert |
| archiviert | Nur lesbar | keine |

Vollständigkeitsprüfung vor "bereit": Titel, Adresse, Flächen und Zimmer je Objektart, Preise je
Vermarktungsart, Energieausweisstatus, mindestens ein Bild, Beschreibung, Ansprechpartner.

### 4.2 Übertragungsstatus (listing_flowfact_links.sync_status)

| Status | Bedeutung |
| --- | --- |
| nicht_uebertragen | Keine Entität in FLOWFACT bekannt |
| uebertragung_laeuft | Lease aktiv, kein zweiter Start möglich |
| uebertragen | Entität existiert, Inhalt-Hash entspricht dem lokalen Stand |
| geaendert_seit_uebertragung | Lokale Inseratsfelder oder Medien wurden nach der letzten Übertragung geändert |
| fehlgeschlagen | Letzte Übertragung mit Fehler beendet, Wiederholung erlaubt |

Idempotenzregel: Vor jedem Anlegen wird FLOWFACT nach der uuid des Objekts durchsucht. Wird eine Entität
gefunden, wird sie verknüpft und aktualisiert statt neu angelegt. Ein Zeitüberschreitungsfehler nach dem
Absenden eines Anlegebefehls führt beim nächsten Versuch zuerst zur Suche, nie direkt zum erneuten Anlegen.

### 4.3 Portalstatus (listing_portal_publications.status)

| Status | Bedeutung | Anzeige |
| --- | --- | --- |
| nicht_veroeffentlicht | | grau |
| angefordert | Veröffentlichung an FLOWFACT gesendet, Bestätigung ausstehend | gelb, Text "Bestätigung ausstehend" |
| aktiv | FLOWFACT meldet das Objekt auf diesem Portal als veröffentlicht | grün |
| fehler | FLOWFACT oder Portal meldet Fehler | rot mit Fehltext |
| zurueckgezogen | Rückzug bestätigt | grau |
| unbekannt | Status konnte nicht ermittelt werden | gelb, Text "Status nicht ermittelbar" |

"aktiv" darf nur durch eine gelesene Statusantwort von FLOWFACT gesetzt werden, nie durch das bloße
Absenden des Veröffentlichungsbefehls.

## 5. Erfassungsassistent

Schritte in fester Reihenfolge, jeder Schritt speichert als Entwurf:

1. Grunddaten: Vermarktungsart, Objektart, Adresse, Adresse anzeigen.
2. Flächen und Ausstattung: Flächen, Zimmer, Etage, Baujahr, Zustand, Heizung, Ausstattung, Stellplatz.
3. Energieausweis.
4. Preise: je Vermarktungsart, Heizkostenlogik nach Abschnitt 3 mit sofortiger Anzeige der Warmmiete.
5. Bilder und Grundrisse: Upload, Sortierung, Titelbild.
6. Texte: manuell oder KI-Vorschlag, Übernahme ausdrücklich.
7. Intern: interne Felder, deutlich als "nicht Teil des Inserats" markiert.
8. Prüfen und Veröffentlichen: Vollständigkeitsprüfung, Vorschau, Portalauswahl, Bestätigung.

Jeder Schritt zeigt an, welche Felder für die Veröffentlichung noch fehlen, blockiert das Speichern
des Entwurfs aber nicht.

## 6. Berechtigungen

| Aktion | admin | mitarbeiter |
| --- | --- | --- |
| Objekte anlegen, bearbeiten | ja | ja |
| Objekte veröffentlichen, zurückziehen | ja | ja |
| Objekte archivieren | ja | nur eigene |
| Benutzer verwalten | ja | nein |
| FLOWFACT-Zugang und Portale konfigurieren | ja | nein |
| Übertragungsprotokoll einsehen | ja | ja (eigene Objekte und alle Objekte lesend) |
| Einstellungen Firma und KI-Modell | ja | nein |

2FA ist für jeden Benutzer optional aktivierbar. Ein Admin kann sie nicht für andere erzwingen.
