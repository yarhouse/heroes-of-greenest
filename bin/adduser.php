<?php

declare(strict_types=1);

// adduser.php :: create a hero, or reset an existing hero's password. Usage: php bin/adduser.php <Name>

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../src/boot.php';

$name = $argv[1] ?? '';
$tty = stream_isatty(STDIN);
fwrite(STDERR, "Password for $name: ");
if ($tty) {
    shell_exec('stty -echo');
}
$password = rtrim((string) fgets(STDIN), "\r\n");
if ($tty) {
    shell_exec('stty echo');
    fwrite(STDERR, "\n");
}

$error = save_hero($name, $password);
if ($error !== '') {
    fwrite(STDERR, "$error\n");
    exit(1);
}
echo "Saved $name. Any of their open sessions are now logged out.\n";
