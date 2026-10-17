<?php

declare(strict_types=1);

// api.php :: runs one player command; replies { lines, actions, commands, heroes, choices?, panel?, hero?, map?,
// board?, logout? }
// as JSON.

require __DIR__ . '/../src/boot.php';
require __DIR__ . '/../src/game.php';

web_init();
header('Content-Type: application/json; charset=utf-8');

function reply(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reply(['lines' => ['POST only.']], 405);
}
// A JSON body can't be sent cross-site without a CORS preflight, which we never grant.
if (!str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
    reply(['lines' => ['JSON only.']], 415);
}
$me = session_user();
if (!$me) {
    reply(['lines' => ['You are not logged in.'], 'logout' => true], 401);
}

$body = json_decode(file_get_contents('php://input'), true);
$cmd = is_string($body['cmd'] ?? null) ? $body['cmd'] : '';
if (strtolower(trim($cmd)) === 'quit') {
    logout();
    reply(['lines' => ['Farewell, hero.'], 'logout' => true]);
}
$lines = run_command($me, $cmd);
$me = q('SELECT * FROM users WHERE id = ?', [$me['id']])->fetch(); // the command may have changed the hero
$heroes = q('SELECT name FROM users WHERE name IS NOT NULL AND id <> ?', [$me['id']])->fetchAll(PDO::FETCH_COLUMN);
$more = ['actions' => actions($me), 'commands' => my_commands($me), 'heroes' => $heroes]; // play.js picks them out
$more += array_filter(['panel' => panel($me)]); // the choice I'm making, if any
if ($me['setup'] !== null) {
    reply(['lines' => $lines, 'choices' => choices($me)] + $more); // no sheet while creating
}
$me = with_stats($me);
reply(['lines' => $lines, 'hero' => hero_sheet($me), 'map' => cmd_map($me), 'board' => $me['fighting'] !== null,
    'choices' => choices($me)] + $more);
