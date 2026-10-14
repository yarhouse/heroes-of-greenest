<?php

declare(strict_types=1);

// reclass.php :: send a hero back to character creation: on their next login they choose a class, ability scores,
// species and background again, and start over at level 1 with that class's kit. Usage: php bin/reclass.php <Name>

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../src/boot.php';

$name = $argv[1] ?? '';
if (!q('SELECT 1 FROM users WHERE name = ?', [$name])->fetch()) {
    fwrite(STDERR, "There's no hero called '$name'. Create one with bin/adduser.php.\n");
    exit(1);
}
q("UPDATE users SET setup = 'class' WHERE name = ?", [$name]);
echo "$name will create their hero again on their next login (or the next command, if they're playing now).\n";
