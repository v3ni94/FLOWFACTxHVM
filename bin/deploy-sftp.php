#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| SFTP-Auslieferung auf IONOS ohne SSH (Architektur Abschnitt 6)
|------------------------------------------------------------------------------
|
| Eigenständiges Skript für die Auslieferung aus GitHub Actions oder von einem
| Arbeitsplatz. Es nutzt die Flysystem-SFTP-Bibliothek aus vendor/. Eine
| gebootete Laravel-Anwendung ist nicht erforderlich. vendor/ wird in CI vor
| der Paketierung mit "composer install --no-dev" gebaut, nicht hier.
|
| VERZEICHNISLAYOUT auf dem Server unterhalb von SFTP_DEPLOY_ROOT:
|
|   shared/.env            Konfiguration, einmalig vom Betreiber angelegt
|   shared/storage/        gemeinsamer Speicher (Logs, Sessions, Medien)
|   releases/<name>/       hochgeladene Releases, die letzten drei bleiben
|   current/               das aktive Release (Releasezeiger)
|
| RELEASEZEIGER OHNE SSH: "current" ist ein gewöhnliches Verzeichnis. Das
| Umschalten besteht aus zwei SFTP-Umbenennungen, die jeweils atomar sind:
| current -> releases/<alt>, danach releases/<neu> -> current. Damit bleiben
| Document Root (current/public), Wurzel-.htaccess und Cronjob-Pfad
| (current/artisan) dauerhaft gleich, und ein Rollback ist dieselbe Operation
| in Gegenrichtung.
|
| REIHENFOLGE: Hochladen in ein NEUES Verzeichnis, Integritätsprüfung,
| Umschalten, Smoke-Test gegen SMOKE_TEST_URL. Schlägt der Smoke-Test fehl,
| wird sofort auf das vorige Release zurückgeschaltet und mit Exit-Code 1
| beendet.
|
| MIGRATIONEN laufen nicht hier, sondern nach dem Umschalten über
| "php current/artisan flow:install" (CLI-Cronjob im IONOS-Control-Center oder
| der Wartungsendpunkt POST /wartung/install).
|
| AUFRUF
|   php bin/deploy-sftp.php --source=build-release/<paket> [--smoke-url=URL] [--keep=3] [--dry-run]
|   php bin/deploy-sftp.php --rollback=<releasename> [--smoke-url=URL]
|   php bin/deploy-sftp.php --list
|
| ZUGANGSDATEN ausschließlich über Umgebungsvariablen, niemals als Argument:
|   SFTP_HOST, SFTP_PORT (Standard 22), SFTP_USERNAME,
|   SFTP_PASSWORD  oder  SFTP_PRIVATE_KEY (Schlüsselinhalt) oder SFTP_PRIVATE_KEY_PATH,
|   SFTP_PASSPHRASE (optional), SFTP_DEPLOY_ROOT, SMOKE_TEST_URL (optional)
|
| Es werden weder Zugangsdaten noch Hostnamen ausgegeben.
*/

use League\Flysystem\Filesystem;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use phpseclib3\Net\SFTP;

$autoload = null;

foreach ([__DIR__.'/../vendor/autoload.php', getcwd().'/vendor/autoload.php'] as $candidate) {
    if (is_file($candidate)) {
        $autoload = $candidate;

        break;
    }
}

if ($autoload === null) {
    fwrite(STDERR, "vendor/autoload.php wurde nicht gefunden. Bitte aus dem Projektverzeichnis oder aus einem Releasepaket aufrufen.\n");
    exit(2);
}

require $autoload;

/**
 * Pflichtdateien eines Releasepakets. Ohne diese Dateien ist ein Release
 * niemals lauffähig und wird weder aktiviert noch als vollständig gemeldet.
 */
final class ReleasePackage
{
    public const array REQUIRED_FILES = [
        'artisan',
        'composer.json',
        'public/index.php',
        'vendor/autoload.php',
        'bootstrap/app.php',
    ];

    /**
     * @return list<string>
     */
    public function missingRequiredFiles(string $source): array
    {
        $missing = [];

        foreach (self::REQUIRED_FILES as $required) {
            if (! is_file($source.'/'.$required)) {
                $missing[] = $required;
            }
        }

        return $missing;
    }

    /**
     * Listet alle Dateien im Quellverzeichnis relativ zu ihm, Verzeichnisse
     * ausgenommen. .env-Dateien werden nie mit aufgenommen.
     *
     * @return list<string>
     */
    public function files(string $source): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $fileInfo) {
            /** @var SplFileInfo $fileInfo */
            if (! $fileInfo->isFile()) {
                continue;
            }

            $relative = ltrim(str_replace('\\', '/', substr($fileInfo->getPathname(), strlen($source))), '/');

            if (str_starts_with($relative, '.env')) {
                continue;
            }

            $files[] = $relative;
        }

        sort($files);

        return $files;
    }
}

final class SftpDeployer
{
    private const string RELEASE_MARKER = 'RELEASE';

    private ?Filesystem $filesystem = null;

    private ?SFTP $connection = null;

    private ?SftpConnectionProvider $provider = null;

    /**
     * Ausgabestrom für Meldungen, Standard STDOUT.
     *
     * @var resource
     */
    private $output;

    /**
     * Fehlerstrom, Standard STDERR. Im Test derselbe Strom wie $output.
     *
     * @var resource
     */
    private $errors;

    /**
     * Verbindung und Dateisystem sind ausschließlich für einen Verhaltenstest
     * von außen setzbar. Im Betrieb bleiben beide null und werden aus den
     * Umgebungsvariablen gebaut.
     *
     * @param  array<string, string>  $env
     * @param  resource|null  $output
     */
    public function __construct(
        private readonly array $env,
        private readonly bool $dryRun,
        private readonly int $keep,
        private readonly ?string $smokeUrl,
        ?Filesystem $filesystem = null,
        ?SFTP $connection = null,
        $output = null,
    ) {
        $this->filesystem = $filesystem;
        $this->connection = $connection;
        $this->output = is_resource($output) ? $output : STDOUT;
        $this->errors = is_resource($output) ? $output : STDERR;
    }

    public function deploy(string $source): int
    {
        $source = rtrim($source, '/');
        $package = new ReleasePackage;

        if (! is_dir($source)) {
            return $this->fail('Das Quellverzeichnis existiert nicht.');
        }

        $missing = $package->missingRequiredFiles($source);

        if ($missing !== []) {
            return $this->fail('Das Paket ist unvollständig, es fehlen: '.implode(', ', $missing).'. Bitte composer install --no-dev ausführen.');
        }

        $files = $package->files($source);
        $release = $this->releaseName($source);

        $this->say(sprintf('Release %s: %d Dateien aus dem Paket.', $release, count($files)));

        if ($this->dryRun) {
            foreach ($files as $file) {
                $this->say('  '.$file);
            }

            $this->say('Probelauf beendet, es wurde nichts übertragen.');

            return 0;
        }

        $fs = $this->filesystem();

        if (! $fs->fileExists('shared/.env')) {
            return $this->fail('shared/.env fehlt auf dem Server. Bitte die .env einmalig per SFTP nach shared/.env legen (Vorlage: .env.example).');
        }

        foreach (['shared/storage', 'shared/storage/app', 'shared/storage/app/private', 'shared/storage/app/private/media', 'shared/storage/framework', 'shared/storage/framework/cache', 'shared/storage/framework/cache/data', 'shared/storage/framework/sessions', 'shared/storage/framework/views', 'shared/storage/logs'] as $directory) {
            $fs->createDirectory($directory);
        }

        $symlinks = $this->symlinksSupported();
        $this->say($symlinks ? 'Symlinks sind möglich: .env und storage werden verknüpft.' : 'Symlinks sind nicht möglich: .env wird kopiert, storage bleibt im Release.');

        $target = 'releases/'.$release;

        try {
            $failure = $this->upload($source, $files, $target, $release, $symlinks);
        } catch (Throwable $exception) {
            $this->abandon($target);

            throw $exception;
        }

        if ($failure !== null) {
            $this->abandon($target);

            return $this->fail($failure);
        }

        $previous = $this->switchTo($release);
        $this->say($previous === null ? 'Erstes Release aktiviert.' : sprintf('Release aktiviert. Voriges Release: %s.', $previous));

        if ($this->smokeUrl !== null) {
            $failure = $this->smokeTest($this->smokeUrl);

            if ($failure !== null) {
                $this->say('Smoke-Test fehlgeschlagen: '.$failure);

                if ($previous !== null) {
                    $this->switchTo($previous);
                    $this->say('Rollback ausgeführt, aktiv ist wieder '.$previous.'.');
                }

                return 1;
            }

            $this->say('Smoke-Test bestanden.');
        } else {
            $this->say('Kein SMOKE_TEST_URL gesetzt, Smoke-Test übersprungen.');
        }

        $this->prune();

        $this->say('Fertig. Nächster Schritt: php current/artisan flow:install (Migrationen und Caches).');

        return 0;
    }

    public function rollback(string $release): int
    {
        $fs = $this->filesystem();

        if (! $fs->directoryExists('releases/'.$release)) {
            return $this->fail('Das Release ist nicht vorhanden. Verfügbare Releases mit --list anzeigen.');
        }

        $incomplete = $this->integrityFailure('releases/'.$release);

        if ($incomplete !== null) {
            return $this->fail('Das Release ist unvollständig und kann nicht aktiviert werden: '.$incomplete);
        }

        if ($this->dryRun) {
            $this->say('Probelauf: würde auf '.$release.' zurückschalten.');

            return 0;
        }

        $previous = $this->switchTo($release);
        $this->say(sprintf('Zurückgeschaltet auf %s (zuvor aktiv: %s).', $release, $previous ?? 'keines'));

        if ($this->smokeUrl !== null) {
            $failure = $this->smokeTest($this->smokeUrl);

            if ($failure !== null) {
                $this->say('Smoke-Test fehlgeschlagen: '.$failure);

                if ($previous !== null) {
                    $this->switchTo($previous);
                    $this->say('Zurückgeschaltet auf das zuvor aktive Release '.$previous.'.');
                }

                return 1;
            }

            $this->say('Smoke-Test bestanden.');
        }

        return 0;
    }

    /**
     * @param  list<string>  $files
     */
    private function upload(string $source, array $files, string $target, string $release, bool $symlinks): ?string
    {
        $fs = $this->filesystem();
        $uploaded = 0;
        $directories = [];

        foreach ($files as $file) {
            if ($symlinks && str_starts_with($file, 'storage/')) {
                continue;
            }

            $directory = dirname($file);

            if ($directory !== '.' && ! isset($directories[$directory])) {
                $fs->createDirectory($target.'/'.$directory);
                $directories[$directory] = true;
            }

            $stream = fopen($source.'/'.$file, 'rb');

            if ($stream === false) {
                return 'Die Datei '.$file.' konnte nicht gelesen werden.';
            }

            $fs->writeStream($target.'/'.$file, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            $uploaded++;

            if ($uploaded % 500 === 0) {
                $this->say(sprintf('  %d Dateien übertragen ...', $uploaded));
            }
        }

        $this->say(sprintf('%d Dateien übertragen.', $uploaded));

        if ($symlinks) {
            if (! $this->connection()->symlink($this->absolutePath('shared/.env'), $this->absolutePath($target.'/.env'))
                || ! $this->connection()->symlink($this->absolutePath('shared/storage'), $this->absolutePath($target.'/storage'))) {
                return 'Die Verknüpfung von .env oder storage konnte nicht angelegt werden.';
            }
        } else {
            $fs->copy('shared/.env', $target.'/.env');
        }

        $fs->write($target.'/'.self::RELEASE_MARKER, $release."\n");

        if (! $fs->fileExists($target.'/public/version.json')) {
            $fs->write($target.'/public/version.json', json_encode([
                'release' => $release,
                'built_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        }

        $incomplete = $this->integrityFailure($target);

        if ($incomplete !== null) {
            return 'Nach dem Upload ist das Release unvollständig: '.$incomplete.' Das Release wird nicht aktiviert.';
        }

        return null;
    }

    private function integrityFailure(string $directory): ?string
    {
        $fs = $this->filesystem();

        foreach (ReleasePackage::REQUIRED_FILES as $required) {
            if (! $fs->fileExists($directory.'/'.$required)) {
                return 'Es fehlt '.$required.'.';
            }
        }

        if (! $fs->fileExists($directory.'/'.self::RELEASE_MARKER)) {
            return 'Der Releasemarker '.self::RELEASE_MARKER.' fehlt, der Upload war nicht abgeschlossen.';
        }

        if (! $fs->fileExists($directory.'/.env')) {
            return 'Die .env des Releases fehlt.';
        }

        return null;
    }

    private function abandon(string $target): void
    {
        try {
            if ($this->filesystem()->directoryExists($target)) {
                $this->filesystem()->deleteDirectory($target);
                $this->say('Unvollständiges Release '.basename($target).' wurde entfernt.');
            }
        } catch (Throwable $exception) {
            $this->say('Das unvollständige Release '.basename($target).' konnte nicht entfernt werden ('.get_class($exception).'). Bitte per SFTP löschen.');
        }
    }

    public function list(): int
    {
        $current = $this->currentRelease();
        $this->say('Aktiv: '.($current ?? 'keines'));

        foreach ($this->releases() as $release) {
            $this->say('  '.$release);
        }

        return 0;
    }

    private function switchTo(string $release): ?string
    {
        $fs = $this->filesystem();
        $previous = null;

        if ($fs->directoryExists('current')) {
            $previous = $this->currentRelease() ?? 'unbekannt-'.gmdate('YmdHis');
            $parkTarget = 'releases/'.$previous;

            if ($fs->directoryExists($parkTarget)) {
                $parkTarget .= '-'.gmdate('YmdHis');
                $previous = basename($parkTarget);
            }

            $fs->move('current', $parkTarget);
        }

        $fs->move('releases/'.$release, 'current');

        return $previous;
    }

    private function currentRelease(): ?string
    {
        $fs = $this->filesystem();

        if (! $fs->fileExists('current/'.self::RELEASE_MARKER)) {
            return null;
        }

        $name = trim($fs->read('current/'.self::RELEASE_MARKER));

        return $name === '' ? null : $name;
    }

    /**
     * @return list<string>
     */
    private function releases(): array
    {
        $fs = $this->filesystem();

        if (! $fs->directoryExists('releases')) {
            return [];
        }

        $names = [];

        foreach ($fs->listContents('releases') as $entry) {
            if ($entry->isDir()) {
                $names[] = basename($entry->path());
            }
        }

        sort($names);

        return $names;
    }

    private function prune(): void
    {
        $releases = $this->releases();
        $excess = count($releases) - $this->keep;

        for ($i = 0; $i < $excess; $i++) {
            $this->filesystem()->deleteDirectory('releases/'.$releases[$i]);
            $this->say('Altes Release entfernt: '.$releases[$i]);
        }
    }

    private function smokeTest(string $baseUrl): ?string
    {
        $baseUrl = rtrim($baseUrl, '/');

        [$status] = $this->httpGet($baseUrl.'/up');

        if ($status !== 200) {
            return sprintf('/up antwortete mit Status %d statt 200.', $status);
        }

        foreach (['/.env', '/storage/logs/laravel.log', '/shared/.env', '/current/.env', '/vendor/autoload.php'] as $path) {
            [$status, $body] = $this->httpGet($baseUrl.$path);

            if ($status === 200 && (str_contains($body, 'APP_KEY') || str_contains($body, '<?php'))) {
                return sprintf('%s ist öffentlich erreichbar. Document Root oder Wurzel-.htaccess prüfen.', $path);
            }
        }

        return null;
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function httpGet(string $url): array
    {
        $context = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => 20, 'ignore_errors' => true, 'follow_location' => 0],
        ]);

        $body = @file_get_contents($url, false, $context);
        $status = 0;

        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return [$status, is_string($body) ? $body : ''];
    }

    private function symlinksSupported(): bool
    {
        $fs = $this->filesystem();
        $probe = 'shared/.symlink-probe-'.gmdate('YmdHis');

        try {
            $fs->write($probe, 'probe');
            $ok = $this->connection()->symlink(basename($probe), $this->absolutePath($probe.'.link'));

            if ($ok) {
                $this->connection()->delete($this->absolutePath($probe.'.link'), false);
            }

            $fs->delete($probe);

            return (bool) $ok;
        } catch (Throwable) {
            try {
                $fs->delete($probe);
            } catch (Throwable) {
                // Aufräumen ist hier zweitrangig.
            }

            return false;
        }
    }

    private function filesystem(): Filesystem
    {
        if ($this->filesystem !== null) {
            return $this->filesystem;
        }

        $root = $this->env['SFTP_DEPLOY_ROOT'] ?? '';

        if ($root === '') {
            throw new RuntimeException('SFTP_DEPLOY_ROOT ist nicht gesetzt.');
        }

        $adapter = new SftpAdapter(
            $this->provider(),
            $root,
            PortableVisibilityConverter::fromArray([
                'file' => ['public' => 0644, 'private' => 0600],
                'dir' => ['public' => 0755, 'private' => 0700],
            ]),
        );

        return $this->filesystem = new Filesystem($adapter, ['visibility' => 'public']);
    }

    private function connection(): SFTP
    {
        return $this->connection ??= $this->provider()->provideConnection();
    }

    /**
     * Absoluter Serverpfad unterhalb von SFTP_DEPLOY_ROOT. Flysystem stellt
     * jedem Pfad das Root voran, die rohe SFTP-Verbindung (symlink, delete)
     * tut das nicht.
     */
    private function absolutePath(string $path): string
    {
        $root = rtrim($this->env['SFTP_DEPLOY_ROOT'] ?? '', '/');

        return $root.'/'.ltrim($path, '/');
    }

    private function provider(): SftpConnectionProvider
    {
        if ($this->provider !== null) {
            return $this->provider;
        }

        $host = $this->env['SFTP_HOST'] ?? '';
        $user = $this->env['SFTP_USERNAME'] ?? '';

        if ($host === '' || $user === '') {
            throw new RuntimeException('SFTP_HOST und SFTP_USERNAME müssen als Umgebungsvariablen gesetzt sein.');
        }

        $password = ($this->env['SFTP_PASSWORD'] ?? '') !== '' ? $this->env['SFTP_PASSWORD'] : null;
        $key = ($this->env['SFTP_PRIVATE_KEY'] ?? '') !== '' ? $this->env['SFTP_PRIVATE_KEY'] : null;
        $keyPath = ($this->env['SFTP_PRIVATE_KEY_PATH'] ?? '') !== '' ? $this->env['SFTP_PRIVATE_KEY_PATH'] : null;

        if ($key === null && $keyPath !== null) {
            $content = @file_get_contents($keyPath);
            $key = $content === false ? null : $content;
        }

        if ($password === null && $key === null) {
            throw new RuntimeException('Entweder SFTP_PASSWORD oder SFTP_PRIVATE_KEY beziehungsweise SFTP_PRIVATE_KEY_PATH muss gesetzt sein.');
        }

        return $this->provider = new SftpConnectionProvider(
            host: $host,
            username: $user,
            password: $password,
            privateKey: $key,
            passphrase: ($this->env['SFTP_PASSPHRASE'] ?? '') !== '' ? $this->env['SFTP_PASSPHRASE'] : null,
            port: (int) (($this->env['SFTP_PORT'] ?? '') !== '' ? $this->env['SFTP_PORT'] : 22),
            useAgent: false,
            timeout: 30,
            maxTries: 3,
        );
    }

    private function releaseName(string $source): string
    {
        $versionFile = $source.'/public/version.json';

        if (is_file($versionFile)) {
            $decoded = json_decode((string) file_get_contents($versionFile), true);

            if (is_array($decoded) && is_string($decoded['release'] ?? null) && preg_match('/^[A-Za-z0-9._-]+$/', $decoded['release']) === 1) {
                return $decoded['release'];
            }
        }

        return 'release-'.gmdate('YmdHis');
    }

    private function say(string $message): void
    {
        fwrite($this->output, $message."\n");
    }

    private function fail(string $message): int
    {
        fwrite($this->errors, 'FEHLER: '.$message."\n");

        return 1;
    }
}

// --------------------------------------------------------------------------
// Argumente
// --------------------------------------------------------------------------

if (PHP_SAPI !== 'cli' || realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) {
    return;
}

$options = getopt('', ['source:', 'smoke-url::', 'keep::', 'dry-run', 'rollback:', 'list', 'help']);

if ($options === false || isset($options['help']) || ($options === [] && $argc > 1)) {
    fwrite(STDOUT, "Aufruf:\n  php bin/deploy-sftp.php --source=DIR [--smoke-url=URL] [--keep=3] [--dry-run]\n  php bin/deploy-sftp.php --rollback=RELEASE [--smoke-url=URL]\n  php bin/deploy-sftp.php --list\nZugangsdaten über SFTP_HOST, SFTP_PORT, SFTP_USERNAME, SFTP_PASSWORD oder SFTP_PRIVATE_KEY(_PATH), SFTP_DEPLOY_ROOT, SMOKE_TEST_URL.\n");
    exit(isset($options['help']) ? 0 : 2);
}

$env = [];

foreach (['SFTP_HOST', 'SFTP_PORT', 'SFTP_USERNAME', 'SFTP_PASSWORD', 'SFTP_PRIVATE_KEY', 'SFTP_PRIVATE_KEY_PATH', 'SFTP_PASSPHRASE', 'SFTP_DEPLOY_ROOT', 'SMOKE_TEST_URL'] as $name) {
    $value = getenv($name);
    $env[$name] = is_string($value) ? $value : '';
}

$smokeUrl = isset($options['smoke-url']) && is_string($options['smoke-url']) && $options['smoke-url'] !== ''
    ? $options['smoke-url']
    : ($env['SMOKE_TEST_URL'] !== '' ? $env['SMOKE_TEST_URL'] : null);

$deployer = new SftpDeployer(
    $env,
    isset($options['dry-run']),
    isset($options['keep']) && is_numeric($options['keep']) ? max(1, (int) $options['keep']) : 3,
    $smokeUrl,
);

try {
    if (isset($options['list'])) {
        exit($deployer->list());
    }

    if (isset($options['rollback']) && is_string($options['rollback'])) {
        exit($deployer->rollback($options['rollback']));
    }

    if (isset($options['source']) && is_string($options['source'])) {
        exit($deployer->deploy($options['source']));
    }

    fwrite(STDERR, "Bitte --source, --rollback oder --list angeben (--help für Details).\n");
    exit(2);
} catch (Throwable $exception) {
    fwrite(STDERR, 'FEHLER: Abbruch mit '.get_class($exception).". Zugangsdaten, Zielpfad und Netzverbindung prüfen.\n");
    exit(1);
}
