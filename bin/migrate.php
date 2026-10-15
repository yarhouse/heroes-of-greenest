<?php

declare(strict_types=1);

// migrate.php :: runs each db/migrations/*.sql not yet applied, in name order. bin/deploy.sh runs it here and there.
// A database with no migrations table is taken to match schema.sql (which drops it), so its files are only marked done.

require __DIR__ . '/../src/boot.php';

$fresh = !q("SHOW TABLES LIKE 'migrations'")->fetch();
q('CREATE TABLE IF NOT EXISTS migrations (
    name VARCHAR(100) PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
$done = q('SELECT name FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
foreach (glob(__DIR__ . '/../db/migrations/*.sql') ?: [] as $file) {
    $name = basename($file);
    if (in_array($name, $done, true)) {
        continue;
    }
    // shortcut: splits on a ; that ends a line, so none may end a line inside a string. ALTERs commit one by one, so
    // a failure midway leaves the statements before it applied: keep a migration to one statement when you can.
    foreach ($fresh ? [] : preg_split('/;\s*$/m', file_get_contents($file)) as $sql) {
        if (trim(preg_replace('/^\s*--.*$/m', '', $sql)) !== '') {
            db()->exec($sql);
        }
    }
    q('INSERT INTO migrations (name) VALUES (?)', [$name]);
    echo ($fresh ? 'marked ' : 'applied ') . "$name\n";
}
