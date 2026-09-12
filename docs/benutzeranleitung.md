# Benutzeranleitung Müller FLOW

Stand: 12.09.2026. Richtet sich an Mitarbeiter der Hausverwaltung Müller GmbH, die Objekte erfassen,
Bilder hochladen, Texte übernehmen und Objekte über FLOWFACT veröffentlichen. Für Verwaltungsaufgaben
(Benutzer, FLOWFACT-Einstellungen, KI-Einstellungen, Vorgaben) siehe [adminanleitung.md](adminanleitung.md).

Alle Bildschirmausschnitte sind als Textbeschreibung angegeben (Platzhalter `[Screenshot: ...]`), da diese
Anleitung ohne laufende Testumgebung gegen das echte FLOWFACT-Konto erstellt wurde.

## 1. Anmeldung

### 1.1 Anmelden

Rufen Sie `/login` auf. Das Formular verlangt E-Mail-Adresse und Passwort, zusätzlich die Option
"Angemeldet bleiben". Ein Zweitfaktor (2FA) ist **nicht verpflichtend**: Wenn Sie 2FA nicht eingerichtet
haben, gelangen Sie nach Eingabe von E-Mail-Adresse und Passwort direkt in die Anwendung.

[Screenshot: Anmeldeformular mit den Feldern E-Mail-Adresse, Passwort, Kontrollkästchen "Angemeldet bleiben"
und der Schaltfläche "Anmelden"]

### 1.2 Optionale Zweitfaktor-Authentifizierung (2FA)

Haben Sie 2FA in Ihrem Konto aktiviert, verlangt die Anmeldung nach Passwort zusätzlich den Code aus Ihrer
Authenticator-App (`/two-factor/challenge`).

Sie aktivieren oder deaktivieren 2FA selbst unter "Konto" (`/account`):

1. "Einrichtung starten" erzeugt einen Schlüssel, den Sie in einer Authenticator-App hinzufügen (per
   Schlüssel-URI oder durch Abtippen des angezeigten Schlüssels).
2. Geben Sie den angezeigten Code ein und bestätigen Sie mit "Zweitfaktor bestätigen".
3. Nach der Bestätigung erhalten Sie einmalig Wiederherstellungscodes. Bewahren Sie diese sicher auf, jeder
   Code lässt sich einmal anstelle des Zweitfaktors verwenden.

Ist 2FA aktiv, können Sie unter "Konto" mit Ihrem aktuellen Passwort neue Wiederherstellungscodes erzeugen
oder den Zweitfaktor wieder deaktivieren. Ein Administrator kann Ihren Zweitfaktor zurücksetzen, ihn aber
nicht erzwingen. Ohne 2FA werden Sie nie ausgesperrt.

[Screenshot: Kontoseite mit Karte "Zweitfaktor (2FA)" im jeweiligen Zustand: nicht aktiviert, Einrichtung
offen mit Schlüssel-URI, oder aktiv mit den Schaltflächen "Neue Wiederherstellungscodes erzeugen" und
"Zweitfaktor deaktivieren"]

### 1.3 Passwort vergessen

Unter `/passwort-vergessen` geben Sie Ihre E-Mail-Adresse ein. Besteht zu dieser Adresse ein Konto, erhalten
Sie einen zeitlich begrenzten Link zum Zurücksetzen. Über diesen Link (`/passwort-zuruecksetzen/{token}`)
vergeben Sie ein neues Passwort (mindestens 10 Zeichen, Groß- und Kleinbuchstaben sowie eine Ziffer).

### 1.4 Einladung annehmen

Ein Administrator kann Sie per E-Mail einladen. Der Einladungslink führt zu `/einladung/{token}` und zeigt
Ihren Namen und Ihre E-Mail-Adresse. Sie vergeben dort ein Passwort (dieselben Anforderungen wie oben) und
werden anschließend automatisch angemeldet. Der Link ist zeitlich begrenzt und nur einmal verwendbar; ist er
abgelaufen oder bereits verwendet, zeigt die Seite eine Fehlermeldung. Bitten Sie in diesem Fall einen
Administrator, die Einladung erneut zu versenden.

## 2. Dashboard und "Müller FLOW starten"

Nach der Anmeldung führt Sie die Anwendung zu `/app/dashboard`. Oben rechts befindet sich die große
Schaltfläche **"Müller FLOW starten"**: Sie legt sofort einen neuen Entwurf an (zunächst mit den
Voreinstellungen Vermietung/Wohnung, die Sie im ersten Schritt anpassen) und öffnet den ersten Schritt des
Erfassungsassistenten. Ist Ihre Anmeldung als Lesender Benutzer eingerichtet, erscheint diese Schaltfläche
nicht, da Lesende Benutzer keine Objekte anlegen dürfen.

Das Dashboard zeigt vier Kennzahlen (Meine Entwürfe, Alle Immobilien, Veröffentlichungen aktiv,
Übertragungsfehler) als anklickbare Kacheln zur gefilterten Objektliste, darunter eine Tabelle "Meine
Entwürfe" mit Objektnummer, Titel, Bearbeitungsstatus und Übertragungsstatus. Meldet sich die
Hintergrundverarbeitung (Scheduler) seit mehreren Minuten nicht mehr, erscheint ein Warnhinweis.

[Screenshot: Dashboard mit Schaltfläche "Müller FLOW starten", vier Kennzahlkacheln und der Tabelle
"Meine Entwürfe"]

## 3. Die acht Schritte des Erfassungsassistenten

Jedes Objekt durchläuft acht feste Schritte (`/app/objekte/{id}/schritt/{1..8}`), sichtbar als Fortschrittsleiste
oben auf jeder Schrittseite. Ein Schritt mit noch offenen Pflichtangaben ist in der Leiste markiert. Über die
Leiste können Sie jederzeit zu einem anderen Schritt springen.

[Screenshot: Fortschrittsleiste mit acht Schritten und dem zusätzlichen Punkt "Prüfen", der aktuelle Schritt
hervorgehoben, Schritte mit offenen Pflichtangaben mit Hinweiszeichen]

1. **Vermietung oder Verkauf**: Vermarktungsart (Miete oder Kauf), Objektart (Wohnung, Haus,
   Mehrfamilienhaus, Grundstück, Stellplatz, Gewerbe mit Unterart Büro/Laden/Lager/Sonstiges), zuständiger
   Mitarbeiter, öffentlicher Ansprechpartner, Verfügbarkeit, Nutzungsstatus.
2. **Adresse und Lage**: Straße, Hausnummer, Adresszusatz, Postleitzahl, Ort, Stadtteil, Land, Adressfreigabe
   (vollständig oder nur PLZ und Ort); zusätzlich in einer aufklappbaren Karte die internen Angaben
   (Gebäudebezeichnung, Einheitsnummer, Lage im Gebäude, Eigentümer- und Verwaltungsdaten), deutlich als
   "nicht Teil des Inserats" gekennzeichnet. Eine Übernahmefunktion kopiert Gebäudedaten aus einem bereits
   erfassten Objekt derselben Postleitzahl in leere Felder.
3. **Flächen und Objektdaten**: nur die zur gewählten Objektart passenden Felder (Wohnfläche, Nutzfläche,
   Gewerbefläche, Grundstücksfläche, Zimmer mit halben Werten, Schlafzimmer, Badezimmer, Etage, Etagen
   gesamt, Baujahr, Modernisierungsjahr, Zustand).
4. **Preise und Heizung**: Preisfelder je Vermarktungsart, Heizung (Art, Energieträger, Wärmeabgabe,
   Warmwasserbereitung), Kostenstruktur der Heizkosten (siehe Abschnitt 4), Stellplatzmodus (siehe
   Abschnitt 5), Kaution, Provision mit Bestätigung.
5. **Ausstattung und Energieausweis**: Ausstattungsmerkmale je Objektart als Ja/Nein/Nicht bekannt,
   Stellplatztyp und -anzahl, Einbauküche mit Mitvermietung (nur bei Miete), Energieausweisstatus (siehe
   Abschnitt 6).
6. **Bilder und Unterlagen**: Hochladen, Kategorien, Freigabe, Drehen, Titelbild, Sortierung (siehe
   Abschnitt 7).
7. **Überschrift und interne Bezeichnung**: Vorschläge für die Überschrift aus bestätigten Daten, die interne
   Bezeichnung nach einem im Adminbereich hinterlegten Muster, die technische Referenz (Objektnummer, UUID)
   nur lesend.
8. **Beschreibungen**: Objekt, Ausstattung, Lage, Sonstiges, mit manueller Eingabe oder KI-Vorschlag (siehe
   Abschnitt 8).

Abschluss: **Prüfen und veröffentlichen** (siehe Abschnitt 9), erreichbar über den Eintrag "Prüfen" in der
Fortschrittsleiste oder automatisch nach "Weiter" im letzten Schritt.

Jeder Schritt bietet die Schaltflächen **Zurück**, **Entwurf speichern** und **Weiter**. "Entwurf speichern"
und "Zurück" speichern ohne Pflichtfeldprüfung (`formnovalidate`); "Weiter" prüft die Eingaben des aktuellen
Schritts und führt zum nächsten Schritt beziehungsweise, im achten Schritt, zur Seite "Prüfen und
veröffentlichen".

### 3.1 Autosave-Anzeige

Unabhängig von den Schaltflächen speichert jeder Schritt automatisch, sobald Sie 1,5 Sekunden nicht mehr
getippt haben. Ein Textfeld unterhalb der Schrittleiste zeigt den Zustand an: während der Übertragung, nach
erfolgreicher Bestätigung durch den Server mit Uhrzeit, oder bei einem Fehler mit Hinweis. Solange keine
Bestätigung vom Server vorliegt, bleiben ungespeicherte Änderungen sichtbar markiert. Autosave löst nie eine
Übertragung an FLOWFACT oder eine Veröffentlichung aus.

[Screenshot: Autosave-Anzeige unterhalb der Schrittschaltflächen mit dem Text "Gespeichert um HH:MM:SS"]

### 3.2 Kacheln und Ja/Nein/Nicht bekannt

Auswahlfelder mit wenigen Optionen (zum Beispiel Vermarktungsart, Objektart, Stellplatzmodus) erscheinen als
große, gut antippbare Kacheln. Ausstattungsmerkmale in Schritt 5 sind grundsätzlich dreiwertig: **Ja**,
**Nein** oder **Nicht bekannt**. Ein nicht bekanntes Merkmal wird niemals als Ja oder Nein an FLOWFACT
übertragen, es bleibt schlicht offen.

## 4. Kostenstruktur der Heizkosten

In Schritt 4 legen Sie bei Vermietung fest, wie die Heizkosten abgerechnet werden. Die Warmmiete wird immer
serverseitig berechnet, nie aus Ihrer Eingabe übernommen. Drei Fälle:

1. **Heizkosten enthalten**: Die Nebenkosten enthalten die Heizkosten bereits. Warmmiete = Kaltmiete +
   Nebenkosten. Beispiel: Kaltmiete 800,00 EUR, Nebenkosten 300,00 EUR (davon Heizkosten bereits enthalten)
   ergibt Warmmiete 1.100,00 EUR.
2. **Heizkosten zusätzlich**: Die Heizkosten werden getrennt von den Nebenkosten erfasst und addiert.
   Warmmiete = Kaltmiete + Nebenkosten + Heizkosten. Beispiel: Kaltmiete 800,00 EUR, Nebenkosten 200,00 EUR,
   Heizkosten 100,00 EUR ergibt Warmmiete 1.100,00 EUR. Fehlen die Heizkosten in diesem Fall, berechnet die
   Anwendung die Warmmiete vorläufig ohne sie (Kaltmiete + Nebenkosten) und zeigt den Hinweis "Heizkosten
   nicht angegeben", den Sie vor der Veröffentlichung ausdrücklich bestätigen müssen.
3. **Eigener Versorgungsvertrag** (nur bei dezentraler Versorgung): Sie schließen selbst einen
   Versorgungsvertrag mit dem Energieanbieter ab, es werden keine Heizkosten über die Nebenkosten
   abgerechnet. Warmmiete = Kaltmiete + Nebenkosten. Beispiel: Kaltmiete 800,00 EUR, Nebenkosten 150,00 EUR
   ergibt Warmmiete 950,00 EUR, im Inserat erscheint der Hinweis "Heizkosten werden direkt mit dem Versorger
   abgerechnet und sind nicht enthalten."

Stellplatzmiete und Kaution fließen nie in die Warmmiete ein und werden getrennt ausgewiesen. Bei
"Heizkosten zusätzlich" darf der eingegebene Heizkostenbetrag die Nebenkosten nicht übersteigen, sonst
weist das Formular den Eintrag zurück.

## 5. Stellplatzmodus

Bei Objekten mit Stellplatz wählen Sie in Schritt 4 einen von vier Modi:

- **Kein Stellplatz**
- **Optional hinzubuchbar**: eigener Betrag, wird getrennt ausgewiesen, fließt nicht in die Warmmiete
- **Verpflichtend, im Preis enthalten**: kein eigener Betrag erfassbar
- **Verpflichtend, zusätzlich zu zahlen**: eigener Betrag, wird getrennt ausgewiesen

Stellplatztyp (Garage, Tiefgarage, Außenstellplatz, Carport, Duplex) und Anzahl erfassen Sie in Schritt 5.

## 6. Energieausweisstatus und Folgen

In Schritt 5 wählen Sie bei Objekten, die einen Energieausweis benötigen (nicht bei Grundstücken und
Stellplätzen), einen der folgenden Status:

- **Vorhanden**: Sie geben zusätzlich Ausweisart, Kennwert, Energieträger und, bei Wohngebäuden,
  Baujahr und Effizienzklasse an. Nur mit vollständigen Angaben kann veröffentlicht werden.
- **Noch nicht vorhanden** oder **Beauftragt**: Die Veröffentlichung ist blockiert. Der Ausweis muss
  spätestens bei der Besichtigung vorliegen; "wird nachgereicht" ist kein Ersatz.
- **Ausnahme zu prüfen**: Die Veröffentlichung ist blockiert, bis ein Administrator die Ausnahme mit
  Begründung bestätigt (zum Beispiel Baudenkmal, kleines Gebäude, Abbruch). Diese Bestätigung erfolgt auf der
  Seite "Prüfen und veröffentlichen".

Diese Einschätzung beruht auf § 87 GEG und ist vor dem Livegang durch einen Rechtsanwalt oder die
Geschäftsführung zu bestätigen (siehe [datenvertrag.md](datenvertrag.md), [masterprompt-abgleich.md](masterprompt-abgleich.md) Abschnitt B.5).

## 7. Bilder und Unterlagen

In Schritt 6 laden Sie Dateien in einer der Kategorien **Bild**, **Grundriss**, **Dokument** oder
**Energieausweis** hoch (erlaubt: JPEG, PNG, WebP, PDF; maximal 15 MB je Datei, maximal 40 Dateien je
Objekt).

- **Freigabe**: Bilder und Grundrisse sind standardmäßig freigegeben (Teil des Inserats). Dokumente und der
  Energieausweis sind standardmäßig **nicht** freigegeben und bleiben intern, bis Sie sie ausdrücklich
  freigeben.
- **Drehen**: Jedes Bild lässt sich um 90 Grad im oder gegen den Uhrzeigersinn drehen.
- **Titelbild**: Das erste Bild in der Sortierreihenfolge ist das Titelbild; Sie ändern die Reihenfolge durch
  Verschieben.
- **HEIC-Hinweis**: Dateien im Format HEIC/HEIF (typisch bei iPhone-Fotos ohne Umwandlung) werden derzeit
  nicht unterstützt. Die Anwendung zeigt den Hinweis "HEIC wird derzeit nicht unterstützt, bitte als JPEG
  exportieren." Exportieren Sie das Bild vor dem Hochladen als JPEG.
- **Standortdatenhinweis**: Bilder werden serverseitig verarbeitet und außerhalb des öffentlichen
  Webverzeichnisses gespeichert; laut Architekturentscheidung ADR-012 werden sie für die Übertragung auf
  Portalgröße verkleinert. Prüfen Sie vor dem Hochladen dennoch, ob Ihre Fotos Standortdaten (GPS-Metadaten)
  enthalten, die Sie nicht veröffentlichen möchten, und entfernen Sie diese bei Bedarf bereits auf dem
  Aufnahmegerät.

[Screenshot: Medienübersicht mit Kategorien als Reiter, Vorschaubildern, Schaltflächen zum Drehen und zur
Freigabe je Datei sowie einem Hinweis auf das Titelbild]

## 8. Überschrift und Textentwürfe

### 8.1 Vorlagenmodus und KI

In Schritt 8 (und für die Überschrift bereits in Schritt 7) können Sie Texte manuell eingeben oder einen
Vorschlag erzeugen lassen. Ist im Adminbereich kein KI-Anbieter mit Schlüssel hinterlegt, arbeitet die
Anwendung im **Vorlagenmodus**: Die Vorschläge entstehen aus festen Textbausteinen ohne externen Aufruf, die
Anwendung kennzeichnet dies ausdrücklich ("Es handelt sich um Vorlagenentwürfe ohne KI."). Ist ein Anbieter
mit Schlüssel hinterlegt, erzeugt die Anwendung die Vorschläge über die Anthropic-API aus Ihren bereits
erfassten, veröffentlichbaren Daten; interne Felder gelangen nie in den Text.

Ein Vorschlag überschreibt nie automatisch ein vorhandenes Feld. Erst wenn Sie einen Vorschlag ausdrücklich
**übernehmen**, wird er in das Objektfeld geschrieben.

### 8.2 Überarbeiten

Zu einem vorhandenen Text können Sie gezielt eine Überarbeitung anfordern: **kürzer**, **sachlicher** oder
**sprachlich verbessern**. Das Ergebnis erscheint als neuer Vorschlag, das aktuelle Feld bleibt bis zur
ausdrücklichen Übernahme unverändert.

### 8.3 Prüfbedürftig

Ändern Sie nach der Übernahme eines Textes noch einmal die zugrunde liegenden Objektdaten (zum Beispiel
Fläche oder Preis), kennzeichnet die Anwendung den übernommenen Text als **prüfbedürftig**: er wurde auf
Basis eines inzwischen veralteten Datenstands erzeugt oder eingegeben. Ein von Ihnen manuell bearbeiteter
Text bleibt dabei immer erhalten und wird nie automatisch durch eine neue Erzeugung überschrieben.

## 9. Prüfen und veröffentlichen

Über den Eintrag "Prüfen" in der Fortschrittsleiste oder die Schaltfläche "Prüfen und veröffentlichen" auf
der Objektübersicht gelangen Sie zur zusammenfassenden Seite. Sie zeigt Bearbeitungsstatus,
Übertragungsstatus und den Portalstatus in einer eigenen Kopfzeile sowie eine Vorschau der Inhalte, wie sie
übertragen würden (die Darstellung im jeweiligen Portal kann abweichen).

### 9.1 Die vier Prüfebenen

Darunter listet die Seite Befunde in vier Karten, je nach Ebene:

- **Intern**: interne Pflichtangaben der Anwendung.
- **FLOWFACT**: Pflichtfelder laut dem im Adminbereich hinterlegten Kontoschema.
- **Portal**: Pflichtangaben des jeweiligen Zielportals (zum Beispiel Energieangaben).
- **Gesetzlich**: gesetzliche Pflichtangaben, insbesondere die Energieausweisangaben nach § 87 GEG.

Jeder Befund ist entweder **blockierend** (verhindert die Veröffentlichung) oder ein **Hinweis** (muss vor
der Veröffentlichung ausdrücklich bestätigt werden, verhindert sie aber nicht von sich aus). Enthält ein
freigegebener Text bei der Adressfreigabe "nur PLZ und Ort" dennoch Straße oder Hausnummer, blockiert dies
die Veröffentlichung mit Angabe der Fundstelle (Überschrift oder welche Beschreibung).

### 9.2 Portalauswahl

Im Bereich "Wo soll das Objekt veröffentlicht werden?" wählen Sie die Zielportale aus dem FLOWFACT-Konto aus
Kontrollkästchen aus, oder markieren "Alle verfügbaren Portale auswählen". Offene Hinweise müssen Sie hier
einzeln bestätigen, bevor die Schaltfläche **JETZT VERÖFFENTLICHEN** aktiv wird.

### 9.3 Die drei Aktionen

- **In FLOWFACT speichern**: legt das Objekt in FLOWFACT an oder aktualisiert es, veröffentlicht aber auf
  keinem Portal. Erfordert eine vollständige Vollständigkeitsprüfung.
- **JETZT VERÖFFENTLICHEN**: fordert die Veröffentlichung auf den ausgewählten Portalen an. Erfordert
  Vollständigkeit, bestätigte Hinweise und eine korrekte Adressfreigabe.
- **Auf ausgewählten Portalen deaktivieren**: zieht die Veröffentlichung auf den markierten Portalen zurück
  (nur sichtbar, wenn bereits Portalveröffentlichungen bestehen).

### 9.4 Was danach passiert

- **Status je Portal**: Nach "JETZT VERÖFFENTLICHEN" zeigt jedes Portal zunächst "Bestätigung ausstehend".
  Erst wenn FLOWFACT das Objekt beim Rücklesen tatsächlich als online meldet, wechselt der Status auf
  "Aktiv". Das bloße Absenden der Veröffentlichungsanforderung setzt nie selbst "Aktiv".
- **Fall B: "Portalveröffentlichung in FLOWFACT abschließen"**: Ist der Veröffentlichungsaufruf für ein
  Portal nicht autorisiert (zum Beispiel weil der API-Zugang keine Rechte für dieses Portal hat), meldet die
  Anwendung ehrlich: "Objekt ist vollständig in FLOWFACT vorbereitet. Portalveröffentlichung in FLOWFACT
  abschließen." Das ist weder ein Fehler noch ein Erfolg; Sie oder ein Administrator schließen die
  Veröffentlichung in diesem Fall direkt in FLOWFACT ab.
- **Deaktivierung**: Nach "Auf ausgewählten Portalen deaktivieren" steht der Portalstatus zunächst auf
  "Deaktivierung angefordert" und wechselt nach Bestätigung durch FLOWFACT auf "Deaktivierung bestätigt".
- **Wiederveröffentlichung nach Änderung**: Ändern Sie ein Objekt nach einer Veröffentlichung erneut, zeigt
  die Kopfzeile "Unveröffentlichte Änderungen". Diese Änderungen werden erst mit einer erneuten,
  ausdrücklichen Aktion ("In FLOWFACT speichern" oder "JETZT VERÖFFENTLICHEN") übertragen; es gibt keine
  automatische Wiederveröffentlichung im Hintergrund.

[Screenshot: Seite "Prüfen und veröffentlichen" mit Statuskopfzeile, Vorschau, den vier Prüfebenen-Karten,
der Portalauswahl mit Schaltfläche "JETZT VERÖFFENTLICHEN" und dem Aktionsbereich mit "In FLOWFACT
speichern"]

## 10. Duplizieren

Auf der Objektdetailseite steht die Schaltfläche "Duplizieren" zur Verfügung. Sie erzeugt ein neues,
unabhängiges Objekt im Bearbeitungsstatus "Entwurf" mit denselben Inseratsfeldern, Preisen, Energieausweis-
und internen Daten sowie physisch kopierten Mediendateien. Nicht übernommen werden Überschrift, interne
Bezeichnung, Nutzungsstatus, FLOWFACT-Verknüpfung, Portalveröffentlichungen, Freigabeversionen, KI-Texte und
die Provisionsbestätigung; die Anwendung weist nach dem Duplizieren ausdrücklich darauf hin, was nicht
übernommen wurde.

## 11. Historie

Über die Schaltfläche "Historie" auf der Objektdetailseite sehen Sie die protokollierten Feldänderungen mit
altem und neuem Wert, Zeitpunkt und Benutzer. Interne Felder (aus Schritt 2) erscheinen in der Historie nur,
wenn Sie das Objekt bearbeiten dürfen.

## 12. Typische Fehlermeldungen und ihre Lösung

| Meldung | Ursache | Lösung |
| --- | --- | --- |
| "Das Objekt ist nicht vollständig und kann nicht veröffentlicht werden. Es fehlen: ..." | Blockierende Pflichtangaben offen | Die genannten Felder in den verlinkten Schritten ergänzen |
| "Bitte bestätigen Sie vor der Veröffentlichung alle Hinweise: ..." | Nicht blockierende Hinweise wurden nicht angehakt | Auf der Seite "Prüfen und veröffentlichen" jedes Kontrollkästchen unter den Hinweisen setzen |
| "Die Adressfreigabe erlaubt nur PLZ und Ort, folgende Texte enthalten dennoch Straße oder Hausnummer: ..." | Adressfreigabe auf "nur PLZ und Ort" gesetzt, ein Text nennt aber Straße oder Hausnummer | Den genannten Text in Schritt 7 oder 8 anpassen oder die Adressfreigabe ändern |
| "HEIC wird derzeit nicht unterstützt, bitte als JPEG exportieren." | Hochgeladene Datei ist im Format HEIC/HEIF | Das Bild vor dem Hochladen als JPEG exportieren |
| "Objekt ist vollständig in FLOWFACT vorbereitet. Portalveröffentlichung in FLOWFACT abschließen." | Fall B: kein Veröffentlichungsrecht für dieses Portal über die API | Kein Fehler; Veröffentlichung direkt in FLOWFACT abschließen oder einen Administrator informieren |
| "Es sind keine Portale verfügbar." | Kein FLOWFACT-Token hinterlegt oder keine Portale im Konto konfiguriert | Einen Administrator bitten, den FLOWFACT-Zugang unter "FLOWFACT-Einstellungen" einzurichten |
| Autosave-Anzeige zeigt einen Fehler statt "Gespeichert" | Ein eingegebener Wert ist ungültig (zum Beispiel ein unmögliches Baujahr) | Feld korrigieren, danach speichert Autosave wieder automatisch |
| "Bitte markieren Sie das Objekt zuerst als bereit." | Veröffentlichung wurde versucht, während der Bearbeitungsstatus noch "Entwurf" ist | Auf der Seite "Prüfen und veröffentlichen" zunächst "Als bereit markieren" wählen |
| Zugriff auf ein Objekt oder eine Aktion wird abgelehnt (403) | Fehlende Berechtigung (zum Beispiel Lesender Benutzer, oder Mitarbeiter ohne Zuordnung und ohne Recht "Veröffentlichen und deaktivieren") | Einen Administrator bitten, die Zuordnung oder Rolle zu prüfen (siehe [adminanleitung.md](adminanleitung.md)) |
