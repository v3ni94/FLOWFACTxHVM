# Abgleich mit dem Masterprompt Müller FLOW

Stand: 12.09.2026. Der Masterprompt (Abschnitte 1 bis 34) lag der ersten Umsetzung nicht vor. Dieses Dokument
stellt je Abschnitt fest, was der gebaute Stand erfüllt, was fehlt und in welcher Welle es umgesetzt wird.
Es ist zugleich der verbindliche Auftrag für die Umsetzungsagenten der zweiten Iteration. Der Datenvertrag
([datenvertrag.md](datenvertrag.md)) gilt weiter; Abschnitt B unten ergänzt ihn.

Bewertung: erfüllt, teilweise, offen, abweichend (bewusste Abweichung mit Begründung).

## A. Abgleich je Abschnitt

| Abschnitt | Anforderung (Kurzfassung) | Stand | Maßnahme | Welle |
| --- | --- | --- | --- | --- |
| 1 | Ablauf Anmelden, "Müller FLOW starten", 8 Schritte, Vorschau, "JETZT VERÖFFENTLICHEN"; Zeitmessung im Abnahmetest | teilweise | Startbutton, Schrittfolge und Abnahmeprotokoll mit Zeit- und Klickmessung ergänzen | 2, 4 |
| 2 | Modelle, Ultracode, höchstens zwei Agenten, Kostenberichte | erfüllt | Fortführen, Bericht je Welle | laufend |
| 3 | Bestandsprüfung, keine Produktivsysteme verändern | erfüllt | Ergebnis in architektur.md und flowfact-api.md | erledigt |
| 4 | PHP 8.4, Laravel, MariaDB, Blade mit reaktiver Ergänzung, IONOS, Queue ohne Worker | abweichend | PHP-Ziel bleibt 8.3, weil das bestätigte IONOS-Profil des Schwesterprojekts 8.3 nutzt; Code ist 8.4-kompatibel. Statt Livewire kleine Vanilla-JS-Module ohne Build (ADR-002); Autosave wird damit umgesetzt | 2 |
| 5 | HVM-CI, Name und Unterzeile, große Schaltflächen, Ja/Nein/Unbekannt, bedingte Felder, mobil | teilweise | Unterzeile, Dreiwertfelder, bedingte Folgefragen, mobile Prüfung | 2 |
| 6 | Login ohne 2FA-Pflicht, Einladungen, Passwort-Reset, Rollen Admin/Mitarbeiter/Leser, separates Recht Veröffentlichen, Sperrung wirkt auf Sitzungen, letzter Admin geschützt | teilweise | Rolle Leser, Recht `darf_veroeffentlichen`, Einladung per E-Mail, Passwort-Reset, Objektzuordnung (Bearbeiter) | 1 |
| 7 | Dashboard mit Startbutton, Meine Entwürfe, Fehler, Liste mit Titelbild, Bearbeiter, Preis; Filter; Detail mit Historie | teilweise | Dashboard und Liste nach Vorgabe, Änderungshistorie | 2 |
| 8 | Acht Schritte in fester Reihenfolge, Fortschritt, Autosave mit Bestätigung, drei getrennte Zustände | teilweise | Neue Schrittfolge (siehe B.1), Autosave, Zustände getrennt anzeigen | 2 |
| 9 | Vermietung/Verkauf, Objektarten inkl. Mehrfamilienhaus, Gewerbe-Unterarten, Ansprechpartner intern und öffentlich, Verfügbarkeit, Nutzungsstatus | teilweise | Felder ergänzen (B.2), nicht übertragbare Objektarten kennzeichnen | 1, 2 |
| 10 | Adresse mit Zusatz, Stadtteil, Hausnummernbereiche, interne Angaben, Adressfreigabe auch für Texte | teilweise | Felder ergänzen, Adressprüfung in Texten (B.7) | 1, 2, 3 |
| 11 | Flächenarten getrennt, halbe Zimmer, unbekannt statt 0, Modernisierungsjahr | teilweise | gewerbeflaeche, modernisierungsjahr, unbekannt-Semantik | 1, 2 |
| 12 | Kostenstruktur Miete, keine Doppelzählung, Stellplatz optional/pflicht, Provision bestätigt, Heizung getrennt nach System, Energieträger, Wärmeabgabe, Warmwasser, Abrechnung | teilweise | Stellplatz-Modus, Provision-Bestätigung, Heizungsfelder, Kautionshinweis | 1, 2 |
| 13 | Ausstattung dreiwertig, Energieausweis mit Status vorhanden/noch nicht/beauftragt/Ausnahme, Ausstellungsdatum, gesetzlich vs. portal vs. intern | teilweise | Dreiwert-Ausstattung, Energieausweisstatus, Pflichtlogik nach § 87 GEG (B.5) | 1, 2 |
| 14 | Upload mobil, drehen, Titelbild, Kategorien inkl. Energieausweis, Dokumente intern bis Freigabe, EXIF entfernen, Inhaltsprüfung | teilweise | Drehen, Dokumentkategorien, Freigabe je Dokument, EXIF-Entfernung belegen, HEIC ablehnen mit Hinweis | 3 |
| 15 | Überschrift, interne Bezeichnung nach Muster, stabile technische Referenz, Zeichenzähler | teilweise | Schritt 7 neu, Vorschläge aus bestätigten Daten, Muster konfigurierbar | 2 |
| 16 | Textentwürfe, Überarbeitungen kürzer/sachlicher/sprachlich, Vorlagenmodus ohne KI, Versionierung, prüfbedürftig bei Datenänderung, Inhalte als Daten | teilweise | Überarbeitungsaktionen, Datenbasis-Hash, Kennzeichnung prüfbedürftig, Vorlagenentwurf ehrlich benannt | 3 |
| 17 | Prüfen und veröffentlichen mit vier Prüfebenen, blockierend vs. Hinweis, drei Aktionen | teilweise | Prüfebenen intern/FLOWFACT/Portal/gesetzlich, Aktionen umbenennen | 2 |
| 18 | Portalauswahl aus dem Konto, "Alle verfügbaren Portale", Standardauswahl je Vermarktungsart, weitere Standards | teilweise | Standards im Adminbereich, Auswahlhilfe | 2 |
| 19 | Veröffentlichung friert Inhaltsversion ein, prüft erneut, überträgt vollständig, Status je Portal | teilweise | Freigabeversionen (B.6), Jobs prüfen Versionsstand | 3 |
| 20 | Fall A, B, C unterscheiden, keine Simulation | teilweise | Fall B: fehlende Berechtigung erkennen und "in FLOWFACT abschließen" anzeigen; Fall C je Portal | 3 |
| 21 | Statusliste mit Nachweisquelle, Zeitpunkt, Inhaltsversion | teilweise | Zusätzliche Status, Nachweisfelder (B.6) | 3 |
| 22 | Schnittstelle zuerst verifizieren, Fähigkeitsmatrix, Autosave löst keine Veröffentlichung aus | teilweise | Fähigkeitsmatrix als Dokument mit Teststatus je Funktion | 4 |
| 23 | Idempotenz, Momentaufnahme, Konflikterkennung, führendes System, keine Löschung durch leere Werte ohne Absicht | teilweise | Freigabeversion als Momentaufnahme; Löschsemantik leerer Felder nur bei bewusster Freigabe; Konflikterkennung über lastModifiedTimestamp der FLOWFACT-Entität | 3 |
| 24 | Ändern, deaktivieren je Portal, archivieren, löschen, duplizieren | teilweise | Duplizieren, Deaktivierung je Portal, Schutz vor Wiederveröffentlichung durch alte Jobs | 2, 3 |
| 25 | Datenmodell mit Freigaben, Historie, Portalen, Aufträgen | teilweise | listing_releases, listing_changes (B.6) | 1 |
| 26 | Sicherheit, Datenschutz, Backup und Restore | teilweise | Sicherungs- und Wiederherstellungsanleitung | 4 |
| 27 | Performance, kein Polling, Wiederaufnahme nach Fehlern | teilweise | Prüfung nach Umbau | 4 |
| 28 | Verpflichtende Tests | teilweise | Fehlende Fälle ergänzen (B.8) | 1 bis 4 |
| 29 | Phasen 1 bis 8 | erfüllt bis Phase 5, Rest teilweise | Wellen 1 bis 4 dieses Dokuments | |
| 30 | Umfang begrenzen | erfüllt | Keine Zusatzmodule | |
| 31 | Liefergegenstände inkl. CLAUDE.md, Benutzer- und Adminanleitung, Fähigkeitsmatrix, Backup | teilweise | Dokumente ergänzen | 4 |
| 32 | Abnahmekriterien | teilweise | Abnahmeprotokoll mit Nachweis je Kriterium | 4 |
| 33 | Quellen | teilweise | developers.flowfact.com und gesetze-im-internet.de sind aus der Entwicklungsumgebung nicht abrufbar; SDK als Ersatzquelle, § 87 GEG aus Kenntnisstand mit Prüfvermerk | offen |

## B. Ergänzung des Datenvertrags

### B.1 Neue Schrittfolge (ersetzt Datenvertrag Abschnitt 5)

1. Vermietung oder Verkauf: Vermarktungsart, Objektart (wohnung, haus, mehrfamilienhaus, grundstueck, stellplatz, gewerbe), Gewerbe-Unterart (buero, laden, lager, sonstiges), zuständiger Mitarbeiter (bearbeiter_user_id), öffentlicher Ansprechpartner (ansprechpartner_user_id), Verfügbarkeit, Nutzungsstatus.
2. Adresse und Lage: Straße, Hausnummer (freier Text, z. B. 12a oder 12 bis 14), Adresszusatz, PLZ (Text), Ort, Stadtteil, Land; interne Angaben Gebäudebezeichnung, Einheitsnummer, Lage im Gebäude; Adressfreigabe (vollständig, nur PLZ und Ort).
3. Flächen und Objektdaten: Wohnfläche, Grundstücksfläche, Nutzfläche, Gewerbefläche, Zimmer (halbe erlaubt), Schlafzimmer, Badezimmer, Etage, Etagen gesamt, Baujahr, Modernisierungsjahr, Zustand. Nur zur Objektart passende Felder.
4. Preise und Heizung: Preisfelder je Vermarktungsart, Kostenstruktur der Heizkosten (enthalten, zusätzlich an Vermieter, eigener Versorgungsvertrag), Stellplatz mit Modus (keiner, optional hinzubuchbar, verpflichtend enthalten, verpflichtend zusätzlich) und Kosten, Kaution mit Hinweis, Provision mit Bestätigung; Heizung: Versorgung (zentral, dezentral), System, Energieträger, Wärmeabgabe, Warmwasser.
5. Ausstattung und Energieausweis: Merkmale dreiwertig (ja, nein, unbekannt), Stellplatztyp und Anzahl (verknüpft mit Schritt 4), Einbauküche mit Mitvermietung, Barrierefreiheit getrennt (stufenlos, Aufzug, barrierearm, rollstuhlgeeignet); Energieausweis mit Status (vorhanden, noch_nicht_vorhanden, beauftragt, ausnahme_zu_pruefen), Ausweisart, Ausstellungsdatum, gültig bis, Kennwert, Energieträger, Klasse, Baujahr laut Ausweis.
6. Bilder und Unterlagen: Fotos, Grundrisse, Energieausweis, sonstige Dokumente; drehen, sortieren, Titelbild, Beschriftung, Freigabe je Dokument.
7. Überschrift und interne Bezeichnung: Vorschläge aus bestätigten Daten, interne Bezeichnung nach Muster, technische Referenz (uuid, nur lesend).
8. Beschreibungen: Objekt, Ausstattung, Lage, Sonstiges mit Entwürfen, Überarbeitungen und Kennzeichnung prüfbedürftig.
Abschluss: Prüfen und veröffentlichen.

Jeder Schritt speichert automatisch (Autosave über JSON-Endpunkt mit Verzögerung 1,5 Sekunden nach der letzten Eingabe, Anzeige "Gespeichert" erst nach Serverantwort, ungespeicherte Änderungen bleiben sichtbar markiert). Die Schaltflächen Zurück, Weiter und Entwurf speichern bleiben erhalten.

### B.2 Neue und geänderte Felder

listings: objektart erweitert um mehrfamilienhaus; gewerbe_unterart (enum, nullable); nutzungsstatus (enum: leerstehend, vermietet, anderweitig_belegt, unbekannt); bearbeiter_user_id (fk, Pflicht); adresszusatz; stadtteil; gebaeudebezeichnung, einheitsnummer, lage_im_gebaeude (intern, wandern in listing_internals); gewerbeflaeche_qm; modernisierungsjahr; interne_bezeichnung (string); ausstattung als json mit Werten "ja", "nein", "unbekannt" je Merkmal (Merkmale: balkon, terrasse, garten, gartennutzung, aufzug, keller, abstellraum, einbaukueche, gaeste_wc, badewanne, dusche, tageslichtbad, fussbodenheizung, rollladen, moebliert, stufenlos, barrierearm, rollstuhlgeeignet, haustiere_erlaubt, wg_geeignet); einbaukueche_mitvermietet (bool, nullable); heizung_system (enum, ersetzt heizungsart), heizung_energietraeger, heizung_waermeabgabe (enum: heizkoerper, fussbodenheizung, beides, unbekannt), heizung_warmwasser (enum: zentral, dezentral, unbekannt).

listing_prices: stellplatz_modus (enum: keiner, optional, pflicht_enthalten, pflicht_zusaetzlich); provision_bestaetigt (bool); stellplatz_im_kaufpreis (bool, nullable); heizkosten_struktur (enum: enthalten, zusaetzlich, eigener_vertrag) ersetzt fachlich das Flag heizkosten_in_nebenkosten_enthalten plus HeizkostenVersorgung (beide bleiben als Ableitung erhalten, damit RentCalculator unverändert bleibt).

listing_energies: status erweitert (vorhanden, noch_nicht_vorhanden, beauftragt, ausnahme_zu_pruefen), ausstellungsdatum (date), ausnahme_begruendung (text), ausnahme_bestaetigt_von_user_id.

listing_media: typ erweitert um energieausweis; rotation (0, 90, 180, 270), freigegeben (bool, Standard true bei bild und grundriss, false bei dokument und energieausweis).

users: role erweitert um leser; darf_veroeffentlichen (bool, Standard false; Admin immer ja); Einladungen über Tabelle user_invitations (token_hash, email, expires_at, accepted_at, eingeladen_von); Passwort-Reset über die Laravel-Standardtabelle.

### B.3 Berechtigungen

| Aktion | admin | mitarbeiter | leser |
| --- | --- | --- | --- |
| Objekte sehen | alle | alle | alle |
| Objekte anlegen | ja | ja | nein |
| Objekt bearbeiten | alle | wenn bearbeiter_user_id oder erstellt_von_user_id der Benutzer ist, oder das Objekt für alle freigegeben ist (Flag freigegeben_fuer_alle) | nein |
| Veröffentlichen, deaktivieren | ja | nur mit darf_veroeffentlichen und Bearbeitungsrecht | nein |
| Archivieren | ja | eigene | nein |
| Duplizieren | ja | ja | nein |
| Benutzer, Einstellungen, FLOWFACT, KI | ja | nein | nein |

Sperrung eines Benutzers beendet bestehende Sitzungen (Sitzungen des Benutzers werden gelöscht, Remember-Token zykliert). Der letzte aktive Admin kann weder deaktiviert noch herabgestuft werden (vorhanden).

### B.4 Prüfebenen vor Veröffentlichung

CompletenessCheck liefert künftig je Befund die Ebene (intern, flowfact, portal, gesetzlich) und die Art (blockierend, hinweis). Blockierend: interne Pflichtfelder, FLOWFACT-Pflichtfelder laut Schema (soweit aus dem Kontoschema bekannt), Portalpflichtfelder (aus Konfiguration je Portaltyp, Standard: ImmoScout24 verlangt Energieangaben wie gesetzlich), gesetzliche Energieangaben. Hinweis: fehlende optionale Angaben, unbekannte Merkmale, fehlende Lagebeschreibung.

### B.5 Energieangaben nach § 87 GEG (Einschätzung, Quelle aus der Entwicklungsumgebung nicht abrufbar, vor Livegang durch Rechtsanwalt oder Betreiber zu bestätigen)

Liegt ein Energieausweis vor, sind in Immobilienanzeigen anzugeben: Art des Ausweises (Bedarf oder Verbrauch), Endenergiebedarf oder Endenergieverbrauch, wesentlicher Energieträger der Heizung, bei Wohngebäuden das Baujahr, bei Wohngebäuden die Effizienzklasse (bei Ausweisen, die eine Klasse enthalten). Umsetzung: Status vorhanden verlangt diese Felder blockierend; Status noch_nicht_vorhanden oder beauftragt blockiert die Veröffentlichung mit dem Hinweis, dass der Ausweis spätestens bei Besichtigung vorliegen muss und die Anzeige die Angaben enthalten muss; "wird nachgereicht" ist kein Ersatz; Status ausnahme_zu_pruefen blockiert, bis ein Admin die Ausnahme mit Begründung bestätigt (z. B. Baudenkmal, kleine Gebäude, Abbruch). Bei Grundstücken und Stellplätzen entfällt die Prüfung. Bei Gewerbe (Nichtwohngebäude) entfallen Baujahr und Klasse als Pflicht; getrennte Werte für Wärme und Strom werden als optionale Felder vorgesehen. Die Regel und ihr Stand stehen im Adminbereich sichtbar mit Datum.

### B.6 Freigabeversionen und Status

Tabelle listing_releases: listing_id, version (laufend je Objekt), payload_json (Ergebnis des Mappers zum Zeitpunkt der Freigabe), medien_json (Liste freigegebener Medien mit Prüfsumme, Reihenfolge, Titel), portale_json (ausgewählte Portal-IDs), inhalt_hash, freigegeben_von_user_id, freigegeben_at, aktion (flowfact_speichern, veroeffentlichen, deaktivieren). Übertragung und Veröffentlichung arbeiten ausschließlich mit der jüngsten Freigabeversion, nie mit dem Live-Stand. Jobs tragen die release_id und brechen ab, wenn eine neuere Version existiert oder das Objekt inzwischen deaktiviert wurde. Die Oberfläche zeigt "Unveröffentlichte Änderungen", wenn der Live-Hash vom Hash der letzten Freigabe abweicht.

PortalStatus erweitert um manuelle_freigabe_erforderlich (Fall B: Veröffentlichungsaufruf nicht autorisiert, HTTP 401 oder 403, oder Portaltyp ohne Unterstützung), deaktivierung_angefordert, deaktivierung_bestaetigt. Je Statuswechsel werden nachweis_quelle (z. B. "GET /estates/{id}/portals onlineSince", "POST /publish Antwort", "manuell"), nachweis_at und release_id gespeichert (Tabelle listing_portal_status_log).

Tabelle listing_changes: listing_id, user_id, feld, alt, neu, created_at, geschrieben über Model-Observer für Listing, ListingPrice, ListingEnergy, ListingMedia (nur Metadaten) und Freigaben. Interne Felder werden protokolliert, aber nie exportiert.

### B.7 Adressfreigabe in Texten

Bei Adressfreigabe "nur PLZ und Ort" prüft die Anwendung Überschrift, Beschreibungen und Bildtitel auf Straße und Hausnummer (Zeichenkettenvergleich, normalisiert) und blockiert die Veröffentlichung mit Hinweis auf die Fundstelle. Der PromptBuilder erhält in diesem Fall keine Straße.

### B.8 Ergänzende Tests (Masterprompt Abschnitt 28)

Stellplatz optional und verpflichtend mit korrekter Gesamtdarstellung; unbekannte Merkmale werden weder als ja noch als nein übertragen; Unterbrechen und Fortsetzen über Autosave; manuell bearbeitete Texte bleiben bei erneuter Erzeugung erhalten; ausgeblendete Hausnummer erscheint in keinem Text und keiner Übertragung; Deaktivierung während wartender Jobs verhindert Wiederveröffentlichung; Leser kann nichts ändern; Einladung und Passwort-Reset zeitlich begrenzt und einmalig; Fall B wird als manuelle Freigabe angezeigt, nicht als Fehler und nicht als Erfolg.

## C. Wellen

| Welle | Inhalt | Agenten |
| --- | --- | --- |
| 1 | Datenmodell und Domäne nach B.2, B.4, B.5, B.6 (Fable); Benutzer, Rollen, Rechte, Einladungen, Passwort-Reset (Sonnet) | 2 parallel |
| 2 | Neuer Assistent Schritte 1 bis 6 mit Autosave (Sonnet); Schritte 7 und 8, Prüfen und veröffentlichen, Dashboard, Liste, Historie, Duplizieren (Sonnet) | 2 parallel |
| 3 | Connector: Freigabeversionen, Fall B, Deaktivierung, Job-Schutz, Konflikterkennung (Fable); KI-Überarbeitungen, Vorlagenmodus, Adressprüfung, Medien drehen und Kategorien (Sonnet) | 2 parallel |
| 4 | Dokumente: CLAUDE.md, Benutzer- und Adminanleitung, Backup und Restore, Fähigkeitsmatrix, Abnahmeprotokoll (Haiku oder Sonnet); kritische Prüfung (Fable) und Behebung | 2 parallel |

## D. Stand nach Welle 4 (12.09.2026)

Umgesetzt und mit Regressionstests belegt (767 Tests, CI mit SQLite und MariaDB): Wellen 1 bis 4 einschließlich
der Behebung der 17 Befunde aus docs/pruefbericht-2026-09-12.md. Damit gelten die Abschnitte 5 bis 21, 23 bis 25,
27, 28, 30 und 31 des Masterprompts als umgesetzt, soweit sie ohne echtes FLOWFACT-Konto umsetzbar sind.

Weiterhin offen oder bewusst abweichend:

| Punkt | Stand | Begründung oder nächster Schritt |
| --- | --- | --- |
| Verifikation gegen das echte FLOWFACT-Konto (Abschnitte 22, 32) | offen | Kein Token vorhanden. docs/faehigkeitsmatrix.md führt jede Funktion als simuliert oder nicht getestet. Smoke-Test nach Freigabe des Tokens |
| Fall A oder Fall B | offen | Entscheidet sich erst am Konto; beide Wege sind implementiert und angezeigt |
| § 87 GEG (Abschnitt 13) | Einschätzung | Rechtsquelle aus der Entwicklungsumgebung nicht abrufbar; Regelwerk mit Datum im Adminbereich sichtbar, vor Livegang durch Rechtsanwalt bestätigen |
| PHP 8.4 und Livewire (Abschnitt 4) | abweichend | PHP 8.3 wegen bestätigtem IONOS-Profil, Code 8.4-kompatibel; Autosave und Interaktion ohne Build mit Vanilla-JS |
| Verpixelung von Bildern (Abschnitt 14) | nicht umgesetzt | Als Ergänzung vorgesehen; Hinweistext im Uploadschritt vorhanden |
| HEIC (Abschnitt 14) | abgelehnt mit Hinweis | Verarbeitung auf IONOS nicht garantiert; JPEG-Export empfohlen |
| Bidirektionale Synchronisation (Abschnitt 23) | bewusst nicht | Müller FLOW führt für zugeordnete Felder, Konflikte werden erkannt und abgebrochen |
| Zeit- und Klickmessung (Abschnitte 1, 32) | Vorlage vorhanden | docs/abnahmeprotokoll.md, Messung erfolgt im Abnahmetest mit echten Nutzern |
| PHPStan | nicht installiert | Als Ziel vorgesehen |
| Mobile Bedienbarkeit | Layout responsiv, kein Gerätetest | Im Abnahmetest auf Smartphone prüfen |
