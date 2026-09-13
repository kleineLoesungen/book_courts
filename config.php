<?php
declare(strict_types=1);

/**
 * Parse a .env file into a key/value array.
 *
 * Bewusst KEIN parse_ini_file(): der INI-Parser behandelt ';' und '#' als
 * Kommentar-Start und wandelt Werte wie 'off', 'no' oder 'none' in einen leeren
 * String um. Ein Passwort darf aber jedes beliebige Zeichen enthalten. Hier wird
 * der Wert deshalb unveraendert uebernommen: alles nach dem ersten '=' bis zum
 * Zeilenende.
 *
 * @throws RuntimeException if the file cannot be read
 */
function parse_env_file(string $path): array
{
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException("Failed to read .env file at {$path}");
    }

    $config = [];
    foreach ($lines as $line) {
        $line = trim($line);

        // Leerzeilen und reine Kommentarzeilen ueberspringen.
        // Inline-Kommentare gibt es bewusst nicht - sonst waere ein '#' oder ';'
        // im Passwort wieder ein Problem.
        if ($line === '' || $line[0] === '#' || $line[0] === ';') {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);

        // Optional umschliessende Anfuehrungszeichen entfernen. Noetig ist das nur
        // noch fuer Werte mit fuehrendem oder nachgestelltem Leerzeichen.
        $len = strlen($value);
        if (
            $len >= 2
            && (($value[0] === '"' && $value[$len - 1] === '"')
                || ($value[0] === "'" && $value[$len - 1] === "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        $config[$key] = $value;
    }

    return $config;
}

/**
 * Load .env configuration and populate global DB config variables.
 *
 * Must be required before any pdo()/database call.
 *
 * @throws RuntimeException if .env is missing, unreadable, or missing required keys
 */
function load_env_config(): void
{
    $env_file = __DIR__ . '/.env';

    if (!file_exists($env_file)) {
        throw new RuntimeException(
            "Configuration file not found: {$env_file}\n" .
            "Copy .env.example to .env, fill in real values, then run: chmod 600 .env"
        );
    }

    $config = parse_env_file($env_file);

    $required = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_SCHEMA'];
    foreach ($required as $key) {
        if (!array_key_exists($key, $config) || $config[$key] === '') {
            throw new RuntimeException("Missing or empty configuration key in .env: {$key}");
        }
    }

    global $DB_HOST, $DB_PORT, $DB_NAME, $DB_USER, $DB_PASS, $DB_SCHEMA, $dsn;
    $DB_HOST = (string) $config['DB_HOST'];
    $DB_PORT = (string) $config['DB_PORT'];
    $DB_NAME = (string) $config['DB_NAME'];
    $DB_USER = (string) $config['DB_USER'];
    $DB_PASS = (string) $config['DB_PASS'];
    $DB_SCHEMA = (string) $config['DB_SCHEMA'];

    $dsn = "pgsql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};options='--client_encoding=UTF8'";
}

/**
 * Session mit gehaerteten Cookie-Einstellungen starten.
 *
 * Ersetzt das blanke session_start() in index.php und admin.php. Die Funktion
 * liegt hier, weil config.php die einzige Datei ist, die beide Entrypoints
 * ohnehin einbinden - eine zusaetzliche Datei muesste in deploy.sh nachgetragen
 * werden.
 */
function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // Secure-Flag nur bei HTTPS setzen, sonst waere die Session lokal (http) unbrauchbar.
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,        // Cookie endet mit dem Browserfenster
        'path'     => '/',
        'httponly' => true,     // kein Zugriff per JavaScript
        'secure'   => $https,
        'samesite' => 'Lax',    // zusaetzlicher Schutz gegen CSRF
    ]);

    session_start();

    // Idle-Timeout: nach 8 Stunden ohne Aktivitaet wird die Sitzung verworfen.
    $max_idle = 8 * 3600;
    if (isset($_SESSION['last_activity']) && (time() - (int) $_SESSION['last_activity']) > $max_idle) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last_activity'] = time();
}

/**
 * Sitzung vollstaendig beenden: Daten leeren, Cookie loeschen, Session verwerfen.
 *
 * session_destroy() allein laesst das Session-Cookie im Browser stehen.
 */
function destroy_session(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}

load_env_config();
