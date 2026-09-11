# Offene Punkte und Annahmen

Stand: 11.09.2026. Diese Liste ist vor dem Livegang abzuarbeiten. Annahmen sind so getroffen, dass sie ohne
Umbau korrigierbar sind.

## Vom Betreiber zu klären

| Nr. | Punkt | Auswirkung | Getroffene Annahme |
| --- | --- | --- | --- |
| 1 | Der Masterprompt "Müller FLOW" (Abschnitte A bis L) lag der Entwicklungssitzung nicht vor, nur Abschnitt M. | Fachliche Vorgaben zu Feldern, Portalen, Design und Freigaben konnten nicht übernommen werden. | Anforderungen wurden aus Abschnitt M abgeleitet und im Datenvertrag festgeschrieben. Bitte Masterprompt nachreichen, Abweichungen werden dann eingearbeitet. |
| 2 | Erlaubt der Tarif FLOWFACT Essential (Kundennummer laut Unterlagen D22180) die Erzeugung eines API-Tokens im FLOWFACT-Konto? | Ohne Token ist der Connector nicht nutzbar; Alternative wäre der Cognito-Anmeldeweg. | Token-Weg umgesetzt (ADR-008). Prüfung am Konto erforderlich, ggf. Rückfrage bei FLOWFACT. |
| 3 | Welche Portale sind in FLOWFACT angebunden und beauftragt (ImmoScout24, Immowelt, Kleinanzeigen, eigene Website)? | Portalauswahl im Veröffentlichungsschritt. | Portale werden zur Laufzeit aus FLOWFACT gelesen, keine feste Liste. |
| 4 | Hosting für flowfact.muellerhv.de: IONOS-Tarif, PHP-Version, MariaDB-Version, Cron-Fähigkeit (CLI oder nur URL), Document Root einstellbar? | Betriebsprofil, Cron-Takt, Deploymentweg. | IONOS Webhosting wie Smart Abrechnen, PHP 8.3, MariaDB, Cron per CLI, Fallback URL-Cronjob vorhanden. |
| 5 | Firmendaten für das Inserat (Anbieter, Anschrift, Telefon, E-Mail, Ansprechpartner-Regel). | Pflichtangaben im Portal. | Vorbelegt aus dem HVM-CI-Skill, im Adminbereich änderbar. |
| 6 | KI-Anbieter und Modell für Objektbeschreibungen, Datenschutzfreigabe (keine personenbezogenen Daten im Prompt). | Kosten, Qualität, Compliance. | Anthropic Messages API, Modell konfigurierbar, Standard ein wirtschaftliches Modell. Prompt enthält nur Objektfelder, keine internen Daten. |
| 7 | Soll eine Freigabe durch eine zweite Person vor Veröffentlichung verpflichtend sein? | Zusätzlicher Status "zur Freigabe". | Nicht verpflichtend. Jeder Mitarbeiter darf veröffentlichen, jede Veröffentlichung wird protokolliert. |
| 8 | Zuordnung von Objekten zum Verwaltungsbestand (WEG, Mietobjekt) über eine Referenz? | Feld `verwaltungsobjekt_referenz` intern. | Freitextfeld, keine Anbindung an den Objektstammdaten-Skill. |

## Technisch offen bis zum ersten Smoke-Test am echten Konto

Siehe flowfact-api.md, Abschnitt 9 und 10. Insbesondere: exakte Schemanamen der Estate-Entität im Konto,
Feldnamen für Miet- und Kaufpreise, Verhalten der Portalveröffentlichung (sofort oder über Freigabe in
FLOWFACT), Antwortformat der Statusabfrage, Limits für Bildgröße und Anzahl.

## Bewusst nicht umgesetzt

| Punkt | Grund |
| --- | --- |
| Mandantenfähigkeit | Ein Anbieter, ADR-013 |
| Import bestehender FLOWFACT-Objekte | Nicht Ziel der Anwendung; kann später als Leseabgleich ergänzt werden |
| Exposé-PDF | FLOWFACT erzeugt Exposés; keine doppelte Funktion |
| Automatische Veröffentlichung nach Erfassung | Widerspricht der Regel, dass Veröffentlichung eine bestätigte Benutzeraktion ist |

## Aus der kritischen Prüfung vom 11.09.2026 (docs/pruefbericht-2026-09-11.md)

Die Befunde 1 bis 18 wurden nach der Prüfung behoben oder dokumentiert. Am echten Konto bleiben zu verifizieren:

| Punkt | Warum offen | Verhalten der Anwendung bis zur Klärung |
| --- | --- | --- |
| Löschen geleerter Felder per PATCH mit leerer Werteliste (`{"values": []}`) | Serversemantik nicht aus dem SDK belegbar | Einstellung `flowfact.leere_felder_loeschen` (Standard an); bei Fehlern am Konto abschaltbar |
| Titeländerung von Bildern über `PATCH /items/{id}` (JSON-Patch auf `title`) | Feldpfad im SDK belegt, Serverantwort nicht | Wird gesendet, Fehler erscheinen im Übertragungsprotokoll |
| Wiedererkennung bereits hochgeladener Bilder über den deterministischen Dateinamen | Ob FLOWFACT den Dateinamen unverändert zurückliefert, ist zu prüfen | Schutz gegen Doppeluploads nach Zeitüberschreitung; ohne Wiedererkennung entsteht schlimmstenfalls ein doppeltes Bild, keine doppelte Immobilie |
| Wechsel der Vermarktungsart nach der Übertragung | Erfordert in FLOWFACT einen Schemawechsel | Übertragung wird mit klarer Meldung verweigert, Objekt in FLOWFACT manuell prüfen oder neues Objekt anlegen |

Zusätzlich behoben ohne Kontobezug: Die ursprüngliche `composer.lock` war für PHP 8.4 aufgelöst und hätte die
Installation auf PHP 8.3 blockiert; die Abhängigkeiten wurden für PHP 8.3 neu aufgelöst (Plattformprüfung bestanden).
