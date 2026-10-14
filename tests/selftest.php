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
/** Takes a new hero through character creation: class, ability scores ('ok' for the suggested ones), begin. */
function create(string $name, string $class, string $scores = 'ok'): array
{
    run_command(hero($name), $class);
    run_command(hero($name), $scores);
    return run_command(hero($name), 'begin');
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
    create('Testa', 'fighter');
    create('Testb', 'fighter');

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

    // Character creation: choose a class, assign the Standard Array, then begin. Nothing else runs until then.
    save_hero('Testc', 'correct horse');
    $out = run_command(hero('Testc'), 'look');
    check(says($out, 'Choose your class') && says($out, 'barbarian') && !says($out, "There's no"),
        'a new hero chooses a class');
    check(says(run_command(hero('Testc'), 'artificer'), "There's no 'artificer' class"), 'an unknown class is refused');
    check(says(run_command(hero('Testc'), 'n'), "no 'n' class") && hero('Testc')['room_id'] === START_ROOM,
        'no game commands during creation');
    $out = run_command(hero('Testc'), 'cle');
    check(says($out, 'You will be a Cleric') && says($out, 'Str 14  Dex 8  Con 13  Int 10  Wis 15  Cha 12'),
        'a class by prefix, and its suggested scores');
    check(says(run_command(hero('Testc'), '15 15 13 12 10 8'), 'exactly once')
        && hero('Testc')['setup'] === 'abilities', 'scores must be the Standard Array');
    check(says(run_command(hero('Testc'), 'back'), 'Choose your class'), "'back' returns to the classes");
    run_command(hero('Testc'), 'rogue');
    $out = run_command(hero('Testc'), '8 15 14 12 13 10');
    check(says($out, 'Testc, level 1 Rogue') && says($out, 'Str 8 (-1)  Dex 15 (+2)  Con 14 (+2)')
        && says($out, 'HP 10/10   AC 13') && says($out, 'Shortsword +4 to hit, 1d6+2 damage')
        && says($out, 'Dagger') && says($out, "Type 'begin'"), 'own scores in order, then a look at the hero and kit');
    $out = run_command(hero('Testc'), 'begin');
    check(says($out, 'You set out') && says($out, 'Greenest Town Square') && hero('Testc')['setup'] === null,
        "'begin' enters the world");
    check(count(objects_here('user_id', (int) hero('Testc')['id'])) === 5 && hero('Testc')['gold'] === 8,
        "the Rogue's kit: Leather Armor, Shortsword, 2 Daggers, Shortbow and 8 gp");
    q("UPDATE users SET setup = 'class', level = 3, xp = 1000 WHERE name = 'Testc'");
    create('Testc', 'barb');
    $t = hero('Testc');
    check($t['class'] === 'barbarian' && $t['level'] === 1 && $t['xp'] === 0 && $t['max_hp'] === 14
        && count(objects_here('user_id', (int) $t['id'])) === 5, 'a hero sent back to creation starts over at level 1');

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
    $sheet = hero_sheet(with_stats(hero('Testa')));
    check($sheet['xp_from'] === 0 && $sheet['xp_next'] === 300 && $sheet['abilities'][0] === ['Str', 15, 2, 4]
        && $sheet['resources'][1] === ['Second Wind', '2/2'], 'hero sheet: the score panel gets numbers, not text');
    check(says($score, 'Saves  Str +4  Dex +2  Con +3  Int -1  Wis +0  Cha +1'),
        "hero saves: the ability modifier, plus PB for the class's two (a Fighter's Str and Con)");

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
        && str_contains(line($inv, 'Greatsword'), 'weapon, 2d6, two-handed, graze  (equipped)')
        && str_contains(line($inv, 'Flail'), 'weapon, 1d8') && !str_contains(line($inv, 'Flail'), 'equipped')
        && hero('Testa')['gold'] === 4, 'a new hero has Kit A: Chain Mail and Greatsword worn, a Flail, 4 gp');
    check(count(objects_here('user_id', (int) hero('Testa')['id'])) === 11 && str_contains(line($inv, 'Javelin'),
        'weapon, 1d6, thrown'), 'Kit A also has 8 Javelins; a password reset gives no second kit');
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
        && str_contains(line($inv, 'Rapier'), '1d8, finesse, vex  (equipped)')
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
    q("UPDATE users SET hp = 2, uses_spent = 2, rest_until = NOW(), resting = 'short' WHERE name = 'Testa'");
    $out = run_command(hero('Testa'), 'score');
    check(says($out, 'spending 0 Hit Dice') && says($out, 'HP 2/11'), 'no Hit Dice left, no healing');
    check(says($out, 'Second Wind 1/2'), 'a short rest gives back one Second Wind');
    run_command(hero('Testa'), 'sleep');
    q("UPDATE users SET rest_until = NOW() WHERE name = 'Testa'");
    $out = run_command(hero('Testa'), 'score');
    $t = hero('Testa');
    check(says($out, 'You finish a long rest') && $t['hp'] === 11 && $t['hit_dice_spent'] === 0
        && $t['uses_spent'] === 0, 'a long rest restores HP, Hit Dice and Second Wind');
    run_command(hero('Testa'), 'rest');
    $out = run_command(hero('Testa'), 'n');
    check(says($out, 'You stop resting.') && hero('Testa')['resting'] === null, 'leaving the room cancels a rest');
    check(says(run_command(hero('Testa'), 'rest'), "isn't safe to rest here"), 'rest only in safe rooms');
    run_command(hero('Testa'), 's');

    // Combat, with every roll queued. Testa wears Kit A: +4 to hit, 2d6+2 damage, AC 17, initiative +2.
    q("INSERT INTO monsters (id, name, ac, hp, initiative, attack, attacks, to_hit, damage, cr, xp, str_save, dex_save,
        con_save, int_save, wis_save, cha_save, damage_type)
        VALUES (9999, 'Training Dummy', 10, '2d6', 0, 'Slam', 1, 3, '1d4', 0.125, 25, 0, 0, 0, 0, 0, 0,
        'bludgeoning')");
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
    check(says($round, 'The blow grazes it for 2 damage.') && says($round, 'Training Dummy: 1 HP'),
        "Graze: the Greatsword's miss still deals the Str modifier");
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
    q('UPDATE spawns SET hp = 30 WHERE id = 9999'); // so the Greatsword's Graze doesn't finish it
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

    // Classes, each against the Training Dummy (AC 10, Slam +3 for 1d4, Dex save +0) in front of the keep.
    q("UPDATE users SET room_id = 2 WHERE name = 'Testc'");
    $score = run_command(hero('Testc'), 'score');
    check(says($score, 'level 1 Barbarian') && says($score, 'HP 14/14   AC 13')
        && says($score, 'Greataxe +4 to hit, 1d12+2 damage') && says($score, 'Hit Dice 1/1 d12   Rage 2/2'),
        'Barbarian: Unarmored Defense (10 + Dex + Con), d12 Hit Dice, 2 Rages');
    q('INSERT INTO objects (item_id, user_id) VALUES (40, ?)', [hero('Testc')['id']]);
    check(says(run_command(hero('Testc'), 'equip chain'), "A Barbarian isn't trained to use the Chain Mail"),
        'armor training by class');
    check(says(run_command(hero('Testc'), 'rage'), 'Start a fight first'), 'Rage needs a fight');
    check(says(run_command(hero('Testa'), 'rage'), 'Only Barbarians'), 'only Barbarians Rage');
    q('UPDATE spawns SET hp = 30, dead_since = NULL WHERE id = 9999');
    $dice_queue = [10, 1, 1, 1]; // initiative 11 vs 1; both roll natural 1s
    run_command(hero('Testc'), 'kill dummy');
    $out = run_command(hero('Testc'), 'rage');
    check(says($out, 'You fly into a Rage! +2 damage') && says($out, '1 Rage left') && !says($out, 'Slam'),
        'Rage costs no turn');
    $dice_queue = [15, 6, 15, 4]; // hit for 6 + 2 Str + 2 Rage; Slam hits for 4, halved
    $out = run_command(hero('Testc'), 'kill');
    check(says($out, 'for 10 damage.') && says($out, 'for 2 damage (resisted).')
        && hero('Testc')['hp'] === 12,
        'Rage: +2 damage on Strength attacks, Resistance to Bludgeoning');
    check(says(run_command(hero('Testc'), 'score'), 'Rage 1/2   raging'), 'score shows the Rage');
    $dice_queue = [20, 1];
    run_command(hero('Testc'), 'flee');
    check(hero('Testc')['raging'] === 0 && hero('Testc')['fighting'] === null, 'the Rage ends with the fight');

    // Reckless Attack, the first condition: Advantage both ways until the start of the Barbarian's next turn.
    q("UPDATE users SET room_id = 2, hp = max_hp WHERE name = 'Testc'");
    q('UPDATE spawns SET hp = 30, dead_since = NULL WHERE id = 9999');
    check(says(run_command(hero('Testc'), 'reckless dummy'), 'level 2 or more'), 'Reckless Attack needs level 2');
    q("UPDATE users SET level = 2 WHERE name = 'Testc'");
    $dice_queue = [10, 1, 3, 15, 6, 2, 12, 3]; // initiative 11 vs 1; I roll 3 and 15, it rolls 2 and 12
    $out = run_command(hero('Testc'), 'reckless dummy');
    check(says($out, 'You throw aside') && says($out, '(19 vs AC 10, with Advantage) for 8 damage.')
        && says($out, 'Slam hits you (15 vs AC 13, with Advantage) for 3 damage.') && $dice_queue === [],
        'Reckless Attack: Advantage on my attacks and on attacks against me');
    check(says(run_command(hero('Testc'), 'score'), 'reckless'), 'score shows the condition');
    $dice_queue = [15, 6, 12, 3];
    $out = run_command(hero('Testc'), 'kill');
    check(says($out, '(19 vs AC 10) for 8') && says($out, 'hits you (15 vs AC 13) for 3') && $dice_queue === []
        && hero('Testc')['conditions'] === '', 'it ends at the start of my next turn');
    q("UPDATE users SET foe_first = TRUE, conditions = 'reckless' WHERE name = 'Testc'");
    $dice_queue = [2, 12, 3, 15, 6];
    $out = run_command(hero('Testc'), 'kill');
    check(says($out, 'hits you (15 vs AC 13, with Advantage)') && says($out, '(19 vs AC 10) for 8')
        && $dice_queue === [], 'a foe that acts first in the round still has Advantage');
    q("UPDATE users SET conditions = 'reckless' WHERE name = 'Testc'");
    $dice_queue = [1, 20, 12, 3]; // the flee fails; one d20 for the attack it draws
    $out = run_command(hero('Testc'), 'flee');
    check(says($out, 'hits you (15 vs AC 13) for 3') && $dice_queue === [], 'fleeing is my turn, so it ends first');
    q("UPDATE users SET conditions = 'reckless' WHERE name = 'Testc'");
    $dice_queue = [20, 1];
    run_command(hero('Testc'), 'flee');
    check(hero('Testc')['conditions'] === '' && hero('Testc')['fighting'] === null, 'conditions end with the fight');

    // Damage types (SRD 5.2.1 p.17): Vulnerability doubles, Resistance halves, Immunity takes it all.
    q("UPDATE monsters SET vulnerabilities = 'slashing', extra_damage = '1d4', extra_type = 'poison' WHERE id = 9999");
    q('UPDATE spawns SET hp = 60, dead_since = NULL WHERE id = 9999');
    q("UPDATE users SET room_id = 2, hp = max_hp WHERE name = 'Testc'");
    $dice_queue = [10, 1, 15, 6, 15, 4, 3]; // Greataxe 6 + 2, doubled; Slam 4 Bludgeoning plus 3 Poison
    $out = run_command(hero('Testc'), 'kill dummy');
    check(says($out, 'for 16 damage (vulnerable).') && says($out, 'hits you (18 vs AC 13) for 7 damage.'),
        'Vulnerability doubles; a second damage roll adds in');
    run_command(hero('Testc'), 'rage');
    $dice_queue = [15, 6, 15, 4, 3];
    $out = run_command(hero('Testc'), 'kill');
    check(says($out, 'for 20 damage (vulnerable).') && says($out, 'for 5 damage (resisted).'),
        "Rage resists the Slam's Bludgeoning but not its Poison");
    q("UPDATE monsters SET vulnerabilities = '', immunities = 'slashing' WHERE id = 9999");
    $dice_queue = [15, 6, 1];
    check(says(run_command(hero('Testc'), 'kill'), 'for 0 damage (immune).'), 'Immunity takes all of it');
    q("UPDATE monsters SET immunities = '', extra_damage = NULL, extra_type = NULL WHERE id = 9999");
    $dice_queue = [20, 1];
    run_command(hero('Testc'), 'flee');

    save_hero('Testd', 'correct horse');
    create('Testd', 'rogue');
    $id = hero('Testd')['id'];
    q("UPDATE users SET room_id = 2 WHERE name = 'Testd'");
    check(says(run_command(hero('Testd'), 'score'), 'd8   Sneak Attack 1d6'), 'Rogue: d8 Hit Dice, Sneak Attack 1d6');
    q('INSERT INTO objects (item_id, user_id) VALUES (15, ?), (50, ?)', [$id, $id]);
    check(says(run_command(hero('Testd'), 'equip shield'), "isn't trained"), 'Rogues have no shield training');
    check(says(run_command(hero('Testd'), 'equip greatsword'), 'no Proficiency Bonus')
        && says(run_command(hero('Testd'), 'score'), 'Greatsword +1 to hit'),
        'an untrained weapon: no Proficiency Bonus');
    run_command(hero('Testd'), 'equip shortsword');
    q('UPDATE spawns SET hp = 30 WHERE id = 9999');
    $dice_queue = [10, 1, 12, 3, 1]; // initiative 12 vs 1; hit for 3 + 2, no Sneak Attack; Slam misses
    $out = run_command(hero('Testd'), 'kill dummy');
    check(says($out, '(16 vs AC 10) for 5 damage.') && hero('Testd')['advantage'] === 1,
        "a Vex weapon's hit gives Advantage on the next attack");
    $dice_queue = [2, 9, 4, 5, 1]; // d20s 2 and 9; hit for 4 + 2 + Sneak Attack 5; Slam misses
    check(says(run_command(hero('Testd'), 'kill'), '(13 vs AC 10, with Advantage) for 11 damage, with Sneak Attack.'),
        'Advantage takes the higher d20, and lets a Rogue Sneak Attack');
    q("UPDATE users SET advantage = FALSE WHERE name = 'Testd'");
    q("UPDATE users SET fighting = 9999 WHERE name = 'Testa'");
    $dice_queue = [12, 1, 3, 1]; // hit for 1 + 2 + Sneak Attack 3; Slam misses
    check(says(run_command(hero('Testd'), 'kill'), '(16 vs AC 10) for 6 damage, with Sneak Attack.'),
        'Sneak Attack without Advantage when another hero fights the same foe');
    q("UPDATE users SET fighting = NULL WHERE name = 'Testa'");
    q("UPDATE users SET level = 2 WHERE name = 'Testd'");
    $dice_queue = [1];
    check(says(run_command(hero('Testd'), 'flee'), 'You flee south') && $dice_queue === [1],
        'Cunning Action: from level 2 a Rogue always gets away');
    $dice_queue = [];

    // Ranged weapons: an opening volley, then Disadvantage with the foe next to you (no distances yet).
    q('UPDATE spawns SET hp = 30, dead_since = NULL WHERE id = 9999');
    q("UPDATE users SET room_id = 2, level = 1, advantage = FALSE WHERE name = 'Testd'");
    run_command(hero('Testd'), 'equip shortbow');
    $score = run_command(hero('Testd'), 'score');
    check(says($score, 'Shortbow +4 to hit, 1d6+2 damage'), 'a ranged weapon uses Dex');
    check(says($score, 'Saves  Str +1  Dex +4  Con +1  Int +4  Wis +0  Cha -1'),
        'a Rogue is proficient in Dex and Int');
    $dice_queue = [10, 1, 12, 3]; // initiative 12 vs 1, then one shot: 16 vs AC 10 for 3 + 2
    $out = run_command(hero('Testd'), 'kill dummy');
    check(says($out, '(16 vs AC 10) for 5 damage.') && says($out, 'The Training Dummy closes in!')
        && !says($out, 'Slam') && $dice_queue === [], 'an opening volley goes unanswered, with no Disadvantage');
    $dice_queue = [4, 1]; // Vex's Advantage and the close foe's Disadvantage cancel: one d20
    check(says(run_command(hero('Testd'), 'kill'), 'You miss the Training Dummy (8 vs AC 10).') && $dice_queue === [],
        'Advantage and Disadvantage cancel out');
    $dice_queue = [15, 4, 1];
    check(says(run_command(hero('Testd'), 'kill'), '(8 vs AC 10, with Disadvantage)') && $dice_queue === [],
        'then ranged attacks have Disadvantage');
    $dice_queue = [1];
    run_command(hero('Testd'), 'flee');
    q('INSERT INTO objects (item_id, user_id) VALUES (71, ?)', [hero('Testa')['id']]);
    run_command(hero('Testa'), 'equip light crossbow');
    $score = run_command(hero('Testa'), 'score');
    check(says($score, 'Light Crossbow +5 to hit, 1d8+2 damage') && !says($score, 'attacks a round'),
        'Loading: one shot a turn, even with Extra Attack');
    run_command(hero('Testa'), 'equip javelin');
    q("UPDATE users SET room_id = 2, fighting = NULL WHERE name = 'Testa'");
    $dice_queue = [10, 1, 1, 1, 1]; // initiative, then a thrown Javelin (Extra Attack: two) misses twice
    $out = run_command(hero('Testa'), 'kill dummy');
    check(says($out, 'closes in!') && !says($out, 'Slam') && $dice_queue === [1], 'a thrown weapon can open a volley');
    $dice_queue = [20, 1];
    run_command(hero('Testa'), 'flee');
    run_command(hero('Testa'), 'equip greatsword');
    // Two-weapon fighting (Light, p.89): an extra attack with a second Light weapon, no ability modifier to damage.
    q('UPDATE spawns SET hp = 60, dead_since = NULL WHERE id = 9999');
    q("UPDATE users SET room_id = 2, fighting = NULL, advantage = FALSE WHERE name IN ('Testc', 'Testd')");
    run_command(hero('Testd'), 'equip shortsword');
    check(says(run_command(hero('Testd'), 'equip dagger offhand'), 'You equip the Dagger in your off hand.')
        && str_contains(line(run_command(hero('Testd'), 'i'), '(off hand)'), 'Dagger')
        && says(run_command(hero('Testd'), 'score'), 'Off hand Dagger +4 to hit, 1d4 damage (Nick)'),
        'a second Light weapon goes in the off hand');
    $dice_queue = [10, 1, 12, 3, 2, 11, 2, 4, 1]; // Shortsword hits (Vex); the Dagger has Advantage, so Sneak Attack
    $out = run_command(hero('Testd'), 'kill dummy');
    check(says($out, '(16 vs AC 10) for 5 damage.')
        && says($out, 'Your off-hand Dagger hits the Training Dummy (15 vs AC 10, with Advantage) for 6 damage, with '
            . 'Sneak Attack.') && $dice_queue === [],
        'the extra attack: no Dex to its damage, and Sneak Attack can ride it');
    q("UPDATE users SET bonus_used = TRUE WHERE name = 'Testd'");
    $dice_queue = [12, 3, 12, 2, 2, 4, 1];
    check(says(run_command(hero('Testd'), 'kill'), 'Your off-hand Dagger hits') && $dice_queue === [],
        "Nick: the extra attack doesn't need the Bonus Action");
    $dice_queue = [20, 1];
    run_command(hero('Testd'), 'flee');
    check(says(run_command(hero('Testc'), 'equip greataxe offhand'), 'Only a Light weapon')
        && says(run_command(hero('Testc'), 'equip handaxe offhand'), 'Light weapon in your main hand first'),
        'the off hand needs Light weapons in both hands');
    run_command(hero('Testc'), 'equip handaxe');
    run_command(hero('Testc'), 'equip handaxe offhand');
    $dice_queue = [10, 1, 15, 3, 2, 14, 3]; // thrown Handaxes open a volley: one hits (Vex), the off-hand one too
    $out = run_command(hero('Testc'), 'kill dummy');
    check(says($out, 'Your off-hand Handaxe hits the Training Dummy (18 vs AC 10, with Advantage) for 3 damage.')
        && $dice_queue === [] && hero('Testc')['bonus_used'] === 0,
        'without Nick it takes the Bonus Action, which is back for the next round');
    q("UPDATE users SET uses_spent = 0, hp = hp - 1 WHERE name = 'Testc'");
    q('INSERT INTO objects (item_id, user_id) VALUES (60, ?)', [hero('Testc')['id']]);
    run_command(hero('Testc'), 'rage');
    check(says(run_command(hero('Testc'), 'drink potion'), BONUS_USED), 'one Bonus Action a round');
    $dice_queue = [15, 2, 3, 1]; // the off-hand Vex's Advantage; 3 + 2 Str + 2 Rage
    $out = run_command(hero('Testc'), 'kill');
    check(says($out, 'for 7 damage') && !says($out, 'off-hand') && $dice_queue === [],
        'so after Rage there is no off-hand attack that round');
    $dice_queue = [20, 1];
    run_command(hero('Testc'), 'flee');
    check(count(array_filter(run_command(hero('Testc'), 'equip greataxe'), fn ($l) => str_contains($l, 'put away')))
        === 2, 'a two-handed weapon puts away both hands');
    $dice_queue = [3, 15, 4, 12];
    $barbarian = with_stats(hero('Testc'));
    check(save($barbarian, 'dex', 16) === [16, true, 1] && save($barbarian, 'str', 16) === [8, false, 0],
        'Danger Sense: a level 2 Barbarian has Advantage on Dex saves');
    $dice_queue = [4, 12];
    check(save(['raging' => 1] + $barbarian, 'str', 16) === [16, true, 1], 'Rage: Advantage on Str saves');

    // Shove and grapple in place of one attack (Testa: level 5, two attacks, Str +2, PB +3: DC 13).
    q('UPDATE spawns SET hp = 60, dead_since = NULL WHERE id = 9999');
    q("UPDATE users SET room_id = 2, fighting = NULL WHERE name IN ('Testa', 'Testb')");
    $dice_queue = [10, 1, 5, 3, 15, 3, 4, 12]; // Str save 5 fails; one attack with Advantage; it gets up, misses
    $out = run_command(hero('Testa'), 'shove dummy');
    check(says($out, 'You shove the Training Dummy to the ground, Prone (Str save 5 vs DC 13).')
        && says($out, '(20 vs AC 10, with Advantage) for 9 damage') && says($out, 'gets back up')
        && says($out, 'misses you (15 vs AC 17).') && $dice_queue === [],
        'Shove: Prone, so my next attack has Advantage');
    $dice_queue = [4, 1, 12];
    $out = run_command(hero('Testa'), 'grapple');
    check(says($out, 'You grab hold of the Training Dummy, Grappled (Str save 4 vs DC 13).') && $dice_queue === [],
        'Grapple: no Disadvantage against the one holding it');
    $dice_queue = [10, 1, 1, 15, 2];
    check(says(run_command(hero('Testb'), 'kill dummy'), 'misses you (5 vs AC 17, with Disadvantage)')
        && $dice_queue === [], 'held, it has Disadvantage against other heroes');
    $dice_queue = [20, 1];
    run_command(hero('Testa'), 'flee');
    $dice_queue = [1, 15, 2];
    check(says(run_command(hero('Testb'), 'kill'), 'hits you (18 vs AC 17) for 2') && $dice_queue === [],
        'the hold ends when the grappler leaves the fight');
    $dice_queue = [20, 1];
    run_command(hero('Testb'), 'flee');
    q('INSERT INTO objects (item_id, user_id) VALUES (19, ?)', [hero('Testa')['id']]);
    run_command(hero('Testa'), 'equip maul');
    q("UPDATE users SET room_id = 2 WHERE name = 'Testa'");
    $dice_queue = [10, 1, 15, 3, 4, 5, 2, 14, 1, 1, 1]; // a hit, Con save 5 fails; then Advantage
    $out = run_command(hero('Testa'), 'kill dummy');
    check(says($out, 'topples over, Prone (Con save 5 vs DC 13).') && says($out, '(19 vs AC 10, with Advantage)')
        && $dice_queue === [] && str_contains(line(run_command(hero('Testa'), 'i'), 'Maul'), 'two-handed, topple'),
        'Topple: a hit can knock the foe Prone');
    $dice_queue = [20, 1];
    run_command(hero('Testa'), 'flee');
    q('INSERT INTO objects (item_id, user_id) VALUES (7, ?)', [hero('Testa')['id']]);
    run_command(hero('Testa'), 'equip mace');
    q("UPDATE users SET room_id = 2 WHERE name = 'Testa'");
    $dice_queue = [10, 1, 15, 3, 1, 2, 12]; // a Mace hit saps it, so its Slam rolls twice and takes the 2
    check(says(run_command(hero('Testa'), 'kill dummy'), 'misses you (5 vs AC 17, with Disadvantage)')
        && $dice_queue === [], 'Sap: a hit gives Disadvantage on its next attack');
    $dice_queue = [1, 1, 12];
    check(says(run_command(hero('Testa'), 'kill'), 'misses you (15 vs AC 17).') && $dice_queue === [],
        'and it wears off by the start of my next turn');
    $dice_queue = [20, 1];
    run_command(hero('Testa'), 'flee');
    q('INSERT INTO objects (item_id, user_id) VALUES (14, ?)', [hero('Testa')['id']]);
    run_command(hero('Testa'), 'equip greataxe');
    $dummy = q('SELECT hp, dead_since FROM spawns WHERE id = 9999')->fetch();
    q('UPDATE spawns SET hp = 3, dead_since = NULL WHERE id = 9999');
    q('INSERT INTO spawns (id, room_id, monster_id, hp) VALUES (9998, 2, 9999, 10)'); // first in the room: the foe
    q("UPDATE users SET room_id = 2, xp = 6500 WHERE name = 'Testa'");
    // A hit for 5; Cleave kills the other dummy (gold 4, no loot); the second attack kills the first.
    $dice_queue = [10, 1, 15, 3, 14, 3, 4, 2, 15, 6, 4, 2];
    $out = run_command(hero('Testa'), 'kill dummy');
    check(says($out, 'You cleave into the Training Dummy (19 vs AC 10) for 3 damage.')
        && count(array_filter($out, fn ($l) => str_contains($l, 'dies!'))) === 2 && hero('Testa')['xp'] === 6550
        && $dice_queue === [], 'Cleave: a hit carries on to a second monster here, and both kills count');
    q('DELETE FROM spawns WHERE id = 9998');
    q('UPDATE spawns SET hp = ?, dead_since = ? WHERE id = 9999', [$dummy['hp'], $dummy['dead_since']]);
    run_command(hero('Testa'), 'equip greatsword');
    run_command(hero('Testa'), 'equip greatsword');

    save_hero('Teste', 'correct horse');
    create('Teste', 'cleric');
    q("UPDATE users SET room_id = 2 WHERE name = 'Teste'");
    $score = run_command(hero('Teste'), 'score');
    check(says($score, 'HP 9/9   AC 14') && says($score, 'Mace +4 to hit, 1d6+2 damage')
        && says($score, 'd8   Spell slots 2/2'), 'Cleric: Chain Shirt and Shield, a Mace, 2 spell slots');
    $out = run_command(hero('Teste'), 'cast');
    check(says($out, 'save DC 12, spell attack +4; slots 2/2') && says($out, 'sacred flame <monster>'),
        'cast lists spells');
    check(says(run_command(hero('Testa'), 'cast sacred flame dummy'), "don't know any spells"), 'only Clerics cast');
    $out = run_command(hero('Teste'), 'prepare');
    check(says($out, 'Prepared spells (4/4)') && str_contains(line($out, 'shield of faith'), '*')
        && !str_contains(line($out, 'healing word'), '*'), "a new Cleric starts with the SRD's suggested four");
    check(says(run_command(hero('Teste'), 'prepare bless, cure, guiding, healing, shield'), 'You can prepare 4 spells')
        && says(run_command(hero('Teste'), 'prepare bless, sacred flame'), "can't prepare 'sacred flame'"),
        'no more than the count, and only spells of level 1+');
    check(says(run_command(hero('Teste'), 'prepare guiding, cure, healing word, bless'), 'You prepare Guiding Bolt, '
        . 'Cure Wounds, Healing Word, Bless.') && says(run_command(hero('Teste'), 'prepare bless'), 'only add spells'),
        'prepare replaces the list once a long rest allows it, then only adds');
    check(says(run_command(hero('Teste'), 'cast fireball'), "don't know a spell called 'fireball'"), 'unknown spell');
    check(says(run_command(hero('Teste'), 'cast sacred'), 'Cast it at what?'), 'an attack spell needs a target');
    q('UPDATE spawns SET hp = 30 WHERE id = 9999');
    $dice_queue = [10, 1, 11, 5, 1]; // initiative 9 vs 1; save 11 fails, 1d8 = 5; Slam misses
    $out = run_command(hero('Teste'), 'cast sac flame dum');
    check(says($out, 'fails its Dex save (11 vs DC 12) against your Sacred Flame and takes 5 damage.')
        && hero('Teste')['slots_spent'] === '', 'Sacred Flame: 1d8 on a failed Dex save, and no slot');
    $dice_queue = [12, 1];
    check(says(run_command(hero('Teste'), 'cast sacred'), 'makes its Dex save (12 vs DC 12) against your Sacred '
        . 'Flame and takes no damage.'),
        'a made save takes nothing; in a fight a spell goes at your foe');
    $ogre = q('SELECT * FROM monsters WHERE id = 13')->fetch();
    $dice_queue = [9, 9];
    check(save($ogre, 'con', 12) === [12, true, 0] && save($ogre, 'wis', 8) === [7, false, 0],
        'six saves per stat block (Ogre: Con +3, Wis -2)');
    $dice_queue = [11, 15, 2, 3, 4, 5, 1]; // a ranged spell attack next to a foe: the lower of 11 and 15; Slam misses
    $out = run_command(hero('Teste'), 'cast guiding');
    check(says($out, 'Your Guiding Bolt hits the Training Dummy (15 vs AC 10, with Disadvantage) for 14 damage.')
        && hero('Teste')['advantage'] === 1 && hero('Teste')['slots_spent'] === '1',
        'Guiding Bolt: 4d6 and Advantage on your next attack, for a slot');
    q("UPDATE users SET hp = 2 WHERE name = 'Teste'");
    $dice_queue = [3, 4];
    $out = run_command(hero('Teste'), 'cast healing word');
    check(says($out, 'You cast Healing Word and regain 7 HP. HP 9/9. Slots 0/2.') && !says($out, 'Slam'),
        'Healing Word: 2d4 + Wis, and it costs no turn');
    check(says(run_command(hero('Teste'), 'cast guiding'), 'no spell slots left'), 'no slots, no spell');
    q("UPDATE users SET level = 3, hp = 1, slots_spent = '4' WHERE name = 'Teste'");
    $dice_queue = [1, 1, 1, 1, 1]; // a level 2 slot: 4d8 + Wis; Slam misses
    $out = run_command(hero('Teste'), 'cast cure');
    check(says($out, 'You cast Cure Wounds (level 2) and regain 6 HP. HP 7/9. Slots 0/4 1/2.')
        && says($out, 'Slam misses'), 'Cure Wounds takes your turn; with the low slots spent, a higher one heals more');
    q("UPDATE users SET level = 5 WHERE name = 'Teste'");
    q('UPDATE spawns SET hp = 30 WHERE id = 9999');
    $dice_queue = [5, 3, 4, 1];
    check(says(run_command(hero('Teste'), 'cast sacred'), 'and takes 7 damage.') && $dice_queue === [],
        'a cantrip doubles its dice at level 5');
    q("UPDATE users SET level = 3 WHERE name = 'Teste'");
    q("INSERT INTO spells (id, name, level, classes, casting, kind, dice, upcast, damage_type, save, half, about)
        VALUES (9999, 'Test Burst', 1, 'cleric', 'action', 'save', '2d6', '1d6', 'fire', 'dex', TRUE, 'a test')");
    check(says(run_command(hero('Teste'), 'prepare guiding, cure, healing, bless, test burst'), 'You prepare'),
        'a higher level allows more, added between rests');
    $dice_queue = [15, 2, 3, 4, 1]; // a made save; a level 2 slot adds 1d6, so half of 9
    check(says(run_command(hero('Teste'), 'cast test b'), 'makes its Dex save (15 vs DC 12) against your Test Burst '
        . '(level 2) and takes 4 damage.') && $dice_queue === [], 'upcast dice, and half damage on a made save');
    $h = with_stats(hero('Teste'));
    $dice_queue = [1, 2];
    $won = [];
    win($h, ['id' => 0, 'name' => 'Rat', 'cr' => '0.125', 'xp' => 0], $won);
    check(hero('Teste')['level'] === 3 && hero('Teste')['max_hp'] === $h['max_hp'],
        'a kill never lowers a level set by hand ahead of the XP');
    q("UPDATE users SET fighting = NULL, room_id = 1, resting = 'long', rest_until = NOW() WHERE name = 'Teste'");
    check(says(run_command(hero('Teste'), 'score'), 'and spell slots back') && hero('Teste')['slots_spent'] === '',
        'a long rest restores spell slots');

    // Lasting effects and Concentration: Shield of Faith, Bless, Hold Person (Teste: Wis +2, DC 12, AC 14).
    q("UPDATE users SET room_id = 1, fighting = NULL WHERE name NOT IN ('Teste', 'Testb')");
    q("UPDATE users SET room_id = 2, fighting = NULL, hp = max_hp, level = 3, advantage = FALSE
       WHERE name IN ('Teste', 'Testb')");
    q('UPDATE spawns SET hp = 60, dead_since = NULL WHERE id = 9999');
    check(says(run_command(hero('Teste'), 'prepare shield, bless, hold, cure'), 'You prepare'),
        'a long rest lets you swap prepared spells');
    check(says(run_command(hero('Teste'), 'cast shield'), 'You cast Shield of Faith on you. Slots 3/4 2/2.')
        && says($score = run_command(hero('Teste'), 'score'), 'AC 16')
        && says($score, 'concentrating on Shield of Faith'), 'Shield of Faith: +2 AC, with Concentration');
    check(says(run_command(hero('Teste'), 'cast bless'), 'You cast Bless on you, Testb.')
        && says(run_command(hero('Teste'), 'score'), 'AC 14') && says(run_command(hero('Testb'), 'score'), 'Bless'),
        'Bless reaches another hero here, and a new Concentration spell ends the old one');
    $dice_queue = [10, 1, 5, 3, 3, 3, 1]; // 5 + 4 misses AC 10, but Bless adds 3
    check(says(run_command(hero('Testb'), 'kill dummy'), '(12 vs AC 10, +3 Bless) for 8 damage.') && $dice_queue === [],
        'Bless adds 1d4 to attack rolls');
    check(says(run_command(hero('Teste'), 'cast hold dummy'), 'works only on a Humanoid, and the Training Dummy is an '
        . 'Aberration.'), "Hold Person refuses a foe that isn't a Humanoid");
    q("UPDATE monsters SET type = 'humanoid' WHERE id = 9999");
    $dice_queue = [10, 1, 5, 3]; // its Wis save 5 fails; it can't act, and fails again at the end of its turn
    $out = run_command(hero('Teste'), 'cast hold dummy');
    check(says($out, 'fails its Wis save (5 vs DC 12) against your Hold Person and is Paralyzed.')
        && says($out, "is Paralyzed and can't act") && says($out, 'still held by your Hold Person')
        && says(run_command(hero('Teste'), 'score'), 'concentrating on Hold Person') && $dice_queue === [],
        'Hold Person: Paralyzed until it makes the save again');
    $dice_queue = [4, 3];
    check(says(run_command(hero('Teste'), 'cast sacred'), 'fails its Dex save (automatic fail, DC 12) against your '
        . 'Sacred Flame and takes 4 damage.') && $dice_queue === [], 'Paralyzed fails Dex saves');
    $dice_queue = [2, 12, 1, 1, 1, 1, 15]; // Advantage; a melee hit is a crit (4d6); then its save at 15 frees it
    $out = run_command(hero('Testb'), 'kill');
    check(says($out, 'Critical hit! You hit the Training Dummy (16 vs AC 10, with Advantage) for 6 damage.')
        && says($out, 'shakes off your Hold Person (Wis save 15 vs DC 12)') && $dice_queue === [],
        'attacks on the Paralyzed have Advantage and crit; the new Concentration ended Bless');
    run_command(hero('Teste'), 'cast shield');
    $dice_queue = [1, 15, 4, 5]; // my Mace misses; the Slam hits for 4; Con save 6 vs DC 10
    check(says(run_command(hero('Teste'), 'kill'), 'You lose your Concentration on Shield of Faith (Con save 6 vs DC '
        . '10).')
        && says(run_command(hero('Teste'), 'score'), 'AC 14') && $dice_queue === [],
        'damage calls for a Con save to keep Concentration');
    run_command(hero('Teste'), 'cast shield');
    q('UPDATE effects SET until = NOW() - INTERVAL 1 SECOND'); // its 10 minutes are up, but the fight goes on
    check(says(run_command(hero('Teste'), 'score'), 'Shield of Faith'), 'an effect lasts at least the fight it was '
        . 'cast in');
    $dice_queue = [20, 1];
    run_command(hero('Teste'), 'flee');
    check(!says(run_command(hero('Teste'), 'score'), 'Shield of Faith'), 'then only its own time');
    q("UPDATE users SET room_id = 2 WHERE name = 'Teste'");
    $dice_queue = [10, 1, 1, 1];
    run_command(hero('Teste'), 'kill dummy');
    check(!says(run_command(hero('Teste'), 'score'), 'Shield of Faith') && $dice_queue === [],
        "and doesn't come back in a new fight against the same monster");
    $dice_queue = [20, 1];
    run_command(hero('Teste'), 'flee');
    q("UPDATE monsters SET type = 'aberration' WHERE id = 9999");
    $dice_queue = [20, 1];
    run_command(hero('Testb'), 'flee');

    // Step 3 spells (Teste at level 5: Wis +2, PB +3, DC 13; nine prepared).
    q("UPDATE users SET room_id = 2, fighting = NULL, level = 5, slots_spent = '', may_prepare = TRUE, hp = 5
       WHERE name = 'Teste'");
    q("UPDATE users SET room_id = 2, fighting = NULL, hp = 5 WHERE name = 'Testb'");
    q('UPDATE spawns SET hp = 60, dead_since = NULL WHERE id = 9999');
    check(says(run_command(hero('Teste'), 'prepare bane, command, inflict, aid, blindness, mass healing, spirit, '
        . 'healing word, cure'), 'You prepare'), 'level 5: nine prepared spells');
    check(says(run_command(hero('Teste'), 'cast cure nobody'), "There's no hero called 'nobody' here."),
        'a heal names a hero who is here');
    $dice_queue = [3, 4];
    check(says(run_command(hero('Teste'), 'cast cure testb'), 'You cast Cure Wounds: Testb regains 6 HP.')
        && hero('Testb')['hp'] === 11, 'Cure Wounds heals another hero, up to their max');
    q("UPDATE users SET hp = 5 WHERE name = 'Testb'");
    $dice_queue = [2, 2];
    check(says(run_command(hero('Teste'), 'cast mass'), 'You cast Mass Healing Word: you regain 4 HP, Testb regains '
        . '6 HP.'),
        'Mass Healing Word: one roll heals every hero here');
    check(says(run_command(hero('Teste'), 'cast aid'), 'You cast Aid on you, Testb.')
        && says(run_command(hero('Testb'), 'score'), 'HP 16/16'), 'Aid: +5 HP and max HP');
    $dice_queue = [10, 1, 15, 6, 4, 1]; // its Con save 15 is made: half of 10
    check(says(run_command(hero('Teste'), 'cast inflict dummy'), 'makes its Con save (15 vs DC 13) against your '
        . 'Inflict Wounds and takes 5 damage.') && $dice_queue === [], 'Inflict Wounds: half damage on a made save');
    $dice_queue = [2, 12, 3]; // Cha save 2 fails; the Slam rolls 12 + 3, minus Bane's 3
    $out = run_command(hero('Teste'), 'cast bane');
    check(says($out, 'fails its Cha save (2 vs DC 13) against your Bane and is under your Bane.')
        && says($out, 'misses you (12 vs AC 14, -3 Bane)') && $dice_queue === [], 'Bane: -1d4 on its attack rolls');
    // Its saves lose Bane's 1d4 too. Blinded, its Slam has Disadvantage; at the end of its turn it saves (20 - 1).
    $dice_queue = [1, 1, 15, 2, 1, 20, 1];
    $out = run_command(hero('Teste'), 'cast blind');
    check(says($out, 'and is Blinded.') && says($out, 'misses you (4 vs AC 14, with Disadvantage, -1 Bane)')
        && says($out, 'shakes off your Blindness/Deafness (Con save 19 vs DC 13)') && $dice_queue === [],
        'Blindness: Disadvantage on its attacks until it saves');
    $dice_queue = [1, 1];
    check(says(run_command(hero('Teste'), 'cast command'), "is halted and can't act") && $dice_queue === [],
        'Command: a failed Wis save costs it a turn');
    $dice_queue = [1, 1, 1];
    check(says(run_command(hero('Teste'), 'kill'), 'Slam misses') && $dice_queue === [], 'only the one turn');
    $dice_queue = [1, 5, 1, 2, 3]; // the Slam misses; then the spirits: Wis save 5 fails, 3d8
    check(says(run_command(hero('Teste'), 'cast spirit'), 'Your Spirit Guardians sear the Training Dummy (Wis save 5 '
        . 'vs DC 13) for 6 damage.') && $dice_queue === [], 'Spirit Guardians hurt the foe after each of its turns');
    $dice_queue = [20, 1];
    run_command(hero('Teste'), 'flee');
    check($dice_queue === [], 'every queued die was used by the classes');

    // Monk (Testf: Str 12, Dex 15, Con 13, Wis 14; Focus save DC 8 + Wis + PB). The dummy: AC 10, saves +0.
    q('DELETE FROM effects');
    q('UPDATE spawns SET hp = 200, dead_since = NULL WHERE id = 9999');
    q('UPDATE users SET fighting = NULL WHERE fighting = 9999');
    save_hero('Testf', 'correct horse');
    create('Testf', 'monk');
    $id = hero('Testf')['id'];
    q("UPDATE users SET room_id = 2 WHERE name = 'Testf'");
    $score = run_command(hero('Testf'), 'score');
    check(says($score, 'level 1 Monk') && says($score, 'HP 9/9   AC 14')
        && says($score, 'Spear +4 to hit, 1d8+2 damage') && says($score, 'Hit Dice 1/1 d8   Martial Arts d6')
        && !says($score, 'Focus')
        && says($score, 'Saves  Str +3  Dex +4  Con +1  Int +0  Wis +2  Cha -1'),
        'Monk: Unarmored Defense (10 + Dex + Wis), Dex attacks, Str and Dex saves, no Focus at level 1');
    check(count(objects_here('user_id', (int) $id)) === 6 && hero('Testf')['gold'] === 11,
        "the Monk's kit: a Spear, 5 Daggers and 11 gp");
    q('INSERT INTO objects (item_id, user_id) VALUES (40, ?), (50, ?), (15, ?), (24, ?)', [$id, $id, $id, $id]);
    check(says(run_command(hero('Testf'), 'equip chain'), "A Monk isn't trained")
        && says(run_command(hero('Testf'), 'equip shield'), "A Monk isn't trained"), 'Monks have no armor training');
    check(says(run_command(hero('Testf'), 'equip greatsword'), 'no Proficiency Bonus')
        && says(run_command(hero('Testf'), 'score'), 'no Martial Arts')
        && !says(run_command(hero('Testf'), 'equip shortsword'), 'no Proficiency')
        && says(run_command(hero('Testf'), 'score'), 'Shortsword +4 to hit, 1d6+2'),
        'Monk weapons: Simple or Light; anything else turns off Martial Arts');
    run_command(hero('Testf'), 'equip spear');
    $dice_queue = [10, 1, 12, 5]; // the Spear is thrown in an opening volley: no Unarmed Strike from afar
    $out = run_command(hero('Testf'), 'kill dummy');
    check(says($out, '(16 vs AC 10) for 7 damage.') && says($out, 'closes in!') && !says($out, 'Unarmed')
        && $dice_queue === [], 'a thrown Spear opens a volley');
    $dice_queue = [12, 5, 11, 3, 1]; // Spear hits for 5 + 2; the Bonus Action strike for 3 + 2
    $out = run_command(hero('Testf'), 'kill');
    check(says($out, '(16 vs AC 10) for 7 damage.')
        && says($out, 'Your Unarmed Strike hits the Training Dummy (15 vs AC 10) for 5 damage.') && $dice_queue === []
        && hero('Testf')['bonus_used'] === 0, 'Martial Arts: an Unarmed Strike as a Bonus Action, with the d6');
    check(says(run_command(hero('Testf'), 'flurry'), 'level 2 or more'), 'Flurry of Blows needs level 2');
    q("UPDATE users SET level = 2 WHERE name = 'Testf'");
    $dice_queue = [1, 11, 3, 11, 3, 1]; // the Spear misses; two Unarmed Strikes hit
    $out = run_command(hero('Testf'), 'flurry');
    check(says($out, 'You unleash a Flurry of Blows!') && count(array_filter($out, fn ($l) => str_contains($l,
        'Your Unarmed Strike hits'))) === 2 && $dice_queue === [] && hero('Testf')['uses_spent'] === 1,
        'Flurry of Blows: two Unarmed Strikes for 1 Focus Point');
    check(says(run_command(hero('Testf'), 'score'), 'Focus 1/2'), 'score shows Focus Points');
    $dice_queue = [1];
    check(says(run_command(hero('Testf'), 'flee'), 'You flee') && $dice_queue === [1],
        'Step of the Wind: from level 2 a Monk always gets away');
    q("UPDATE users SET room_id = 2, hp = 3, uses_spent = 2 WHERE name = 'Testf'");
    check(says(run_command(hero('Testf'), 'patient dummy'), 'no Focus Points left'), 'techniques cost Focus');
    $dice_queue = [10, 1, 4, 1]; // Uncanny Metabolism heals 4 + 2; the thrown Spear misses
    $out = run_command(hero('Testf'), 'kill dummy');
    check(says($out, 'Uncanny Metabolism: your Focus Points return, and HP 9/9.') && $dice_queue === []
        && hero('Testf')['uses_spent'] === 0 && hero('Testf')['once_used'] === 1,
        'Uncanny Metabolism: rolling Initiative brings back Focus and some HP');
    $dice_queue = [12, 5, 15, 2]; // no Bonus Action strike; the Slam rolls twice and takes the 2
    $out = run_command(hero('Testf'), 'patient');
    check(says($out, 'Patient Defense') && says($out, 'misses you (5 vs AC 14, with Disadvantage)')
        && !says($out, 'Unarmed Strike') && $dice_queue === [], 'Patient Defense: Dodge as the Bonus Action');
    q("UPDATE users SET level = 3 WHERE name = 'Testf'");
    $dice_queue = [1, 1, 15, 4, 2, 5, 3, 4]; // misses; the Slam hits for 4, 2 + 2 + 3 deflected; Dex save 5 fails
    $out = run_command(hero('Testf'), 'kill');
    check(says($out, 'hits you (18 vs AC 14) for 0 damage, 4 deflected.')
        && says($out, 'You turn the blow back on the Training Dummy (Dex save 5 vs DC 12) for 9 damage.')
        && hero('Testf')['hp'] === 9 && hero('Testf')['uses_spent'] === 2 && $dice_queue === [],
        'Deflect Attacks: 1d10 + Dex + level off a hit, and a Focus Point turns it back');
    q("UPDATE users SET uses_spent = 0 WHERE name = 'Testf'");
    $dice_queue = [1, 11, 3, 5, 2, 11, 3, 1]; // a strike topples it (Dex save 5); the next has Advantage; it gets up
    $out = run_command(hero('Testf'), 'flurry');
    check(says($out, 'Your open hand topples the Training Dummy, Prone (Dex save 5 vs DC 12).')
        && says($out, '(15 vs AC 10, with Advantage)') && says($out, 'gets back up') && $dice_queue === [],
        'Open Hand Technique: a Flurry hit can knock it Prone');
    q("UPDATE users SET level = 5, uses_spent = 0 WHERE name = 'Testf'");
    $dice_queue = [12, 5, 5, 2, 12, 5, 3, 11, 4]; // Con save 5 fails; then Advantage; the dummy loses its turn
    $out = run_command(hero('Testf'), 'stun');
    check(says($out, 'The Training Dummy is Stunned until your next turn (Con save 5 vs DC 13)!')
        && says($out, "is Stunned and can't act.") && count(array_filter($out, fn ($l) => str_contains($l,
        'with Advantage'))) === 2 && $dice_queue === [] && hero('Testf')['uses_spent'] === 1,
        'Stunning Strike: a failed Con save Stuns until my next turn');
    $dice_queue = [12, 5, 15, 2, 12, 5, 11, 4, 1]; // it saves: only the next attack has Advantage
    $out = run_command(hero('Testf'), 'stun');
    check(says($out, "reels but isn't Stunned (Con save 15 vs DC 13)")
        && count(array_filter($out, fn ($l) => str_contains($l, 'with Advantage'))) === 1 && says($out, 'Slam misses')
        && $dice_queue === [], 'a saved Stunning Strike leaves it reeling');
    q("UPDATE users SET level = 6 WHERE name = 'Testf'");
    q("UPDATE monsters SET resistances = 'bludgeoning' WHERE id = 9999");
    $dice_queue = [1, 1, 11, 4, 1];
    $out = run_command(hero('Testf'), 'kill');
    check(says($out, 'Your Unarmed Strike hits the Training Dummy (16 vs AC 10) for 6 damage (force).')
        && $dice_queue === [], 'Empowered Strikes: Force damage past a Bludgeoning resistance');
    q("UPDATE monsters SET resistances = '' WHERE id = 9999");
    $dice_queue = [1];
    run_command(hero('Testf'), 'flee');
    q("UPDATE users SET hp = 1 WHERE name = 'Testf'");
    $dice_queue = [3];
    check(says(run_command(hero('Testf'), 'wholeness'), 'regain 5 HP. HP 6/9, 1 Wholeness of Body left.'),
        'Wholeness of Body: a Martial Arts die + Wis');
    $score = run_command(hero('Testf'), 'score');
    check(says($score, 'Martial Arts d8   Focus 4/6') && says($score, '2 attacks a round'),
        'level 6: the d8, Extra Attack, 6 Focus Points');
    q("UPDATE users SET resting = 'short', rest_until = NOW(), hit_dice_spent = 6 WHERE name = 'Testf'");
    check(says(run_command(hero('Testf'), 'score'), 'and regaining your Focus Points.')
        && hero('Testf')['uses_spent'] === 0, 'a short rest brings back every Focus Point');
    q("UPDATE users SET resting = 'long', rest_until = NOW() WHERE name = 'Testf'");
    run_command(hero('Testf'), 'score');
    check(hero('Testf')['once_used'] === 0 && hero('Testf')['feature_spent'] === 0,
        'a long rest brings back Uncanny Metabolism and Wholeness of Body');
    q("UPDATE users SET level = 14 WHERE name = 'Testf'");
    check(says(run_command(hero('Testf'), 'score'), 'Saves  Str +6  Dex +7  Con +6  Int +5  Wis +7  Cha +4'),
        'Disciplined Survivor: proficient in every save at 14');

    // Paladin (Testg: Str 15, Cha 14: spell save DC 12, spell attack +4). The dummy: AC 10.
    q('UPDATE spawns SET hp = 200, dead_since = NULL WHERE id = 9999');
    q('UPDATE users SET fighting = NULL WHERE fighting = 9999');
    save_hero('Testg', 'correct horse');
    create('Testg', 'paladin');
    q("UPDATE users SET room_id = 2 WHERE name IN ('Testg', 'Testf')");
    $score = run_command(hero('Testg'), 'score');
    check(says($score, 'level 1 Paladin') && says($score, 'HP 11/11   AC 18')
        && says($score, 'Longsword +4 to hit, 1d8+2 damage')
        && says($score, 'Hit Dice 1/1 d10   Lay On Hands 5/5   Spell slots 2/2')
        && says($score, 'Saves  Str +2  Dex +0  Con +1  Int -1  Wis +3  Cha +4')
        && count(objects_here('user_id', (int) hero('Testg')['id'])) === 9 && hero('Testg')['gold'] === 9,
        'Paladin: Chain Mail, Shield, Longsword, 6 Javelins; Lay On Hands 5 x level; Wis and Cha saves');
    $out = run_command(hero('Testg'), 'cast');
    check(says($out, 'save DC 12, spell attack +4; slots 2/2') && says($out, 'divine favor')
        && says($out, 'cure wounds')
        && !says($out, 'divine smite'), 'a Paladin casts with Cha, and has no Divine Smite before level 2');
    q("UPDATE users SET hp = 3 WHERE name = 'Testg'");
    q("UPDATE users SET hp = 1 WHERE name = 'Testf'");
    check(says(run_command(hero('Testg'), 'lay'), 'You regain 5 HP. HP 8/11, 0 left in your Lay On Hands pool.')
        && says(run_command(hero('Testg'), 'lay testf'), 'pool is empty'), 'Lay On Hands heals from a pool');
    q("UPDATE users SET uses_spent = 0 WHERE name = 'Testg'");
    check(says(run_command(hero('Testg'), 'lay testf'), 'Testf regains 5 HP') && hero('Testf')['hp'] === 6,
        'and can heal another hero here');
    q("UPDATE users SET level = 2, hp = 19, max_hp = 19 WHERE name = 'Testg'");
    check(says(run_command(hero('Testg'), 'score'), 'AC 19'), 'level 2: the Defense fighting style');
    $dice_queue = [10, 1, 12, 5, 3, 4, 1]; // a hit for 5 + 2, the smite's 2d8 for 7, free once a long rest
    $out = run_command(hero('Testg'), 'cast divine smite dummy');
    check(says($out, '(16 vs AC 10) for 7 damage.')
        && says($out, 'Your Divine Smite (free) sears the Training Dummy for 7 more damage. Slots 2/2.')
        && q('SELECT hp FROM spawns WHERE id = 9999')->fetchColumn() === 186
        && $dice_queue === [], "Paladin's Smite: Divine Smite on a melee hit, free once a long rest");
    $type = q('SELECT type FROM monsters WHERE id = 9999')->fetchColumn();
    q("UPDATE monsters SET type = 'undead' WHERE id = 9999");
    $dice_queue = [12, 5, 3, 4, 5, 1];
    $out = run_command(hero('Testg'), 'cast divine smite');
    check(says($out, 'Your Divine Smite sears the Training Dummy for 12 more damage. Slots 1/2.')
        && $dice_queue === [], 'then it spends a slot, and an Undead takes 1d8 more');
    q('UPDATE monsters SET type = ? WHERE id = 9999', [$type]);
    $dice_queue = [1, 1];
    check(says(run_command(hero('Testg'), 'cast divine sm'), 'You miss') && hero('Testg')['slots_spent'] === '1'
        && $dice_queue === [], 'a smite that never hits spends nothing');
    $out = run_command(hero('Testg'), 'cast divine favor');
    $dice_queue = [12, 5, 2, 1];
    check(says($out, 'You cast Divine Favor on you. Slots 0/2.') && !says($out, 'Slam')
        && says(run_command(hero('Testg'), 'kill'), '(16 vs AC 10) for 9 damage (+2 Divine Favor).')
        && $dice_queue === [],
        'Divine Favor: a Bonus Action, then 1d4 Radiant on each weapon hit');
    q("UPDATE users SET level = 5, slots_spent = '' WHERE name = 'Testg'");
    q('DELETE FROM effects');
    check(says(run_command(hero('Testg'), 'prepare divine favor, cure wounds, shining smite'), 'You prepare'),
        'a level 5 Paladin prepares a level 2 spell');
    $dice_queue = [12, 5, 3, 3, 2, 12, 5, 1, 1]; // 2d6 Radiant; then the second attack has Advantage
    $out = run_command(hero('Testg'), 'cast shining dummy');
    check(says($out, 'Your Shining Smite sears the Training Dummy for 6 more damage. Slots 4/4 1/2.')
        && says($out, '(17 vs AC 10, with Advantage)') && $dice_queue === [],
        'Shining Smite: a level 2 smite, and attacks on the foe have Advantage');
    q("UPDATE users SET level = 6 WHERE name = 'Testg'");
    check(says(run_command(hero('Testg'), 'score'), 'Saves  Str +4  Dex +2  Con +3  Int +1  Wis +6  Cha +7'),
        'Aura of Protection: + Cha to every save from level 6');
    q("UPDATE users SET level = 11 WHERE name = 'Testg'");
    q('DELETE FROM effects');
    $dice_queue = [12, 5, 4, 1, 1, 1]; // the second attack misses; the Longsword's Sap gives the Slam Disadvantage
    $out = run_command(hero('Testg'), 'kill');
    check(says($out, '(18 vs AC 10) for 11 damage (+4 Radiant Strikes).')
        && $dice_queue === [], 'Radiant Strikes: 1d8 Radiant on melee hits from level 11');
    $dice_queue = [20, 1];
    run_command(hero('Testg'), 'flee');
    q("UPDATE users SET uses_spent = 5, resting = 'short', rest_until = NOW() WHERE name = 'Testg'");
    run_command(hero('Testg'), 'score');
    check(hero('Testg')['uses_spent'] === 5, "a short rest doesn't refill Lay On Hands");
    q("UPDATE users SET resting = 'long', rest_until = NOW() WHERE name = 'Testg'");
    run_command(hero('Testg'), 'score');
    check(hero('Testg')['uses_spent'] === 0 && hero('Testg')['once_used'] === 0,
        'a long rest refills it and the free Divine Smite');

    // Ranger (Testh: Dex 15, Wis 14: spell save DC 12). The dummy: AC 10, Str save +0.
    q('DELETE FROM effects');
    q('UPDATE spawns SET hp = 200, max_hp = 200, dead_since = NULL WHERE id = 9999');
    q('UPDATE users SET fighting = NULL WHERE fighting = 9999');
    save_hero('Testh', 'correct horse');
    create('Testh', 'ranger');
    q("UPDATE users SET room_id = 2 WHERE name = 'Testh'");
    $score = run_command(hero('Testh'), 'score');
    check(says($score, 'level 1 Ranger') && says($score, 'HP 11/11   AC 14')
        && says($score, 'Scimitar +4 to hit, 1d6+2 damage')
        && says($score, 'Hit Dice 1/1 d10   Favored Enemy 2/2   Spell slots 2/2')
        && says($score, 'Saves  Str +3  Dex +4  Con +1  Int -1  Wis +2  Cha +0')
        && count(objects_here('user_id', (int) hero('Testh')['id'])) === 4 && hero('Testh')['gold'] === 7,
        'Ranger: Studded Leather, Scimitar, Shortsword, Longbow; Str and Dex saves; 2 free Hunter\'s Marks');
    $out = run_command(hero('Testh'), 'cast');
    check(says($out, "hunter's mark") && says($out, 'ensnaring strike <monster>') && says($out, 'cure wounds')
        && !says(run_command(hero('Testh'), 'prepare'), "hunter's mark"),
        "Hunter's Mark is always prepared, beside the SRD's suggested two");
    check(says(run_command(hero('Testh'), 'cast hunt'), "You cast Hunter's Mark (free) on you.")
        && hero('Testh')['uses_spent'] === 1 && hero('Testh')['slots_spent'] === '',
        "Favored Enemy: Hunter's Mark without a slot");
    $dice_queue = [10, 1, 12, 3, 4, 1];
    $out = run_command(hero('Testh'), 'kill dummy');
    check(says($out, "(16 vs AC 10) for 9 damage (+4 Hunter's Mark).") && $dice_queue === [],
        "Hunter's Mark: 1d6 Force on each weapon hit");
    $dice_queue = [12, 3, 4, 5, 1, 1]; // the Str save fails; Restrained, the Slam has Disadvantage
    $out = run_command(hero('Testh'), 'cast ensnaring');
    check(says($out, 'Your Ensnaring Strike: the Training Dummy is Restrained (Str save 5 vs DC 12). Slots 1/2.')
        && says($out, 'misses you (natural 1, with Disadvantage)')
        && concentrating(hero('Testh')) === 'Ensnaring Strike' && $dice_queue === [],
        "Ensnaring Strike: a hit, a Str save or Restrained; it ends Hunter's Mark's Concentration");
    $dice_queue = [2, 12, 3, 1, 1];
    check(says(run_command(hero('Testh'), 'kill'), '(16 vs AC 10, with Advantage) for 5 damage.') && $dice_queue === [],
        'attacks on a Restrained foe have Advantage');
    q("UPDATE users SET level = 3 WHERE name = 'Testh'");
    $dice_queue = [2, 12, 3, 5, 1, 1];
    check(says(run_command(hero('Testh'), 'kill'), 'for 10 damage (+5 Colossus Slayer).') && $dice_queue === [],
        'Colossus Slayer: 1d8 more on a foe missing HP');
    $dice_queue = [20, 1];
    run_command(hero('Testh'), 'flee');
    run_command(hero('Testh'), 'equip longbow');
    check(says(run_command(hero('Testh'), 'score'), 'Longbow +6 to hit, 1d8+2 damage'),
        'Archery: +2 to ranged attacks');
    q("UPDATE users SET level = 7, room_id = 2 WHERE name = 'Testh'");
    q('UPDATE monsters SET attacks = 2 WHERE id = 9999');
    q('DELETE FROM effects');
    $dice_queue = [10, 1, 1, 1]; // an opening volley of two misses
    run_command(hero('Testh'), 'kill dummy');
    $dice_queue = [1, 1, 1, 1, 15, 2, 15, 1]; // two misses; its first Slam hits, so the second rolls twice
    $out = run_command(hero('Testh'), 'kill');
    check(says($out, 'hits you (18 vs AC 14) for 2 damage.') && says($out, 'misses you (natural 1, with Disadvantage)')
        && $dice_queue === [], 'Multiattack Defense: after a hit, its other attacks this turn have Disadvantage');
    q('UPDATE monsters SET attacks = 1 WHERE id = 9999');
    check(says(run_command(hero('Testh'), 'score'), 'Favored Enemy 2/3'), 'level 5 and up: 3 free Hunter\'s Marks');
    $dice_queue = [20, 1];
    run_command(hero('Testh'), 'flee');

    // Wizard (Testi: Int 15: spell save DC 12, spell attack +4). The dummy: AC 10, saves +0.
    q('DELETE FROM effects');
    q('UPDATE spawns SET hp = 200, max_hp = 200, dead_since = NULL WHERE id = 9999');
    q('UPDATE users SET fighting = NULL WHERE fighting = 9999');
    save_hero('Testi', 'correct horse');
    create('Testi', 'wizard');
    $id = hero('Testi')['id'];
    q("UPDATE users SET room_id = 2 WHERE name = 'Testi'");
    $score = run_command(hero('Testi'), 'score');
    check(says($score, 'level 1 Wizard') && says($score, 'HP 7/7   AC 11')
        && says($score, 'Quarterstaff +1 to hit, 1d8-1 damage') && says($score, 'Hit Dice 1/1 d6   Spell slots 2/2')
        && says($score, 'Saves  Str -1  Dex +1  Con +1  Int +4  Wis +4  Cha +0')
        && count(objects_here('user_id', (int) $id)) === 3 && hero('Testi')['gold'] === 5,
        'Wizard: d6, a Quarterstaff and 2 Daggers, Int and Wis saves');
    q('INSERT INTO objects (item_id, user_id) VALUES (24, ?)', [$id]);
    check(says(run_command(hero('Testi'), 'equip shortsword'), 'no Proficiency Bonus'), 'Wizards use Simple weapons');
    run_command(hero('Testi'), 'equip quarterstaff');
    $out = run_command(hero('Testi'), 'cast');
    check(says($out, 'fire bolt <monster>') && says($out, 'cantrip: spell attack, 1d10 Fire')
        && says($out, 'magic missile') && says($out, 'mage armor [hero]'), 'Wizard cantrips and prepared spells');
    check(says(run_command(hero('Testi'), 'cast mage'), 'You cast Mage Armor on you. Slots 1/2.')
        && says(run_command(hero('Testi'), 'score'), 'AC 14'), 'Mage Armor: AC 13 + Dex');
    $dice_queue = [10, 1, 1, 2, 3, 1];
    $out = run_command(hero('Testi'), 'cast magic dummy');
    check(says($out, 'Your Magic Missile strikes the Training Dummy for 9 damage.') && $dice_queue === [],
        'Magic Missile: three darts, no attack roll');
    $dice_queue = [12, 3, 1];
    check(says(run_command(hero('Testi'), 'cast fire'), 'Your Fire Bolt misses the Training Dummy (7 vs AC 10, with '
        . 'Disadvantage).') && $dice_queue === [], 'Fire Bolt: a ranged spell attack, Disadvantage next to the foe');
    q("UPDATE users SET level = 3, slots_spent = '' WHERE name = 'Testi'");
    $dice_queue = [3, 3, 8, 1];
    check(says(run_command(hero('Testi'), 'cast fire'), 'but still deals 4 damage.') && $dice_queue === [],
        'Potent Cantrip: a missed cantrip deals half damage');
    run_command(hero('Testi'), 'prepare mage armor, magic missile, burning hands, thunderwave, scorching ray');
    $dice_queue = [12, 15, 3, 4, 1, 1, 15, 15, 2, 2, 1]; // three rays: a hit, a miss, a hit
    $out = run_command(hero('Testi'), 'cast scorching');
    check(count(array_filter($out, fn ($l) => str_contains($l, 'Your Scorching Ray hits'))) === 2
        && says($out, 'Your Scorching Ray misses') && $dice_queue === [] && hero('Testi')['slots_spent'] === '0,1',
        'Scorching Ray: three rays, each its own attack roll');
    q("UPDATE users SET level = 5, slots_spent = '' WHERE name = 'Testi'");
    run_command(hero('Testi'), 'prepare mage, magic, burning, thunderwave, scorching, fireball');
    $dice_queue = [5, 1, 1, 1, 1, 1, 1, 1, 1, 1];
    check(says(run_command(hero('Testi'), 'cast fireball'), 'fails its Dex save (5 vs DC 13) against your Fireball '
        . 'and takes 8 damage.') && $dice_queue === [], 'Fireball: 8d6, Dex save for half');
    $dice_queue = [20, 1];
    run_command(hero('Testi'), 'flee');
    q("UPDATE users SET slots_spent = '1,1,1', resting = 'short', rest_until = NOW() WHERE name = 'Testi'");
    check(says(run_command(hero('Testi'), 'score'), 'with Arcane Recovery bringing back slots of level 3.')
        && hero('Testi')['slots_spent'] === '1,1,0', 'Arcane Recovery: slots worth half your level, highest first');
    q("UPDATE users SET resting = 'short', rest_until = NOW() WHERE name = 'Testi'");
    check(!says(run_command(hero('Testi'), 'score'), 'Arcane Recovery'), 'once a long rest');

    // Sorcerer (Testj: Cha 15: spell save DC 12, spell attack +4). The dummy: AC 10, saves +0.
    q('DELETE FROM effects');
    q('UPDATE spawns SET hp = 200, max_hp = 200, dead_since = NULL WHERE id = 9999');
    q('UPDATE users SET fighting = NULL WHERE fighting = 9999');
    save_hero('Testj', 'correct horse');
    create('Testj', 'sorcerer');
    q("UPDATE users SET room_id = 2 WHERE name = 'Testj'");
    $score = run_command(hero('Testj'), 'score');
    check(says($score, 'level 1 Sorcerer') && says($score, 'HP 8/8   AC 11') && says($score, 'Spear +2 to hit')
        && says($score, 'Hit Dice 1/1 d6   Innate Sorcery 2/2   Spell slots 2/2')
        && says($score, 'Saves  Str +0  Dex +1  Con +4  Int -1  Wis +1  Cha +4')
        && count(objects_here('user_id', (int) hero('Testj')['id'])) === 3 && hero('Testj')['gold'] === 28,
        'Sorcerer: d6, a Spear and 2 Daggers, Con and Cha saves');
    check(says(run_command(hero('Testj'), 'innate'), 'needs a fight'), 'Innate Sorcery needs a fight');
    $dice_queue = [10, 1, 12, 5];
    check(says(run_command(hero('Testj'), 'cast fire bolt dummy'), 'closes in!') && $dice_queue === [],
        'a Fire Bolt opens a volley');
    $out = run_command(hero('Testj'), 'innate');
    $dice_queue = [12, 5, 1];
    check(says($out, 'Magic surges up') && !says($out, 'Slam') && says(run_command(hero('Testj'), 'cast fire'),
        'Your Fire Bolt hits the Training Dummy (16 vs AC 10) for 5 damage.') && $dice_queue === []
        && says(run_command(hero('Testj'), 'cast'), 'save DC 13, spell attack +4'),
        "Innate Sorcery: Advantage cancels the close foe's Disadvantage, and +1 to the save DC");
    q("UPDATE users SET level = 2, slots_spent = '3' WHERE name = 'Testj'");
    $out = run_command(hero('Testj'), 'font 1');
    check(says($out, 'You shape 2 Sorcery Points into a level 1 spell slot. Slots 1/3.')
        && hero('Testj')['uses_spent'] === 2, 'Font of Magic: Sorcery Points into a slot');
    check(says(run_command(hero('Testj'), 'font points 1'), 'Sorcery Points: 1/2. Slots 0/3.'),
        'and a slot back into points');
    q("UPDATE users SET uses_spent = 0, slots_spent = '' WHERE name = 'Testj'");
    $dice_queue = [15, 5, 1, 1, 1, 1];
    $out = run_command(hero('Testj'), 'cast heightened burning');
    check(says($out, 'fails its Dex save (5 vs DC 13, with Disadvantage) against your Burning Hands and takes 3 '
        . 'damage.')
        && hero('Testj')['uses_spent'] === 2 && $dice_queue === [], 'Heightened Spell: Disadvantage on the save');
    check(says(run_command(hero('Testj'), 'cast heightened fire'), 'only on a spell with a saving throw')
        && says(run_command(hero('Testj'), 'cast seeking fire'), 'You need 1 Sorcery Point'), 'Metamagic checks');
    q("UPDATE users SET uses_spent = 1 WHERE name = 'Testj'");
    $dice_queue = [1, 15, 5, 1];
    $out = run_command(hero('Testj'), 'cast seeking fire');
    check(says($out, 'Seeking Spell steers it back')
        && says($out, 'Your Fire Bolt hits the Training Dummy (19 vs AC 10)')
        && $dice_queue === [], 'Seeking Spell: a missed spell attack rolls again');
    q("UPDATE users SET level = 6 WHERE name = 'Testj'");
    $out = run_command(hero('Testj'), 'cast');
    $dice_queue = [12, 5, 1, 1]; // two d10s at level 5, + Cha
    check(says(run_command(hero('Testj'), 'cast fire'), 'for 8 damage.') && says($out, 'command <monster>')
        && says(run_command(hero('Testj'), 'score'), 'AC 13') && $dice_queue === []
        && str_contains(with_stats(hero('Testj'))['resistances'], 'fire'),
        'Draconic Sorcery: AC 10 + Dex + Cha, Command always prepared, and Elemental Affinity with Fire');
    $dice_queue = [20, 1];
    run_command(hero('Testj'), 'flee');
    q("UPDATE users SET uses_spent = 5, resting = 'short', rest_until = NOW() WHERE name = 'Testj'");
    check(says(run_command(hero('Testj'), 'score'), 'Sorcerous Restoration bringing back 3 Sorcery Points')
        && hero('Testj')['uses_spent'] === 2, 'Sorcerous Restoration: half your level in points on a short rest');

    // Bard (Testk: Cha 15: spell save DC 12; Dagger +4). The dummy: AC 10, saves +0, Slam +3.
    q('DELETE FROM effects');
    q('UPDATE spawns SET hp = 200, max_hp = 200, dead_since = NULL WHERE id = 9999');
    q('UPDATE users SET fighting = NULL WHERE fighting = 9999');
    save_hero('Testk', 'correct horse');
    create('Testk', 'bard');
    q("UPDATE users SET room_id = 2 WHERE name IN ('Testk', 'Testj')");
    $score = run_command(hero('Testk'), 'score');
    check(says($score, 'level 1 Bard') && says($score, 'HP 9/9   AC 13') && says($score, 'Dagger +4 to hit, 1d4+2')
        && says($score, 'Hit Dice 1/1 d8   Bardic Inspiration 2/2 d6   Spell slots 2/2')
        && says($score, 'Saves  Str -1  Dex +4  Con +1  Int +1  Wis +0  Cha +4')
        && count(objects_here('user_id', (int) hero('Testk')['id'])) === 3 && hero('Testk')['gold'] === 19,
        'Bard: d8, Leather Armor and 2 Daggers, Dex and Cha saves');
    $out = run_command(hero('Testk'), 'cast');
    check(says($out, 'vicious mockery <monster>') && says($out, 'healing word [hero]') && says($out, 'faerie fire'),
        'Bard cantrips and prepared spells');
    check(says(run_command(hero('Testk'), 'inspire'), 'Inspire whom?')
        && says(run_command(hero('Testk'), 'inspire testj'), 'You inspire Testj with a d6 of Bardic Inspiration. 1 '
            . 'left.') && says(run_command(hero('Testk'), 'inspire testj'), 'already holds')
        && says(run_command(hero('Testj'), 'score'), 'Bardic Inspiration'),
        'Bardic Inspiration: a die for another hero');
    $dice_queue = [10, 1, 4, 3, 1, 1]; // an opening volley: 9 vs AC 10, so the die is rolled
    check(says(run_command(hero('Testj'), 'cast fire dummy'), 'hits the Training Dummy (12 vs AC 10, +3 Bardic '
        . 'Inspiration) for 4 damage.') && $dice_queue === [] && !effects_on('user_id', (int) hero('Testj')['id']),
        'an inspired hero adds the die to a missed attack roll, and it is spent');
    $dice_queue = [20, 1];
    run_command(hero('Testj'), 'flee');
    $dice_queue = [10, 1, 5, 4, 15, 2];
    $out = run_command(hero('Testk'), 'cast vicious dummy');
    check(says($out, 'fails its Wis save (5 vs DC 12) against your Vicious Mockery and takes 4 damage.')
        && says($out, 'misses you (5 vs AC 13, with Disadvantage)') && $dice_queue === [],
        'Vicious Mockery: Psychic damage and Disadvantage on its next attack');
    q("UPDATE users SET level = 3 WHERE name = 'Testk'");
    $dice_queue = [10, 1, 12, 5];
    $out = run_command(hero('Testk'), 'kill');
    check(says($out, 'misses you (10 vs AC 13, -5 Cutting Words).') && $dice_queue === []
        && hero('Testk')['uses_spent'] === 2, 'Cutting Words: a Bardic Inspiration die off a hit that it could turn');
    $dice_queue = [20, 1];
    run_command(hero('Testk'), 'flee');
    q("UPDATE users SET level = 5, resting = 'short', rest_until = NOW() WHERE name = 'Testk'");
    check(says(run_command(hero('Testk'), 'score'), 'regaining your Bardic Inspiration')
        && says(run_command(hero('Testk'), 'score'), 'Bardic Inspiration 2/2 d8'),
        'Font of Inspiration: every use back on a short rest; a d8 from level 5');
    q("UPDATE users SET level = 6 WHERE name = 'Testk'");
    check(says(run_command(hero('Testk'), 'prepare bane, command, cure, dissonant, faerie, healing, thunderwave, aid, '
        . 'shatter, mass, fireball, spirit'), 'You prepare') && says(run_command(hero('Testk'), 'cast'), 'fireball'),
        'Magical Discoveries: two spells from the Cleric, Druid or Wizard lists, on top of the others');
    check(says(run_command(hero('Testk'), 'prepare bane, fireball, spirit, inflict'), 'only two')
        && says(run_command(hero('Testk'), 'prepare bane, command, cure, dissonant, faerie, healing, thunderwave, aid, '
        . 'shatter, mass, hold'), 'not 11'), 'and no more until Magical Secrets');
    q("UPDATE users SET level = 14, room_id = 2 WHERE name = 'Testk'");
    $dice_queue = [10, 1, 2, 4, 1]; // a thrown Dagger's opening volley
    check(says(run_command(hero('Testk'), 'kill dummy'), 'You hit the Training Dummy (13 vs AC 10, +4 Peerless Skill)')
        && $dice_queue === [] && hero('Testk')['uses_spent'] === 1, 'Peerless Skill: your own die on a missed attack');
    $dice_queue = [20, 1];
    run_command(hero('Testk'), 'flee');

    // Druid (Testl: Wis 15: spell save DC 12; Sickle +1). The dummy: AC 10, saves +0, Slam +3.
    q('DELETE FROM effects');
    q('UPDATE spawns SET hp = 200, max_hp = 200, dead_since = NULL WHERE id = 9999');
    q('UPDATE users SET fighting = NULL WHERE fighting = 9999');
    save_hero('Testl', 'correct horse');
    create('Testl', 'druid');
    q("UPDATE users SET room_id = 2 WHERE name = 'Testl'");
    $score = run_command(hero('Testl'), 'score');
    check(says($score, 'level 1 Druid') && says($score, 'HP 10/10   AC 14') && says($score, 'Sickle +1 to hit, 1d4-1')
        && says($score, 'Hit Dice 1/1 d8   Spell slots 2/2')
        && says($score, 'Saves  Str -1  Dex +1  Con +2  Int +3  Wis +4  Cha +0')
        && count(objects_here('user_id', (int) hero('Testl')['id'])) === 4 && hero('Testl')['gold'] === 9,
        'Druid: d8, Leather Armor, Shield, Sickle and Quarterstaff, Int and Wis saves');
    check(says(run_command(hero('Testl'), 'cast'), 'starry wisp <monster>')
        && says(run_command(hero('Testl'), 'wild wolf'), 'level 2 or more'), 'Druid spells; no Wild Shape at level 1');
    q("UPDATE users SET level = 2 WHERE name = 'Testl'");
    $out = run_command(hero('Testl'), 'wild');
    check(says($out, 'Wild Shape forms (2/2 uses left)') && says($out, 'wolf') && says($out, 'giant rat')
        && !says($out, '  ape '), 'Wild Shape forms: SRD Beasts up to CR 1/4 at level 2');
    check(says(run_command(hero('Testl'), 'wild wolf'), 'You shift into a Wolf! AC 12, 2 temporary HP, 1 Wild Shape '
        . 'left.')
        && says($score = run_command(hero('Testl'), 'score'), 'HP 10/10 +2 temp   AC 12')
        && says($score, 'Bite +4 to hit, 1d6+2 damage') && says($score, 'Wild Shape: Wolf')
        && says(run_command(hero('Testl'), 'cast cure'), "can't cast spells in Wild Shape"),
        "Wild Shape: the Beast's AC and attack, Temporary Hit Points, and no spells");
    $dice_queue = [10, 1, 10, 3, 15, 4];
    $out = run_command(hero('Testl'), 'kill dummy');
    check(says($out, 'You hit the Training Dummy (14 vs AC 10) for 5 damage.')
        && says($out, 'hits you (18 vs AC 12) for 4 damage (2 to temporary HP).') && hero('Testl')['hp'] === 8
        && hero('Testl')['temp_hp'] === 0 && $dice_queue === [], 'a Bite as a Wolf; Temporary Hit Points go first');
    check(says(run_command(hero('Testl'), 'wild'), 'stand as yourself again')
        && says(run_command(hero('Testl'), 'score'), 'AC 14'), 'wild alone leaves the form');
    q("UPDATE users SET level = 3 WHERE name = 'Testl'");
    $dice_queue = [5, 3, 4, 2, 2, 1];
    check(says(run_command(hero('Testl'), 'land'), 'fails its Con save (5 vs DC 12) and takes 7 damage, and you '
        . 'regain 2 HP.') && $dice_queue === [] && hero('Testl')['uses_spent'] === 2,
        "Land's Aid: a Wild Shape for 2d6 Necrotic and 2d6 HP");
    check(says(run_command(hero('Testl'), 'cast'), 'fire bolt <monster>') && says(run_command(hero('Testl'), 'cast'),
        'burning hands'), 'Circle of the Land (Arid): Fire Bolt and Burning Hands always prepared');
    $dice_queue = [20, 1];
    run_command(hero('Testl'), 'flee');
    q("UPDATE users SET level = 6, slots_spent = '1,1,1', resting = 'short', rest_until = NOW() WHERE name = 'Testl'");
    check(says(run_command(hero('Testl'), 'score'), 'with Natural Recovery bringing back slots of level 3 and '
        . 'regaining a Wild Shape.') && hero('Testl')['uses_spent'] === 1 && hero('Testl')['slots_spent'] === '1,1,0',
        'Natural Recovery: slots on a short rest, and a Wild Shape back');
    q("UPDATE users SET level = 7, room_id = 2 WHERE name = 'Testl'");
    $dice_queue = [10, 1, 15, 2, 5, 1];
    check(says(run_command(hero('Testl'), 'kill dummy'), 'for 6 damage (+5 cold Primal Strike).') && $dice_queue === [],
        'Primal Strike: 1d8 more of a type it takes in full');
    q("UPDATE users SET level = 10 WHERE name = 'Testl'");
    check(str_contains(with_stats(hero('Testl'))['resistances'], 'fire'), "Nature's Ward: Fire Resistance (Arid)");
    $dice_queue = [20, 1];
    run_command(hero('Testl'), 'flee');
    run_command(hero('Testl'), 'wild dire');
    q("UPDATE users SET resting = 'long', rest_until = NOW() WHERE name = 'Testl'");
    run_command(hero('Testl'), 'score');
    check(hero('Testl')['shape'] === null && hero('Testl')['temp_hp'] === 0, 'a long rest ends Wild Shape');

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
