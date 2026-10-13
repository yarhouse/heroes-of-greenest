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
    q("UPDATE users SET resting = NULL, rest_until = NULL WHERE name = 'Testa'"); // the loop above ran 'rest'
    $score = run_command(hero('Testa'), 'score');
    check(says($score, 'Testa, level 1 Fighter') && says($score, 'Str 15 (+2)') && says($score, 'Int 8 (-1)')
        && says($score, 'HP 11/11   AC 17   Proficiency Bonus +2'), 'score: Standard Array Fighter, AC and HP');
    check(says($score, 'Greatsword +4 to hit, 2d6+2 damage') && says($score, 'Hit Dice 1/1 d10   Second Wind 2/2')
        && says($score, 'XP 0 (300 for level 2)'), 'score: attack, Hit Dice, Second Wind and XP');

    // Dice. Tests queue die faces in $dice_queue; each die rolled takes the next one.
    $dice_queue = [3, 4];
    check(roll('2d6+1') === 8, 'roll adds dice and modifier');
    $dice_queue = [1, 4];
    check(roll('1d4+2', true) === 7, 'a critical hit rolls the dice twice, not the modifier');
    $dice_queue = [6, 6, 2];
    check(roll('2d6+1d6-1') === 13, 'roll handles extra damage dice and a negative modifier');
    $r = roll('1d20');
    check($r >= 1 && $r <= 20, 'an empty queue rolls for real');
    foreach (['d6', '2d6+', '1d6 + 2'] as $bad) {
        try {
            roll($bad);
            check(false, "rejects dice '$bad'");
        } catch (LogicException) {
            check(true, "rejects dice '$bad'");
        }
    }
    $dice_queue = [7];
    try {
        roll('1d6');
        check(false, 'rejects a queued face too big for the die');
    } catch (LogicException) {
        check(true, 'rejects a queued face too big for the die');
    }
    $dice_queue = [];
    check(mod(8) === -1 && mod(9) === -1 && mod(10) === 0 && mod(15) === 2 && mod(20) === 5, 'ability modifiers');

    // Items. Until SRD gear they have no effect, but can be carried and worn.
    q("INSERT INTO items (id, name, slot, level) VALUES (9991, 'Test Blade', 'weapon', 99),
        (9992, 'Test Club', 'weapon', 99), (9993, 'Test Charm', 'trinket', 99)");
    q('INSERT INTO objects (item_id, room_id) VALUES (9991, 1), (9992, 1), (9993, 1), (9993, 1), (9993, 1),
        (9993, 1)');
    check(says(run_command(hero('Testa'), 'look'), 'A Test Blade lies here.'), 'look shows items on the floor');
    check(says(run_command(hero('Testa'), 'i'), 'carrying nothing'), 'empty inventory');
    check(says(run_command(hero('Testa'), 'get bla'), 'You pick up the Test Blade.'), 'get by word prefix');
    check(!says(run_command(hero('Testa'), 'look'), 'Test Blade'), 'got items leave the floor');
    check(says(run_command(hero('Testb'), 'get blade'), "don't see a blade"), 'only one hero can get an item');
    check(says(run_command(hero('Testa'), 'equip club'), "aren't carrying"), "can't equip what you don't carry");
    run_command(hero('Testa'), 'equip blade');
    check(says(run_command(hero('Testa'), 'inventory'), '(equipped)'), 'inventory shows worn gear');
    run_command(hero('Testa'), 'get club');
    check(says(run_command(hero('Testa'), 'equip club'), 'put away the Test Blade'), 'a new weapon swaps out the old');
    foreach (range(1, 4) as $_) {
        run_command(hero('Testa'), 'get charm');
        run_command(hero('Testa'), 'equip charm');
    }
    check(says(run_command(hero('Testa'), 'equip charm'), 'already wear 3 trinkets'), 'at most 3 trinkets');
    check(says(run_command(hero('Testa'), 'drop club'), 'You drop the Test Club.'), 'drop');
    check(says(run_command(hero('Testa'), 'look'), 'A Test Club lies here.'), 'dropped gear is on the floor');
    $name = loot(1, START_ROOM);
    check(says(run_command(hero('Testa'), 'look'), a_an($name) . ' lies here.'), 'loot lands on the floor');
    q('UPDATE objects SET user_id = NULL, room_id = 1, worn = FALSE WHERE user_id = ?', [hero('Testa')['id']]);

    // Rests and Second Wind. Testa is in the town square, a safe room.
    q("UPDATE users SET hp = 3 WHERE name = 'Testa'");
    check(says(run_command(hero('Testa'), 'rest'), 'settle in for a short rest'), 'rest starts a short rest');
    check(says(run_command(hero('Testa'), 'sleep'), 'already taking a short rest (10 min to go)'),
        'one rest at a time');
    check(!says(run_command(hero('Testa'), 'score'), 'You finish'), 'a rest takes time');
    q("UPDATE users SET rest_until = NOW() WHERE name = 'Testa'");
    $dice_queue = [6];
    $out = run_command(hero('Testa'), 'score');
    check(says($out, 'You finish a short rest, spending 1 Hit Die') && says($out, 'HP 10/11')
        && hero('Testa')['hit_dice_spent'] === 1, 'a short rest spends Hit Dice: d10 + Con modifier each');
    check(hero('Testa')['resting'] === null, 'a finished rest is cleared');
    $dice_queue = [5];
    $out = run_command(hero('Testa'), 'second');
    check(says($out, 'regain 1 HP. HP 11/11, 1 Second Wind left'), 'Second Wind heals 1d10 + level, up to max HP');
    check(says(run_command(hero('Testa'), 'second'), 'already at full health'), 'no Second Wind at full HP');
    q("UPDATE users SET hp = 2, second_wind_spent = 2, rest_until = NOW(), resting = 'short' WHERE name = 'Testa'");
    $out = run_command(hero('Testa'), 'score');
    check(says($out, 'spending 0 Hit Dice') && says($out, 'HP 2/11'), 'no Hit Dice left, no healing');
    check(says($out, 'Second Wind 1/2'), 'a short rest gives back one Second Wind');
    run_command(hero('Testa'), 'sleep');
    q("UPDATE users SET rest_until = NOW() WHERE name = 'Testa'");
    $out = run_command(hero('Testa'), 'score');
    $t = hero('Testa');
    check(says($out, 'You finish a long rest') && $t['hp'] === 11 && $t['hit_dice_spent'] === 0
        && $t['second_wind_spent'] === 0, 'a long rest restores HP, Hit Dice and Second Wind');
    run_command(hero('Testa'), 'rest');
    $out = run_command(hero('Testa'), 'n');
    check(says($out, 'You stop resting.') && hero('Testa')['resting'] === null, 'leaving the room cancels a rest');
    check(says(run_command(hero('Testa'), 'rest'), "isn't safe to rest here"), 'rest only in safe rooms');
    run_command(hero('Testa'), 's');

    // Combat, with every roll queued. Testa: +4 to hit, 2d6+2 damage, AC 17, initiative +2.
    q("INSERT INTO monsters (id, name, ac, hp, initiative, attack, attacks, to_hit, damage, cr, xp)
        VALUES (9999, 'Training Dummy', 10, '2d6', 0, 'Slam', 1, 3, '1d4', 0.125, 25)");
    q('INSERT INTO spawns (id, room_id, monster_id, dead_since) VALUES (9999, 2, 9999, NOW() - INTERVAL 1 DAY)');
    $dice_queue = [6, 6];
    check(says(run_command(hero('Testa'), 'n'), 'A Training Dummy is here.'), 'a dead monster respawns when read');
    check(q('SELECT hp FROM spawns WHERE id = 9999')->fetchColumn() === 12, 'respawn rolls the HP dice');
    check(says(run_command(hero('Testa'), 'kill goblin'), "don't see a goblin"), 'kill needs a monster that is here');
    $dice_queue = [10, 5, 12, 3, 4, 10]; // initiative 12 vs 5; hit 16 for 3+4+2; Slam 13 misses
    $round = run_command(hero('Testa'), 'k dum');
    check(says($round, 'Initiative: you 12, it 5') && says($round, 'You hit the Training Dummy (16 vs AC 10) for 9')
        && says($round, "The Training Dummy's Slam misses you (13 vs AC 17)")
        && says($round, 'Training Dummy: 3 HP'), 'k <word prefix> rolls initiative and fights a round');
    check(hero('Testa')['fighting'] === 9999 && hero('Testa')['foe_first'] === 0, 'the fight and its order are saved');
    $dice_queue = [1, 20, 2, 3]; // natural 1; natural 20 doubles the 1d4
    $round = run_command(hero('Testa'), 'kill');
    check(says($round, 'You miss the Training Dummy (natural 1)')
        && says($round, "Critical hit! The Training Dummy's Slam hits you (23 vs AC 17) for 5 damage.")
        && hero('Testa')['hp'] === 6, 'a natural 1 misses and a natural 20 crits');
    check(says(run_command(hero('Testa'), 'look'), 'Training Dummy is here, fighting you.'), 'look shows your foe');
    check(says(run_command(hero('Testa'), 's'), 'Flee first'), "can't walk out of a fight");
    check(says(run_command(hero('Testa'), 'rest'), 'middle of a fight'), "can't rest in a fight");
    $dice_queue = [4];
    $out = run_command(hero('Testa'), 'second');
    check(says($out, 'regain 5 HP') && !says($out, 'Slam'), 'Second Wind in a fight costs no turn');
    $dice_queue = [2, 15, 19, 4]; // flee 4 vs 15 fails; the opportunity attack hits for 4
    $out = run_command(hero('Testa'), 'flee');
    check(says($out, 'cuts you off (4 vs 15)') && says($out, 'hits you (22 vs AC 17) for 4')
        && hero('Testa')['hp'] === 7, 'a failed flee draws one attack');

    q("UPDATE users SET hp = 1, gold = 100 WHERE name = 'Testa'");
    $dice_queue = [2, 15, 1];
    $out = run_command(hero('Testa'), 'kill');
    $t = hero('Testa');
    check(says($out, 'You have died!') && $t['room_id'] === START_ROOM && $t['hp'] === 3 && $t['gold'] === 50
        && $t['fighting'] === null, 'death: back to town with 1/4 HP and half the gold');

    run_command(hero('Testa'), 'n');
    $dice_queue = [1, 20, 1, 1]; // initiative 3 vs 20: the dummy goes first, and both roll natural 1s
    $out = run_command(hero('Testa'), 'kill dummy');
    $slam = array_search(true, array_map(fn ($l) => str_contains($l, 'Slam misses'), $out), true);
    $swing = array_search(true, array_map(fn ($l) => str_contains($l, 'You miss'), $out), true);
    check(hero('Testa')['foe_first'] === 1 && $slam !== false && $swing !== false && $slam < $swing,
        'the initiative winner acts first each round');
    $dice_queue = [20, 1];
    $out = run_command(hero('Testa'), 'flee');
    check(says($out, 'You flee south') && hero('Testa')['fighting'] === null, 'flee escapes by a random exit');

    run_command(hero('Testb'), 'n');
    $dice_queue = [10, 1, 1, 1];
    run_command(hero('Testb'), 'kill dummy');
    run_command(hero('Testa'), 'n');
    q('UPDATE spawns SET hp = 1 WHERE id = 9999');
    q("UPDATE users SET xp = 290 WHERE name = 'Testa'");
    $dice_queue = [10, 1, 15, 1, 1, 4, 2]; // hit; gold 1d6 = 4; loot 1d30 = 2 (none)
    $out = run_command(hero('Testa'), 'kill dummy');
    $t = hero('Testa');
    check(says($out, 'The Training Dummy dies! You gain 25 XP and 4 gold.') && $t['xp'] === 315
        && $t['gold'] === 54, "a kill pays the stat block's XP and gold by CR");
    check(says($out, 'You reach level 2! +7 max HP.') && $t['level'] === 2 && $t['max_hp'] === 18 && $t['hp'] === 10,
        'enough XP levels up: 6 + Con modifier HP');
    check(hero('Testb')['fighting'] === null, 'a kill ends the fight for everyone on that monster');
    check($dice_queue === [], 'every queued die was used');
    check(!says(run_command(hero('Testa'), 'look'), 'Training Dummy'), 'dead monsters are gone');
    check(says(run_command(hero('Testa'), 'kill'), 'Kill what?'), 'kill with no target');
    q('UPDATE spawns SET dead_since = NOW() - INTERVAL 599 SECOND WHERE id = 9999');
    check(!says(run_command(hero('Testa'), 'look'), 'Training Dummy'), 'no respawn before respawn_secs');
    q('UPDATE spawns SET dead_since = NOW() - INTERVAL 601 SECOND WHERE id = 9999');
    check(says(run_command(hero('Testa'), 'look'), 'Training Dummy'), 'respawn after respawn_secs');
    q("UPDATE users SET level = 5 WHERE name = 'Testa'");
    $score = run_command(hero('Testa'), 'score');
    check(says($score, 'Proficiency Bonus +3') && says($score, '+5 to hit, 2d6+2 damage, 2 attacks a round')
        && says($score, 'Second Wind 2/3'), 'level 5: Proficiency Bonus +3, Extra Attack, more Second Wind');

    // Converted areas (db/areas/*.sql). Kobold Mines hangs off the Southern Fields.
    q("UPDATE users SET room_id = 6 WHERE name = 'Testa'");
    check(says(run_command(hero('Testa'), 's'), 'Within a Dark Crevice'), 'Southern Fields lead south to Kobold Mines');
    check(says(run_command(hero('Testa'), 'd'), 'Exits: east west up down'), 'converted exits inside the area');
    check(says(run_command(hero('Testa'), 'u'), 'Exits: north down'), 'exits leaving the area are dropped');
    check(says(run_command(hero('Testa'), 'n'), 'Southern Fields'), 'the way back to Greenest');
    q("UPDATE users SET room_id = 24609 WHERE name = 'Testa'");
    check(says(run_command(hero('Testa'), 'look'), 'A Goblin Child is here.'), 'converted monsters spawn');
} finally {
    db()->rollBack();
}
exit($failed ? 1 : 0);
