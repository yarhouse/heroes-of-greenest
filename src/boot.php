<?php

declare(strict_types=1);

// boot.php :: config, database, sessions and login, shared by every entry point.

const SESSION_DAYS = 30;
const MAX_FAILED_LOGINS = 5;
const LOCK_MINUTES = 15;

$config = require __DIR__ . '/../config.php';

function db(): PDO
{
    static $pdo = null;
    global $config;
    return $pdo ??= new PDO($config['dsn'], $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

/** Run a prepared statement. Every query goes through here; never build SQL from input. */
function q(string $sql, array $args = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st;
}

function is_https(): bool
{
    return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
}

/** Security headers, session start, and refusal of cross-site POSTs. Call first in every web entry point. */
function web_init(): void
{
    header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');

    // Private session dir: shared hosting's /tmp is visible to other accounts and their cleanup settings.
    $dir = __DIR__ . '/../var/sessions';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    session_save_path($dir);
    session_name('greenest');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string) (SESSION_DAYS * 86400));
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
    session_set_cookie_params([
        'lifetime' => SESSION_DAYS * 86400,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        $self = (is_https() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '');
        if ($origin !== null && $origin !== $self) {
            http_response_code(403);
            exit('Cross-site request refused.');
        }
    }
}

/** Ties a session to the password it logged in with, so a password reset ends every old session. */
function pw_version(string $hash): string
{
    return hash('sha256', $hash);
}

/** Logs in and returns '' on success, or an error message for the player. */
function login(string $name, string $password): string
{
    $u = q('SELECT id, pass_hash, failed_logins, locked_until > NOW() AS locked FROM users WHERE name = ?', [$name])
        ->fetch();
    if (!$u) {
        password_hash($password, PASSWORD_DEFAULT); // same work as a real check, so misses don't reveal valid names
        return 'Wrong name or password.';
    }
    if ($u['locked']) {
        return 'Too many failed logins. Try again in ' . LOCK_MINUTES . ' minutes.';
    }
    if (!password_verify($password, $u['pass_hash'])) {
        $fails = $u['failed_logins'] + 1;
        $lock = $fails >= MAX_FAILED_LOGINS;
        q(
            'UPDATE users SET failed_logins = ?, locked_until = IF(?, NOW() + INTERVAL ? MINUTE, NULL) WHERE id = ?',
            [$lock ? 0 : $fails, (int) $lock, LOCK_MINUTES, $u['id']]
        );
        return 'Wrong name or password.';
    }

    $hash = $u['pass_hash'];
    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
    }
    q('UPDATE users SET failed_logins = 0, locked_until = NULL, pass_hash = ? WHERE id = ?', [$hash, $u['id']]);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['uid'] = $u['id'];
    $_SESSION['pwv'] = pw_version($hash);
    return '';
}

function logout(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/** The logged-in hero's row, or null. */
function session_user(): ?array
{
    $id = $_SESSION['uid'] ?? null;
    if (!is_int($id)) {
        return null;
    }
    $me = q('SELECT * FROM users WHERE id = ?', [$id])->fetch();
    if (!$me || !hash_equals(pw_version($me['pass_hash']), $_SESSION['pwv'] ?? '')) {
        logout();
        return null;
    }
    return $me;
}

/** Creates a hero, or resets their password (and unlocks them) if the name exists. Returns '' or an error. */
function save_hero(string $name, string $password): string
{
    if (!preg_match('/^[A-Za-z]{3,16}$/', $name)) {
        return 'Name must be 3-16 letters.';
    }
    $len = strlen($password);
    if ($len < 8 || $len > 72) { // bcrypt ignores bytes past 72
        return 'Password must be 8-72 characters.';
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    q(
        'INSERT INTO users (name, pass_hash, last_event_id) SELECT ?, ?, COALESCE(MAX(id), 0) FROM events
         ON DUPLICATE KEY UPDATE pass_hash = ?, failed_logins = 0, locked_until = NULL',
        [$name, $hash, $hash]
    );
    return '';
}
