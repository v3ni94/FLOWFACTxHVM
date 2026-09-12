# Adminanleitung Müller FLOW

Stand: 12.09.2026. Richtet sich an Administratoren der Hausverwaltung Müller GmbH. Für die tägliche Arbeit
mit Objekten siehe [benutzeranleitung.md](benutzeranleitung.md). Alle Angaben sind gegen den vorhandenen
Code geprüft (Routen, Controller, Konsolenbefehle, Konfiguration); Verhalten, das nur am echten FLOWFACT-
oder Anthropic-Konto sichtbar wird, ist als solches gekennzeichnet (siehe [faehigkeitsmatrix.md](faehigkeitsmatrix.md)).

## 1. Benutzer, Rollen und Rechte (`/admin/users`)

### 1.1 Benutzer einladen

Der vorgesehene Weg ist **"Benutzer einladen"** (`/admin/users/invite`): Name, E-Mail-Adresse, Rolle und das
Recht "Darf veröffentlichen" angeben, danach **"Einladung senden"**. Die Anwendung verschickt eine E-Mail mit
einem zeitlich begrenzten, einmaligen Link. Solange eine Einladung nicht angenommen oder widerrufen ist,
erscheint sie in der Liste "Offene Einladungen" mit den Aktionen **"Erneut senden"** (setzt eine neue
Gültigkeit) und **"Widerrufen"**. Alternativ steht **"Mit Initialpasswort anlegen"** (`/admin/users/create`)
zur Verfügung: Der Benutzer erhält sofort ein von Ihnen vergebenes Passwort, ohne E-Mail-Versand.

### 1.2 Rollen

| Rolle | Bedeutung |
| --- | --- |
| Administrator | Verwaltet Benutzer, FLOWFACT-Zugang, KI-Einstellungen und Vorgaben; darf jedes Objekt bearbeiten, veröffentlichen, deaktivieren und archivieren |
| Mitarbeiter | Legt Objekte an und bearbeitet sie; darf Objekte veröffentlichen und zurückziehen nur mit dem zusätzlichen Recht "Darf veröffentlichen" **und** wenn er Bearbeiter, Ersteller oder das Objekt für alle Mitarbeiter freigegeben ist |
| Lesender Benutzer | Darf alle Objekte ansehen, aber nichts anlegen, ändern, veröffentlichen oder archivieren |

Das Recht **"Darf veröffentlichen"** ist ein von der Rolle unabhängiges Kontrollkästchen bei Mitarbeitern
(bei Administratoren immer gültig, bei Lesenden Benutzern ohne Wirkung). Ohne dieses Recht kann ein
Mitarbeiter ein Objekt zwar vollständig erfassen, aber weder veröffentlichen noch eine Portalveröffentlichung
zurückziehen; die Anwendung weist den Versuch mit HTTP 403 ab.

### 1.3 Sperren und Sitzungen

**"Deaktivieren"** auf der Benutzerliste setzt den Benutzer inaktiv, zykliert sein Remember-Token (ein
vorhandenes "Angemeldet bleiben"-Cookie wird dadurch ungültig) und löscht seine bestehenden Datenbank-
Sitzungen sofort: Der Benutzer wird auch bei einer bereits laufenden Sitzung beim nächsten Seitenaufruf
abgemeldet. **"Aktivieren"** hebt die Sperre wieder auf. **"2FA zurücksetzen"** löscht Geheimnis,
Bestätigung und Wiederherstellungscodes eines Benutzers, falls dieser seinen Zweitfaktor verloren hat; ein
Administrator kann 2FA damit nur zurücksetzen, nie erzwingen.

### 1.4 Letzter Administrator

Der letzte aktive Administrator kann weder deaktiviert noch auf eine andere Rolle herabgestuft werden. Beide
Versuche werden mit einer Fehlermeldung zurückgewiesen, bevor eine Änderung gespeichert wird.

## 2. FLOWFACT-Einstellungen (`/admin/flowfact`)

### 2.1 Token und Verbindungstest

Unter **"API-Token"** hinterlegen Sie den FLOWFACT-API-Token (Header `x-ff-api-token`, ADR-008). Der Token
wird ausschließlich geschrieben: Die Ansicht zeigt nach dem Speichern nur "hinterlegt am", nie den Wert
selbst, auch nicht teilweise. **"Verbindung testen"** ruft `GET user-service/users/currentUser` auf und zeigt
bei Erfolg Anmeldename, Kontotyp und Company-ID, bei Fehler eine bereinigte Meldung ohne Token. **"Token
entfernen"** löscht den hinterlegten Token und alle bisherigen Prüfergebnisse; danach sind Übertragungen an
FLOWFACT nicht mehr möglich, bis ein neuer Token hinterlegt ist.

### 2.2 Schemata laden

**"Schemata laden"** ruft die Estate-Schemata des Kontos ab (`GET schema-service/v2/schemas?group=estates`)
und lädt zusätzlich die Felder jedes im Bereich "Konto und Schemata" hinterlegten Schemas (Felder für Miete
und Kauf getrennt einstellbar). Ohne hinterlegten Token bricht die Aktion mit einer Fehlermeldung ab.

### 2.3 Feld- und Codezuordnung

Im Bereich **"Feldzuordnung"** ordnen Sie die internen Feldnamen der Anwendung den Zielfeldern des geladenen
FLOWFACT-Schemas zu. Im Bereich **"Codezuordnung"** hinterlegen Sie die FLOWFACT-Codes für Auswahlfelder wie
Objektart und Gewerbe-Unterart; ohne Code für eine Objektart (etwa Stellplatz oder Lagerfläche) wird ein
solches Objekt zwar lokal erfasst, aber nicht an FLOWFACT übertragen (die Erfassungsseite weist Mitarbeiter
darauf mit einem Hinweistext hin).

### 2.4 Konfliktverhalten und leere Felder löschen

Der Connector unterstützt bereits serverseitig zwei Einstellungen ohne eigenes Formularfeld im Adminbereich:

- `flowfact.konfliktverhalten` (Standardwert: abbrechen): Steuert, ob eine seit der letzten Übertragung in
  FLOWFACT geänderte Entität (erkannt über `lastModifiedTimestamp`) die Übertragung abbrechen lässt oder
  überschrieben wird.
- `flowfact.leere_felder_loeschen` (Standardwert: aktiv): Steuert, ob zuvor gesendete, nun lokal geleerte
  Felder in FLOWFACT ausdrücklich gelöscht werden (`{ values: [] }`) oder unverändert bleiben.

**Befund dieser Prüfung**: Für beide Schlüssel existiert keine Eingabemöglichkeit in
`resources/views/admin/flowfact/edit.blade.php`; eine Änderung ist derzeit nur über einen direkten
Datenbankeintrag in der Tabelle `settings` möglich. `flow:check-config` zeigt das aktuelle
Konfliktverhalten zur Kontrolle an. Dies ist als offener Punkt zu behandeln, bevor der Livebetrieb beginnt.

### 2.5 Smoke-Test-Ablauf

```
php artisan flow:flowfact:smoke
php artisan flow:flowfact:smoke --write
```

Ohne Option prüft der Befehl ausschließlich lesend (Verbindung, Schemata, Suche, Portale). Mit `--write`
führt er zusätzlich die schreibenden Schritte mit einem selbst erzeugten Testobjekt (Kennung `TEST-<Zeit>`)
und einem 64x64-Pixel-Testbild aus und räumt es anschließend wieder auf; eine echte Portalveröffentlichung
ist im Befehl absichtlich nicht enthalten. Das Protokoll jedes Laufs liegt unter
`storage/logs/flowfact-smoke-<Datum>.md`. Werten Sie das Protokoll zeilenweise aus: Jede Zeile nennt den
aufgerufenen Endpunkt, die Erwartung und das tatsächliche Ergebnis; ein fehlgeschlagener Schritt bricht die
weiteren schreibenden Schritte ab, bereits angelegte Testdaten werden dennoch entfernt, soweit die
vorangegangenen Schritte das zulassen.

`php artisan flow:flowfact:schema` (ohne Option: listet alle Estate-Schemata; mit `--schema=<Name>`: zeigt
Felder, Typ, Caption sowie den Zuordnungsstatus je Feld) unterstützt die Einrichtung der Feldzuordnung.

**Wichtig**: Bislang wurde keine Funktion des Connectors gegen ein echtes FLOWFACT-Konto ausgeführt. Alle
bisherigen Teststatus in [faehigkeitsmatrix.md](faehigkeitsmatrix.md) lauten "simuliert mit Http::fake" oder
"nicht getestet". Führen Sie den Smoke-Test vor dem Livegang aus und tragen Sie das Ergebnis in die
Fähigkeitsmatrix ein.

## 3. KI-Einstellungen (`/admin/ki`)

- **Anbieter**: `fake` (Vorlagenmodus ohne externen Aufruf) oder `anthropic`.
- **Modell**: konfigurierbar, Voreinstellung `claude-opus-5` (`config/ai.php`); zusätzlich hinterlegt sind
  `claude-sonnet-5` und `claude-haiku-4-5`.
- **Schlüssel**: Der Anthropic-API-Schlüssel wird, wie der FLOWFACT-Token, ausschließlich geschrieben; die
  Ansicht zeigt nur "hinterlegt am". **"Verbindung testen"** führt einen minimalen Aufruf aus und zeigt
  Modell sowie Ein- und Ausgabetoken.
- Solange der Anbieter nicht `anthropic` ist oder kein Schlüssel hinterlegt ist, erzeugt die Anwendung für
  alle Objektbeschreibungen ausschließlich **Vorlagenentwürfe ohne externen Aufruf** (Vorlagenmodus). Die
  Seite zeigt diesen Zustand ausdrücklich an.
- **Verbrauch**: Die letzten 30 Tage, aufgeschlüsselt je Modell (Aufrufe, Eingabe- und Ausgabetoken) und je
  Zweck (Entwurf, Überarbeitung, ohne Zuordnung).
- **Kostenschätzung**: ausschließlich als **US-Dollar-Listenpreis** je Modell (`config/ai.php`,
  `preis_input_je_million_usd`, `preis_output_je_million_usd`), eine statische Näherung, keine abgerechneten
  Werte und keine Umrechnung in Euro.

## 4. Vorgaben (`/admin/vorgaben`)

- **Land** (ISO-Code, Standard DE) und **Währung** (fest EUR, nicht änderbar).
- **Ansprechpartner**: Standardansprechpartner für neue Objekte.
- **Portalvorauswahl**: getrennt für Miete und Kauf, aus den beim FLOWFACT-Konto geladenen Portalen; wird auf
  der Seite "Prüfen und veröffentlichen" als Vorauswahl angezeigt (ändert nichts an der tatsächlichen
  Portalliste des Kontos).
- **Bezeichnungsmuster**: Muster für die interne Bezeichnung mit den Platzhaltern `[Objektnummer]`,
  `[Straße]`, `[Hausnummer]`, `[Einheit]`, `[Etage]`, `[Ort]` (Standard:
  `[Objektnummer] | [Straße] | [Einheit] | [Etage]`).
- **Textbausteine**: ein Satz je Zeile, als Vorschlagshilfe für "Beschreibung Sonstiges".

## 5. Energieausweis-Ausnahmen freigeben

Steht der Energieausweisstatus eines Objekts auf "Ausnahme zu prüfen", erscheint auf der Seite "Prüfen und
veröffentlichen" für Administratoren eine eigene Karte mit Pflichtfeld "Begründung" (zum Beispiel Baudenkmal,
kleines Gebäude, Abbruch). Erst nach der Bestätigung durch einen Administrator gilt die
Energieausweisanforderung als erfüllt und die Veröffentlichung ist nicht mehr aus diesem Grund blockiert. Die
zugrunde liegende Einschätzung nach § 87 GEG (Stand 12.09.2026) ist eine Auslegung ohne abrufbare
Gesetzesquelle aus der Entwicklungsumgebung und vor dem Livegang durch einen Rechtsanwalt oder die
Geschäftsführung zu bestätigen.

## 6. Betriebsprüfung

```
php artisan flow:check-config
```

Prüft und meldet tabellarisch mit Exit-Code 1 bei Fehlern: `APP_ENV`, `APP_DEBUG` (Warnung in Produktion),
Schema von `APP_URL`, `TRUSTED_PROXIES`, Datenbankverbindung und Migrationsstatus, Beschreibbarkeit des
Medienverzeichnisses, konfigurierten Mail-Mailer, Warteschlangentreiber sowie Anzahl ausstehender und
fehlgeschlagener Jobs, das Lebenszeichen des Schedulers (Fehler erst ab mehr als 15 Minuten ohne Lauf),
FLOWFACT-Stage, Basis-URL, ob ein API-Token hinterlegt ist, sowie das aktuelle Konfliktverhalten. Geeignet für
ein eigenes Monitoring, das den Exit-Code auswertet. Details zur Einbindung als Cronjob:
[betrieb/installation.md](betrieb/installation.md) Abschnitt 10.

## 7. Was bei Übertragungsfehlern zu tun ist

1. **Protokoll lesen**: Unter "FLOWFACT-Einstellungen" zeigt der Bereich "Übertragungsprotokoll" die letzten
   30 Aufrufe mit Aktion, HTTP-Status, Erfolg und einer gekürzten Zusammenfassung (nie mit Token oder
   internen Feldern). Auf der Objektdetailseite und der Seite "Prüfen und veröffentlichen" erscheinen
   zusätzlich die letzten Einträge zu diesem Objekt.
2. **Wiederholung**: Ein fehlgeschlagener Übertragungsjob wird automatisch mit Rückstellzeiten wiederholt
   (30, 120, 300 Sekunden bei Serverfehlern; bei HTTP 429 zusätzlich mit der von FLOWFACT gemeldeten
   `Retry-After`-Zeit). Ein Authentifizierungsfehler (401/403 auf dem Übertragungsendpunkt, nicht zu
   verwechseln mit Fall B beim Veröffentlichen) lässt den Job dagegen sofort endgültig scheitern, da eine
   Wiederholung ohne geänderten Token nicht zum Erfolg führen würde; prüfen Sie in diesem Fall zuerst den
   hinterlegten Token.
3. **Konflikt**: Wurde die Entität in FLOWFACT seit der letzten Übertragung dort geändert
   (`lastModifiedTimestamp` weicht ab), verhält sich die Übertragung je nach `flowfact.konfliktverhalten`
   (siehe Abschnitt 2.4): im Standardfall bricht sie mit einer Fehlermeldung ab, statt die FLOWFACT-Seite zu
   überschreiben.
4. **Lease**: Eine laufende Übertragung sperrt das Objekt über ein Zeitfenster (`sperre_bis`) gegen einen
   zweiten, parallelen Lauf (zum Beispiel bei einem versehentlichen Doppelklick auf "In FLOWFACT speichern").
   Läuft die Sperre ohne Rückmeldung ab (etwa nach einem Absturz des Hintergrundprozesses), gibt der nächste
   Versuch die Übertragung automatisch wieder frei; ein manuelles Eingreifen in die Datenbank ist im
   Normalbetrieb nicht nötig.
