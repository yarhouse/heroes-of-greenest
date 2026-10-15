<?php

declare(strict_types=1);

// account.php :: logged out, ask the DM for an account; logged in, change your account name or password.

require __DIR__ . '/../src/boot.php';

web_init();

$me = session_user();
$post = fn (string $key): string => is_string($_POST[$key] ?? null) ? $_POST[$key] : '';
$account = $me['account'] ?? '';
$note = '';
$error = '';
$done = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $account = trim($post('account'));
    $note = $post('note');
    $new = $post('password');
    if ($new !== $post('again')) {
        $error = "The passwords don't match.";
    } elseif ($me) {
        $error = update_account($me, $post('current'), $account, $new);
        $done = 'Saved.' . ($new === '' ? '' : ' Any other sessions are now logged out.');
    } else {
        $error = request_account($account, $new, $note);
        $done = 'Request sent. You can log in once the DM approves it.';
    }
    $me = session_user() ?? $me;
}
$e = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
?>
<!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Heroes of Greenest: <?= $me ? 'Account' : 'Request an account' ?></title>
<link rel="stylesheet" href="vendor/bootstrap/bootstrap.min.css">
<link rel="stylesheet" href="style.css">
</head>
<body>
<main class="login">
    <h1><?= $me ? 'Your account' : 'Request an account' ?></h1>
    <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) : ?>
    <p class="done" role="status"><?= $e($done) ?></p>
    <?php endif ?>
    <?php if ($me || $error || $_SERVER['REQUEST_METHOD'] !== 'POST') : ?>
    <form method="post">
        <?php if ($error) : ?><p class="error" role="alert"><?= $e($error) ?></p><?php endif ?>
        <?php if ($me && $me['name'] !== null) : ?><p>Hero: <?= $e($me['name']) ?></p><?php endif ?>
        <label>Account name
            <input name="account" required autocomplete="username" pattern="[A-Za-z]{3,16}" maxlength="16"
                value="<?= $e($account) ?>">
            <small>3-16 letters, used only to log in. You name your hero in the game.</small>
        </label>
        <?php if ($me) : ?>
        <label>New password <small>Leave blank to keep yours.</small>
            <input name="password" type="password" minlength="8" maxlength="72" autocomplete="new-password">
        </label>
        <label>New password again <input name="again" type="password" autocomplete="new-password"></label>
        <label>Current password
            <input name="current" type="password" required autocomplete="current-password">
        </label>
        <button>Save</button>
        <?php else : ?>
        <label>Password <small>8-72 characters.</small>
            <input name="password" type="password" required minlength="8" maxlength="72" autocomplete="new-password">
        </label>
        <label>Password again <input name="again" type="password" required autocomplete="new-password"></label>
        <label>Who are you?
            <input name="note" required maxlength="200" value="<?= $e($note) ?>">
            <small>So the DM knows it's you, e.g. "Sam, plays Thorin on Thursdays".</small>
        </label>
        <button>Send request</button>
        <?php endif ?>
    </form>
    <?php endif ?>
    <p class="aside"><a href="./"><?= $me ? 'Back to the game' : 'Back to log in' ?></a></p>
</main>
</body>
</html>
