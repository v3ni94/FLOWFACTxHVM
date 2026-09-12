# Abnahmeprotokoll Müller FLOW

Stand: 12.09.2026. Vorlage für den Abnahmetest nach Masterprompt Abschnitt 1 (Ablauf) und Abschnitt 32
(Abnahmekriterien), ergänzt um die Pflichttests aus Masterprompt Abschnitt 28. Dieses Dokument ist eine
**Vorlage**: Ergebnis, Datum und Prüfer sind bei jedem Abnahmelauf neu einzutragen, nicht vorab auszufüllen.
Kein hier genannter automatisierter Test wurde für dieses Dokument gegen ein echtes FLOWFACT- oder
Anthropic-Konto ausgeführt; das Feld "Ergebnis" bleibt entsprechend offen, bis der jeweilige Test tatsächlich
gelaufen ist (siehe [faehigkeitsmatrix.md](faehigkeitsmatrix.md)).

## 1. Abnahmekriterien

Nachweisart: **A** = automatisierter Test (Dateiname angegeben), **M** = manueller Test in der Oberfläche,
**E** = Test am echten FLOWFACT- oder Anthropic-Konto (bislang nicht durchgeführt, siehe
faehigkeitsmatrix.md).

| Nr. | Abnahmekriterium | Nachweisart | Nachweis | Ergebnis | Datum | Prüfer |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | Anmeldung ohne 2FA-Pflicht gelingt mit korrekten Zugangsdaten | A | `tests/Feature/Auth/LoginTest.php::test_login_mit_korrekten_zugangsdaten_meldet_an_und_setzt_last_login_at` | | | |
| 2 | Anmeldung mit aktiviertem Zweitfaktor führt zur Challenge-Seite statt direkter Anmeldung | A | `tests/Feature/Auth/LoginTest.php::test_login_mit_aktiviertem_zweitfaktor_fuehrt_zur_challenge_statt_direkter_anmeldung` | | | |
| 3 | Anmeldung wird nach fünf Fehlversuchen gedrosselt | A | `tests/Feature/Auth/LoginTest.php::test_nach_fuenf_fehlversuchen_wird_die_anmeldung_gedrosselt` | | | |
| 4 | "Müller FLOW starten" legt einen Entwurf an und öffnet Schritt 1 | M | Anmelden, auf dem Dashboard "Müller FLOW starten" wählen, prüfen, dass Schritt 1 geöffnet wird | | | |
| 5 | Die acht Schritte lassen sich in fester Reihenfolge durchlaufen, Fortschrittsleiste zeigt offene Pflichtangaben | A, M | `tests/Feature/Listing/WizardStepsTest.php`; ergänzend Sichtprüfung der Fortschrittsleiste | | | |
| 6 | Heizkostenfälle A bis F rechnen die Warmmiete korrekt | A | `tests/Unit/Domain/Listing/RentCalculatorTest.php::test_fall_a_...` bis `test_fall_f_...` (sechs Testfälle) | | | |
| 7 | Stellplatz optional und verpflichtend wird korrekt dargestellt und nie in die Warmmiete eingerechnet | A | `tests/Unit/Domain/Listing/PriceStructureTest.php`, `tests/Feature/Listing/WizardStep4RentTest.php` | | | |
| 8 | Unbekannte Ausstattungsmerkmale werden weder als Ja noch als Nein übertragen | A | `tests/Feature/Flowfact/FlowfactPayloadMapperTest.php`, `tests/Unit/Domain/Listing/MasterpromptEnumsTest.php` | | | |
| 9 | Autosave speichert nach 1,5 Sekunden und löst nie eine Übertragung oder Statusänderung aus | A | `tests/Feature/Listing/WizardAutosaveTest.php::test_autosave_ruft_niemals_den_publishingservice_auf_und_aendert_den_status_nicht` | | | |
| 10 | Unterbrechen und Fortsetzen der Erfassung über Autosave verliert keine Eingaben | A, M | `tests/Feature/Listing/WizardAutosaveTest.php`; ergänzend manueller Test: Seite mitten in der Eingabe neu laden | | | |
| 11 | Manuell bearbeitete Texte bleiben bei erneuter Texterzeugung erhalten | A | `tests/Feature/Listing/Step8Test.php::test_ein_manuell_bearbeiteter_text_bleibt_bei_erneuter_erzeugung_erhalten` | | | |
| 12 | Bei Adressfreigabe "nur PLZ und Ort" wird ein Text mit Straße oder Hausnummer erkannt und die Veröffentlichung blockiert | A | `tests/Feature/Listing/Step8Test.php::test_die_adressfreigabe_blockiert_einen_text_mit_strasse_und_hausnummer`, `::test_die_adressfreigabe_blockiert_die_veroeffentlichung_wenn_ein_text_die_strasse_enthaelt` | | | |
| 13 | Ein Doppelklick auf "In FLOWFACT speichern" oder ein paralleler Job führt nicht zu einer doppelten Anlage (Lease) | A | `tests/Feature/Flowfact/Regression/Regression07LeaseTest.php` | | | |
| 14 | Eine Zeitüberschreitung nach dem Anlegebefehl führt beim nächsten Versuch zur Suche, nicht zu einer zweiten Anlage | A | `tests/Feature/Flowfact/ListingSyncServiceTest.php::test_zeitueberschreitung_nach_anlegen_fuehrt_beim_zweiten_lauf_zur_suche_statt_zum_zweiten_anlegen` | | | |
| 15 | Ein Authentifizierungsfehler gegen FLOWFACT lässt den Job sofort endgültig scheitern statt ihn erfolglos zu wiederholen | A | `tests/Feature/Flowfact/TransferJobsTest.php::test_auth_fehler_laesst_den_job_sofort_endgueltig_scheitern`, `tests/Feature/Flowfact/ListingSyncServiceTest.php::test_auth_fehler_ohne_wiederholung_mit_dokumentierter_meldung` | | | |
| 16 | Eine Ratenbegrenzung (HTTP 429) reiht den Job mit der gemeldeten Retry-After-Zeit neu ein | A | `tests/Feature/Flowfact/TransferJobsTest.php::test_ratenbegrenzung_reiht_den_job_mit_retry_after_neu_ein`, `tests/Feature/Flowfact/ListingSyncServiceTest.php::test_ratenbegrenzung_wird_als_ausnahme_durchgereicht` | | | |
| 17 | Ein Teilerfolg bei der Veröffentlichung auf mehreren Portalen wird in der Meldung je Portal ausgewiesen | A | `tests/Feature/Flowfact/Regression/Regression10FallBTest.php::test_teilergebnis_je_portal_steht_in_der_meldung` | | | |
| 18 | Fall B (401/403 oder fehlendes Portalrecht) wird als "Portalveröffentlichung in FLOWFACT abschließen" angezeigt, nicht als Fehler und nicht als Erfolg | A | `tests/Feature/Flowfact/Regression/Regression10FallBTest.php::test_401_auf_publish_setzt_manuelle_freigabe_und_spaeteres_ruecklesen_setzt_aktiv`, `::test_portals_without_access_rights_in_der_antwort_ist_fall_b` | | | |
| 19 | Eine Änderung nach Veröffentlichung führt nicht zu einer ungefragten erneuten Veröffentlichung, sondern zeigt "Unveröffentlichte Änderungen" | A | `tests/Feature/Flowfact/Regression/Regression08FreigabeversionTest.php`, `tests/Feature/Flowfact/Regression/Regression09VeralteterJobTest.php::test_rueckzug_nach_der_freigabe_verhindert_die_veroeffentlichung_durch_den_alten_job` | | | |
| 20 | Deaktivierung während wartender Veröffentlichungs-Jobs verhindert deren nachträgliche Wiederveröffentlichung | A | `tests/Feature/Flowfact/Regression/Regression09VeralteterJobTest.php::test_rueckzug_nach_der_freigabe_verhindert_die_veroeffentlichung_durch_den_alten_job`, `tests/Feature/Flowfact/Regression/Regression11DeaktivierungTest.php` | | | |
| 21 | Unberechtigte Zugriffe werden abgewiesen: Lesender Benutzer kann nichts ändern, Mitarbeiter ohne Zuordnung oder ohne "Darf veröffentlichen" kann nicht veröffentlichen oder zurückziehen | A | `tests/Feature/Domain/ListingPolicyTest.php` (u. a. `test_ein_leser_darf_nur_sehen`, `test_ein_mitarbeiter_ohne_darf_veroeffentlichen_darf_nicht_veroeffentlichen_oder_zurueckziehen`, `test_ein_mitarbeiter_ohne_darf_veroeffentlichen_erhaelt_403_beim_zurueckziehen`) | | | |
| 22 | Portalstatus "aktiv" wird ausschließlich nach Rücklesen aus FLOWFACT gesetzt, nie durch das bloße Absenden der Veröffentlichung | A | `tests/Feature/Flowfact/RefreshPortalStatusJobTest.php`, `tests/Feature/Flowfact/Regression/Regression10FallBTest.php::test_401_auf_publish_setzt_manuelle_freigabe_und_spaeteres_ruecklesen_setzt_aktiv` | | | |
| 23 | Interne Daten (listing_internals) erscheinen nie im Payload, im Protokoll oder in Bildtiteln | A | `tests/Feature/Flowfact/FlowfactPayloadMapperTest.php`, `tests/Feature/Flowfact/TokenLeakTest.php` | | | |
| 24 | HEIC-Dateien werden mit verständlichem Hinweis abgelehnt statt mit allgemeiner Fehlermeldung | A | `tests/Feature/Media/MediaRotateFreigabeTest.php::test_heic_datei_wird_mit_eigener_meldung_abgelehnt` | | | |
| 25 | Duplizieren übernimmt Inseratsfelder, aber keine FLOWFACT-Verknüpfung, keine Portalveröffentlichung und keine KI-Texte | A | `tests/Feature/Listing/DuplicateTest.php` | | | |
| 26 | Smoke-Test lesend gegen das echte FLOWFACT-Konto liefert ein auswertbares Protokoll | E | `php artisan flow:flowfact:smoke`, Protokoll unter `storage/logs/flowfact-smoke-<Datum>.md` | offen (noch nicht durchgeführt) | | |
| 27 | Smoke-Test mit `--write` legt ein Testobjekt an, veröffentlicht es nicht real und räumt es wieder auf | E | `php artisan flow:flowfact:smoke --write`, Freigabe der Geschäftsführung erforderlich | offen (noch nicht durchgeführt) | | |
| 28 | Eine reale Portalveröffentlichung wird erst nach ausdrücklicher Freigabe der Geschäftsführung ausgelöst | M | Organisatorische Prüfung, kein Code-Schalter für automatische reale Veröffentlichung vorhanden | | | |

## 2. Zeit- und Klickmessung

Für den Ablauf "Müller FLOW starten" bis "JETZT VERÖFFENTLICHEN" bei einem vollständig ausgefüllten
Mietobjekt mit fünf Bildern. Je Testlauf eine Zeile ausfüllen.

| Lauf | Startzeit Erfassung | Ende Erfassung (Schritt 8 abgeschlossen) | Zahl der Eingaben | Zahl der Klicks | Uploaddauer (getrennt, Schritt 6) | Externe Portalzeit (getrennt, ab "JETZT VERÖFFENTLICHEN" bis Bestätigung "Aktiv") |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | | | | | | |
| 2 | | | | | | |

Hinweise zur Messung: "Zahl der Eingaben" zählt Tastatureingaben in Textfeldern und Auswahlklicks in
Kacheln/Dropdowns zusammen; "Zahl der Klicks" zählt zusätzlich Navigations- und Schaltflächenklicks
(Weiter, Entwurf speichern, Upload starten). "Uploaddauer" beginnt mit der Dateiauswahl und endet mit der
Bestätigung aller Uploads in Schritt 6, getrennt von der übrigen Erfassungszeit. "Externe Portalzeit" beginnt
mit dem Klick auf "JETZT VERÖFFENTLICHEN" und endet, sobald der Portalstatus auf "Aktiv" wechselt (durch den
Hintergrundabgleich `flow:portal-status`); diese Zeit hängt von FLOWFACT und dem jeweiligen Portal ab und ist
kein Maß für die Anwendung selbst.

## 3. Ehrlichkeitshinweis zu fehlender automatisierter Abdeckung

Folgende, in Abschnitt 1 genannte Punkte haben **keine** automatisierte Abdeckung und benötigen einen
manuellen Test oder einen Test am echten Konto, bevor sie als abgenommen gelten:

- Nr. 4 ("Müller FLOW starten" in der Oberfläche): nur Routendefinition und View-Rendering sind automatisiert
  geprüft (`tests/Feature/Pages/PagesRenderTest.php`), der eigentliche Klickpfad ist manuell zu bestätigen.
- Nr. 26 bis 28 (Smoke-Test und reale Veröffentlichung): ausschließlich am echten Konto nachweisbar, bislang
  nicht durchgeführt. Vor Livegang zwingend mit der Geschäftsführung abzustimmen und in
  [faehigkeitsmatrix.md](faehigkeitsmatrix.md) nachzutragen.
