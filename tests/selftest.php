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
/** The first line containing $text, or ''. */
function line(array $lines, string $text): string
{
    return array_values(array_filter($lines, fn ($l) => str_contains($l, $text)))[0] ?? '';
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

    // Map: Town Square (safe) has Market Lane west, the inn (safe) east, the keep north, the Mill Bridge (stair down) south.
    q("UPDATE users SET room_id = 1 WHERE name = 'Testa'");
    $map = cmd_map(hero('Testa'));
    check(mb_substr($map[5], 5, 11) === '[ ]─(@)─( )' && mb_substr($map[3], 9, 3) === '[ ]'
        && mb_substr($map[4], 10, 1) === '│' && mb_substr($map[7], 9, 3) === '[v]', 'map: rooms, links, safe, stairs');
    check($map[0] === '' && $map[5][0] === ' ', 'map: nothing beyond the reach of the Town Square');
    $foe = q('SELECT room_id FROM spawns ORDER BY id LIMIT 1')->fetchColumn();
    q('UPDATE users SET room_id = ? WHERE name = ?', [$foe, 'Testa']);
    $dice_queue = [6];
    $map = cmd_map(hero('Testa'));
    check(str_starts_with($map[count($map) - 2], 'Here: ') && !says($map, 'no monsters') && $dice_queue === [6],
        'map: names monsters in your room, rolls no dice');
    $dice_queue = [];
    q("UPDATE users SET room_id = 1 WHERE name = 'Testa'");

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

    // Gear. New heroes carry the SRD Fighter's starting equipment A.
    $inv = run_command(hero('Testa'), 'i');
    check(str_contains(line($inv, 'Chain Mail'), 'heavy armor, AC 16  (equipped)')
        && str_contains(line($inv, 'Greatsword'), 'weapon, 2d6, two-handed  (equipped)')
        && str_contains(line($inv, 'Flail'), 'weapon, 1d8') && !str_contains(line($inv, 'Flail'), 'equipped')
        && hero('Testa')['gold'] === 4, 'a new hero has Kit A: Chain Mail and Greatsword worn, a Flail, 4 gp');
    check(count(objects_here('user_id', (int) hero('Testa')['id'])) === 3, 'a password reset gives no second kit');
    q('INSERT INTO objects (item_id, room_id) VALUES (50, 1), (18, 1), (22, 1), (32, 1), (36, 1), (60, 1)');
    check(says(run_command(hero('Testa'), 'look'), 'A Longsword lies here.'), 'look shows items on the floor');
    check(says(run_command(hero('Testa'), 'get long'), 'You pick up the Longsword.'), 'get by word prefix');
    check(!says(run_command(hero('Testa'), 'look'), 'Longsword'), 'got items leave the floor');
    check(says(run_command(hero('Testb'), 'get longsword'), "don't see a longsword"), 'only one hero can get an item');
    check(says(run_command(hero('Testa'), 'equip rapier'), "aren't carrying"), "can't equip what you don't carry");
    run_command(hero('Testa'), 'get shield');
    $out = run_command(hero('Testa'), 'equip shield');
    $score = run_command(hero('Testa'), 'score');
    check(says($out, 'You put away the Greatsword.') && says($score, 'AC 19')
        && says($score, 'Unarmed Strike +4 to hit, 1+2 damage'),
        'a shield puts away a two-handed weapon: +2 AC, and no weapon is an Unarmed Strike (1 + Str)');
    run_command(hero('Testa'), 'equip longsword');
    check(says(run_command(hero('Testa'), 'score'), 'Longsword +4 to hit, 1d8+2 damage'),
        'a Versatile weapon with a shield does its one-handed die');
    $out = run_command(hero('Testa'), 'equip greatsword');
    check(says($out, 'put away the Longsword') && says($out, 'put away the Shield'),
        'a two-handed weapon puts away the weapon and the shield');
    run_command(hero('Testa'), 'equip longsword');
    $score = run_command(hero('Testa'), 'score');
    check(says($score, 'Longsword +4 to hit, 1d10+2 damage') && says($score, 'AC 17'),
        'a Versatile weapon without a shield does its two-handed die');
    check(says(run_command(hero('Testa'), 'equip long'), 'already have the Longsword equipped'), 'equip twice');
    q("UPDATE users SET dexterity = 18 WHERE name = 'Testa'");
    run_command(hero('Testa'), 'get rapier');
    run_command(hero('Testa'), 'equip rapier');
    check(says(run_command(hero('Testa'), 'score'), 'Rapier +6 to hit, 1d8+4 damage'),
        'Finesse uses the better of Str and Dex');
    run_command(hero('Testa'), 'get leather');
    check(says(run_command(hero('Testa'), 'equip leather'), 'put away the Chain Mail')
        && says(run_command(hero('Testa'), 'score'), 'AC 16'), 'light armor: AC + all of Dex, +1 Defense');
    run_command(hero('Testa'), 'get scale');
    run_command(hero('Testa'), 'equip scale');
    check(says(run_command(hero('Testa'), 'score'), 'AC 17'), 'medium armor: AC + Dex up to +2, +1 Defense');
    run_command(hero('Testa'), 'drop scale');
    check(says(run_command(hero('Testa'), 'score'), 'AC 14'), 'unarmored: 10 + Dex, no Defense');
    q("UPDATE users SET dexterity = 14 WHERE name = 'Testa'");
    $inv = run_command(hero('Testa'), 'i');
    check(str_contains(line($inv, 'Longsword'), 'weapon, 1d8 (1d10 two-handed)')
        && str_contains(line($inv, 'Rapier'), '1d8, finesse  (equipped)')
        && str_contains(line($inv, 'Leather Armor'), 'light armor, AC 11 + Dex')
        && str_contains(line($inv, 'Shield'), 'shield, +2 AC'), 'inventory describes gear');
    check(says(run_command(hero('Testa'), 'drop longsword'), 'You drop the Longsword.'), 'drop');
    check(says(run_command(hero('Testa'), 'look'), 'A Longsword lies here.'), 'dropped gear is on the floor');
    run_command(hero('Testa'), 'equip chain');
    run_command(hero('Testa'), 'equip greatsword');
    check(says(run_command(hero('Testa'), 'score'), 'AC 17   Proficiency'), 'back in Chain Mail with a Greatsword');

    // Potions. Drinking is a Bonus Action, like Second Wind.
    run_command(hero('Testa'), 'get potion');
    check(says(run_command(hero('Testa'), 'equip potion'), 'Drink it instead'), "potions can't be equipped");
    check(says(run_command(hero('Testa'), 'drink potion'), 'already at full health'), 'no potion at full HP');
    check(says(run_command(hero('Testa'), 'drink flail'), "aren't carrying a flail to drink"), 'drink only potions');
    q("UPDATE users SET hp = 3 WHERE name = 'Testa'");
    $dice_queue = [4, 1];
    check(says(run_command(hero('Testa'), 'drink pot'), 'regain 7 HP. HP 10/11.'), 'a Potion of Healing heals 2d4+2');
    check(!says(run_command(hero('Testa'), 'i'), 'Potion'), 'a drunk potion is gone');

    $name = loot(0.125, START_ROOM);
    check(says(run_command(hero('Testa'), 'look'), a_an($name) . ' lies here.'), 'loot lands on the floor');
    check(q('SELECT cost FROM items WHERE name = ?', [$name])->fetchColumn() <= 0.125 * LOOT_GP_PER_CR
        && loot(0, START_ROOM) === null, 'loot costs at most LOOT_GP_PER_CR x CR');

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

    // Combat, with every roll queued. Testa wears Kit A: +4 to hit, 2d6+2 damage, AC 17, initiative +2.
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
    q('INSERT INTO objects (item_id, user_id) VALUES (32, ?)', [hero('Testa')['id']]);
    check(says(run_command(hero('Testa'), 'equip leather'), "can't change armor"), "can't change armor in a fight");
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

    // The Delve (src/delve.php): generated from a weekly seed below the Mill Cellar.
    $back = ['north' => 'south', 'south' => 'north', 'east' => 'west', 'west' => 'east'];
    foreach ([1, 2, 3, 202642, 202701] as $seed) {
        $d = delve_layout($seed);
        $links = [];
        foreach ($d['exits'] as [$from, $dir, $to]) {
            $links[$from][$dir] = $to;
        }
        $two_way = true;
        foreach ($d['exits'] as [$from, $dir, $to]) {
            $two_way = $two_way && ($links[$to][$back[$dir]] ?? null) === $from;
        }
        $dist = [DELVE_STAIR + 1 => 0];
        for ($queue = [DELVE_STAIR + 1]; $queue; ) {
            $id = array_shift($queue);
            foreach ($links[$id] as $to) {
                if (!isset($dist[$to])) {
                    $dist[$to] = $dist[$id] + 1;
                    $queue[] = $to;
                }
            }
        }
        $n = count($d['rooms']);
        check($n >= DELVE_MIN_ROOMS && $n <= DELVE_MAX_ROOMS && max(array_keys($d['rooms'])) <= DELVE_LAST
            && $two_way && count($dist) === $n && $dist[$d['boss']] === max($dist) && isset($d['spawns'][$d['boss']])
            && !isset($d['spawns'][DELVE_STAIR + 1]) && max($d['spawns']) <= 16,
            "Delve seed $seed: $n rooms, all reachable, exits two-way, boss in the farthest room, SRD monsters");
    }
    check(delve_layout(7) === delve_layout(7) && delve_layout(7) !== delve_layout(8), 'same seed, same Delve');

    // Real heroes online inside would block a rebuild, so mark them offline (rolled back with everything else).
    q("UPDATE users SET last_seen = NULL WHERE name NOT IN ('Testa', 'Testb')");
    q('UPDATE delve SET seed = 0');
    q("UPDATE users SET room_id = 7 WHERE name IN ('Testa', 'Testb')");
    $out = run_command(hero('Testa'), 'd');
    check(says($out, 'The Delve Stair') && says($out, 'Exits: up down')
        && q('SELECT seed FROM delve')->fetchColumn() === delve_week(),
        "arriving at the stair builds this week's Delve");
    $d = delve_layout(delve_week());
    check(says(run_command(hero('Testa'), 'd'), $d['rooms'][DELVE_STAIR + 1][0]), 'down into its first room');
    check(q('SELECT COUNT(*) FROM objects WHERE room_id = ?', [$d['boss']])->fetchColumn() === 1,
        'the boss guards a treasure');
    q('UPDATE delve SET seed = 1');
    run_command(hero('Testb'), 'd');
    check(q('SELECT seed FROM delve')->fetchColumn() === 1 && hero('Testa')['room_id'] === DELVE_STAIR + 1,
        "a stale Delve isn't rebuilt while a hero online is inside");
    q("UPDATE users SET last_seen = NOW() - INTERVAL 1 HOUR WHERE name = 'Testa'");
    run_command(hero('Testb'), 'u');
    run_command(hero('Testb'), 'd');
    check(q('SELECT seed FROM delve')->fetchColumn() === delve_week() && hero('Testa')['room_id'] === DELVE_STAIR,
        'once they are offline it is rebuilt, and heroes left inside wake at the stair');
} finally {
    db()->rollBack();
}
exit($failed ? 1 : 0);
