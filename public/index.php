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
<html lang="en" data-bs-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Heroes of Greenest</title>
<link rel="stylesheet" href="vendor/bootstrap/bootstrap.min.css">
<link rel="stylesheet" href="style.css">
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<link rel="icon" href="favicon.ico" sizes="16x16 32x32 48x48">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
</head>
<body>
<?php if ($me) : ?>
<main class="play">
    <div id="log" role="log" aria-live="polite"></div>
    <div id="actions" role="toolbar" aria-label="Quick commands"></div>
    <ul id="hints" role="listbox" aria-label="Matching commands" hidden></ul>
    <form id="prompt" autocomplete="off">
        <label for="cmd" class="visually-hidden">Command</label>
        <span aria-hidden="true">&gt;</span>
        <input id="cmd" autofocus autocapitalize="off" spellcheck="false" maxlength="300"
            role="combobox" aria-autocomplete="list" aria-controls="hints" aria-expanded="false"
            data-commands="<?= $e(json_encode(my_commands($me))) ?>">
        <button id="hero-toggle" type="button" class="btn btn-sm d-lg-none" data-bs-toggle="offcanvas"
            data-bs-target="#hero" aria-controls="hero" hidden>Hero</button>
    </form>
    <!-- The hero sheet: docked beside the log on wide screens, a slide-in drawer on phones. play.js fills it. -->
    <aside id="hero" class="offcanvas-lg offcanvas-end" tabindex="-1" aria-labelledby="hero-name">
        <div class="offcanvas-header pb-0">
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="offcanvas" data-bs-target="#hero"
                aria-label="Close"></button>
        </div>
        <div class="offcanvas-body d-flex flex-column flex-grow-1 pb-0">
            <section id="sheet" class="card w-100" hidden>
                <header class="card-header d-flex align-items-baseline justify-content-between gap-2">
                    <h2 id="hero-name" class="h5 m-0"></h2>
                    <span id="hero-class" class="small text-body-secondary text-end"></span>
                </header>
                <div class="card-body vstack gap-3">
                    <div>
                        <div class="d-flex justify-content-between small">
                            <span class="text-body-secondary">HP</span><span id="hp-text"></span>
                        </div>
                        <div id="hp-bar" class="progress" role="progressbar" aria-label="Hit points">
                            <div class="progress-bar"></div>
                        </div>
                    </div>
                    <div id="tiles" class="row row-cols-3 g-1"></div>
                    <div id="abilities" class="row row-cols-6 g-1" aria-label="Ability modifiers and scores"></div>
                    <dl id="rows" class="vstack gap-1 small m-0"></dl>
                    <div id="status"></div>
                    <div>
                        <div class="d-flex justify-content-between small">
                            <span class="text-body-secondary">XP</span><span id="xp-text"></span>
                        </div>
                        <div id="xp-bar" class="progress" role="progressbar" aria-label="Experience to next level">
                            <div class="progress-bar"></div>
                        </div>
                    </div>
                </div>
                <pre id="map" class="card-footer m-0" aria-label="Map"></pre>
            </section>
            <p class="aside mt-auto mb-0"><a href="account.php">Account and password</a></p>
        </div>
    </aside>
</main>
<script src="vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="play.js"></script>
<?php else : ?>
<main class="login">
    <h1>Heroes of Greenest</h1>
    <form method="post">
        <?php if ($error) : ?><p class="error" role="alert"><?= $e($error) ?></p><?php endif ?>
        <label>Account <input name="name" required autocomplete="username" value="<?= $e($name) ?>"></label>
        <label>Password <input name="password" type="password" required autocomplete="current-password"></label>
        <button>Enter</button>
    </form>
    <p class="aside"><a href="account.php">Request an account</a></p>
    <p class="credits">This work includes material from the System Reference Document 5.2.1 ("SRD 5.2.1") by Wizards of
        the Coast LLC, available at <a href="https://www.dndbeyond.com/srd">https://www.dndbeyond.com/srd</a>. The SRD
        5.2.1 is licensed under the Creative Commons Attribution 4.0 International License, available at
        <a href="https://creativecommons.org/licenses/by/4.0/legalcode">https://creativecommons.org/licenses/by/4.0/legalcode</a>.
    </p>
</main>
<?php endif ?>
</body>
</html>
