# Backup und Restore, Müller FLOW auf IONOS Webhosting

Stand: 12.09.2026. Ergänzt [installation.md](installation.md) Abschnitt 11 (Sicherung) und Abschnitt 12
(Rollback) um ein vollständiges Wiederherstellungsverfahren. Richtet sich an den Betreiber mit Zugriff auf
das IONOS-Control-Center. Stellen, an denen der Betreiber Angaben aus dem eigenen IONOS-Konto nachschlagen
muss, sind mit **AUS IONOS-KONTO** gekennzeichnet, wie in der Installationsanleitung.

## 1. Was zu sichern ist

| Bestandteil | Inhalt | Warum wichtig |
| --- | --- | --- |
| Datenbank (MariaDB) | Alle Tabellen: Objekte, Preise, Energieausweise, interne Daten, Medien-Metadaten, Texte, FLOWFACT-Verknüpfungen, Portalveröffentlichungen, Freigabeversionen, Übertragungsprotokoll, Einstellungen (`settings`), Benutzer, Einladungen | Enthält den gesamten fachlichen Datenbestand; ohne sie ist nach einem Ausfall kein Objekt mehr rekonstruierbar |
| `shared/storage` | Hochgeladene Objektbilder, Grundrisse, Dokumente und Energieausweise (physische Dateien, ADR-012), Logs, Sitzungen, Kurzzeitbereich | Liegt außerhalb der Releases und wird von automatischen Release-Sicherungen **nicht** erfasst; ohne sie fehlen alle Medien, auch wenn die Datenbank wiederhergestellt ist |
| `shared/.env` | Konfiguration und **`APP_KEY`** | **Ohne den `APP_KEY` sind verschlüsselte Einstellungen unwiederbringlich verloren**, insbesondere der FLOWFACT-API-Token (ADR-008) und der KI-API-Schlüssel (ADR-009). Ein Restore der Datenbank ohne den ursprünglichen `APP_KEY` liefert für diese Felder nur unlesbaren Schlüsseltext |

Die Datenbank und `shared/storage` gehören fachlich zusammen: Ein Medieneintrag in der Datenbank ohne die
zugehörige Datei in `shared/storage` (oder umgekehrt) führt zu fehlenden Bildern in der Oberfläche. Sichern
Sie beide möglichst zum selben Zeitpunkt.

## 2. Sicherungsablauf auf IONOS Webhosting

### 2.1 Datenbank

IONOS bietet unter "Datenbanken & MariaDB" **AUS IONOS-KONTO** in der Regel automatische tägliche
Sicherungen mit Rückspielfunktion; Aufbewahrungsdauer dort prüfen (siehe installation.md Abschnitt 11). Für
eine zusätzliche, eigene Sicherung stehen zwei gleichwertige Wege zur Verfügung:

- **phpMyAdmin** (im IONOS-Control-Center unter "Datenbanken & MariaDB" **AUS IONOS-KONTO** verlinkt):
  Datenbank auswählen, Reiter "Exportieren", Exportmethode "Angepasst", Format SQL, Option "Struktur und
  Daten" wählen und herunterladen.
- **`mysqldump`**, sofern der Vertrag SSH- oder Terminalzugriff erlaubt:

  ```
  mysqldump --host=<DB_HOST> --user=<DB_USERNAME> -p <DB_DATABASE> > flow-backup-<Datum>.sql
  ```

  Host, Benutzername und Datenbankname stammen aus `shared/.env` beziehungsweise **AUS IONOS-KONTO**.

### 2.2 Medien (`shared/storage`)

Per SFTP mit den Zugangsdaten aus installation.md Abschnitt 1 das gesamte Verzeichnis
`<SFTP_DEPLOY_ROOT>/shared/storage` auf einen lokalen oder anderen Speicherort herunterladen (SFTP-Client
oder `sftp`/`rsync`-Kommandozeile). Alternativ ein von IONOS angebotenes Backup-Produkt einrichten, falls der
Vertrag eines enthält.

### 2.3 `shared/.env`

Eine Kopie von `<SFTP_DEPLOY_ROOT>/shared/.env` an einem sicheren, vom Produktivsystem getrennten Ort
aufbewahren (zum Beispiel ein Passwortmanager oder ein verschlüsselter Ablageort für den Betreiber). Diese
Datei enthält Datenbankzugangsdaten und den `APP_KEY` und ist entsprechend vertraulich zu behandeln.

## 3. Aufbewahrung

- Automatische IONOS-Datenbanksicherungen: Aufbewahrungsdauer und Rückspielmöglichkeit **AUS IONOS-KONTO**
  prüfen und dokumentieren.
- Eigene Sicherungen von Datenbank und `shared/storage`: mindestens die letzten sieben Tage einzeln, darüber
  hinaus in größeren Abständen (zum Beispiel wöchentlich für einen Monat, monatlich darüber hinaus), sofern
  der Betreiber keine engere Vorgabe der Geschäftsführung erhält.
- `shared/.env`-Kopie: bei jeder Änderung des `APP_KEY` oder der Datenbankzugangsdaten aktualisieren, ältere
  Kopien nicht ungeprüft löschen, solange nicht sicher ist, dass keine damit verschlüsselten Daten mehr
  existieren.

## 4. Wiederherstellung Schritt für Schritt in eine frische Installation

Voraussetzung: eine neue oder zurückgesetzte Installation nach [installation.md](installation.md)
Abschnitte 1 bis 9 (Vertrag, Subdomain, Verzeichnislayout, `shared/.env`, erstes Deployment), aber noch ohne
eigene Daten beziehungsweise mit Daten, die überschrieben werden sollen.

1. **`shared/.env` wiederherstellen**: die gesicherte Kopie (Abschnitt 2.3) per SFTP nach
   `<SFTP_DEPLOY_ROOT>/shared/.env` hochladen. Dies stellt sicher, dass derselbe `APP_KEY` wie beim Backup
   verwendet wird; ohne diesen Schritt bleiben verschlüsselte Einstellungsfelder aus der wiederhergestellten
   Datenbank unlesbar.
2. **Datenbank wiederherstellen**: Über phpMyAdmin ("Importieren", die gesicherte SQL-Datei auswählen) oder,
   bei Shellzugriff, mit

   ```
   mysql --host=<DB_HOST> --user=<DB_USERNAME> -p <DB_DATABASE> < flow-backup-<Datum>.sql
   ```

   Vor dem Import prüfen, dass die Zieldatenbank leer ist oder das Überschreiben ausdrücklich gewollt ist.
3. **Medien wiederherstellen**: Den gesicherten Inhalt von `shared/storage` per SFTP in
   `<SFTP_DEPLOY_ROOT>/shared/storage` zurückspielen, sodass die Pfade in der Datenbank wieder auf
   vorhandene Dateien verweisen.
4. **Anwendung in Betrieb nehmen**: `php current/artisan flow:install --no-interaction` einmal ausführen
   (Migrationen, Caches). Da `flow:install` idempotent ist und keine destruktiven Migrationen enthält
   (Abschnitt 6), lässt sich dieser Schritt gefahrlos wiederholen.
5. **Scheduler und Cronjobs** wie in installation.md Abschnitte 7 und 8 einrichten, falls es sich um eine
   komplett neue Installation handelt.

## 5. Prüfung nach dem Restore

1. **Betriebsprüfung**: `php current/artisan flow:check-config` ausführen und sicherstellen, dass keine
   Fehler gemeldet werden (Datenbankverbindung, Medienverzeichnis beschreibbar, Warteschlange,
   Scheduler-Lebenszeichen, FLOWFACT-Stage und ob ein Token hinterlegt ist).
2. **Anmeldung**: Mit einem bekannten Administratorkonto unter `/login` anmelden. Gelingt die Anmeldung nicht
   mehr (zum Beispiel weil ein Passwort-Hash nicht mit dem erwarteten `APP_KEY` zusammenpasst oder kein
   Administrator mehr existiert), einen neuen Administrator anlegen:

   ```
   php current/artisan flow:user:create --name="Vorname Nachname" --email="name@muellerhv.de" --role=admin
   ```
3. **Ein Objekt öffnen**: Ein beliebiges vorhandenes Objekt unter `/app/objekte` öffnen und prüfen, dass
   Preis-, Energieausweis- und interne Daten korrekt angezeigt werden.
4. **Medien sichtbar**: Auf der geöffneten Objektseite prüfen, dass Bilder und Grundrisse tatsächlich als
   Vorschau erscheinen (nicht nur als Datenbankeintrag ohne Bild). Erscheint kein Bild, obwohl die Datenbank
   einen Medieneintrag enthält, wurde `shared/storage` nicht vollständig oder nicht an den richtigen Pfad
   zurückgespielt.
5. **FLOWFACT-Einstellungen**: Unter "FLOWFACT-Einstellungen" prüfen, ob "hinterlegt am" für den API-Token
   weiterhin angezeigt wird und "Verbindung testen" erfolgreich ist. Schlägt dies fehl, obwohl der Token vor
   der Sicherung funktionierte, deutet dies auf einen abweichenden `APP_KEY` hin (Schritt 1 wiederholen oder
   den Token neu hinterlegen).

## 6. Keine destruktiven Migrationen

Die Anwendung führt bei `flow:install` ausschließlich additive, idempotente Migrationen aus; es gibt keine
Migration, die im Regelbetrieb Tabellen löscht oder Daten unwiderruflich entfernt. Ein Rollback auf ein
älteres Release (installation.md Abschnitt 12) ändert die Datenbank nicht automatisch zurück; ein
Datenbank-Rollback ist ausschließlich über eine vorherige Datenbanksicherung möglich (Abschnitt 4 dieses
Dokuments). Vor jeder manuellen Datenbankoperation, die keine reine Migration ist (zum Beispiel ein
Wiederherstellungsversuch mit `mysql < backup.sql`), vorher prüfen, dass eine aktuelle Sicherung der zu
überschreibenden Datenbank vorliegt.
