<?php

declare(strict_types=1);

// index.php :: the login form, or the play page once logged in.

require __DIR__ . '/../src/boot.php';
require __DIR__ . '/../src/game.php';

web_init();

$name = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
    $pass = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $error = login($name, $pass);
    if ($error === '') {
        header('Location: ./', true, 303);
        exit;
    }
}
$me = session_user();
$e = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Heroes of Greenest</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php if ($me) : ?>
<main class="play">
    <div id="log" role="log" aria-live="polite"></div>
    <ul id="hints" role="listbox" aria-label="Matching commands" hidden></ul>
    <form id="prompt" autocomplete="off">
        <label for="cmd" class="sr-only">Command</label>
        <span aria-hidden="true">&gt;</span>
        <input id="cmd" autofocus autocapitalize="off" spellcheck="false" maxlength="300"
            role="combobox" aria-autocomplete="list" aria-controls="hints" aria-expanded="false"
            data-commands="<?= $e(json_encode(COMMANDS)) ?>">
    </form>
</main>
<script src="play.js"></script>
<?php else : ?>
<main class="login">
    <h1>Heroes of Greenest</h1>
    <form method="post">
        <?php if ($error) : ?><p class="error" role="alert"><?= $e($error) ?></p><?php endif ?>
        <label>Name <input name="name" required autocomplete="username" value="<?= $e($name) ?>"></label>
        <label>Password <input name="password" type="password" required autocomplete="current-password"></label>
        <button>Enter</button>
    </form>
</main>
<?php endif ?>
</body>
</html>
