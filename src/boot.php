<?php

declare(strict_types=1);

// boot.php :: config, database, sessions and login, shared by every entry point.

const SESSION_DAYS = 30;
const MAX_FAILED_LOGINS = 5;
const LOCK_MINUTES = 15;
const NAME_RULE = '/^[A-Za-z]{3,16}$/'; // account names and hero names
const MAX_REQUESTS = 20; // pending account requests; the form refuses more until the DM approves or denies some

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

/** The folder an entry point is served from, as a cookie path: '/heroes-of-greenest/' when hosted, '/' under php -S. */
function cookie_path(string $script): string
{
    return rtrim(dirname($script), '/\\') . '/';
}

/** Security headers, session start, and refusal of cross-site POSTs. Call first in every web entry point. */
function web_init(): void
{
    // img data: is for Bootstrap's built-in icons (close button, checkboxes, selects).
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; frame-ancestors 'none'");
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
    // Scoped to the game's folder so the rest of yarhouse.dev never receives the session cookie.
    $path = cookie_path($_SERVER['SCRIPT_NAME'] ?? '/');
    session_set_cookie_params([
        'lifetime' => SESSION_DAYS * 86400,
        'path' => $path,
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
    // shortcut: expires the cookie set on '/' before scoping (logs those heroes out once); drop after 2026-11-15.
    if ($path !== '/' && isset($_COOKIE['greenest'])) {
        setcookie('greenest', '', ['expires' => 1, 'path' => '/']);
    }

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

/** Logs in by account name and returns '' on success, or an error message for the player. */
function login(string $account, string $password): string
{
    $u = q(
        'SELECT id, pass_hash, failed_logins, locked_until > NOW() AS locked FROM users WHERE account = ?',
        [$account]
    )->fetch();
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

/** '' if the password is acceptable, else why not. */
function password_error(string $password): string
{
    $len = strlen($password);
    return $len < 8 || $len > 72 ? 'Password must be 8-72 characters.' : ''; // bcrypt ignores bytes past 72
}

/**
 * Creates an account, whose player names their hero and chooses a class and abilities on first login, or resets its
 * password (and unlocks it) if the account exists. Returns '' or an error.
 */
function save_account(string $account, string $password): string
{
    if (!preg_match(NAME_RULE, $account)) {
        return 'Account name must be 3-16 letters.';
    }
    if ($error = password_error($password)) {
        return $error;
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    q(
        'INSERT INTO users (account, pass_hash, last_event_id) SELECT ?, ?, COALESCE(MAX(id), 0) FROM events
         ON DUPLICATE KEY UPDATE pass_hash = ?, failed_logins = 0, locked_until = NULL',
        [$account, $hash, $hash]
    );
    return '';
}

/**
 * Asks the DM for an account (bin/requests.php approves it). Returns '' or an error. A name that's taken, by an
 * account or an earlier request, is dropped silently, so the form can't be used to find account names.
 */
function request_account(string $account, string $password, string $note): string
{
    $note = trim(preg_replace('/\p{C}/u', '', $note) ?? ''); // the DM reads these in a terminal: no escape codes
    if (!preg_match(NAME_RULE, $account)) {
        return 'Account name must be 3-16 letters.';
    }
    if ($error = password_error($password)) {
        return $error;
    }
    if ($note === '' || mb_strlen($note) > 200) {
        return 'Say who you are (up to 200 characters), so the DM knows you.';
    }
    if (q('SELECT COUNT(*) FROM requests')->fetchColumn() >= MAX_REQUESTS) {
        return 'Too many requests are waiting. Ask the DM to look at them.';
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if (!q('SELECT 1 FROM users WHERE account = ?', [$account])->fetch()) {
        q('INSERT IGNORE INTO requests (account, pass_hash, note) VALUES (?, ?, ?)', [$account, $hash, $note]);
    }
    return '';
}

/** Makes the account a request asked for, and removes the request. Returns '' or an error for the DM. */
function approve_request(string $account): string
{
    if (q('SELECT 1 FROM users WHERE account = ?', [$account])->fetch()) {
        return "There's already an account called $account. Deny this request; they can ask again.";
    }
    $made = q(
        'INSERT INTO users (account, pass_hash, last_event_id)
         SELECT account, pass_hash, (SELECT COALESCE(MAX(id), 0) FROM events) FROM requests WHERE account = ?',
        [$account]
    )->rowCount();
    q('DELETE FROM requests WHERE account = ?', [$account]);
    return $made ? '' : "There's no request for $account.";
}

/**
 * Changes the logged-in player's account name and, unless $new is '', their password. $current is checked like a
 * login, failures and lockout included. Other sessions end when the password changes; this one carries on.
 */
function update_account(array $me, string $current, string $account, string $new): string
{
    if (!preg_match(NAME_RULE, $account)) {
        return 'Account name must be 3-16 letters.';
    }
    if ($new !== '' && $error = password_error($new)) {
        return $error;
    }
    if ($error = login($me['account'], $current)) {
        return $error === 'Wrong name or password.' ? 'Wrong current password.' : $error;
    }
    if (q('SELECT 1 FROM users WHERE account = ? AND id <> ?', [$account, $me['id']])->fetch()) {
        return "There's already an account called $account.";
    }
    q('UPDATE users SET account = ? WHERE id = ?', [$account, $me['id']]);
    if ($new !== '') {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        q('UPDATE users SET pass_hash = ? WHERE id = ?', [$hash, $me['id']]);
        $_SESSION['pwv'] = pw_version($hash);
    }
    return '';
}
