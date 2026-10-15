<?php

declare(strict_types=1);

// requests.php :: account requests from account.php. Usage: php bin/requests.php [approve|deny <Account>]
// With no arguments, lists them. Approving makes the account, whose player names their hero on first login.

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../src/boot.php';

[, $op, $account] = $argv + [1 => '', 2 => ''];
if ($op === '') {
    $rows = q('SELECT account, created_at, note FROM requests ORDER BY created_at')->fetchAll();
    foreach ($rows as $r) {
        printf("%-16s %s  %s\n", $r['account'], $r['created_at'], $r['note']);
    }
    echo $rows ? '' : "No requests.\n";
    exit;
}
if (!in_array($op, ['approve', 'deny'], true) || !q('SELECT 1 FROM requests WHERE account = ?', [$account])->fetch()) {
    fwrite(STDERR, "Usage: php bin/requests.php [approve|deny <Account>], for an account listed with no arguments.\n");
    exit(1);
}
$error = $op === 'approve' ? approve_request($account) : '';
if ($error !== '') {
    fwrite(STDERR, "$error\n");
    exit(1);
}
q('DELETE FROM requests WHERE account = ?', [$account]);
echo $op === 'approve' ? "Approved $account: they can log in now.\n" : "Denied $account.\n";
