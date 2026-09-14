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

/**
 * PDO-Singleton.
 *
 * Liegt hier, damit index.php und admin.php dieselbe Verbindung und denselben
 * search_path verwenden - und damit die Login-Helfer weiter unten die Datenbank
 * erreichen.
 */
function pdo(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        global $dsn, $DB_USER, $DB_PASS, $DB_SCHEMA;

        // Schema-Namen validieren: er landet per String-Interpolation im SET-Befehl,
        // denn fuer Bezeichner gibt es keine Platzhalter.
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $DB_SCHEMA)) {
            throw new RuntimeException("Ungueltiger Schema-Name in DB_SCHEMA: {$DB_SCHEMA}");
        }

        $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('SET search_path TO "' . $DB_SCHEMA . '"');
    }
    return $pdo;
}

/* =======================
   Brute-Force-Schutz
   ======================= */

const LOGIN_WINDOW_MINUTES = 15;  // Beobachtungsfenster
const LOGIN_MAX_PER_EMAIL  = 5;   // Fehlversuche je Konto im Fenster
const LOGIN_MAX_PER_IP     = 20;  // Fehlversuche je IP im Fenster

function client_ip(): string
{
    // Bewusst nur REMOTE_ADDR: X-Forwarded-For ist vom Client faelschbar.
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

/**
 * Ist der Login fuer dieses Konto oder diese IP momentan gesperrt?
 *
 * Zwei Schwellen: die niedrige je Konto stoppt gezieltes Raten, die hohe je IP
 * bremst das Durchprobieren vieler Konten - ohne dass ein einzelner Fehlversuch
 * den ganzen Verein aussperrt, der oft hinter derselben IP sitzt.
 */
function login_is_blocked(string $email): bool
{
    $sql = "SELECT
              count(*) FILTER (WHERE lower(email) = lower(:email)) AS by_email,
              count(*) FILTER (WHERE ip = :ip) AS by_ip
            FROM login_attempt
            WHERE attempted_at > now() - make_interval(mins => :win::int)";
    $st = pdo()->prepare($sql);
    $st->execute([':email' => $email, ':ip' => client_ip(), ':win' => LOGIN_WINDOW_MINUTES]);
    $row = $st->fetch();

    return ((int) $row['by_email'] >= LOGIN_MAX_PER_EMAIL)
        || ((int) $row['by_ip'] >= LOGIN_MAX_PER_IP);
}

function login_record_failure(string $email): void
{
    pdo()->prepare("INSERT INTO login_attempt (email, ip) VALUES (:email, :ip)")
        ->execute([':email' => $email, ':ip' => client_ip()]);

    // Gelegentlich aufraeumen, damit die Tabelle nicht unbegrenzt waechst.
    if (random_int(1, 20) === 1) {
        pdo()->exec("DELETE FROM login_attempt WHERE attempted_at < now() - interval '1 day'");
    }
}

function login_clear_failures(string $email): void
{
    pdo()->prepare("DELETE FROM login_attempt WHERE lower(email) = lower(:email)")
        ->execute([':email' => $email]);
}

/**
 * Alte Termine aufraeumen - aber hoechstens einmal pro Tag.
 *
 * Frueher lief prune_old() bei jeder einzelnen Buchung: eine unbegrenzte
 * DELETE-Operation mitten im Request des Nutzers. Der Marker in der Tabelle
 * maintenance begrenzt das auf einen Lauf pro Tag. Das UPDATE mit WHERE-Klausel
 * liefert nur dann eine Zeile zurueck, wenn es tatsaechlich zugeschlagen hat -
 * damit raeumt auch bei gleichzeitigen Requests genau einer auf.
 */
function prune_old_if_due(int $days_back = 14): void
{
    $sql = "INSERT INTO maintenance (task, last_run) VALUES ('prune_old', now())
            ON CONFLICT (task) DO UPDATE SET last_run = now()
            WHERE maintenance.last_run < now() - interval '1 day'
            RETURNING 1";
    $due = pdo()->query($sql)->fetchColumn();

    if ($due) {
        $st = pdo()->prepare("SELECT * FROM prune_old(:days::int)");
        $st->execute([':days' => $days_back]);
        $st->fetch();
    }
}

/**
 * Verbrennt denselben Rechenaufwand wie eine echte Passwortpruefung.
 *
 * Ohne das antwortet der Login bei unbekannter E-Mail messbar schneller und
 * verraet damit, welche Adressen registriert sind.
 */
function dummy_password_verify(string $password): void
{
    static $hash = null;
    if ($hash === null) {
        $hash = password_hash('dummy', PASSWORD_DEFAULT);
    }
    password_verify($password, $hash);
}

load_env_config();
