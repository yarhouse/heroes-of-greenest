<?php

declare(strict_types=1);

// selftest.php :: end-to-end check of login and commands against the configured database.
// Runs inside a transaction that is rolled back, so it is safe to run anywhere. Usage: php tests/selftest.php

require __DIR__ . '/../src/boot.php';
require __DIR__ . '/../src/game.php';

$failed = false;
function check(bool $ok, string $what): void
{
    global $failed;
    echo ($ok ? 'ok    ' : 'FAIL  ') . $what . "\n";
    $failed = $failed || !$ok;
}
function hero(string $name): array
{
    return q('SELECT * FROM users WHERE name = ?', [$name])->fetch();
}
function says(array $lines, string $text): bool
{
    return (bool) array_filter($lines, fn ($l) => str_contains($l, $text));
}

db()->beginTransaction();
try {
    check(save_hero('Testa', 'short') !== '', 'rejects a short password');
    check(save_hero('Te5t', 'long enough') !== '', 'rejects a bad name');
    check(save_hero('Testa', 'correct horse') === '', 'creates a hero');
    save_hero('Testb', 'battery staple');

    check(login('Nobody', 'correct horse') === 'Wrong name or password.', 'unknown name fails like a wrong password');
    for ($i = 0; $i < MAX_FAILED_LOGINS; $i++) {
        login('Testa', 'wrong password');
    }
    check(str_starts_with(login('Testa', 'correct horse'), 'Too many'), 'locks after repeated failures');
    q("UPDATE users SET locked_until = NULL WHERE name = 'Testa'");
    check(login('testa', 'correct horse') === '', 'logs in, name is case-insensitive');
    check((session_user()['name'] ?? '') === 'Testa', 'session resolves to the hero');
    save_hero('Testa', 'new password!');
    check(session_user() === null, 'password reset ends old sessions');

    check(says(run_command(hero('Testa'), 'look'), 'Exits: north'), 'look shows exits');
    check(says(run_command(hero('Testa'), 'n'), 'Before the Keep'), 'n moves north');
    check(says(run_command(hero('Testa'), 'up'), "can't go up"), 'blocked exit is refused');
    run_command(hero('Testa'), 'south');
    check(says(run_command(hero('Testa'), 'xyzzy'), 'Huh?'), 'unknown command');

    run_command(hero('Testb'), 'look'); // Testb is online in the square
    check(says(run_command(hero('Testa'), "'meet at the inn"), 'You say: meet at the inn'), "' is say");
    $heard = run_command(hero('Testb'), 'who');
    check(says($heard, 'Testa says: meet at the inn'), 'others in the room hear it live');
    check(says($heard, 'Testa') && says($heard, 'Greenest Town Square'), 'who lists heroes and rooms');
    check(!says(run_command(hero('Testb'), 'who'), 'Testa says:'), 'live speech is shown once');
    $look = run_command(hero('Testb'), 'look');
    check(says($look, 'Testa said here just now: meet at the inn'), 'look shows recent speech');
    check(says($look, 'Testa is here.'), 'look shows heroes online here');
    check(!says(run_command(hero('Testb'), "say <b>x</b>\nfake line"), "\n"), 'control characters are stripped');

    // Autocomplete offers COMMANDS, so each must reach a handler. quit is handled by api.php, not run_command.
    foreach (array_keys(COMMANDS) as $usage) {
        $verb = explode(' ', $usage)[0];
        if ($verb !== 'quit') {
            check(!says(run_command(hero('Testa'), $verb), 'Huh?'), "help lists '$verb' and it runs");
        }
    }
    check(says(run_command(hero('Testa'), 'help'), 'say <text>'), 'help lists commands');
    $score = run_command(hero('Testa'), 'score');
    check(says($score, 'Testa, level 1') && says($score, 'HP 15/15') && says($score, '(18 for level 2)'), 'score');
    q("UPDATE users SET hp = 5, hp_at = NOW() - INTERVAL 300 SECOND WHERE name = 'Testa'");
    check(says(run_command(hero('Testa'), 'score'), 'HP 12/15'), 'regen: half of REGEN_SECS heals half of max HP');
    q("UPDATE users SET hp = 5, hp_at = NOW() - INTERVAL 1 DAY WHERE name = 'Testa'");
    check(says(run_command(hero('Testa'), 'score'), 'HP 15/15'), 'regen stops at max HP');

    // Items. Testa is in the square with strength 5, dexterity 5, 15 max HP and nothing carried.
    q("INSERT INTO items VALUES (9991, 'Test Blade', 'weapon', 99, 10, 0, 0, 0, 0),
        (9992, 'Test Club', 'weapon', 99, 3, 0, 0, 0, 0), (9993, 'Test Charm', 'trinket', 99, 0, 0, 10, 2, 0)");
    q('INSERT INTO objects (item_id, room_id) VALUES (9991, 1), (9992, 1), (9993, 1), (9993, 1), (9993, 1),
        (9993, 1)');
    check(says(run_command(hero('Testa'), 'look'), 'A Test Blade lies here.'), 'look shows items on the floor');
    check(says(run_command(hero('Testa'), 'i'), 'carrying nothing'), 'empty inventory');
    check(says(run_command(hero('Testa'), 'get bla'), 'You pick up the Test Blade.'), 'get by word prefix');
    check(!says(run_command(hero('Testa'), 'look'), 'Test Blade'), 'got items leave the floor');
    check(says(run_command(hero('Testb'), 'get blade'), "don't see a blade"), 'only one hero can get an item');
    check(says(run_command(hero('Testa'), 'equip club'), "aren't carrying"), "can't equip what you don't carry");
    run_command(hero('Testa'), 'equip blade');
    check(says(run_command(hero('Testa'), 'score'), 'Attack 15'), 'a weapon adds to attack');
    check(says(run_command(hero('Testa'), 'inventory'), '+10 attack  (equipped)'), 'inventory shows gear');
    run_command(hero('Testa'), 'get club');
    $out = run_command(hero('Testa'), 'equip club');
    check(says($out, 'put away the Test Blade') && says(run_command(hero('Testa'), 'score'), 'Attack 8'),
        'equipping a weapon swaps out the old one');
    foreach (range(1, 4) as $_) {
        run_command(hero('Testa'), 'get charm');
        run_command(hero('Testa'), 'equip charm');
    }
    $score = run_command(hero('Testa'), 'score');
    check(says($score, 'HP 45/45') && says($score, 'Strength 11') && says($score, 'Attack 14'),
        'trinkets add max HP and strength (and strength adds to attack), and regen fills the new max');
    check(says(run_command(hero('Testa'), 'equip charm'), 'already wear 3 trinkets'), 'at most 3 trinkets');
    check(says(run_command(hero('Testa'), 'drop club'), 'You drop the Test Club.'), 'drop');
    check(says(run_command(hero('Testa'), 'look'), 'A Test Club lies here.')
        && says(run_command(hero('Testa'), 'score'), 'Attack 11'), 'dropped gear is on the floor and no longer worn');
    $name = loot(1, START_ROOM);
    check(says(run_command(hero('Testa'), 'look'), a_an($name) . ' lies here.'), 'loot lands on the floor');
    q('UPDATE objects SET user_id = NULL, room_id = 1, worn = FALSE WHERE user_id = ?', [hero('Testa')['id']]);

    // Combat. Stats are rigged so each outcome is certain: armor 0 and dexterity 0 never dodge,
    // dexterity 10000 always wins initiative and escapes, max_dam 10000 always blocks escape and kills.
    q("INSERT INTO monsters VALUES (9999, 'Training Dummy', 50, 1, 0, 1, 6, 6)");
    q('INSERT INTO spawns (id, room_id, monster_id, dead_since) VALUES (9999, 2, 9999, NOW() - INTERVAL 1 DAY)');
    // hp 8 plus 300 s of regen is 15 when the fight starts, so the round check below also covers that.
    q("UPDATE users SET dexterity = 0, hp = 8, hp_at = NOW() - INTERVAL 300 SECOND, gold = 100 WHERE name = 'Testa'");
    check(says(run_command(hero('Testa'), 'n'), 'A Training Dummy is here.'), 'a dead monster respawns when read');
    $hp = (int) q('SELECT hp FROM spawns WHERE id = 9999')->fetchColumn();
    check($hp >= 40 && $hp <= 50, 'respawn rolls 80-100% of max HP');
    check(says(run_command(hero('Testa'), 'kill goblin'), "don't see a goblin"), 'kill needs a monster that is here');
    $round = run_command(hero('Testa'), 'k dum');
    check(
        says($round, 'You hit the Training Dummy') && says($round, 'hits you for 1 damage'),
        'k <word prefix> fights a round'
    );
    check(
        hero('Testa')['hp'] === 14 && hero('Testa')['fighting'] === 9999,
        'regen is banked when a fight starts, and the round is saved'
    );
    q("UPDATE users SET hp_at = NOW() - INTERVAL 1 DAY WHERE name = 'Testa'");
    check(says(run_command(hero('Testa'), 'score'), 'HP 14/15'), 'no regen while fighting');
    check(says(run_command(hero('Testa'), 'look'), 'Training Dummy is here, fighting you.'), 'look shows your foe');
    check(says(run_command(hero('Testa'), 's'), 'Flee first'), "can't walk out of a fight");

    q('UPDATE monsters SET max_dam = 10000 WHERE id = 9999');
    $out = run_command(hero('Testa'), 'flee');
    check(says($out, 'blocks your way') && says($out, 'You have died!'), 'a failed flee gives the monster a swing');
    $t = hero('Testa');
    check($t['room_id'] === START_ROOM && $t['hp'] === 4 && $t['gold'] === 50 && $t['fighting'] === null,
        'death: back to town with 1/4 HP and half the gold');

    q("UPDATE users SET dexterity = 10000 WHERE name = 'Testa'");
    q('UPDATE monsters SET max_dam = 1 WHERE id = 9999');
    run_command(hero('Testa'), 'n');
    run_command(hero('Testa'), 'kill dummy');
    $out = run_command(hero('Testa'), 'flee');
    check(says($out, 'You flee south') && hero('Testa')['fighting'] === null, 'flee escapes by a random exit');

    run_command(hero('Testb'), 'n');
    run_command(hero('Testb'), 'kill dummy');
    run_command(hero('Testa'), 'n');
    q('UPDATE spawns SET hp = 1 WHERE id = 9999');
    q("UPDATE users SET exp = 13 WHERE name = 'Testa'");
    $out = run_command(hero('Testa'), 'kill dummy');
    $t = hero('Testa');
    check(says($out, 'The Training Dummy dies!') && $t['exp'] >= 18 && $t['exp'] <= 19 && $t['gold'] >= 55,
        'a kill pays Dragon Knight experience and gold');
    check(says($out, 'You reach level 2!') && $t['level'] === 2 && $t['max_hp'] === 17 && $t['strength'] === 7
        && $t['dexterity'] === 10001, "enough experience levels up with Dragon Knight's gains");
    check(hero('Testb')['fighting'] === null, 'a kill ends the fight for everyone on that monster');
    check(!says(run_command(hero('Testa'), 'look'), 'Training Dummy'), 'dead monsters are gone');
    check(says(run_command(hero('Testa'), 'kill'), 'Kill what?'), 'kill with no target');
    q('UPDATE spawns SET dead_since = NOW() - INTERVAL 599 SECOND WHERE id = 9999');
    check(!says(run_command(hero('Testa'), 'look'), 'Training Dummy'), 'no respawn before respawn_secs');
    q('UPDATE spawns SET dead_since = NOW() - INTERVAL 601 SECOND WHERE id = 9999');
    check(says(run_command(hero('Testa'), 'look'), 'Training Dummy'), 'respawn after respawn_secs');
} finally {
    db()->rollBack();
}
exit($failed ? 1 : 0);
