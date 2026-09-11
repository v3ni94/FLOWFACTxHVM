# Installation und Betrieb, Müller FLOW auf IONOS Webhosting

Stand: 11.09.2026. Diese Anleitung beschreibt die Inbetriebnahme von Müller FLOW unter der Subdomain
`flowfact.muellerhv.de` auf IONOS Webhosting. Sie richtet sich an den Betreiber, der Zugriff auf das
IONOS-Control-Center hat. Stellen, an denen der Betreiber Angaben aus dem eigenen IONOS-Konto nachschlagen
muss, statt sie hier zu übernehmen, sind ausdrücklich mit **AUS IONOS-KONTO** gekennzeichnet.

## 1. Voraussetzungen

- Vertrag mit PHP 8.3 oder neuer, MariaDB-Datenbank (IONOS Webhosting, Paket mit Datenbank).
- Subdomain `flowfact.muellerhv.de` **AUS IONOS-KONTO**: im Control-Center unter "Domains & SSL" angelegt
  und mit einem SSL-Zertifikat versehen (IONOS stellt in der Regel Let's-Encrypt-Zertifikate automatisch aus).
- SFTP-Zugangsdaten für den Vertrag **AUS IONOS-KONTO**: Host, Benutzername, Passwort oder SSH-Schlüssel,
  unter "FTP & SFTP-Zugänge".
- MariaDB-Zugangsdaten **AUS IONOS-KONTO**: Host (in der Regel `dbXXXXXXX.hosting-data.io` oder ähnlich),
  Datenbankname, Benutzername, Passwort, unter "Datenbanken & MariaDB".
- Ein GitHub-Repository mit Actions-Zugriff, Secrets je Environment (staging, production) angelegt, siehe
  Abschnitt 6.

## 2. Verzeichnislayout auf dem Server

Unterhalb des SFTP-Wurzelverzeichnisses des Vertrags (`SFTP_DEPLOY_ROOT`):

```
shared/.env            Konfiguration, einmalig angelegt, überlebt jedes Deployment
shared/storage/        Logs, Sitzungen, Medienablage, Kurzzeitbereich
releases/<name>/       hochgeladene Releases, die letzten drei bleiben erhalten
current/               das aktive Release (Releasezeiger, siehe bin/deploy-sftp.php)
```

`current` ist ein gewöhnliches Verzeichnis, kein Symlink. Ein Deployment schaltet per SFTP-Umbenennung um,
ein Rollback ist dieselbe Operation in Gegenrichtung.

## 3. Document Root

Bevorzugt: Document Root im IONOS-Control-Center **AUS IONOS-KONTO** direkt auf
`<SFTP_DEPLOY_ROOT>/current/public` setzen (Menü "Domains & SSL", Feld "Verzeichnis").

Lässt sich der Document Root nicht auf `public/` legen (manche IONOS-Tarife erlauben nur die Vertragswurzel),
greift die Wurzel-`.htaccess` (`/.htaccess` im Repository) als Rückfalloption: sie sperrt alle sensiblen
Pfade und schreibt jede Anfrage intern nach `current/public/` um. In beiden Fällen gilt: kein `Options`-Befehl
in `.htaccess`, weil IONOS Webhosting ihn in Kundenverzeichnissen nicht erlaubt und sonst mit Fehler 500
antwortet.

## 4. `shared/.env` einmalig anlegen

Vor dem ersten Deployment `shared/.env` per SFTP anlegen (Vorlage: `.env.example` aus dem Repository). Wichtige
Schlüssel:

| Schlüssel | Wert |
| --- | --- |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://flowfact.muellerhv.de` |
| `APP_KEY` | mit `php artisan key:generate --show` erzeugen und eintragen |
| `DB_CONNECTION` | `mariadb` |
| `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | **AUS IONOS-KONTO** |
| `TRUSTED_PROXIES` | `*` (IONOS Webhosting veröffentlicht seine Proxy-Adressen nicht, siehe Architektur Abschnitt 5) |
| `SESSION_SECURE_COOKIE` | `true` |
| `MEDIA_ROOT` | leer lassen (Standard: `storage/app/private/media` unterhalb von `shared/storage`) |
| `FLOWFACT_STAGE`, `FLOWFACT_BASE_URL` | je nach Vertragsstatus, siehe `docs/offene-punkte.md` |
| `CRON_SCHEDULE_TOKEN`, `CRON_INSTALL_TOKEN` | je ein zufälliger, langer Wert (z. B. `php -r "echo bin2hex(random_bytes(32));"`), nur wenn der URL-Cronjob (Abschnitt 7) genutzt wird |

Der FLOWFACT-API-Token wird **nicht** in der `.env` hinterlegt, sondern nach der Installation im Adminbereich
der Anwendung eingegeben (ADR-008).

## 5. Erstes Deployment

Über GitHub Actions: Workflow "Deploy (SFTP)" mit `target=staging` (zuerst) beziehungsweise `production` und
`action=deploy` manuell auslösen. Der Workflow baut ein Releasepaket, lädt es per SFTP in ein neues
Verzeichnis, schaltet um und führt einen Smoke-Test gegen `/up` aus. Schlägt der Smoke-Test fehl, schaltet er
automatisch auf das vorige Release zurück.

Alternativ von einem Arbeitsplatz mit installierten Produktionsabhängigkeiten:

```
php bin/deploy-sftp.php --source=build-release/<paket> --smoke-url=https://flowfact.muellerhv.de
```

## 6. GitHub-Secrets je Environment

Unter "Settings" → "Environments" je Environment (staging, production) anlegen, niemals als Repository-weite
Secrets:

- `SFTP_HOST`, `SFTP_PORT`, `SFTP_USERNAME`
- `SFTP_PASSWORD` oder `SFTP_PRIVATE_KEY`
- `SFTP_DEPLOY_ROOT`
- `SMOKE_TEST_URL` (z. B. `https://flowfact.muellerhv.de`)

Für `production` zusätzlich einen Umgebungsschutz mit erforderlicher Freigabe einrichten, damit niemand ohne
zweite Person in Produktion ausliefert.

## 7. Cronjob nach jedem Deployment

Nach jedem Deployment muss `flow:install` einmal laufen (Migrationen, Konfigurations-, Routen-, View- und
Event-Cache, Prüfung auf fehlende PHP-Erweiterungen und Schreibrechte). Zwei gleichwertige Wege:

**a) CLI-Cronjob** (empfohlen, wenn der Tarif Shellzugriff für Cronjobs erlaubt) **AUS IONOS-KONTO**: unter
"Cronjobs" im Control-Center einen Job mit folgendem Befehl anlegen, einmalig nach jedem Deployment manuell
auslösbar:

```
php <SFTP_DEPLOY_ROOT>/current/artisan flow:install --no-interaction
```

**b) URL-Cronjob** (wenn kein CLI-Cronjob verfügbar ist): einen HTTP-Cronjob **AUS IONOS-KONTO** einrichten,
der per POST `https://flowfact.muellerhv.de/wartung/install` mit Header `X-Cron-Token: <CRON_INSTALL_TOKEN>`
aufruft. Der Endpunkt antwortet mit JSON `{"success": true, "output": "..."}`. Ohne gesetzten
`CRON_INSTALL_TOKEN` liefert die Route 404 (deaktiviert), bei falschem Token 403, bei GET 405.

## 8. Laufender Scheduler (jede Minute)

Der Anwendungsscheduler (`routes/console.php`) startet die Warteschlange (`queue:work database
--stop-when-empty --max-time=45`) und ein tägliches `flow:check-config`. Er muss jede Minute angestoßen werden
(ADR-006, kein Dauerprozess auf IONOS Webhosting):

**a) CLI-Cronjob** **AUS IONOS-KONTO**, jede Minute:

```
php <SFTP_DEPLOY_ROOT>/current/artisan schedule:run
```

**b) URL-Cronjob**, wenn kein CLI-Cronjob im Minutentakt verfügbar ist: POST
`https://flowfact.muellerhv.de/wartung/schedule` mit Header `X-Cron-Token: <CRON_SCHEDULE_TOKEN>`. Ist nur ein
Fünf-Minuten-Takt verfügbar, zeigt `flow:check-config` die tatsächliche Verzögerung des letzten Laufs an
(Fehler erst ab mehr als 15 Minuten ohne Lauf).

## 9. Ersten Administrator anlegen

Nach dem ersten `flow:install` einen Administrator anlegen. Sobald der entsprechende Artisan-Befehl aus dem
Grundgerüst (`flow:user:create`, Zuständigkeit des Auth-Arbeitspakets) verfügbar ist:

```
php current/artisan flow:user:create --name="Vorname Nachname" --email="name@muellerhv.de" --role=admin
```

Bis dahin lässt sich ein Administrator über `php artisan tinker` auf dem Server anlegen. Das Passwort ist bei
der ersten Anmeldung zu ändern.

## 10. Betriebsprüfung

```
php current/artisan flow:check-config
```

Prüft und gibt tabellarisch aus: `APP_ENV`, `APP_DEBUG` (Warnung in Produktion), Schema von `APP_URL`,
`TRUSTED_PROXIES`, Datenbankverbindung und Migrationsstatus, Beschreibbarkeit des Medienverzeichnisses,
konfigurierten Mail-Mailer, Warteschlangentreiber sowie Anzahl ausstehender und fehlgeschlagener Jobs,
Lebenszeichen des Schedulers (Fehler erst ab mehr als 15 Minuten ohne Lauf), FLOWFACT-Stage, Basis-URL und ob
ein API-Token hinterlegt ist. Exit-Code 1 bei Fehlern, geeignet für ein eigenes Monitoring.

## 11. Sicherung (Backup)

- Datenbank: IONOS bietet **AUS IONOS-KONTO** unter "Datenbanken & MariaDB" in der Regel automatische
  tägliche Sicherungen mit Rückspielfunktion; Aufbewahrungsdauer dort prüfen.
- `shared/storage` (insbesondere hochgeladene Objektbilder und Dokumente, ADR-012) liegt außerhalb der
  Releases und wird von den automatischen Release-Sicherungen nicht erfasst. Regelmäßige eigene Sicherung
  per SFTP oder über ein von IONOS angebotenes Backup-Produkt einrichten.
- `shared/.env` enthält Zugangsdaten und `APP_KEY`. Eine Kopie an einem sicheren, getrennten Ort aufbewahren;
  ohne `APP_KEY` sind verschlüsselte Felder (unter anderem der FLOWFACT-Token, ADR-008) nicht mehr lesbar.

## 12. Rollback

Über GitHub Actions: Workflow "Deploy (SFTP)" mit `action=list` ausführen, um verfügbare Releases zu sehen,
dann mit `action=rollback` und dem gewünschten `release`-Namen erneut ausführen. Der Smoke-Test läuft auch
beim Rollback; schlägt er fehl, schaltet das Skript automatisch auf das zuvor aktive Release zurück.
