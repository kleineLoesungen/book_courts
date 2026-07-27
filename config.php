<?php
declare(strict_types=1);

/**
 * Load .env configuration and populate global DB config variables.
 *
 * Replaces conf.php. Must be required before any pdo()/database call.
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

    $config = parse_ini_file($env_file, false, INI_SCANNER_NORMAL);
    if ($config === false) {
        throw new RuntimeException("Failed to parse .env file at {$env_file}");
    }

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

load_env_config();
