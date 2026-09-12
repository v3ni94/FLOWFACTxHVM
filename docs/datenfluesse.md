# Datenflüsse Müller FLOW

Stand: 12.09.2026. Beschreibt die externen Datenflüsse der Anwendung (Masterprompt Abschnitt 26) sowie
Speicherorte, Löschung und die datenschutzrechtlichen Prüfpunkte vor Livegang. Dieses Dokument ersetzt keine
Rechtsberatung; die datenschutzrechtliche Bewertung ist vor dem Livegang durch die Geschäftsführung oder
einen Rechtsanwalt zu bestätigen.

## 1. Externe Datenflüsse

Gegen den Code geprüft (`app/Flowfact`, `app/Services/Ai`, `app/Mail`, `app/Notifications`, `.env.example`):
Die Anwendung nimmt genau drei Arten externer Verbindungen vor. Kein weiterer ausgehender HTTP-Aufruf ist im
Code vorhanden.

### 1.1 FLOWFACT-API

| Aspekt | Angabe |
| --- | --- |
| Welche Daten | Ausschließlich veröffentlichbare Inseratsfelder aus der Positivliste `App\Domain\Listing\PublishableFields` (Adresse, Flächen, Preise, Ausstattung, Energieausweisangaben, Texte, freigegebene Medien). Interne Daten (`listing_internals`) können technisch nicht in den Payload gelangen (ADR-003), ein Test mit Markerwerten belegt dies |
| Wann | Bei den Benutzeraktionen "In FLOWFACT speichern" und "JETZT VERÖFFENTLICHEN" auf der Seite "Prüfen und veröffentlichen", bei "Auf ausgewählten Portalen deaktivieren", sowie im Hintergrund durch den fünfminütigen Portalstatus-Abgleich (`flow:portal-status`) und die zugehörigen Warteschlangen-Jobs. Niemals durch Autosave |
| Welche Zugangsdaten | Ein einziger API-Token je Konto (Header `x-ff-api-token`), verschlüsselt in der Tabelle `settings` gespeichert, nie an den Browser gesendet, nie protokolliert (ADR-008, ADR-014) |
| Protokollierung | Jeder Aufruf erzeugt einen Eintrag in `transfer_logs`; Token und interne Felder werden vor dem Speichern entfernt, Nutzdaten auf 4 KB gekürzt |

### 1.2 Anthropic-API (KI-Texte)

| Aspekt | Angabe |
| --- | --- |
| Welche Daten | Ausschließlich veröffentlichbare, bereits erfasste Objektdaten (Adresse ohne interne Zusätze, Flächen, Preise, Ausstattungsmerkmale, vorhandene Lagebeschreibung, Energieangaben). `App\Services\Ai\PromptBuilder` liest weder die Relation `internal` noch personenbezogene Felder wie Ansprechpartner, UUID oder Objektnummer. Enthält die Adressfreigabe "nur PLZ und Ort", erhält der Prompt keine Straße (Masterprompt-Abgleich B.7) |
| Wann | Ausschließlich auf ausdrückliche Anforderung eines Mitarbeiters: Textvorschlag erzeugen (Schritt 7 und 8) oder eine gezielte Überarbeitung (kürzer, sachlicher, sprachlich) anfordern. Kein automatisches Regenerieren, kein Hintergrundaufruf |
| Welche Zugangsdaten | Ein API-Schlüssel je Konto, verschlüsselt in `settings` gespeichert, nie an den Browser gesendet. Ist kein Anbieter `anthropic` mit Schlüssel hinterlegt, erzeugt die Anwendung stattdessen ausschließlich Vorlagentexte ohne externen Aufruf (Vorlagenmodus) |
| Protokollierung | Modellkennung und Tokenzahlen werden in `KiUsage` gespeichert (für die Verbrauchsanzeige im Adminbereich); der erzeugte Text selbst wird als `ListingText` gespeichert, nie an Dritte weitergegeben |

### 1.3 IONOS-SMTP (E-Mail-Versand)

| Aspekt | Angabe |
| --- | --- |
| Welche Daten | Name und E-Mail-Adresse des eingeladenen oder passwortzurücksetzenden Benutzers sowie ein einmaliger, zeitlich begrenzter Link (Token gehasht in der Datenbank, im Link nur der Klartext-Token) |
| Wann | Beim Versenden einer Benutzereinladung (`App\Mail\UserInvitationMail`, inklusive "Erneut senden") und beim Anfordern eines Passwort-Reset-Links (`App\Notifications\PasswordResetNotification`) |
| Konfiguration | Über die Laravel-Standard-Mailkonfiguration (`MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` in `shared/.env`); lokal und in Tests Voreinstellung `log` (kein tatsächlicher Versand), im Produktivbetrieb auf den SMTP-Zugang der Hausverwaltung Müller GmbH bei IONOS zu stellen |

### 1.4 Keine weiteren externen Aufrufe

Der Code enthält außerhalb der drei genannten Ziele keine weiteren ausgehenden Verbindungen (geprüft über
eine Suche nach HTTP-Aufrufen im Anwendungscode). Der Browser der Mitarbeiter erhält zu keinem Zeitpunkt den
FLOWFACT-Token, den Anthropic-Schlüssel oder SMTP-Zugangsdaten: Alle drei Verbindungen werden ausschließlich
serverseitig aufgebaut.

## 2. Speicherorte und Löschung

| Daten | Speicherort | Löschung |
| --- | --- | --- |
| Medien (Bilder, Grundrisse, Dokumente, Energieausweis) | Dateisystem außerhalb des Webroots (`shared/storage`, ADR-012), Metadaten in `listing_media` | Beim Löschen eines Mediums über die Oberfläche wird die Datei sofort entfernt; beim Archivieren eines Objekts bleiben Medien und Metadaten zu Nachweiszwecken erhalten, das Objekt ist nur noch lesbar |
| Übertragungsprotokoll (`transfer_logs`) | Datenbank | Keine automatische Löschfrist im Code hinterlegt; eine Aufbewahrungs- und Löschregel ist vor Livegang vom Betreiber festzulegen (Abschnitt 4) |
| Änderungshistorie (`listing_changes`) | Datenbank | Wie Übertragungsprotokoll: keine automatische Löschfrist, vor Livegang festzulegen |
| Freigabeversionen (`listing_releases`) | Datenbank, `payload_json` und `medien_json` als eingefrorener Stand je Freigabe | Keine automatische Löschung; wächst mit jeder Übertragung oder Veröffentlichung |
| Interne Daten (`listing_internals`) | Datenbank, getrennt von den Inseratsfeldern | Werden mit dem Objekt archiviert, nie an FLOWFACT oder die KI übertragen |
| Sitzungen | Datenbank (`sessions`) im Produktivbetrieb | Werden beim Deaktivieren eines Benutzers sofort gelöscht (Abschnitt 1.3 der Adminanleitung), sonst nach Ablauf der konfigurierten Sitzungsdauer |

## 3. Verantwortlichkeiten

- **Betreiber (Hausverwaltung Müller GmbH)**: verantwortlich für den Vertrag mit FLOWFACT und Anthropic, für
  die Vergabe und Rotation der Zugangsdaten, für die Freigabe eines Livegangs und für die Entscheidung über
  Aufbewahrungsfristen (Abschnitt 4).
- **Administratoren der Anwendung**: verantwortlich für die korrekte Hinterlegung der Zugangsdaten, für die
  Vergabe von Benutzerrollen und dem Recht "Darf veröffentlichen", sowie für das Auslösen und Auswerten des
  Smoke-Tests vor dem Livegang.
- **Mitarbeiter**: verantwortlich dafür, dass sie nur Daten erfassen, die tatsächlich veröffentlicht werden
  sollen, und dass Bilder vor dem Hochladen auf unerwünschte Standort- oder andere Metadaten geprüft wurden
  (siehe [benutzeranleitung.md](benutzeranleitung.md) Abschnitt 7).

## 4. Vor dem Livegang datenschutzrechtlich zu prüfen

Ohne dass dieses Dokument selbst eine Rechtsberatung darstellt, sind vor dem Livegang mindestens folgende
Punkte zu klären, üblicherweise mit einem Rechtsanwalt oder Datenschutzbeauftragten:

1. **Auftragsverarbeitung mit FLOWFACT**: Ob und in welcher Form ein Auftragsverarbeitungsvertrag (Art. 28
   DSGVO) mit dem FLOWFACT-Anbieter besteht oder abzuschließen ist, da personenbezogene Daten (zumindest
   Ansprechpartnerdaten im Inserat, sofern diese über den Mapper übertragen werden) an FLOWFACT übermittelt
   werden.
2. **Auftragsverarbeitung mit Anthropic**: Ob ein Auftragsverarbeitungsvertrag mit dem Anbieter der
   Anthropic-API besteht oder abzuschließen ist, auch wenn nach Code-Prüfung keine personenbezogenen Felder
   in den Prompt gelangen; zu klären bleibt insbesondere der Umgang mit den in Abschnitt 1.2 genannten
   veröffentlichbaren Objektdaten als Auftragsdaten.
3. **Löschfristen**: Verbindliche Aufbewahrungsfristen für Übertragungsprotokoll, Änderungshistorie,
   Freigabeversionen und archivierte Objekte festlegen; im Code ist derzeit keine automatische Löschung nach
   Ablauf einer Frist hinterlegt (siehe Abschnitt 2).
4. **Betroffenenrechte**: Verfahren festlegen, wie Auskunfts-, Berichtigungs- und Löschersuchen betroffener
   Personen (insbesondere zu Ansprechpartnerdaten und Mieter- oder Interessentenanfragen außerhalb dieser
   Anwendung) bearbeitet werden.
5. **Auftragsverarbeitung IONOS**: Ob der bestehende Hostingvertrag mit IONOS die Verarbeitung
   personenbezogener Daten (Datenbank, Medien, Protokolle, E-Mail-Versand) datenschutzrechtlich abdeckt.

Diese Anwendung selbst trifft keine Aussage darüber, ob die genannten Verträge bestehen; sie stellt lediglich
fest, welche Daten technisch wohin fließen.
