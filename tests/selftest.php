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
    return q('SELECT * FROM users WHERE account = ?', [$name])->fetch();
}
function says(array $lines, string $text): bool
{
    return (bool) array_filter($lines, fn ($l) => str_contains($l, $text));
}
/**
 * Takes a new hero through character creation: a name (the account's, if it has none yet), class, ability scores
 * ('ok' for the suggested ones), species and background, the first skills of each choice it owes, begin. With no
 * species the hero skips species and background, like one made before there were any, so the class's own numbers hold.
 */
function create(string $name, string $class, string $scores = 'ok', string $species = '', string $bg = ''): array
{
    if (hero($name)['setup'] === 'name') {
        run_command(hero($name), $name);
    }
    run_command(hero($name), $class);
    run_command(hero($name), $scores);
    if ($species === '') {
        q("UPDATE users SET setup = 'confirm' WHERE name = ?", [$name]);
    } else {
        run_command(hero($name), $species);
        run_command(hero($name), $bg);
    }
    while ($o = owed_choices(hero($name))) { // the first skills each choice lists
        $free = array_values(array_diff($o[0]['options'], $o[0]['locked']));
        run_command(hero($name), 'choose ' . implode(', ', array_slice($free, 0, $o[0]['pick'])));
    }
    return run_command(hero($name), 'begin');
}
/**
 * Ends $name's fight with a sure escape out the room's first exit (by name), spending no dice: what a flee did before
 * fights had distances, for checks that only need the fight over.
 */
function leave(string $name): void
{
    $to = q('SELECT to_room FROM exits WHERE room_id = ? ORDER BY dir LIMIT 1', [hero($name)['room_id']])
        ->fetchColumn();
    q('UPDATE users SET ' . END_FIGHT . ', room_id = ?, x = NULL, y = NULL WHERE name = ?', [$to, $name]);
}
/** Hands the turn in $name's room to $name, as if the heroes before them in the round had taken theirs. */
function turn(string $name): void
{
    q('UPDATE rooms r JOIN users u ON u.room_id = r.id SET r.turn = u.id, r.turn_at = NOW() WHERE u.name = ?', [$name]);
}
/** The first line containing $text, or ''. */
function line(array $lines, string $text): string
{
    return array_values(array_filter($lines, fn ($l) => str_contains($l, $text)))[0] ?? '';
}

$migrated = q("SHOW TABLES LIKE 'migrations'")->fetch()
    ? q('SELECT name FROM migrations')->fetchAll(PDO::FETCH_COLUMN) : [];
check(array_diff(array_map('basename', glob(__DIR__ . '/../db/migrations/*.sql') ?: []), $migrated) === [],
    'every db/migrations file is applied (php bin/migrate.php)');
check(cookie_path('/heroes-of-greenest/api.php') === '/heroes-of-greenest/' && cookie_path('/index.php') === '/',
    'the session cookie is scoped to the game folder');

db()->beginTransaction();
try {
    // The rooms these checks fight in get open boards (no trees or rocks), so distances are as the checks count them.
    $open = fn ($w, $h) => implode("\n", array_fill(0, $h, str_repeat('.', $w)));
    q('UPDATE rooms SET layout = ? WHERE id IN (2, 6, 9)', [$open(8, 6)]);
    q('UPDATE rooms SET layout = ? WHERE id = 6001', [$open(16, 10)]);
    check(save_account('Testa', 'short') !== '', 'rejects a short password');
    check(save_account('Te5t', 'long enough') !== '', 'rejects a bad name');
    check(save_account('Testa', 'correct horse') === '', 'creates an account');
    save_account('Testb', 'battery staple');
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
    save_account('Testa', 'new password!');
    check(session_user() === null, 'password reset ends old sessions');

    // Accounts asked for on account.php and approved by the DM, then changed by their player.
    check(request_account('Te5t', 'correct horse', 'Sam') !== '', 'a request needs an account name of letters');
    check(request_account('Testn', 'short', 'Sam') !== '', 'and a good password');
    check(request_account('Testn', 'correct horse', " \e\u{9B} ") !== '', 'and says who is asking');
    check(request_account('Testn', 'correct horse', "Sam\e[2J, Thursdays") === ''
        && q("SELECT note FROM requests WHERE account = 'Testn'")->fetchColumn() === 'Sam[2J, Thursdays',
        'a request, its note without control characters');
    check(request_account('testa', 'correct horse', 'Mallory') === ''
        && !q("SELECT 1 FROM requests WHERE account = 'testa'")->fetch(), 'a taken name looks sent but is dropped');
    $hash = q("SELECT pass_hash FROM requests WHERE account = 'Testn'")->fetchColumn();
    check(request_account('TESTN', 'other password', 'Mallory') === ''
        && q("SELECT pass_hash FROM requests WHERE account = 'Testn'")->fetchColumn() === $hash,
        'the first request for a name stands');
    for ($i = (int) q('SELECT COUNT(*) FROM requests')->fetchColumn(); $i < MAX_REQUESTS; $i++) {
        q("INSERT INTO requests (account, pass_hash, note) VALUES (?, '', 'filler')", ["Fill$i"]);
    }
    check(str_starts_with(request_account('Testo', 'correct horse', 'Sam'), 'Too many'), 'requests are capped');
    q("DELETE FROM requests WHERE note = 'filler'");
    check(login('Testn', 'correct horse') !== '', 'no login before approval');
    check(approve_request('Testn') === '' && login('testn', 'correct horse') === '' && hero('Testn')['setup'] === 'name'
        && !q("SELECT 1 FROM requests WHERE account = 'Testn'")->fetch(), 'approved, they log in and name a hero');
    check(approve_request('Nobody') !== '', 'approving needs a request');
    $me = hero('Testn');
    check(update_account($me, 'wrong password', 'Testn', '') === 'Wrong current password.'
        && hero('Testn')['failed_logins'] === 1, 'account changes need the current password, counted like a login');
    check(str_contains(update_account($me, 'correct horse', 'TESTA', ''), 'already an account'),
        'an account name in use is refused');
    check(update_account($me, 'correct horse', 'Testn', 'x') !== '' && login('Testn', 'correct horse') === '',
        'a bad new password changes nothing');
    check(update_account($me, 'correct horse', 'Testo', 'new horse!') === '' && session_user()['account'] === 'Testo'
        && login('Testo', 'new horse!') === '', 'a new name and password, and this session carries on');
    run_command(hero('Testo'), 'nimble');
    check(hero('Testo')['name'] === 'Nimble' && login('Nimble', 'new horse!') !== '',
        "players log in with the account name, not the hero's");

    // Character creation: choose a class, assign the Standard Array, a species and a background, then begin. Nothing
    // else runs until then.
    save_account('Testc', 'correct horse');
    $out = run_command(hero('Testc'), 'look');
    check(says($out, "What is your hero's name?") && actions(hero('Testc')) === [], 'a new hero is named first');
    check(count(cmd_who()) === 1 + q('SELECT COUNT(*) FROM users WHERE name IS NOT NULL')->fetchColumn(),
        'who leaves out heroes without a name');
    check(says(run_command(hero('Testc'), 'Te5t'), '3-16 letters'), 'a hero name of letters');
    check(says(run_command(hero('Testc'), 'testa'), 'already a hero called Testa'), 'hero names are unique');
    $out = run_command(hero('Testc'), 'testc');
    check(says($out, 'Welcome to Greenest, Testc!') && says($out, 'barbarian') && !says($out, "There's no"),
        'then the hero chooses a class');
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
    check(says(run_command(hero('Testc'), '8 15 14 12 13 10'), 'choose your species')
        && hero('Testc')['dexterity'] === 15, 'own scores in order, then the species');
    check(says(run_command(hero('Testc'), 'kobold'), "no 'kobold' species"), 'an unknown species is refused');
    $out = run_command(hero('Testc'), 'dragonborn');
    check(says($out, 'your dragon ancestor') && says($out, 'red       Fire breath')
        && hero('Testc')['species'] === null, 'a species with a choice asks for it');
    check(says(run_command(hero('Testc'), 'halfling'), 'You are a Halfling') && says(run_command(hero('Testc'), ''),
        'Savage Attacker'), 'a species, then the backgrounds');
    check(says(run_command(hero('Testc'), 'sage wis'), 'You were a Sage: Con +1, Wis +2'),
        'a background with one ability named: +2 there, +1 to the best other');
    run_command(hero('Testc'), 'back');
    run_command(hero('Testc'), 'rogue');
    run_command(hero('Testc'), '8 15 14 12 13 10');
    run_command(hero('Testc'), 'half');
    check(says(run_command(hero('Testc'), 'criminal int int'), 'two different'), 'a background ability once');
    $out = run_command(hero('Testc'), 'crim');
    check(says($out, 'You were a Criminal: Dex +2, Con +1, and Alert.') && says($out, 'Rogue skills'),
        'a background, then the class skills to choose');
    check(says(run_command(hero('Testc'), 'choose acrobatics, insight, perception, investigation'), 'Expertise'),
        'then a Rogue 1 picks Expertise');
    $out = run_command(hero('Testc'), 'choose stealth, perception');
    check(says($out, 'Testc, level 1 Halfling Rogue')
        && says($out, 'Str 8 (-1)  Dex 17 (+3)  Con 15 (+2)') && says($out, 'HP 10/10   AC 14')
        && says($out, 'Shortsword +5 to hit, 1d6+3 damage') && says($out, 'Species Halfling: Speed 30 ft, Brave')
        && says($out, 'Background Criminal: Alert; Sleight of Hand, Stealth') && says($out, 'Dagger')
        && says($out, "Type 'begin'"), 'by default +2 and +1 to the best two, then a look at the hero and kit');
    $out = run_command(hero('Testc'), 'begin');
    check(says($out, 'You set out') && says($out, 'Greenest Town Square') && hero('Testc')['setup'] === null,
        "'begin' enters the world");
    check(count(objects_here('user_id', (int) hero('Testc')['id'])) === 5 && hero('Testc')['gold'] === 8,
        "the Rogue's kit: Leather Armor, Shortsword, 2 Daggers, Shortbow and 8 gp");
    q("UPDATE users SET setup = 'class', level = 3, xp = 1000 WHERE account = 'Testc'");
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
    q("UPDATE events SET created = NOW() - INTERVAL 11 MINUTE WHERE text = 'meet at the inn'");
    check(!says(run_command(hero('Testb'), 'look'), 'meet at the inn'), 'speech fades from the room after 10 minutes');
    run_command(hero('Testb'), 'n');
    check(says(run_command(hero('Testa'), 'shout to the keep'), 'You shout: to the keep'), 'shout');
    $heard = run_command(hero('Testb'), 'who');
    check(says($heard, 'Testa shouts: to the keep'), 'a shout is heard all over town');
    check(!says(run_command(hero('Testb'), 's'), 'said here just now: to the keep'), 'and leaves no echo');
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
    // the loop above ran 'rest' and 'sneak'
    q("UPDATE users SET resting = NULL, rest_until = NULL, sneaking = FALSE WHERE name = 'Testa'");

    // help, Tab and the buttons hide exactly the features a hero's own handler refuses, for every class and level.
    $help = run_command(hero('Testa'), 'help');
    check(says($help, 'second') && !says($help, 'cast <spell>') && !says($help, 'rage'),
        "a Fighter's help has Second Wind, not spells or Rage");
    save_account('Gate', 'correct horse');
    run_command(hero('Gate'), 'Gate');
    check(actions(hero('Gate')) === array_keys(CLASSES), 'creation buttons: the classes');
    $wrong = [];
    foreach (array_keys(CLASSES) as $class) {
        q("UPDATE users SET setup = 'class' WHERE name = 'Gate'");
        create('Gate', $class);
        foreach ([1, 2, 3, 4, 5, 6] as $level) {
            q("UPDATE users SET level = ? WHERE name = 'Gate'", [$level]);
            $mine = array_map(fn ($usage) => explode(' ', $usage)[0], array_keys(my_commands(hero('Gate'))));
            foreach ([...array_keys(FEATURES), 'cast', 'prepare'] as $verb) {
                $out = run_command(hero('Gate'), $verb);
                $refused = (bool) preg_grep("/^(Only |You don't (know any|prepare))/", $out);
                if (in_array($verb, $mine, true) === $refused) {
                    $wrong[] = "$class $level $verb";
                }
            }
        }
    }
    check($wrong === [], 'my_commands() matches what the handlers allow: ' . implode(', ', $wrong));
    $spawn = q('SELECT s.id, s.room_id, m.name FROM spawns s JOIN monsters m ON m.id = s.monster_id
                ORDER BY s.id LIMIT 1')->fetch();
    q('UPDATE users SET room_id = ? WHERE name = ?', [$spawn['room_id'], 'Testa']);
    run_command(hero('Testa'), 'look'); // spawns it
    $foe = strtolower($spawn['name']);
    $c = choices(with_stats(hero('Testa')));
    check(in_array('kill ', actions(hero('Testa')), true) && $c['kill'] === [[$foe, "kill $foe"]]
        && in_array(['greatsword', 'drop greatsword'], $c['drop'], true) && !isset($c['cast']),
        'buttons: kill, then what is here to kill; drop what I carry; no cast for a Fighter');
    q('UPDATE users SET room_id = ? WHERE name = ?', [$spawn['room_id'], 'Gate']); // Gate ended as a Sorcerer 6
    $c = choices(with_stats(hero('Gate')));
    check(in_array(['magic missile', 'cast magic missile '], $c['cast'], true)
        && $c['cast magic missile'] === [[$foe, "cast magic missile $foe"]],
        "cast's buttons: my spells, then a monster for one that needs one");
    save_account('Healer', 'correct horse');
    create('Healer', 'cleric');
    q('UPDATE users SET room_id = ? WHERE name = ?', [$spawn['room_id'], 'Healer']);
    $c = choices(with_stats(hero('Healer')));
    check(in_array(['bless', 'cast bless'], $c['cast'], true)
        && in_array(['cure wounds', 'cast cure wounds '], $c['cast'], true)
        && $c['cast cure wounds'][0] === ['yourself', 'cast cure wounds']
        && in_array(['gate', 'cast cure wounds gate'], $c['cast cure wounds'], true),
        'a spell for the party needs no name; a healing one, myself or a hero here');
    q('UPDATE users SET fighting = ? WHERE name = ?', [$spawn['id'], 'Testa']);
    check(actions(hero('Testa')) === ['kill', 'shove', 'grapple', 'second', 'flee'],
        "buttons in a fight: a Fighter's moves, no targets needed, and drink only with a potion");
    q('INSERT INTO objects (item_id, user_id) VALUES (60, ?)', [hero('Testa')['id']]); // a Potion of Healing
    check(in_array('drink ', actions(hero('Testa')), true)
        && choices(with_stats(hero('Testa')))['drink'] === [['potion of healing', 'drink potion of healing']],
        'with a potion, drink, then the potions I carry');
    q('DELETE FROM objects WHERE item_id = 60 AND user_id = ?', [hero('Testa')['id']]);
    q("UPDATE users SET fighting = NULL, room_id = 1 WHERE name = 'Testa'");
    check(actions(hero('Testa'))
        === ['north', 'south', 'east', 'west', 'rest', 'sleep', 'inspect ', 'look', 'inventory'],
        'buttons in the Town Square: exits, rests since it is safe, and things to inspect');
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

    // Map: Town Square (safe) has Market Lane (stair down to the Sewers) west, the inn (safe) east, the keep north, the
    // Mill Bridge (stair down) south. at($map, dx, dy) reads the map from my @, a room 6 columns and 4 rows apart.
    $at = function (array $map, int $dx, int $dy): string {
        $map = array_slice($map, 0, -2); // not the footer
        foreach ($map as $r => $line) {
            if (($c = strpos($line, '@')) !== false) {
                return $c + $dx < 0 || $r + $dy < 0 ? ' ' : $map[$r + $dy][$c + $dx] ?? ' ';
            }
        }
        return '';
    };
    q("UPDATE users SET room_id = 1 WHERE name = 'Testa'");
    $map = cmd_map(hero('Testa'));
    $row = implode('', array_map(fn ($dx) => $at($map, $dx, 0), range(-8, 8)));
    check($row === '......::@::.:::::' && $at($map, -4, 1) === '>' && $at($map, 2, 5) === '>'
        && $at($map, -1, -2) . $at($map, 0, -2) . $at($map, 1, -2) === '#.#',
        'map: rooms, exits as gaps in walls, safe floors, stairs');
    check($at($map, 0, 8) === '.' && $at($map, 0, 4) === '.', 'map: the whole town, explored or not');
    check($map[count($map) - 2] === 'Greenest. Here: no monsters', 'map: the footer names the zone');
    // The Southern Fields lead west into the Wood and south into the Kobold Mines, other zones: gaps onto rock.
    q("UPDATE users SET room_id = 6 WHERE name = 'Testa'");
    $map = cmd_map(hero('Testa'));
    check($at($map, -3, 0) === '.' && $at($map, -6, 0) === ' ' && $at($map, 0, 2) === '.' && $at($map, 0, 4) === ' ',
        "map: an exit into another zone is a gap in the wall, the room beyond isn't drawn");
    // Fog of war in the Wood (6002 - 6001 - 6000, west to east): where I've been shows, its neighbours as outlines.
    q("UPDATE users SET room_id = 6000 WHERE name = 'Testa'");
    $map = cmd_map(hero('Testa'));
    check($at($map, -6, 0) === ' ' && $at($map, -6, -2) === '#' && $at($map, -12, -2) === ' ',
        "fog: a room I haven't been in is an outline beside one I have, and nothing farther");
    run_command(hero('Testa'), 'w');
    run_command(hero('Testa'), 'e');
    $map = cmd_map(hero('Testa'));
    check($at($map, -6, 0) === '.' && $at($map, -3, 0) === '.' && $at($map, -12, 0) === ' '
        && $at($map, -12, -2) === '#', 'fog: walking a room explores it, and outlines the next');
    q("UPDATE users SET room_id = 6000 WHERE name = 'Testa'");
    $map = cmd_map(hero('Testa'));
    check(str_starts_with($map[count($map) - 2], 'Wood of Sharp Teeth. Here: '),
        'map: the footer names the Wood');
    q("UPDATE users SET room_id = 1 WHERE name = 'Testa'");
    $foe = q('SELECT room_id FROM spawns ORDER BY id LIMIT 1')->fetchColumn();
    q('UPDATE users SET room_id = ? WHERE name = ?', [$foe, 'Testa']);
    $dice_queue = [6];
    $map = cmd_map(hero('Testa'));
    check(str_contains($map[count($map) - 2], '. Here: ') && !says($map, 'no monsters') && ctype_lower($at($map, -2, 0))
        && $dice_queue === [6], 'map: shows and names monsters in your room, rolls no dice');
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
        'weapon, 1d6, slow, thrown'), 'Kit A also has 8 Javelins; a password reset gives no second kit');
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
    check(loot(0, START_ROOM, '50') === 'Shield', "a monster's loot is its gear, whatever its CR");

    // Rests and Second Wind. Testa is in the town square, a safe room.
    q("UPDATE users SET hp = 3 WHERE name = 'Testa'");
    check(says(run_command(hero('Testa'), 'rest'), 'settle in for a short rest'), 'rest starts a short rest');
    check(says(run_command(hero('Testa'), 'sleep'), 'already taking a short rest (1 min to go)'),
        'one rest at a time');
    $sheet = hero_sheet(with_stats(hero('Testa')));
    check(in_array('short rest, 1 min left', $sheet['status'], true) && $sheet['rest_secs'] > 50,
        'the hero sheet shows the time left, for play.js to finish the rest');
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
    q("UPDATE users SET hp = 5 WHERE name = 'Testa'");
    check(says(heal_hint(hero('Testa')), "You're hurt (HP 5/11). Rest in a safe room")
        && says(heal_hint(hero('Testa')), '(1 left)'), 'hurt after a fight away from town: where to rest');
    q("UPDATE users SET hp = 6 WHERE name = 'Testa'");
    check(heal_hint(hero('Testa')) === [], 'no hint above half HP');
    q("UPDATE users SET hp = 11 WHERE name = 'Testa'");
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
    $dice_queue = [19, 4]; // stepping out of its reach: its opportunity attack hits for 4
    $out = run_command(hero('Testa'), 'flee');
    check(says($out, 'strikes as you pull away') && says($out, 'hits you (22 vs AC 17) for 4')
        && says($out, 'You flee south') && hero('Testa')['hp'] === 7 && hero('Testa')['fighting'] === null,
        'flee runs out an exit, and leaving its reach draws one attack');
    check(!says($out, "You're hurt"), 'no heal hint above half HP');

    q("UPDATE users SET hp = 1, gold = 100 WHERE name = 'Testa'");
    q('UPDATE spawns SET hp = 30 WHERE id = 9999'); // so the Greatsword's Graze doesn't finish it
    run_command(hero('Testa'), 'n');
    $dice_queue = [10, 1, 1, 1]; // Initiative, and both miss
    run_command(hero('Testa'), 'kill dummy');
    $dice_queue = [2, 15, 1]; // I miss (Graze 2); its Slam hits for 1
    $out = run_command(hero('Testa'), 'kill');
    $t = hero('Testa');
    check(says($out, 'You have died!') && $t['room_id'] === START_ROOM && $t['hp'] === 3 && $t['gold'] === 50
        && $t['fighting'] === null, 'death: back to town with 1/4 HP and half the gold');
    check($t['x'] === null, 'and in the middle of the board there');
    check(says($out, "You're hurt (HP 3/11). You can rest here."), 'and told to rest there');

    run_command(hero('Testa'), 'n');
    $dice_queue = [1, 20, 1, 1]; // initiative 3 vs 20: the dummy goes first, and both roll natural 1s
    $out = run_command(hero('Testa'), 'kill dummy');
    $slam = array_search(true, array_map(fn ($l) => str_contains($l, 'Slam misses'), $out), true);
    $swing = array_search(true, array_map(fn ($l) => str_contains($l, 'You miss'), $out), true);
    check(hero('Testa')['foe_first'] === 1 && $slam !== false && $swing !== false && $slam < $swing,
        'the initiative winner acts first each round');
    $dice_queue = [1]; // it goes first (Slam, a natural 1), having closed in on me where I came in, by the exit
    $out = run_command(hero('Testa'), 'flee');
    check(says($out, 'You flee south') && !says($out, 'pull away') && hero('Testa')['fighting'] === null
        && $dice_queue === [], 'flee from the exit square: straight out');

    run_command(hero('Testb'), 'n');
    $dice_queue = [10, 1, 1, 1];
    run_command(hero('Testb'), 'kill dummy');
    q("UPDATE users SET last_seen = NULL WHERE name = 'Testb'"); // Testb's page is closed: Testb's turns pass
    q('UPDATE spawns SET hp = 1 WHERE id = 9999');
    q("UPDATE users SET xp = 290 WHERE name = 'Testa'");
    $gold = hero('Testb')['gold'];
    // Testa joins (Initiative 10); Testb Dodges, and the dummy's Slam at Testb misses (1 and 1, with Disadvantage);
    // Testa hits; gold 1d6 = 4; loot 1d30 = 2 (none)
    $dice_queue = [10, 1, 1, 15, 1, 1, 4, 2];
    run_command(hero('Testa'), 'n');
    $out = run_command(hero('Testa'), 'kill dummy');
    $t = hero('Testa');
    check(says($out, 'The Training Dummy dies! You gain 12 XP and 2 gold.') && $t['xp'] === 302
        && $t['gold'] === 52 && hero('Testb')['gold'] === $gold + 2,
        "a kill pays the stat block's XP and gold by CR, split among the heroes in the fight");
    check(!says($out, 'You reach level') && $t['level'] === 1, 'XP alone does not level you');
    $spent = hero('Testa')['uses_spent'];
    q("UPDATE users SET hp = max_hp, resting = 'short', rest_until = NOW() WHERE name = 'Testa'");
    $out = run_command(hero('Testa'), '');
    q("UPDATE users SET uses_spent = ? WHERE name = 'Testa'", [$spent]); // the rest gave a Second Wind back
    $t = hero('Testa');
    check(says($out, 'You reach level 2! +7 max HP.') && $t['level'] === 2 && $t['max_hp'] === 18,
        'a rest brings the level: 6 + Con modifier HP');
    check(hero('Testb')['fighting'] === null, 'a kill ends the fight for everyone in it');
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

    // Action Surge (Fighter 2) and the Champion (3): Improved Critical and Remarkable Athlete. Testa, level 5: two
    // Greatsword attacks, +5 to hit, 2d6+2.
    check(says(run_command(hero('Testb'), 'surge dummy'), 'level 2 or more'), 'Action Surge needs Fighter 2');
    check(says($score, 'Action Surge 1/1'), 'score: Action Surge');
    q("UPDATE users SET hp = max_hp, room_id = 2 WHERE name = 'Testa'");
    q('UPDATE spawns SET hp = 100, dead_since = NULL WHERE id = 9999');
    // Initiative 3 and 10 with Advantage, +2, vs 5; four attacks hit for 3 + 4 + 2; Slam a natural 1
    $dice_queue = [3, 10, 5, 12, 3, 4, 12, 3, 4, 12, 3, 4, 12, 3, 4, 1];
    $out = run_command(hero('Testa'), 'surge dummy');
    check(says($out, 'Initiative: you 12, it 5') && $dice_queue === [],
        'Remarkable Athlete: Advantage on Initiative');
    check(says($out, 'Action Surge! You attack, and attack again.') && count(array_filter($out,
        fn ($l) => str_contains($l, 'You hit the Training Dummy (17 vs AC 10) for 9'))) === 4
        && says($out, 'Training Dummy: 64 HP'), 'Action Surge: the Attack action twice, four attacks at level 5');
    check(says(run_command(hero('Testa'), 'surge'), 'no Action Surge left')
        && says(run_command(hero('Testa'), 'score'), 'Action Surge 0/1'), 'once a short rest');
    $dice_queue = [19, 6, 6, 6, 6, 2, 1]; // a 19 crits for 4d6 + 2; a 2 misses (Graze 2); Slam a natural 1
    $out = run_command(hero('Testa'), 'kill');
    check(says($out, 'Critical hit! You hit the Training Dummy (24 vs AC 10) for 26 damage.') && $dice_queue === [],
        'Improved Critical: a 19 is a Critical Hit');
    q('UPDATE monsters SET ac = 30 WHERE id = 9999');
    // a 19 hits AC 30 and crits; then a natural 1 (no Graze on a natural 1? it grazes: the modifier only); then I back
    // off a whole Speed and half again, and the dummy gets no opportunity attack; its turn: it walks up, Slam a 1
    $dice_queue = [19, 1, 1, 1, 1, 2, 1];
    $out = run_command(hero('Testa'), 'kite');
    check(says($out, 'Critical hit! You hit the Training Dummy (24 vs AC 30) for 6 damage.'),
        'a Critical Hit hits whatever the AC');
    check(!says($out, 'pull away') && $dice_queue === [],
        'Remarkable Athlete: after a Critical Hit, backing off draws no opportunity attack');
    q('UPDATE monsters SET ac = 10 WHERE id = 9999');
    leave('Testa');
    q("UPDATE users SET resting = 'short', rest_until = NOW(), room_id = 1 WHERE name = 'Testa'");
    run_command(hero('Testa'), '');
    check(hero('Testa')['feature_spent'] === 0, 'a short rest gives back Action Surge');
    // Out of reach with 10 ft of my move left: the first action is a Dash, the second the attack.
    q("UPDATE users SET room_id = 2, x = 0, y = 5 WHERE name = 'Testa'");
    q('UPDATE spawns SET hp = 100 WHERE id = 9999');
    $dice_queue = [10, 10, 1, 1, 1]; // Initiative; my attacks are natural 1s (Graze 2 each); its Slam a 1
    run_command(hero('Testa'), 'kill dummy');
    q("UPDATE users SET x = 0, y = 5, moved = 20 WHERE name = 'Testa'");
    q('UPDATE spawns SET x = 7, y = 0 WHERE id = 9999');
    $dice_queue = [1, 1, 1];
    $out = run_command(hero('Testa'), 'surge dummy');
    check(says($out, 'You dash in on the Training Dummy.') && says($out, 'Action Surge! You attack.')
        && count(array_filter($out, fn ($l) => str_contains($l, 'You miss'))) === 2 && $dice_queue === [],
        'Action Surge out of reach: Dash, then the Attack action');
    leave('Testa');

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
    leave('Testc');
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
    q("UPDATE users SET conditions = 'reckless' WHERE name = 'Testc'");
    q('UPDATE spawns SET init = 20 WHERE id = ?', [hero('Testc')['fighting']]); // it acts first each round now
    $dice_queue = [2, 12, 3, 15, 6];
    $out = run_command(hero('Testc'), 'kill');
    check(says($out, 'hits you (15 vs AC 13, with Advantage)') && says($out, '(19 vs AC 10) for 8')
        && $dice_queue === [], 'a foe that acts first in the round still has Advantage');
    q("UPDATE users SET conditions = 'reckless' WHERE name = 'Testc'");
    q('UPDATE spawns SET init = 1 WHERE id = ?', [hero('Testc')['fighting']]);
    $dice_queue = [12, 3]; // its opportunity attack as I go: no Advantage, as Reckless ended when my turn began
    $out = run_command(hero('Testc'), 'flee');
    check(says($out, 'hits you (15 vs AC 13) for 3') && $dice_queue === [],
        'fleeing is my turn, so Reckless ends first');
    check(hero('Testc')['conditions'] === '' && hero('Testc')['fighting'] === null, 'conditions end with the fight');

    // Frenzy (Berserker 3): Reckless Attack while raging adds Rage Damage d6s to the first Str-based hit of the turn.
    $spent = hero('Testc')['uses_spent'];
    q("UPDATE users SET level = 3, room_id = 2, hp = max_hp WHERE name = 'Testc'");
    q('UPDATE spawns SET hp = 60, dead_since = NULL WHERE id = 9999');
    $dice_queue = [10, 1, 1, 1]; // initiative; a natural 1 (Graze); Slam a natural 1
    run_command(hero('Testc'), 'kill dummy');
    $dice_queue = [10, 15, 6, 1, 1]; // without Rage: 15 hits for 6 + 2, no Frenzy; Slam a natural 1 (with Advantage)
    check(says(run_command(hero('Testc'), 'reckless'), '(19 vs AC 10, with Advantage) for 8 damage.')
        && $dice_queue === [], 'no Frenzy without Rage');
    run_command(hero('Testc'), 'rage');
    $dice_queue = [10, 15, 6, 4, 3, 1, 1]; // 6 + 2 Str + 2 Rage + 4 + 3 Frenzy
    check(says(run_command(hero('Testc'), 'reckless'), '(19 vs AC 10, with Advantage) for 17 damage, with Frenzy.')
        && $dice_queue === [], 'Frenzy: Reckless Attack while raging adds 2d6');
    leave('Testc');
    q("UPDATE users SET level = 2, uses_spent = ? WHERE name = 'Testc'", [$spent]); // the Rage back

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
    leave('Testc');

    save_account('Testd', 'correct horse');
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
    // Thief (Rogue 3), and Uncanny Dodge (5): my Reaction halves the first hit of each monster turn.
    q("UPDATE users SET level = 5, room_id = 2, hp = max_hp WHERE name = 'Testd'");
    check(says(run_command(hero('Testd'), 'score'), 'Sneak Attack 3d6   Thief: Fast Hands, Second-Story Work'),
        'Thief: its features are shown');
    q('UPDATE spawns SET hp = 30, dead_since = NULL WHERE id = 9999');
    $dice_queue = [10, 1, 1, 1, 15, 3]; // initiative; Steady Aim's two natural 1s; Slam hits for 3, I take 1
    $out = run_command(hero('Testd'), 'kill dummy');
    check(says($out, 'hits you (18 vs AC 13) for 1 damage, 2 dodged (Uncanny Dodge).') && $dice_queue === [],
        'Uncanny Dodge halves a hit, rounding down');
    $dice_queue = [1];
    run_command(hero('Testd'), 'flee');
    $dice_queue = [];

    // Ranged weapons: from 25 ft away no Disadvantage, then Disadvantage with the foe next to you.
    q('UPDATE spawns SET hp = 30, dead_since = NULL, x = 3, y = 0, placed_at = NOW() WHERE id = 9999');
    q("UPDATE users SET room_id = 2, x = 4, y = 5, level = 1, advantage = FALSE WHERE name = 'Testd'");
    run_command(hero('Testd'), 'equip shortbow');
    $score = run_command(hero('Testd'), 'score');
    check(says($score, 'Shortbow +4 to hit, 1d6+2 damage'), 'a ranged weapon uses Dex');
    check(says($score, 'Saves  Str +1  Dex +4  Con +1  Int +4  Wis +0  Cha -1'),
        'a Rogue is proficient in Dex and Int');
    $dice_queue = [10, 1, 12, 3, 1]; // initiative 12 vs 1, one shot: 16 vs AC 10 for 3 + 2; it closes in, Slam a 1
    $out = run_command(hero('Testd'), 'kill dummy');
    check(says($out, '(16 vs AC 10) for 5 damage.') && says($out, 'The Training Dummy closes in.')
        && $dice_queue === [], 'a shot from 25 ft has no Disadvantage; the dummy closes in to answer');
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
    q("UPDATE users SET room_id = 2, fighting = NULL, x = 4, y = 5 WHERE name = 'Testa'");
    q('UPDATE spawns SET x = 3, y = 0, placed_at = NOW() WHERE id = 9999');
    $dice_queue = [10, 1, 1, 1, 1]; // initiative, a thrown Javelin (Extra Attack: two) misses twice; Slam a 1
    $out = run_command(hero('Testa'), 'shoot dummy');
    check(says($out, 'You miss the Training Dummy (natural 1)') && !says($out, 'You close in')
        && says($out, 'The Training Dummy closes in.') && $dice_queue === [], 'shoot throws a thrown weapon from afar');
    leave('Testa');
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
    leave('Testd');
    check(says(run_command(hero('Testc'), 'equip greataxe offhand'), 'Only a Light weapon')
        && says(run_command(hero('Testc'), 'equip handaxe offhand'), 'Light weapon in your main hand first'),
        'the off hand needs Light weapons in both hands');
    run_command(hero('Testc'), 'equip handaxe');
    run_command(hero('Testc'), 'equip handaxe offhand');
    q("UPDATE users SET x = 4, y = 5 WHERE name = 'Testc'");
    q('UPDATE spawns SET x = 3, y = 2, placed_at = NOW() WHERE id = 9999');
    // Handaxes thrown from 15 ft: one hits (Vex), the off-hand one too; the dummy closes in, Slam a 1
    $dice_queue = [10, 1, 15, 3, 2, 14, 3, 1];
    $out = run_command(hero('Testc'), 'shoot dummy');
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
    leave('Testc');
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
    $dice_queue = [10, 1, 1, 5, 3, 15, 3, 4, 12]; // Str save 5 fails; one attack with Advantage; it gets up, misses
    $out = run_command(hero('Testa'), 'shove dummy');
    check(says($out, 'You shove the Training Dummy to the ground, Prone (Str save 5 vs DC 13).')
        && says($out, '(20 vs AC 10, with Advantage) for 9 damage') && says($out, 'gets back up')
        && says($out, 'misses you (15 vs AC 17).') && $dice_queue === [],
        'Shove: Prone, so my next attack has Advantage');
    $dice_queue = [4, 1, 12];
    $out = run_command(hero('Testa'), 'grapple');
    check(says($out, 'You grab hold of the Training Dummy, Grappled (Str save 4 vs DC 13).') && $dice_queue === [],
        'Grapple: no Disadvantage against the one holding it');
    $dice_queue = [10, 1, 15, 2]; // I join; a natural 1; its Slam (15 and 2, with Disadvantage) at me
    turn('Testb');
    check(says(run_command(hero('Testb'), 'kill dummy'), 'misses you (5 vs AC 17, with Disadvantage)')
        && $dice_queue === [], 'held, it has Disadvantage against other heroes');
    leave('Testa');
    $dice_queue = [1, 15, 2];
    check(says(run_command(hero('Testb'), 'kill'), 'hits you (18 vs AC 17) for 2') && $dice_queue === [],
        'the hold ends when the grappler leaves the fight');
    leave('Testb');
    q('INSERT INTO objects (item_id, user_id) VALUES (19, ?)', [hero('Testa')['id']]);
    run_command(hero('Testa'), 'equip maul');
    q("UPDATE users SET room_id = 2 WHERE name = 'Testa'");
    $dice_queue = [10, 1, 1, 15, 3, 4, 5, 2, 14, 1, 1, 1]; // a hit, Con save 5 fails; then Advantage
    $out = run_command(hero('Testa'), 'kill dummy');
    check(says($out, 'topples over, Prone (Con save 5 vs DC 13).') && says($out, '(19 vs AC 10, with Advantage)')
        && $dice_queue === [] && str_contains(line(run_command(hero('Testa'), 'i'), 'Maul'), 'two-handed, topple'),
        'Topple: a hit can knock the foe Prone');
    leave('Testa');
    q('INSERT INTO objects (item_id, user_id) VALUES (7, ?)', [hero('Testa')['id']]);
    run_command(hero('Testa'), 'equip mace');
    q("UPDATE users SET room_id = 2 WHERE name = 'Testa'");
    $dice_queue = [10, 1, 1, 15, 3, 1, 2, 12]; // a Mace hit saps it, so its Slam rolls twice and takes the 2
    check(says(run_command(hero('Testa'), 'kill dummy'), 'misses you (5 vs AC 17, with Disadvantage)')
        && $dice_queue === [], 'Sap: a hit gives Disadvantage on its next attack');
    $dice_queue = [1, 1, 12];
    check(says(run_command(hero('Testa'), 'kill'), 'misses you (15 vs AC 17).') && $dice_queue === [],
        'and it wears off by the start of my next turn');
    leave('Testa');
    q('INSERT INTO objects (item_id, user_id) VALUES (14, ?)', [hero('Testa')['id']]);
    run_command(hero('Testa'), 'equip greataxe');
    $dummy = q('SELECT hp, dead_since FROM spawns WHERE id = 9999')->fetch();
    q('UPDATE spawns SET hp = 3, dead_since = NULL WHERE id = 9999');
    q('INSERT INTO spawns (id, room_id, monster_id, hp) VALUES (9998, 2, 9999, 10)'); // first in the room: the foe
    q("UPDATE users SET room_id = 2, xp = 6500 WHERE name = 'Testa'");
    // A hit for 5; Cleave kills the other dummy (gold 4, no loot); the second attack kills the first.
    $dice_queue = [10, 1, 1, 15, 3, 14, 3, 4, 2, 15, 6, 4, 2];
    $out = run_command(hero('Testa'), 'kill dummy');
    check(says($out, 'You cleave into the Training Dummy (19 vs AC 10) for 3 damage.')
        && count(array_filter($out, fn ($l) => str_contains($l, 'dies!'))) === 2 && hero('Testa')['xp'] === 6550
        && $dice_queue === [], 'Cleave: a hit carries on to a second monster here, and both kills count');
    q('DELETE FROM spawns WHERE id = 9998');
    q('UPDATE spawns SET hp = ?, dead_since = ? WHERE id = 9999', [$dummy['hp'], $dummy['dead_since']]);
    run_command(hero('Testa'), 'equip greatsword');
    run_command(hero('Testa'), 'equip greatsword');

    save_account('Teste', 'correct horse');
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
    $dice_queue = [10, 1, 1, 5, 3, 3, 3, 1]; // 5 + 4 misses AC 10, but Bless adds 3
    check(says(run_command(hero('Testb'), 'kill dummy'), '(12 vs AC 10, +3 Bless) for 8 damage.') && $dice_queue === [],
        'Bless adds 1d4 to attack rolls');
    check(says(run_command(hero('Teste'), 'cast hold dummy'), 'works only on a Humanoid, and the Training Dummy is an '
        . 'Aberration.'), "Hold Person refuses a foe that isn't a Humanoid");
    q("UPDATE monsters SET type = 'humanoid' WHERE id = 9999");
    $dice_queue = [10, 5, 3]; // I join; its Wis save 5 fails; it can't act, and fails again at the end of its turn
    turn('Teste');
    $out = run_command(hero('Teste'), 'cast hold dummy');
    check(says($out, 'fails its Wis save (5 vs DC 12) against your Hold Person and is Paralyzed.')
        && says($out, "is Paralyzed and can't act") && says($out, 'still held by your Hold Person')
        && says(run_command(hero('Teste'), 'score'), 'concentrating on Hold Person') && $dice_queue === [],
        'Hold Person: Paralyzed until it makes the save again');
    $dice_queue = [4, 3];
    turn('Teste');
    check(says(run_command(hero('Teste'), 'cast sacred'), 'fails its Dex save (automatic fail, DC 12) against your '
        . 'Sacred Flame and takes 4 damage.') && $dice_queue === [], 'Paralyzed fails Dex saves');
    $dice_queue = [2, 12, 1, 1, 1, 1]; // Advantage; a melee hit is a crit (4d6)
    turn('Testb');
    $out = run_command(hero('Testb'), 'kill');
    check(says($out, 'Critical hit! You hit the Training Dummy (16 vs AC 10, with Advantage) for 6 damage.')
        && $dice_queue === [], 'attacks on the Paralyzed have Advantage and crit; the new Concentration ended Bless');
    $dice_queue = [1, 1, 15]; // my Mace misses (with Advantage); on its turn its save at 15 frees it
    check(says(run_command(hero('Teste'), 'kill'), 'shakes off your Hold Person (Wis save 15 vs DC 12)')
        && $dice_queue === [], 'Hold Person ends when it makes the save');
    leave('Testb');
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
    leave('Teste');
    check(!says(run_command(hero('Teste'), 'score'), 'Shield of Faith'), 'then only its own time');
    q("UPDATE users SET room_id = 2 WHERE name = 'Teste'");
    $dice_queue = [10, 1, 1, 1];
    run_command(hero('Teste'), 'kill dummy');
    check(!says(run_command(hero('Teste'), 'score'), 'Shield of Faith') && $dice_queue === [],
        "and doesn't come back in a new fight against the same monster");
    leave('Teste');
    q("UPDATE monsters SET type = 'aberration' WHERE id = 9999");
    leave('Testb');

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
    leave('Teste');
    check($dice_queue === [], 'every queued die was used by the classes');

    // Monk (Testf: Str 12, Dex 15, Con 13, Wis 14; Focus save DC 8 + Wis + PB). The dummy: AC 10, saves +0.
    q('DELETE FROM effects');
    q('UPDATE spawns SET hp = 200, dead_since = NULL WHERE id = 9999');
    q('UPDATE users SET fighting = NULL WHERE fighting = 9999');
    save_account('Testf', 'correct horse');
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
    q("UPDATE users SET x = 4, y = 5 WHERE name = 'Testf'");
    q('UPDATE spawns SET x = 3, y = 2, placed_at = NOW() WHERE id = 9999');
    $dice_queue = [10, 1, 12, 5, 1]; // the Spear thrown from 15 ft: no Unarmed Strike from afar; Slam a 1
    $out = run_command(hero('Testf'), 'shoot dummy');
    check(says($out, '(16 vs AC 10) for 7 damage.') && says($out, 'The Training Dummy closes in.')
        && !says($out, 'Unarmed') && $dice_queue === [], 'a thrown Spear, from afar');
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
    q("UPDATE users SET room_id = 2, hp = 3, uses_spent = 2, x = 4, y = 5 WHERE name = 'Testf'");
    q('UPDATE spawns SET x = 3, y = 2, placed_at = NOW() WHERE id = 9999');
    check(says(run_command(hero('Testf'), 'patient dummy'), 'no Focus Points left'), 'techniques cost Focus');
    $dice_queue = [10, 1, 4, 1, 1]; // Uncanny Metabolism heals 4 + 2; the thrown Spear misses; it closes, Slam a 1
    $out = run_command(hero('Testf'), 'shoot dummy');
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
    save_account('Testg', 'correct horse');
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
    leave('Testg');
    q("UPDATE users SET uses_spent = 5, resting = 'short', rest_until = NOW() WHERE name = 'Testg'");
    run_command(hero('Testg'), 'score');
    check(hero('Testg')['uses_spent'] === 5, "a short rest doesn't refill Lay On Hands");
    q("UPDATE users SET resting = 'long', rest_until = NOW() WHERE name = 'Testg'");
    run_command(hero('Testg'), 'score');
    check(hero('Testg')['uses_spent'] === 0 && hero('Testg')['once_used'] === 0,
        'a long rest refills it and the free Divine Smite');

    // Channel Divinity (Paladin 3): Divine Sense, and Sacred Weapon (Oath of Devotion). Testg: Str +2, Cha +2, PB +2.
    q("UPDATE users SET level = 3, hp = max_hp, room_id = 1 WHERE name = 'Testg'");
    q('UPDATE spawns SET hp = 200, dead_since = NULL WHERE id = 9999');
    check(says(run_command(hero('Testg'), 'score'), 'Channel Divinity 2/2'), 'Paladin 3: two Channel Divinities');
    $type = q('SELECT type FROM monsters WHERE id = 9999')->fetchColumn();
    q("UPDATE monsters SET type = 'undead' WHERE id = 9999");
    check(says(run_command(hero('Testg'), 'sense'), 'a Training Dummy (Undead) to the north')
        && says(run_command(hero('Testg'), 'score'), 'Channel Divinity 1/2'),
        'Divine Sense: the Undead next door, for a Channel Divinity');
    q('UPDATE monsters SET type = ? WHERE id = 9999', [$type]);
    q("UPDATE users SET room_id = 5, feature_spent = 0 WHERE name = 'Testg'");
    check(says(run_command(hero('Testg'), 'sense'), "Chauntea's temple to the east is consecrated ground."),
        'Divine Sense: consecrated ground');
    q("UPDATE users SET room_id = 2, feature_spent = 2 WHERE name = 'Testg'");
    check(says(run_command(hero('Testg'), 'sacred dummy'), 'no Channel Divinity left'), 'two a rest');
    q("UPDATE users SET feature_spent = 1 WHERE name = 'Testg'");
    q("UPDATE monsters SET resistances = 'slashing' WHERE id = 9999");
    $dice_queue = [10, 1, 12, 5, 1, 1]; // initiative; 12 + 4 + 2 Cha hits for 5 + 2 Radiant; Sap: Slam 1 and 1
    $out = run_command(hero('Testg'), 'sacred dummy');
    check(says($out, 'Your Longsword blazes with holy light: +2 to hit')
        && says($out, '(18 vs AC 10) for 7 damage (radiant).') && $dice_queue === [],
        'Sacred Weapon: +Cha to hit, and Radiant when the foe resists the weapon');
    check(says($score = run_command(hero('Testg'), 'score'), 'Longsword +6 to hit') && says($score, 'Sacred Weapon')
        && says($score, 'Channel Divinity 0/2'), 'it lasts: score shows it');
    q("UPDATE monsters SET resistances = '' WHERE id = 9999");
    leave('Testg');
    q("UPDATE users SET resting = 'short', rest_until = NOW() WHERE name = 'Testg'");
    run_command(hero('Testg'), 'score');
    check(hero('Testg')['feature_spent'] === 1, 'a short rest gives back one Channel Divinity');
    q('DELETE FROM effects');

    // Ranger (Testh: Dex 15, Wis 14: spell save DC 12). The dummy: AC 10, Str save +0.
    q('DELETE FROM effects');
    q('UPDATE spawns SET hp = 200, max_hp = 200, dead_since = NULL WHERE id = 9999');
    q('UPDATE users SET fighting = NULL WHERE fighting = 9999');
    save_account('Testh', 'correct horse');
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
    leave('Testh');
    run_command(hero('Testh'), 'equip longbow');
    check(says(run_command(hero('Testh'), 'score'), 'Longbow +6 to hit, 1d8+2 damage'),
        'Archery: +2 to ranged attacks');
    q("UPDATE users SET level = 7, room_id = 2 WHERE name = 'Testh'");
    q('UPDATE monsters SET attacks = 2 WHERE id = 9999');
    q('DELETE FROM effects');
    q("UPDATE users SET x = 4, y = 5 WHERE name = 'Testh'");
    q('UPDATE spawns SET x = 3, y = 0, placed_at = NOW() WHERE id = 9999');
    $dice_queue = [10, 1, 1, 1, 1, 1]; // two arrows from 25 ft miss; it closes in, and both Slams roll 1s
    run_command(hero('Testh'), 'kill dummy');
    $dice_queue = [1, 1, 1, 1, 15, 2, 15, 1]; // two misses; its first Slam hits, so the second rolls twice
    $out = run_command(hero('Testh'), 'kill');
    check(says($out, 'hits you (18 vs AC 14) for 2 damage.') && says($out, 'misses you (natural 1, with Disadvantage)')
        && $dice_queue === [], 'Multiattack Defense: after a hit, its other attacks this turn have Disadvantage');
    q('UPDATE monsters SET attacks = 1 WHERE id = 9999');
    check(says(run_command(hero('Testh'), 'score'), 'Favored Enemy 2/3'), 'level 5 and up: 3 free Hunter\'s Marks');
    leave('Testh');

    // Wizard (Testi: Int 15: spell save DC 12, spell attack +4). The dummy: AC 10, saves +0.
    q('DELETE FROM effects');
    q('UPDATE spawns SET hp = 200, max_hp = 200, dead_since = NULL WHERE id = 9999');
    q('UPDATE users SET fighting = NULL WHERE fighting = 9999');
    save_account('Testi', 'correct horse');
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
    leave('Testi');
    q("UPDATE users SET slots_spent = '1,1,1', resting = 'short', rest_until = NOW() WHERE name = 'Testi'");
    check(says(run_command(hero('Testi'), 'score'), 'with Arcane Recovery bringing back slots of level 3.')
        && hero('Testi')['slots_spent'] === '1,1,0', 'Arcane Recovery: slots worth half your level, highest first');
    q("UPDATE users SET resting = 'short', rest_until = NOW() WHERE name = 'Testi'");
    check(!says(run_command(hero('Testi'), 'score'), 'Arcane Recovery'), 'once a long rest');

    // Sorcerer (Testj: Cha 15: spell save DC 12, spell attack +4). The dummy: AC 10, saves +0.
    q('DELETE FROM effects');
    q('UPDATE spawns SET hp = 200, max_hp = 200, dead_since = NULL WHERE id = 9999');
    q('UPDATE users SET fighting = NULL WHERE fighting = 9999');
    save_account('Testj', 'correct horse');
    create('Testj', 'sorcerer');
    q("UPDATE users SET room_id = 2, x = 4, y = 5 WHERE name = 'Testj'");
    q('UPDATE spawns SET x = 3, y = 0, placed_at = NOW() WHERE id = 9999');
    $score = run_command(hero('Testj'), 'score');
    check(says($score, 'level 1 Sorcerer') && says($score, 'HP 8/8   AC 11') && says($score, 'Spear +2 to hit')
        && says($score, 'Hit Dice 1/1 d6   Innate Sorcery 2/2   Spell slots 2/2')
        && says($score, 'Saves  Str +0  Dex +1  Con +4  Int -1  Wis +1  Cha +4')
        && count(objects_here('user_id', (int) hero('Testj')['id'])) === 3 && hero('Testj')['gold'] === 28,
        'Sorcerer: d6, a Spear and 2 Daggers, Con and Cha saves');
    check(says(run_command(hero('Testj'), 'innate'), 'needs a fight'), 'Innate Sorcery needs a fight');
    $dice_queue = [10, 1, 12, 5, 1]; // the Fire Bolt hits from 25 ft; the dummy closes in, Slam a 1
    check(says(run_command(hero('Testj'), 'cast fire bolt dummy'), 'The Training Dummy closes in.')
        && $dice_queue === [], 'a Fire Bolt from 25 ft away');
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
    leave('Testj');
    q("UPDATE users SET uses_spent = 5, resting = 'short', rest_until = NOW() WHERE name = 'Testj'");
    check(says(run_command(hero('Testj'), 'score'), 'Sorcerous Restoration bringing back 3 Sorcery Points')
        && hero('Testj')['uses_spent'] === 2, 'Sorcerous Restoration: half your level in points on a short rest');

    // Bard (Testk: Cha 15: spell save DC 12; Dagger +4). The dummy: AC 10, saves +0, Slam +3.
    q('DELETE FROM effects');
    q('UPDATE spawns SET hp = 200, max_hp = 200, dead_since = NULL WHERE id = 9999');
    q('UPDATE users SET fighting = NULL WHERE fighting = 9999');
    save_account('Testk', 'correct horse');
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
    q("UPDATE users SET x = 4, y = 5 WHERE name = 'Testj'");
    q('UPDATE spawns SET x = 3, y = 0, placed_at = NOW() WHERE id = 9999');
    $dice_queue = [10, 1, 4, 3, 1, 1, 1]; // from 25 ft: 9 vs AC 10, so the die is rolled; the dummy closes, Slam a 1
    check(says(run_command(hero('Testj'), 'cast fire dummy'), 'hits the Training Dummy (12 vs AC 10, +3 Bardic '
        . 'Inspiration) for 4 damage.') && $dice_queue === [] && !effects_on('user_id', (int) hero('Testj')['id']),
        'an inspired hero adds the die to a missed attack roll, and it is spent');
    leave('Testj');
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
    leave('Testk');
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
    q("UPDATE users SET level = 14, room_id = 2, x = 4, y = 5 WHERE name = 'Testk'");
    q('UPDATE spawns SET x = 3, y = 2, placed_at = NOW() WHERE id = 9999');
    $dice_queue = [10, 1, 2, 4, 1, 1]; // a Dagger thrown from 15 ft; the dummy closes in, Slam a 1
    check(says(run_command(hero('Testk'), 'shoot dummy'), 'You hit the Training Dummy (13 vs AC 10, +4 Peerless Skill)')
        && $dice_queue === [] && hero('Testk')['uses_spent'] === 1, 'Peerless Skill: your own die on a missed attack');
    leave('Testk');

    // Druid (Testl: Wis 15: spell save DC 12; Sickle +1). The dummy: AC 10, saves +0, Slam +3.
    q('DELETE FROM effects');
    q('UPDATE spawns SET hp = 200, max_hp = 200, dead_since = NULL WHERE id = 9999');
    q('UPDATE users SET fighting = NULL WHERE fighting = 9999');
    save_account('Testl', 'correct horse');
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
    leave('Testl');
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
    leave('Testl');
    run_command(hero('Testl'), 'wild dire');
    q("UPDATE users SET resting = 'long', rest_until = NOW() WHERE name = 'Testl'");
    run_command(hero('Testl'), 'score');
    check(hero('Testl')['shape'] === null && hero('Testl')['temp_hp'] === 0, 'a long rest ends Wild Shape');
    run_command(hero('Testl'), 'wild dire');
    run_command(hero('Testl'), 'cast moonbeam');
    q("UPDATE users SET setup = 'class' WHERE name = 'Testl'"); // what bin/reclass.php does
    create('Testl', 'fighter');
    $h = hero('Testl');
    check($h['class'] === 'fighter' && $h['level'] === 1 && $h['shape'] === null && $h['temp_hp'] === 0
        && !q('SELECT 1 FROM effects WHERE caster_id = ?', [$h['id']])->fetch(),
        'a hero sent back to creation starts over: no Wild Shape, Temporary HP or spells left on');

    // Warlock (Testm: Cha 15: spell attack +4; Sickle +1). The dummy: AC 10, saves +0, Slam +3.
    q('DELETE FROM effects');
    q('UPDATE spawns SET hp = 200, max_hp = 200, dead_since = NULL WHERE id = 9999');
    q('UPDATE users SET fighting = NULL WHERE fighting = 9999');
    save_account('Testm', 'correct horse');
    create('Testm', 'warlock');
    q("UPDATE users SET room_id = 2 WHERE name = 'Testm'");
    $score = run_command(hero('Testm'), 'score');
    check(says($score, 'level 1 Warlock') && says($score, 'HP 9/9   AC 13') && says($score, 'Sickle +1 to hit, 1d4-1')
        && says($score, 'Hit Dice 1/1 d8   Pact slots 1/1 (level 1)')
        && says($score, 'Saves  Str -1  Dex +2  Con +1  Int +1  Wis +2  Cha +4')
        && count(objects_here('user_id', (int) hero('Testm')['id'])) === 4 && hero('Testm')['gold'] === 15,
        'Warlock: d8, Leather Armor, a Sickle and 2 Daggers, Wis and Cha saves');
    $out = run_command(hero('Testm'), 'cast');
    check(says($out, 'eldritch blast <monster>') && says($out, 'hex  ') && says($out, 'mage armor [hero]'),
        'Warlock cantrips and spells');
    run_command(hero('Testm'), 'drop leather'); // Mage Armor needs no armor on
    check(says(run_command(hero('Testm'), 'cast mage'), 'You cast Mage Armor (free) on you.')
        && says(run_command(hero('Testm'), 'score'), 'AC 15') && hero('Testm')['slots_spent'] === '',
        'Armor of Shadows: Mage Armor on yourself without a slot');
    check(says(run_command(hero('Testm'), 'cast hex'), 'You cast Hex on you. Slots 0/1.'), 'Hex, with a Pact slot');
    q("UPDATE users SET room_id = 2, x = 4, y = 5 WHERE name = 'Testm'");
    q('UPDATE spawns SET x = 3, y = 0, placed_at = NOW() WHERE id = 9999');
    $dice_queue = [10, 1, 10, 5, 3, 1]; // from 25 ft; the dummy closes in, Slam a 1
    check(says(run_command(hero('Testm'), 'cast eldritch dummy'), 'Your Eldritch Blast hits the Training Dummy (14 vs '
        . 'AC 10) for 8 damage (+3 Hex).') && $dice_queue === [],
        'Eldritch Blast: 1d10 Force; Hex adds to spell attacks');
    q("UPDATE users SET level = 2 WHERE name = 'Testm'");
    $dice_queue = [12, 15, 5, 3, 1];
    check(says(run_command(hero('Testm'), 'cast eldritch'), '(16 vs AC 10, with Disadvantage) for 10 damage (+3 Hex).')
        && $dice_queue === [], 'Agonizing Blast: + Cha to Eldritch Blast damage');
    check(says(run_command(hero('Testm'), 'cunning'), "can't perform it in a fight"), 'no Magical Cunning in a fight');
    leave('Testm');
    q("UPDATE users SET slots_spent = '2' WHERE name = 'Testm'");
    check(says(run_command(hero('Testm'), 'cunning'), '1 Pact Magic slot returns. Slots 1/2.')
        && says(run_command(hero('Testm'), 'cunning'), 'since your last long rest'),
        'Magical Cunning: half the Pact Magic slots, once a long rest');
    q("UPDATE users SET resting = 'short', rest_until = NOW() WHERE name = 'Testm'");
    check(says(run_command(hero('Testm'), 'score'), 'with your Pact Magic slots back')
        && hero('Testm')['slots_spent'] === '', 'Pact Magic: every slot back on a short rest');
    q("UPDATE users SET level = 3, room_id = 2, x = 4, y = 5 WHERE name = 'Testm'");
    q('UPDATE spawns SET hp = 6, x = 3, y = 0, placed_at = NOW() WHERE id = 9999');
    $dice_queue = [10, 1, 10, 1, 3, 3, 2];
    $out = run_command(hero('Testm'), 'cast eldritch dummy');
    check(says($out, "Dark One's Blessing: you have 5 temporary HP.") && hero('Testm')['temp_hp'] === 5
        && $dice_queue === [], "Dark One's Blessing: Cha + level in Temporary Hit Points for a kill");
    q('UPDATE spawns SET hp = 200, max_hp = 200, dead_since = NULL WHERE id = 9999');
    $out = run_command(hero('Testm'), 'cast');
    check(says($out, 'scorching ray') && says($out, 'command <monster>') && says(run_command(hero('Testm'), 'score'),
        'Pact slots 2/2 (level 2)'), 'Fiend Spells always prepared; level 2 Pact slots at level 3');
    q("UPDATE users SET level = 5 WHERE name = 'Testm'");
    $dice_queue = [10, 1, 10, 5, 3, 1, 1]; // two beams from 25 ft; the dummy closes in, Slam a 1
    $out = run_command(hero('Testm'), 'cast eldritch dummy');
    check(count(array_filter($out, fn ($l) => str_contains($l, 'Your Eldritch Blast hits'))) === 1
        && says($out, 'Your Eldritch Blast misses') && $dice_queue === [], 'Eldritch Blast: two beams at level 5');
    $dice_queue = [1, 1, 1, 1, 15, 2, 3, 12];
    check(says(run_command(hero('Testm'), 'cast eldritch'), 'You keep your Concentration on Hex (Con save 13 vs DC 10)')
        && $dice_queue === [], 'Eldritch Mind: Advantage on Concentration saves');
    q("UPDATE users SET level = 6 WHERE name = 'Testm'");
    $dice_queue = [1, 1, 1, 1, 15, 2, 2, 3, 7];
    check(says(run_command(hero('Testm'), 'cast eldritch'), 'You keep your Concentration on Hex (Con save 11 vs DC 10)')
        && $dice_queue === [] && hero('Testm')['uses_spent'] === 1, "Dark One's Own Luck: 1d10 on a failed save");
    leave('Testm');

    // Species and backgrounds, against the dummy in room 2 (AC 10, saves +0, Slam +3 for 1d4 Bludgeoning).
    q('UPDATE spawns SET hp = 200, max_hp = 200, dead_since = NULL, conditions = \'\' WHERE id = 9999');
    $hero = function (string $name, string $class, string $species, string $bg): array {
        q('UPDATE users SET fighting = NULL WHERE fighting = 9999');
        save_account($name, 'correct horse');
        create($name, $class, 'ok', $species, $bg);
        q('UPDATE users SET room_id = 2 WHERE name = ?', [$name]);
        return hero($name);
    };
    save_account('Pick', 'correct horse');
    run_command(hero('Pick'), 'Pick');
    run_command(hero('Pick'), 'fighter');
    run_command(hero('Pick'), 'ok');
    check(in_array('dragonborn ', actions(hero('Pick')), true) && in_array('dwarf', actions(hero('Pick')), true)
        && choices(hero('Pick'))['dragonborn'][0] === ['black', 'dragonborn black'],
        "species buttons: one with a choice goes in the prompt, then its kinds");
    run_command(hero('Pick'), 'human sav');
    check(says(run_command(hero('Pick'), 'soldier'), 'already have Savage Attacker as a Human'),
        "a Human's Versatile feat can't be the background's too");

    $hero('Drag', 'fighter', 'dragonborn red', 'soldier'); // Str 17, Dex 15, Con 13: breath DC 11
    $score = run_command(hero('Drag'), 'score');
    check(says($score, 'level 1 Dragonborn Fighter') && says($score, 'Breath Weapon 2/2 1d10 Fire')
        && says($score, 'Species Red Dragonborn: Speed 30 ft, Darkvision 60 ft, Fire Resistance')
        && str_contains(with_stats(hero('Drag'))['resistances'], 'fire'), 'Dragonborn: Breath Weapon and Resistance');
    $dice_queue = [10, 5, 5, 7, 1]; // initiative; its Dex save 5; 7 Fire; then no attack left; Slam a natural 1
    $out = run_command(hero('Drag'), 'breathe dummy');
    check(says($out, 'You breathe fire at the Training Dummy (Dex save 5 vs DC 11) for 7 damage.')
        && !says($out, 'You hit') && hero('Drag')['species_spent'] === 1 && $dice_queue === [],
        'breathe: in place of an attack, a Dex save or 1d10');
    $dice_queue = [15, 1, 1, 6, 6, 1]; // a hit; 2d6 rolls 2, rerolled 12 for 12 + 3; Slam a natural 1
    check(says(run_command(hero('Drag'), 'kill'), '(20 vs AC 10) for 15 damage') && $dice_queue === [],
        'Savage Attacker: a weapon hit rolls its damage dice twice and keeps the higher');
    leave('Drag');
    q("UPDATE users SET resting = 'long', rest_until = NOW() WHERE name = 'Drag'");
    run_command(hero('Drag'), 'score');
    check(hero('Drag')['species_spent'] === 0, 'a long rest restores Breath Weapon');

    $hero('Orca', 'fighter', 'orc', 'criminal'); // Dex 16, Alert: initiative +5
    q("UPDATE users SET hp = 1 WHERE name = 'Orca'");
    $dice_queue = [3, 10, 15, 4, 1]; // initiative 8 vs 10; Slam 18 hits for 4; a natural 1 (Graze 2)
    $out = run_command(hero('Orca'), 'kill dummy');
    check(says($out, 'Initiative: you 8, it 10') && says($out, 'Relentless Endurance: you stay on your feet at 1 HP!')
        && hero('Orca')['hp'] === 1 && hero('Orca')['room_id'] === 2,
        'Alert adds PB to Initiative; an Orc at 0 HP drops to 1');
    leave('Orca');

    $hero('Half', 'rogue', 'halfling', 'criminal'); // Dex 17
    $dice_queue = [1, 12, 5, 1, 15, 3, 1]; // initiative 1 rerolled; attack 1 rerolled; 1d6 3; Slam a natural 1
    $out = run_command(hero('Half'), 'kill dummy');
    check(says($out, 'Initiative: you 17, it 5') && says($out, '(20 vs AC 10) for 6 damage') && $dice_queue === [],
        'Halfling Luck: a 1 on a d20 Test is rerolled');
    leave('Half');

    $hero('Gnom', 'wizard', 'gnome rock', 'sage');
    $dice_queue = [2, 15];
    [$total, $saved, $edge] = save(with_stats(hero('Gnom')), 'wis', 10);
    check($edge === 1 && $saved && says(run_command(hero('Gnom'), 'score'), 'level 1 Gnome Wizard'),
        'Gnomish Cunning: Advantage on Int, Wis and Cha saves');

    $hero('Huma', 'fighter', 'human alert', 'sage'); // Con 15, Wis 11; Magic Initiate (Wizard) with Cha 12
    $out = run_command(hero('Huma'), 'cast');
    check(says($out, 'save DC 11, spell attack +3):') && says($out, 'fire bolt <monster>')
        && says($out, 'magic missile <monster>') && !says($out, 'prepare'), 'Magic Initiate: a Fighter casts with Cha');
    $dice_queue = [10, 5, 2, 15, 3, 4, 1]; // initiative 10 + 2 + Alert; a miss rerolled to a hit; 2d6 7; Slam 1
    $out = run_command(hero('Huma'), 'kill dummy');
    check(says($out, 'Initiative: you 14') && says($out, '(19 vs AC 10, Heroic Inspiration rerolls a 2) for 9 damage')
        && hero('Huma')['species_spent'] === 1, 'Heroic Inspiration: a Human rerolls a failed attack roll');
    $dice_queue = [1, 1, 1, 1];
    $out = run_command(hero('Huma'), 'cast magic missile');
    check(says($out, 'Your Magic Missile (free) strikes the Training Dummy for 6 damage')
        && hero('Huma')['origin_spent'] !== '', 'Magic Initiate: its level 1 spell free once a long rest');
    check(says(run_command(hero('Huma'), 'cast magic missile'), 'no spell slots left'), 'then only with slots');
    leave('Huma');

    $hero('Tief', 'fighter', 'tiefling abyssal', 'soldier');
    q("UPDATE users SET level = 5 WHERE name = 'Tief'");
    $t = with_stats(hero('Tief'));
    check(str_contains($t['resistances'], 'poison')
        && array_column(known_spells($t), 'name') === ['Poison Spray', 'Hold Person'],
        'Tiefling: Resistance and legacy spells, a level 2 one from level 5');

    $hero('Dwar', 'fighter', 'dwarf', 'soldier'); // Con 13: 11 HP, +1
    check(says(run_command(hero('Dwar'), 'score'), 'HP 12/12'), 'Dwarven Toughness: +1 HP a level');

    $hero('Goli', 'fighter', 'goliath fire', 'soldier');
    $dice_queue = [10, 5, 15, 3, 4, 1, 1, 5, 1]; // a hit, Savage keeps 7 + 3; Fire's Burn 5; Slam a natural 1
    check(says(run_command(hero('Goli'), 'kill dummy'), 'for 15 damage (+5 Fire\'s Burn)'),
        "Fire's Burn: a Goliath's hit adds 1d10 Fire");
    leave('Goli');
    $hero('Ston', 'fighter', 'goliath stone', 'criminal'); // Con 14
    $dice_queue = [1, 20, 15, 4, 12, 1]; // it goes first and hits for 4; 1d12 12 + 2 takes it all; a natural 1
    $out = run_command(hero('Ston'), 'kill dummy');
    check(says($out, "for 0 damage, 4 shrugged off (Stone's Endurance).") && hero('Ston')['hp'] === 12,
        "Stone's Endurance: a Reaction takes 1d12 + Con off a hit");
    leave('Ston');
    $hero('Stor', 'fighter', 'goliath storm', 'criminal');
    $dice_queue = [1, 20, 15, 4, 8, 1]; // it goes first and hits for 4; 1d8 8 Thunder back; a natural 1
    check(says(run_command(hero('Stor'), 'kill dummy'), "Storm's Thunder: thunder cracks back at the Training Dummy"),
        "Storm's Thunder: a Reaction deals 1d8 Thunder back");
    leave('Stor');
    $hero('Hill', 'fighter', 'goliath hill', 'criminal');
    $dice_queue = [10, 5, 15, 3, 4, 1];
    check(says(run_command(hero('Hill'), 'kill dummy'), "Hill's Tumble: the Training Dummy goes down, Prone."),
        "Hill's Tumble: a hit knocks it Prone");
    leave('Hill');
    $hero('Clou', 'fighter', 'goliath cloud', 'criminal');
    q("UPDATE users SET fighting = 9999 WHERE name = 'Clou'");
    $dice_queue = [];
    check(says(run_command(hero('Clou'), 'flee'), "Cloud's Jaunt") && hero('Clou')['fighting'] === null,
        "Cloud's Jaunt: a Goliath flees without a roll");
    q('UPDATE spawns SET conditions = \'\' WHERE id = 9999');

    // Aggro and stealth (Sly, a Dwarf Rogue, Criminal: proficient in Stealth; Leather Armor, Shortsword). Room 2 has
    // the dummy (passive Perception 10) and a Lurker that attacks whoever it sees come in (12).
    $hero('Sly', 'rogue', 'dwarf', 'criminal');
    q("INSERT INTO monsters (id, name, ac, hp, initiative, attack, attacks, to_hit, damage, cr, xp, str_save, dex_save,
        con_save, int_save, wis_save, cha_save, damage_type, perception, aggressive)
        VALUES (9998, 'Lurker', 10, '2d6', 0, 'Club', 1, 3, '1d4', 0.125, 25, 0, 0, 0, 0, 0, 0, 'bludgeoning', 12,
        TRUE)");
    q('INSERT INTO spawns (id, room_id, monster_id, hp, max_hp) VALUES (9998, 2, 9998, 30, 30)');
    $stealth = skill_bonus(with_stats(hero('Sly')), 'stealth');
    $away = fn (bool $sneak = false) => q(
        "UPDATE users SET fighting = NULL, foe_first = FALSE, room_id = 1, sneaking = ? WHERE name = 'Sly'",
        [(int) $sneak]
    );
    $away();
    $dice_queue = [10, 5]; // initiative: I go first, so it doesn't swing yet
    $out = run_command(hero('Sly'), 'n');
    check(says($out, 'The Lurker attacks you! Initiative: you ') && says($out, ', it 5.') && !says($out, 'Club')
        && hero('Sly')['fighting'] === 9998 && hero('Sly')['foe_first'] === 0 && $dice_queue === [],
        'an aggressive monster attacks a hero it sees come in');
    $away();
    $dice_queue = [1, 20, 2]; // initiative: it goes first and swings now (a natural 2 misses)
    $out = run_command(hero('Sly'), 'n');
    check(says($out, "The Lurker's Club misses you") && hero('Sly')['foe_first'] === 1 && hero('Sly')['moved'] === 0
        && $dice_queue === [], 'winning Initiative it attacks at once, and my next command is my turn');
    $away();
    q("UPDATE users SET level = 6 WHERE name = 'Sly'");
    check(!says(run_command(hero('Sly'), 'n'), 'attacks you') && hero('Sly')['fighting'] === null,
        'it leaves a hero more than 5 levels above its CR alone');
    q("UPDATE users SET level = 1 WHERE name = 'Sly'");
    check(in_array('sneak', actions(hero('Sly')), true) && !in_array('sneak', actions(hero('Testa') + ['room_id' => 1]),
        true), 'a sneak button outside safe rooms');
    $away();
    check(says(run_command(hero('Sly'), 'sneak'), 'You move quietly') && hero('Sly')['sneaking'] === 1,
        'sneak turns sneaking on');
    $dice_queue = [2, 10, 5];
    $out = run_command(hero('Sly'), 'n');
    check(says($out, '(Stealth ' . (2 + $stealth) . ': too loud to hide.)') && says($out, 'The Lurker attacks you!')
        && $dice_queue === [], 'a Stealth check under 15 hides nothing');
    check(says($out, 'You stop sneaking.') && hero('Sly')['sneaking'] === 0, 'a fight ends sneaking');
    check(says(run_command(hero('Sly'), 'sneak'), "can't sneak in the middle of a fight"), 'no sneaking in a fight');
    $away(true);
    q('UPDATE monsters SET perception = 25 WHERE id = 9998');
    $dice_queue = [15, 10, 5];
    $out = run_command(hero('Sly'), 'n');
    check(says($out, '(Stealth ' . (15 + $stealth) . ': the Lurker notices you.)')
        && says($out, 'The Lurker attacks you!') && $dice_queue === [],
        'a passive Perception as high as the check spots the hero');
    q('UPDATE monsters SET perception = 12 WHERE id = 9998');
    $away(true);
    $dice_queue = [15];
    $out = run_command(hero('Sly'), 'n');
    check(says($out, '(Stealth ' . (15 + $stealth) . ': nothing here notices you.)') && !says($out, 'attacks you')
        && hero('Sly')['stealth'] === 15 + $stealth && hero('Sly')['fighting'] === null,
        'sneaking past passive Perception: no attack');
    check(says(run_command(hero('Sly'), 'score'), 'hidden (Stealth ' . (15 + $stealth) . ')'), 'score shows it');
    // Initiative with Advantage (3, 12) against Disadvantage (15, 2); the attack with Advantage (4, 14); Shortsword
    // 1d6 3, Sneak Attack 1d6 2; the Club a natural 1.
    $dice_queue = [3, 12, 15, 2, 4, 14, 3, 2, 1];
    $out = run_command(hero('Sly'), 'kill lurker');
    check(says($out, "You attack the Lurker from hiding! It's surprised. Initiative: you ")
        && says($out, ', with Advantage, it 2, with Disadvantage.') && says($out, ', with Advantage) for ')
        && says($out, 'with Sneak Attack') && hero('Sly')['stealth'] === null && hero('Sly')['sneaking'] === 0
        && $dice_queue === [],
        'attacking from hiding: Advantage on Initiative and the first attack, the monster surprised; it ends sneaking');
    q("UPDATE users SET hp = 1, sneaking = TRUE, advantage = FALSE WHERE name = 'Sly'"); // as if left on somehow
    $dice_queue = [1, 20, 1, 1]; // a natural 1; the Club crits for 2
    check(says(run_command(hero('Sly'), 'kill'), 'You have died!') && hero('Sly')['room_id'] === START_ROOM
        && hero('Sly')['sneaking'] === 0 && $dice_queue === [], 'waking in town ends sneaking');
    $away(true);
    q('UPDATE spawns SET dead_since = NOW() WHERE id = 9998');
    $dice_queue = [1, 2]; // the dummy is the only monster: 1 on the check; it isn't aggressive
    check(says(run_command(hero('Sly'), 'n'), '(Stealth ' . (1 + $stealth) . ': too loud to hide.)')
        && hero('Sly')['fighting'] === null && $dice_queue === [2], 'a monster that is not aggressive never attacks');
    $dice_queue = [];
    check(with_stats(hero('Testa'))['noisy'] && !with_stats(hero('Sly'))['noisy'],
        "Chain Mail gives Disadvantage on Stealth, Leather Armor doesn't");
    check(says(run_command(hero('Sly'), 'sneak'), 'You stop sneaking.') && hero('Sly')['sneaking'] === 0,
        'sneak again stops');
    run_command(hero('Sly'), 'sneak');
    check(says(run_command(hero('Sly'), 'say psst'), 'You stop sneaking.') && hero('Sly')['sneaking'] === 0
        && !says(run_command(hero('Sly'), 'say hello'), 'sneaking'), 'speaking ends sneaking');

    // Hirelings (Hire, a Fighter: Greatsword +4, 2d6+2). A Guard: AC 16, 11 HP, Spear +3 for 1d6+1.
    save_account('Hire', 'correct horse');
    create('Hire', 'fighter');
    q("UPDATE users SET gold = 3 WHERE name = 'Hire'");
    check(says(run_command(hero('Hire'), 'hire guard'), 'at the Swaying Lantern'), 'hirelings wait at the Lantern');
    q("UPDATE users SET room_id = ? WHERE name = 'Hire'", [LANTERN]);
    check(in_array('hire ', actions(hero('Hire')), true)
        && in_array(['mastiff', 'hire mastiff'], choices(with_stats(hero('Hire')))['hire'], true),
        'the Lantern offers them');
    check(says(run_command(hero('Hire'), 'hire dragon'), 'Hire whom?'), 'only guards, mastiffs and acolytes');
    $out = run_command(hero('Hire'), 'hire guard');
    check(says($out, 'A Guard takes your 2 gold') && hero('Hire')['gold'] === 1 && hero('Hire')['hireling_hp'] === 11,
        "hire: 2 gold a day, at the stat block's average HP");
    check(says(run_command(hero('Hire'), 'hire mastiff'), 'Dismiss it first'), 'one hireling at a time');
    check(says(run_command(hero('Hire'), 'hire guard'), 'and you have 1.'), 'a day needs 2 gold');
    q("UPDATE users SET gold = 2 WHERE name = 'Hire'");
    check(says(run_command(hero('Hire'), 'hire guard'), 'for another day')
        && preg_grep('/Guard 11\/11 HP, AC 16, 4[78] h paid/', run_command(hero('Hire'), 'score')),
        'hiring again pays for another day, shown on the sheet');
    q("UPDATE users SET room_id = 2 WHERE name = 'Hire'");
    q("UPDATE spawns SET hp = 30 WHERE id = 9999");
    check(says(run_command(hero('Hire'), 'look'), 'Your Guard is here, with you.'), 'it goes where you go');
    $dice_queue = [10, 1, 15, 1, 1, 12, 3, 2, 10]; // initiative; I hit for 4, it hits for 4; d2 = 2: at the Guard
    $out = run_command(hero('Hire'), 'kill dummy');
    check(says($out, "Your Guard's Spear hits the Training Dummy (15 vs AC 10) for 4 damage.")
        && says($out, "The Training Dummy's Slam misses your Guard (13 vs AC 16).") && !says($out, 'misses you (')
        && says($out, 'Guard: 11/11 HP.  Training Dummy: 22 HP.'), 'it attacks my foe after me, and draws its attacks');
    q("UPDATE users SET hireling_hp = 3 WHERE name = 'Hire'");
    $dice_queue = [15, 1, 1, 1, 2, 20, 4, 4]; // I hit; natural 1; d2 = 2, and a critical Slam for 8
    $out = run_command(hero('Hire'), 'kill');
    check(says($out, "Your Guard's Spear misses the Training Dummy (natural 1).")
        && says($out, 'Your Guard falls') && hero('Hire')['hireling_monster_id'] === null, 'at 0 HP it is gone');
    $dice_queue = [1, 1]; // with no hireling there's no d2
    check(says(run_command(hero('Hire'), 'kill'), 'Slam misses you (natural 1)') && $dice_queue === [],
        'then its attacks are all at me');
    q("UPDATE users SET fighting = NULL, room_id = ?, gold = 2 WHERE name = 'Hire'", [LANTERN]);
    run_command(hero('Hire'), 'hire guard');
    q("UPDATE users SET hireling_hp = 1, resting = 'short', rest_until = NOW() WHERE name = 'Hire'");
    check(says(run_command(hero('Hire'), 'look'), 'Your Guard rests too') && hero('Hire')['hireling_hp'] === 11,
        'a rest heals it');
    q("UPDATE users SET hired_until = NOW() - INTERVAL 1 SECOND WHERE name = 'Hire'");
    $out = run_command(hero('Hire'), 'look');
    check(says($out, "Your Guard's paid time is up") && !says($out, 'Your Guard is here')
        && hero('Hire')['hireling_monster_id'] === null, 'it leaves when its paid time runs out');
    q("UPDATE users SET gold = 2 WHERE name = 'Hire'");
    run_command(hero('Hire'), 'hire mastiff');
    check(says(run_command(hero('Hire'), 'dismiss'), 'Your Mastiff heads back')
        && hero('Hire')['hireling_monster_id'] === null, 'dismiss');

    // Shops and the temple (Shop, a Fighter with kit A and 4 gold: Greatsword +4, 2d6+2).
    save_account('Shop', 'correct horse');
    create('Shop', 'fighter');
    $mine = fn (string $item) => find_object(objects_here('user_id', hero('Shop')['id']), $item);
    check(says(run_command(hero('Shop'), 'buy dagger'), 'Nothing is for sale here'), 'buying needs a shop');
    q("UPDATE users SET room_id = 4 WHERE name = 'Shop'");
    check(says(run_command(hero('Shop'), 'n'), 'The Smithy'), 'the Smithy is north of Market Lane');
    $out = run_command(hero('Shop'), 'list');
    check(preg_grep('/^  Dagger +2 GP  weapon, 1d4/', $out) && preg_grep('/^  Club +1 GP/', $out)
        && !says($out, 'Musket') && !says($out, 'Chain Mail') && !says($out, '+1'),
        'it lists its weapons, under 1 GP for 1 gold');
    check(in_array('buy ', actions(hero('Shop')), true)
        && in_array(['dagger', 'buy dagger'], choices(with_stats(hero('Shop')))['buy'], true), 'buttons offer them');
    check(says(run_command(hero('Shop'), 'buy rapier'), 'The Rapier costs 25 gold, and you have 4.')
        && hero('Shop')['gold'] === 4, "you can't buy what you can't afford");
    check(says(run_command(hero('Shop'), 'buy dagger'), 'You buy the Dagger for 2 gold.')
        && hero('Shop')['gold'] === 2 && $mine('dagger') !== null, 'buy');
    check(says(run_command(hero('Shop'), 'sell flail'), 'You sell the Flail for 5 gold.')
        && hero('Shop')['gold'] === 7 && $mine('flail') === null, 'sell for half the cost');
    check(says(run_command(hero('Shop'), 'sell javelin'), 'Nobody will pay anything for the Javelin.')
        && $mine('javelin') !== null, 'nothing for what costs under 2 GP');
    q("UPDATE users SET room_id = 11, gold = 415 WHERE name = 'Shop'");
    check((bool) preg_grep('/^  Longsword \+1 +415 GP/', run_command(hero('Shop'), 'list')),
        'the Curio Loft sells +1 weapons');
    run_command(hero('Shop'), 'buy longsword');
    run_command(hero('Shop'), 'equip longsword');
    $s = with_stats(hero('Shop'));
    check($s['weapon'] === 'Longsword +1' && $s['to_hit'] === 5 && $s['damage'] === '1d10+1',
        'a +1 Weapon adds 1 to attack and damage rolls');
    q("UPDATE users SET room_id = 10, gold = 200 WHERE name = 'Shop'");
    check(says(run_command(hero('Shop'), 'buy greater'), 'You buy the Potion of Healing (greater) for 200 gold.'),
        'the Apothecary sells potions');
    check(says(run_command(hero('Shop'), 'pray heal'), 'east of the Mill Bridge'), 'priests cast at the temple');
    q("UPDATE users SET room_id = 5, hp = 1, max_hp = 30, gold = 400 WHERE name = 'Shop'");
    check(says(run_command(hero('Shop'), 'e'), 'Temple of Chauntea') && in_array('pray ', actions(hero('Shop')), true),
        'the temple is east of the Mill Bridge');
    check(says(run_command(hero('Shop'), 'pray'), 'aid     200 GP'), 'pray alone lists the services');
    $dice_queue = [1, 2];
    check(says(run_command(hero('Shop'), 'pray cure'), 'You give 50 gold, and a priest of Chauntea casts Cure Wounds '
        . 'on you. HP 7/30.') && hero('Shop')['gold'] === 350, 'Cure Wounds: 2d8 + 3 for 50 gold');
    check(says(run_command(hero('Shop'), 'pray heal'), 'You give 100 gold, and a priest of Chauntea casts Cure Wounds '
        . '2 times on you. HP 30/30.') && hero('Shop')['hp'] === 30, 'heal: to full, 50 gold for each 12 HP missing');
    check(says(run_command(hero('Shop'), 'pray heal'), 'already at full health'), 'not at full HP');
    check(says(run_command(hero('Shop'), 'pray aid'), 'You give 200 gold, and a priest of Chauntea casts Aid on you. '
        . 'HP 35/35.') && with_stats(hero('Shop'))['max_hp'] === 35, 'Aid: +5 HP and max HP for 200 gold');
    check(says(run_command(hero('Shop'), 'pray aid'), 'Aid is already on you'), "Aid doesn't stack");
    q("UPDATE users SET hp = 1, gold = 49 WHERE name = 'Shop'");
    check(says(run_command(hero('Shop'), 'pray cure'), "That's a donation of 50 gold, and you have 49."),
        'the priests want their donation');

    // Converted areas (db/areas/*.sql). Kobold Mines hangs off the Southern Fields.
    check(q('SELECT id, aggressive FROM monsters WHERE id IN (24700, 6002, 6000) ORDER BY id')
        ->fetchAll(PDO::FETCH_KEY_PAIR) === [6000 => 0, 6002 => 0, 24700 => 1],
        "Riftshadow's aggressive monsters are, unless they start asleep (the Brown Bear)");
    q('UPDATE monsters SET aggressive = FALSE'); // so walking through the areas and the Delve starts no fights
    q("UPDATE users SET room_id = 6 WHERE name = 'Testa'");
    check(says(run_command(hero('Testa'), 's'), 'Within a Dark Crevice'), 'Southern Fields lead south to Kobold Mines');
    check(says(run_command(hero('Testa'), 'd'), 'Exits: east west up down'), 'converted exits inside the area');
    check(says(run_command(hero('Testa'), 'u'), 'Exits: north down'), 'exits leaving the area are dropped');
    check(says(run_command(hero('Testa'), 'n'), 'Southern Fields'), 'the way back to Greenest');
    q("UPDATE users SET room_id = 24609 WHERE name = 'Testa'");
    check(says(run_command(hero('Testa'), 'look'), 'A Goblin Child is here.'), 'converted monsters spawn');
    q("UPDATE users SET room_id = 4 WHERE name = 'Testa'");
    check(says(run_command(hero('Testa'), 'd'), 'Under the Dump')
        && says(run_command(hero('Testa'), 'u'), 'Market Lane'), 'Market Lane leads down to the Sewers, and back up');
    $sql = file_get_contents(__DIR__ . '/../db/areas/sewer.sql');
    $is = fn (string $mob, string $srd): bool => (bool) preg_match("/'$mob'.*SRD $srd, Rift/", $sql);
    check($is('Junior Mindflayer', 'Darkmantle') && $is('Senior Mindflayer', 'Grick')
        && $is('Master Mindflayer', 'Otyugh') && $is('Evil Wanderer', 'Spy') && $is('Small Spider', 'Spider')
        && $is('Ugly Wererat', 'Wererat'),
        'the Sewers: stand-ins tiered by Rift level, role words, the longest name that matches');
    $forms = fn (int $level): array => array_column(wild_forms(['level' => $level]), 'name');
    check(!array_intersect(['Bat', 'Swarm of Insects'], $forms(4)) && in_array('Bat', $forms(8), true),
        'no Wild Shape forms that fly before Druid 8');

    // Inspecting the town and asking its people, with skill checks behind some of it (Testa: Dex 14, Wis 10).
    q("UPDATE users SET room_id = ?, fighting = NULL, background = NULL WHERE name IN ('Testa', 'Testb')", [LANTERN]);
    q('DELETE FROM checks');
    $look = run_command(hero('Testa'), 'look');
    check(says($look, 'Marta Dunmore, the innkeeper, is here.') && says($look, 'You could inspect: ledger, lantern.'),
        'look shows the townsfolk and what to inspect');
    $dice_queue = [1];
    $ledger = run_command(hero('Testa'), 'look at the ledger');
    check(says($ledger, 'GUESTS OF THE SWAYING LANTERN') && says($ledger, '  Testa ') && says($ledger, '  Testb ')
        && says($ledger, 'Fighter, level') && says($ledger, '(Sleight of Hand check: 3 vs DC 10, fail)')
        && !says($ledger, 'Two kobolds'), 'the ledger lists every hero; a failed check hides its secret');
    $dice_queue = [20];
    check(says(run_command(hero('Testa'), 'inspect ledger'), 'Try again tomorrow') && $dice_queue === [20],
        'a failed check waits a day, without rolling');
    q('UPDATE checks SET at = NOW() - INTERVAL 25 HOUR');
    check(says(run_command(hero('Testa'), 'inspect ledger'), 'Two kobolds') && $dice_queue === [],
        'a day later it rolls again, and a success shows the secret');
    $dice_queue = [1];
    $again = run_command(hero('Testa'), 'inspect ledger');
    check(says($again, 'Two kobolds') && !says($again, 'check:') && $dice_queue === [1], 'a success stands');
    $dice_queue = [];
    check(says(run_command(hero('Testa'), 'inspect dragon'), 'You see no dragon here'), 'inspect needs a real thing');
    check(says(run_command(hero('Testa'), 'look marta'), 'apron full of keys')
        && says(run_command(hero('Testa'), 'inspect marta'), 'will talk about: ledger, trouble'),
        'inspecting a townsperson describes them and what they talk about');
    q("UPDATE users SET background = 'soldier' WHERE name = 'Testa'");
    $t = with_stats(hero('Testa'));
    check(skill_bonus($t, 'athletics') === mod($t['strength']) + $t['prof_bonus']
        && skill_bonus($t, 'stealth') === mod($t['dexterity']) && skill_bonus($t, 'insight') === mod($t['wisdom']),
        "skill checks: ability modifier, plus Proficiency Bonus in the background's skills");
    check(says(run_command(hero('Testa'), 'ask marta'), 'Rooms upstairs')
        && says(run_command(hero('Testa'), 'speak marta dunmore'), "Try 'ask marta about ledger'"),
        'ask (or speak) with no topic: their greeting and what they talk about');
    check(says(run_command(hero('Testa'), 'ask about the lantern'), 'grandmother'), 'ask about, with one person here');
    check(says(run_command(hero('Testa'), 'ask marta wood'), 'it was green'), 'ask <npc> <topic>, without about');
    check(says(run_command(hero('Testa'), 'ask marta about weather'), 'nothing to say about weather')
        && says(run_command(hero('Testa'), 'ask bob about wood'), 'nobody called bob'), "ask what they don't know");
    $dice_queue = [19];
    $talk = run_command(hero('Testa'), 'ask marta about the raids');
    check(says($talk, 'Who steals hinges?') && says($talk, '(Insight check: 19 vs DC 15, success)')
        && says($talk, 'frightened'), 'a topic with a skill: an Influence check unlocks more');
    $dice_queue = [];
    check(in_array('ask ', actions(hero('Testa')), true) && in_array('inspect ', actions(hero('Testa')), true)
        && in_array(['ledger', 'ask marta dunmore about ledger'],
            choices(with_stats(hero('Testa')))['ask marta dunmore'], true)
        && array_is_list(choices(with_stats(hero('Testa')))['ask marta dunmore']) && !says(run_command(hero('Testa'), 'ask marta about the hello'), 'nothing to say'),
        'buttons: ask a townsperson, then a topic');

    // The Town Square's bulletin board: posts are rows added by hand, and reading the board marks them read.
    q("UPDATE users SET room_id = 1, last_bulletin_id = 0 WHERE name = 'Testa'");
    q("INSERT INTO bulletins (text) VALUES ('Selftest news.\nA second line.')");
    $post = (int) db()->lastInsertId();
    check(says(run_command(hero('Testa'), 'look'), "A new notice is pinned to the bulletin board. Try 'inspect bulletin'"),
        'an unread post: the square points at the board');
    $board = run_command(hero('Testa'), 'inspect board');
    check(says($board, 'shingle roof') && says($board, '  (just now) Selftest news.')
        && says($board, '    A second line.') && hero('Testa')['last_bulletin_id'] === $post
        && !says(run_command(hero('Testa'), 'look'), 'new notice'),
        'reading the board shows the newest posts and marks them read');
    q("UPDATE users SET room_id = 6 WHERE name = 'Testa'");
    check(says(run_command(hero('Testa'), 'ask about wood'), 'no one here to talk to'), 'nobody to ask in the fields');

    // The battle board (task 4a). Board, a Fighter (Greatsword +4, 2d6+2; Shortbow +4, 1d6+2; AC 17, initiative +2,
    // Speed 30), against a Training Dummy (AC 10, Slam +3 for 1d4, Speed 30) of its own in the Armorer, room 9: an 8x6
    // board with one exit, north. Squares are named as `move` takes them, a1 the top left; [x, y] counts from 0.
    save_account('Board', 'correct horse');
    create('Board', 'fighter');
    q('INSERT INTO spawns (id, room_id, monster_id, dead_since) VALUES (9901, 9, 9999, NOW() - INTERVAL 1 DAY)');
    q("UPDATE users SET room_id = 4 WHERE name = 'Board'");
    $dice_queue = [6, 6]; // its HP as it respawns
    run_command(hero('Board'), 's');
    $b = hero('Board');
    check([$b['x'], $b['y']] === [4, 0], 'coming in by the north exit puts me mid north wall, at e1');
    $dice_queue = [10, 5, 12, 3, 4, 10]; // Initiative 12 vs 5; I close 20 ft and hit 16 for 3+4+2; its Slam 13 misses
    $out = run_command(hero('Board'), 'kill dummy');
    $b = hero('Board');
    $at = fn () => array_values(q('SELECT x, y FROM spawns WHERE id = 9901')->fetch());
    check($at() === [3, 5] && [$b['x'], $b['y']] === [3, 4] && says($out, 'You close in on the Training Dummy')
        && says($out, 'You hit the Training Dummy (16 vs AC 10) for 9')
        && says($out, 'Slam misses you (13 vs AC 17)') && $dice_queue === [] && $b['moved'] === null,
        'it stands across the room, at d6; I close 20 ft to d5 and attack, all in one turn');
    $map = cmd_map($b);
    check($map[5][4] === '@' && $map[6][4] === 't' && $map[0][5] === '.'
        && $map[count($map) - 2] === 'Training Dummy: 5 ft away, 3 HP.',
        'in a fight the map is the board: me, my foe, the exit, how far it is and its HP');
    $dice_queue = [15, 2]; // leaving its reach: its opportunity attack, 18, hits for 2
    $out = run_command(hero('Board'), 'move d1');
    $b = hero('Board');
    check(says($out, 'The Training Dummy strikes as you pull away!') && says($out, 'hits you (18 vs AC 17) for 2')
        && says($out, 'You move to d1') && [$b['x'], $b['y']] === [3, 0] && $b['moved'] === 20 && $dice_queue === [],
        'moving away draws an opportunity attack, and leaves the rest of my turn');
    check(says(run_command(hero('Board'), 'move d6'), 'The Training Dummy is there.')
        && says(run_command(hero('Board'), 'move z9'), 'Move where?'), 'move needs an empty square on the board');
    $out = run_command(hero('Board'), 'move d3');
    check(says($out, 'You move to d3') && !says($out, 'pull away') && hero('Board')['moved'] === 30,
        'a second move in the same turn, toward it: no attack');
    check(says(run_command(hero('Board'), 'move d1'), 'You have 0 ft of movement left'), 'my Speed is 30 ft a turn');
    $dice_queue = [1]; // out of movement I Dash in, so no attack; its Slam rolls a natural 1
    $out = run_command(hero('Board'), 'kill');
    check(says($out, 'You run at the Training Dummy') && !says($out, 'You hit') && !says($out, 'You miss')
        && says($out, 'Slam misses you (natural 1)') && $dice_queue === [],
        'closing in that takes a Dash takes my action');

    // A Shortbow (80/320 ft) from across the room: no Disadvantage, and the dummy closes in to answer.
    q('INSERT INTO objects (item_id, user_id) VALUES (72, ?)', [hero('Board')['id']]);
    q('UPDATE users SET ' . END_FIGHT . " WHERE name = 'Board'");
    run_command(hero('Board'), 'equip shortbow');
    q("UPDATE users SET x = 0, y = 0 WHERE name = 'Board'");
    q('UPDATE spawns SET x = 7, y = 5, placed_at = NOW(), hp = 30 WHERE id = 9901');
    $dice_queue = [15, 1, 12, 4, 5]; // Initiative 17 vs 1; my arrow 16 hits for 4+2; it closes 30 ft; Slam 8 misses
    $out = run_command(hero('Board'), 'kill dummy');
    check(says($out, 'You hit the Training Dummy (16 vs AC 10) for 6 damage.') && !says($out, 'You close in')
        && says($out, 'The Training Dummy closes in.') && says($out, 'Slam misses you (8 vs AC 17)')
        && $at() === [1, 0] && $dice_queue === [], 'shooting from 35 ft away; it walks 30 ft to me and attacks');
    q("UPDATE users SET advantage = FALSE WHERE name = 'Board'"); // Vex's, from that hit, would cancel it out
    $dice_queue = [15, 3, 2]; // next to it: Disadvantage, so the 3 (7) misses; Slam 5 misses
    check(says(run_command(hero('Board'), 'kill'), 'You miss the Training Dummy (7 vs AC 10, with Disadvantage)')
        && $dice_queue === [], 'a ranged attack within 5 ft of an enemy has Disadvantage');

    // The Wood's boards are 16x10. From 75 ft the dummy can't reach me, so it Dashes; with a bow of its own it shoots.
    q('UPDATE users SET ' . END_FIGHT . ", room_id = 6001, x = 0, y = 5 WHERE name = 'Board'");
    q('UPDATE spawns SET room_id = 6001, x = 15, y = 5, placed_at = NOW() WHERE id = 9901');
    $dice_queue = [15, 1, 1]; // Initiative 17 vs 1; my natural 1; it runs 60 ft and can't attack
    $out = run_command(hero('Board'), 'kill dummy');
    check(says($out, 'The Training Dummy runs at you.') && !says($out, 'Slam') && $at() === [3, 5]
        && $dice_queue === [], "a monster that can't reach me this turn Dashes");
    q("UPDATE monsters SET shot = 'Shortbow', shot_hit = 4, shot_damage = '1d6+2', shot_type = 'piercing',
        shot_range = 80, shot_long = 320 WHERE id = 9999");
    q('UPDATE users SET ' . END_FIGHT . " WHERE name = 'Board'");
    q('UPDATE spawns SET x = 15, y = 5 WHERE id = 9901');
    $dice_queue = [15, 1, 1, 10]; // Initiative; my natural 1; its Shortbow, 14, misses
    $out = run_command(hero('Board'), 'kill dummy');
    check(says($out, "The Training Dummy's Shortbow misses you (14 vs AC 17)") && $at() === [15, 5]
        && $dice_queue === [], 'a monster with a ranged attack shoots from where it stands');
    q('UPDATE spawns SET x = 1 WHERE id = 9901');
    $dice_queue = [1, 1, 10]; // next to it: my two d20s (Disadvantage) are 1s; its Slam, 13, misses
    check(says(run_command(hero('Board'), 'kill'), "The Training Dummy's Slam misses you (13 vs AC 17)")
        && $dice_queue === [], 'and its melee attack once I am next to it');
    q('UPDATE monsters SET shot = NULL, shot_hit = NULL, shot_damage = NULL, shot_type = NULL, shot_range = NULL,
        shot_long = NULL WHERE id = 9999');

    // Fleeing: a Dash for an exit. A step that stays within its reach draws no attack. It waits where it was, so
    // coming straight back in finds it there; 10 minutes later it's back at a fresh spot across the room.
    q('UPDATE users SET ' . END_FIGHT . ", room_id = 9, x = 4, y = 1, fighting = 9901, foe_first = FALSE
        WHERE name = 'Board'");
    q('UPDATE spawns SET room_id = 9, x = 3, y = 1, placed_at = NOW() WHERE id = 9901');
    $dice_queue = [];
    $out = run_command(hero('Board'), 'flee');
    check(says($out, 'You flee north from the Training Dummy!') && !says($out, 'pull away')
        && hero('Board')['room_id'] === 4 && hero('Board')['fighting'] === null && $at() === [3, 1],
        'flee runs out the nearest exit; it stays where it was');
    run_command(hero('Board'), 's');
    $dice_queue = [10, 5, 12, 12, 4, 1]; // Initiative; next to it at once: Disadvantage (16) hits for 6; Slam a 1
    $out = run_command(hero('Board'), 'kill dummy');
    check(!says($out, 'closes in') && says($out, 'with Disadvantage') && $dice_queue === [],
        'coming back in, it is still by the door');
    q('UPDATE users SET ' . END_FIGHT . " WHERE name = 'Board'");
    q('UPDATE spawns SET placed_at = NOW() - INTERVAL 11 MINUTE WHERE id = 9901');
    $dice_queue = [10, 5, 12, 4, 1]; // Initiative; from 25 ft my arrow 16 hits for 6; it closes in, Slam a 1
    $out = run_command(hero('Board'), 'kill dummy');
    check(says($out, 'The Training Dummy closes in.') && !says($out, 'Disadvantage') && $dice_queue === [],
        'ten minutes on, it starts across the room again');
    q('UPDATE users SET ' . END_FIGHT . " WHERE name = 'Board'");
    q('UPDATE spawns SET dead_since = NOW() - INTERVAL 1 DAY WHERE id = 9901');
    $dice_queue = [6, 6];
    room_monsters(9);
    check($at() === [null, null], 'a monster that respawns has no square until its next fight');
    check(says(run_command(hero('Board'), 'move a1'), "You're not in a fight"), 'move is for fights');

    // Kiting and the other moves (task 4b). Board shoots, then backs off to the square farthest from the dummy (Speed
    // 30, 60 HP here).
    q('UPDATE spawns SET hp = 60, x = 7, y = 2, placed_at = NOW() WHERE id = 9901');
    q("UPDATE users SET x = 4, y = 2, advantage = FALSE WHERE name = 'Board'");
    $dice_queue = [15, 1, 12, 4, 5]; // Initiative; the arrow hits for 6; I back off 20 ft to a3; it closes in, Slam 8
    $out = run_command(hero('Board'), 'kite dummy');
    $b = hero('Board');
    check(says($out, 'You hit the Training Dummy (16 vs AC 10) for 6 damage.') && says($out, 'You back off to a3')
        && [$b['x'], $b['y']] === [0, 2] && says($out, 'The Training Dummy closes in.') && $dice_queue === [],
        'kite: shoot, then back off as far from it as my Speed allows');
    q("UPDATE users SET x = 2, y = 2, advantage = FALSE WHERE name = 'Board'");
    q('UPDATE spawns SET x = 1, y = 2 WHERE id = 9901');
    $dice_queue = [1, 12, 4, 5]; // next to it I back off first (its opportunity attack, a 1), then shoot; Slam 8
    $out = run_command(hero('Board'), 'kite');
    $away = array_search(true, array_map(fn ($l) => str_contains($l, 'pull away'), $out), true);
    $shot = array_search(true, array_map(fn ($l) => str_contains($l, 'You hit'), $out), true);
    check($away !== false && $shot !== false && $away < $shot && !says($out, 'Disadvantage')
        && hero('Board')['x'] === 7 && $dice_queue === [],
        'next to it, kite backs off first, so the shot has no Disadvantage');
    q("UPDATE users SET advantage = FALSE WHERE name = 'Board'");
    $dice_queue = [1, 5]; // its opportunity attack (a 1) as I run 35 ft to a3; it closes in, Slam 8
    $out = run_command(hero('Board'), 'dash');
    check(says($out, 'You dash to a3') && hero('Board')['x'] === 0 && !says($out, 'You hit') && !says($out, 'You miss')
        && $dice_queue === [], 'dash: run twice as far, with no attack');
    $dice_queue = [5]; // no opportunity attack; it closes in, Slam 8
    $out = run_command(hero('Board'), 'disengage');
    check(says($out, 'You disengage') && !says($out, 'pull away') && hero('Board')['x'] === 6 && $dice_queue === [],
        'disengage: move away with no opportunity attack');
    $dice_queue = [5, 15]; // Dodging: its Slam takes the lower d20
    check(says(run_command(hero('Board'), 'dodge'), 'misses you (8 vs AC 17, with Disadvantage)')
        && $dice_queue === [], 'dodge: attacks on me have Disadvantage');

    // Weapon masteries that move things: Slow (a Longbow) and Push (a Pike, which also has Reach: 10 ft).
    $id = hero('Board')['id'];
    q('INSERT INTO objects (item_id, user_id) VALUES (77, ?), (21, ?)', [$id, $id]); // Longbow, Pike
    q('UPDATE users SET ' . END_FIGHT . ", x = 0, y = 2, advantage = FALSE WHERE name = 'Board'");
    run_command(hero('Board'), 'equip longbow');
    q('UPDATE spawns SET x = 6, y = 2, placed_at = NOW() WHERE id = 9901');
    $dice_queue = [15, 1, 12, 4]; // Initiative; the arrow hits; at 20 ft a turn it can't reach me, so it runs
    $out = run_command(hero('Board'), 'kill dummy');
    check(says($out, "Slow: the Training Dummy's Speed drops 10 ft") && says($out, 'runs at you')
        && $dice_queue === [], 'Slow: 10 ft off its Speed until my next turn');
    q('UPDATE users SET ' . END_FIGHT . ", x = 0, y = 2 WHERE name = 'Board'");
    run_command(hero('Board'), 'equip pike');
    q("UPDATE spawns SET x = 3, y = 2, placed_at = NOW(), conditions = '' WHERE id = 9901");
    $dice_queue = [15, 1, 12, 5, 5]; // Initiative; I step to 10 ft and hit, pushing it 10 ft; it closes in, Slam 8
    $out = run_command(hero('Board'), 'kill dummy');
    $b = hero('Board');
    check([$b['x'], $b['y']] === [1, 2] && says($out, 'You hit the Training Dummy (16 vs AC 10) for 7')
        && says($out, 'pushed 10 ft') && says($out, 'The Training Dummy closes in.') && $at() === [2, 2]
        && $dice_queue === [], 'a Reach weapon hits from 10 ft; Push shoves it 10 ft back');

    // Grappled: held by another hero still fighting it, it can't move; my own hold ends when I step out of reach.
    q('UPDATE users SET ' . END_FIGHT . ", x = 0, y = 2 WHERE name = 'Board'");
    run_command(hero('Board'), 'equip longbow');
    q('UPDATE spawns SET x = 6, y = 2, grappled_by = ? WHERE id = 9901', [hero('Testa')['id']]);
    q("UPDATE users SET fighting = 9901 WHERE name = 'Testa'");
    $dice_queue = [15, 1, 1]; // Initiative; my natural 1; held, it can't come at me
    check(says(run_command(hero('Board'), 'kill dummy'), "The Training Dummy can't move.") && $at() === [6, 2]
        && $dice_queue === [], "a grappled monster can't move");
    q("UPDATE users SET fighting = NULL WHERE name = 'Testa'");
    q('UPDATE users SET ' . END_FIGHT . ", x = 5, y = 2, fighting = 9901, foe_first = FALSE WHERE name = 'Board'");
    q('UPDATE spawns SET grappled_by = ? WHERE id = 9901', [hero('Board')['id']]);
    $dice_queue = [1]; // its opportunity attack, a 1
    $out = run_command(hero('Board'), 'move a3');
    check(says($out, 'You let go of the Training Dummy.')
        && q('SELECT grappled_by FROM spawns WHERE id = 9901')->fetchColumn() === null,
        'stepping out of reach lets go');
    q("UPDATE monsters SET shot = 'Dagger', shot_hit = 4, shot_damage = '1d4+2', shot_type = 'piercing',
        shot_range = 20, shot_long = 60 WHERE id = 9999");
    q('UPDATE users SET ' . END_FIGHT . ", x = 0, y = 2 WHERE name = 'Board'");
    q('UPDATE spawns SET x = 7, y = 2, placed_at = NOW() WHERE id = 9901');
    $dice_queue = [15, 1, 1, 10]; // Initiative; my natural 1; it steps in 15 ft and throws: 14 misses
    $out = run_command(hero('Board'), 'kill dummy');
    check(says($out, 'The Training Dummy steps in.')
        && says($out, "The Training Dummy's Dagger misses you (14 vs AC 17)") && $at() === [4, 2]
        && $dice_queue === [], 'a monster that shoots walks up to its normal range first');
    q('UPDATE monsters SET shot = NULL, shot_hit = NULL, shot_damage = NULL, shot_type = NULL, shot_range = NULL,
        shot_long = NULL WHERE id = 9999');

    // From hiding I creep up before the fight's first turn (here as far as my Pike's reach), with Advantage.
    q('UPDATE users SET ' . END_FIGHT . ", x = 0, y = 2, stealth = 30 WHERE name = 'Board'");
    run_command(hero('Board'), 'equip pike');
    q('UPDATE spawns SET x = 7, y = 2, placed_at = NOW() WHERE id = 9901');
    $dice_queue = [10, 5, 1, 1, 12, 3, 5, 5]; // Initiative 10 (Advantage) vs 1 (surprised); 12 (Advantage) hits; Slam 8
    $out = run_command(hero('Board'), 'kill dummy');
    check(says($out, 'You creep up on the Training Dummy, unseen.') && hero('Board')['x'] === 5
        && says($out, '(16 vs AC 10, with Advantage)') && $dice_queue === [], 'from hiding I close in before it acts');
    q('UPDATE users SET ' . END_FIGHT . " WHERE name = 'Board'");

    // kite <square>: shoot, then go where I say. With a move first, that's move, shoot, move in one turn.
    run_command(hero('Board'), 'equip longbow');
    q("UPDATE users SET x = 0, y = 2, advantage = FALSE, stealth = NULL WHERE name = 'Board'");
    q("UPDATE spawns SET x = 7, y = 2, placed_at = NOW(), conditions = '' WHERE id = 9901");
    $dice_queue = [15, 1, 12, 4]; // Initiative; the arrow hits (Slow); I go to a6; slowed, it can only run at me
    $out = run_command(hero('Board'), 'kite dummy a6');
    check(says($out, 'You hit the Training Dummy') && says($out, 'You move to a6') && hero('Board')['y'] === 5
        && says($out, 'runs at you') && $dice_queue === [], 'kite <monster> <square>: shoot, then move there');
    $dice_queue = [1, 12, 4, 5]; // a4 draws its opportunity attack (a 1); from there the arrow hits; to a1; Slam 8
    run_command(hero('Board'), 'move a4');
    $out = run_command(hero('Board'), 'kite a1');
    $shot = array_search(true, array_map(fn ($l) => str_contains($l, 'You hit'), $out), true);
    $went = array_search(true, array_map(fn ($l) => str_contains($l, 'You move to a1'), $out), true);
    check($shot !== false && $went !== false && $shot < $went && [hero('Board')['x'], hero('Board')['y']] === [0, 0]
        && $dice_queue === [], 'move, shoot, move: move a4, then kite a1');
    check(says(run_command(hero('Board'), 'kite z9'), 'Kite to where?'), 'kite needs a square on the board');
    $it = q('SELECT x, y FROM spawns WHERE id = 9901')->fetch();
    $dice_queue = [];
    check(says(run_command(hero('Board'), 'kite ' . square([$it['x'], $it['y']])), 'The Training Dummy is there.')
        && $dice_queue === [], "kite won't go to a square someone's on, and doesn't shoot");
    q("UPDATE users SET moved = 30 WHERE name = 'Board'"); // my Speed is spent this turn
    $out = run_command(hero('Board'), 'kite b1');
    check(says($out, 'You stay at a1: no movement left this turn for b1') && hero('Board')['x'] === 0,
        'kite says when it has no movement left to go to the square');
    q('UPDATE users SET ' . END_FIGHT . " WHERE name = 'Board'");

    // Speed: a Monk's Unarmored Movement (+10 ft at level 2, 5 more every 4 levels) and a Barbarian's Fast Movement
    // (+10 ft from level 5).
    check(with_stats(['level' => 2] + hero('Testf'))['speed'] === 40
        && with_stats(['level' => 14] + hero('Testf'))['speed'] === 55
        && with_stats(['level' => 5] + hero('Testc'))['speed'] === 40 && with_stats(hero('Board'))['speed'] === 30,
        'Unarmored Movement (+25 ft by level 14) and Fast Movement');

    // Spell ranges: a touch spell closes in first.
    save_account('Mage', 'correct horse');
    create('Mage', 'wizard');
    q("UPDATE users SET room_id = 9, x = 0, y = 2 WHERE name = 'Mage'");
    q('UPDATE spawns SET x = 6, y = 2, placed_at = NOW() WHERE id = 9901');
    $dice_queue = [15, 1, 12, 4, 5]; // Initiative; I close 25 ft and touch it: 16 hits for 4; Slam 8 misses
    $out = run_command(hero('Mage'), 'cast shocking dummy');
    check(says($out, 'You close in on the Training Dummy.') && hero('Mage')['x'] === 5 && $dice_queue === [],
        'a touch spell closes in first');
    q('UPDATE users SET ' . END_FIGHT . " WHERE name = 'Mage'");

    // Hirelings on the board (task 4c). Board (Longbow +4, 1d8+2; AC 17) hires a Guard (AC 16, 11 HP, Spear +3 for
    // 1d6+1, thrown 20/60 ft; Speed 30). It starts beside me, nearest my foe, and by default closes in and fights it.
    $id = hero('Board')['id'];
    $hire = fn ($monster, $hp) => q('UPDATE users SET hireling_monster_id = ?, hireling_hp = ?, hireling_order = NULL,
        hireling_aid = FALSE, hired_until = NOW() + INTERVAL 1 DAY WHERE id = ?', [$monster, $hp, $id]);
    $its = fn () => array_values(q('SELECT hireling_x, hireling_y FROM users WHERE id = ?', [$id])->fetch());
    $hire(32, 11);
    q('UPDATE users SET ' . END_FIGHT . ', x = 0, y = 2, hp = max_hp, advantage = FALSE, stealth = NULL WHERE id = ?',
        [$id]);
    q("UPDATE spawns SET x = 7, y = 2, placed_at = NOW(), conditions = '', grappled_by = NULL, hp = 60
        WHERE id = 9901");
    $dice_queue = [15, 1, 12, 4, 12, 3, 5]; // Initiative; my arrow hits; the Guard walks 25 ft and hits for 4; Slam 8
    $out = run_command(hero('Board'), 'kill dummy');

    check(says($out, 'Your Guard closes in.') && says($out, "Your Guard's Spear hits the Training Dummy (15 vs AC 10)")
        && says($out, "The Training Dummy's Slam misses your Guard (8 vs AC 16).") && $its() === [6, 2]
        && $dice_queue === [], "it closes in and fights; held by it, the dummy can't reach me, so it fights the Guard");
    check(cmd_map(with_stats(hero('Board')))[3][7] === 'G', 'the board shows it, upper-case');
    check(says(run_command(hero('Board'), 'order guard'), 'Your Guard will stay by your side.')
        && hero('Board')['hireling_order'] === 'guard', 'order: a stance, at no cost');
    // It falls back beside me (the dummy's opportunity attack misses), throws its Spear from 30 ft (Disadvantage: two
    // 1s), and the dummy comes at us both; a d2 of 1 picks me, and its Slam misses.
    $dice_queue = [1, 5, 1, 1, 1, 5];
    $out = run_command(hero('Board'), 'kill');
    check(says($out, 'The Training Dummy strikes at your Guard as it pulls away!')
        && says($out, 'Your Guard falls back beside you.') && $its() === [1, 2]
        && says($out, "Your Guard's Spear misses the Training Dummy (natural 1, with Disadvantage).")
        && says($out, 'Slam misses you') && $dice_queue === [], 'guard: by my side, throwing its Spear from there');
    // Held fast elsewhere (grappled by Testa), the dummy can't come on; holding, the Guard stays put and throws.
    run_command(hero('Board'), 'order hold');
    q('UPDATE users SET fighting = 9901 WHERE id = ?', [hero('Testa')['id']]);
    q('UPDATE spawns SET x = 7, y = 2, grappled_by = ? WHERE id = 9901', [hero('Testa')['id']]);
    $dice_queue = [1, 1]; // its Spear: two 1s (Disadvantage past 20 ft)
    $out = run_command(hero('Board'), 'dodge');
    check($its() === [1, 2] && says($out, "can't move") && $dice_queue === [], 'hold: it stays where it is');
    q('UPDATE users SET fighting = NULL WHERE id = ?', [hero('Testa')['id']]);
    q('UPDATE spawns SET grappled_by = NULL, x = 6, y = 1 WHERE id = 9901');
    q('UPDATE users SET hireling_x = 3, hireling_y = 1 WHERE id = ?', [$id]);
    // From 15 ft its Spear hits for 4; then the dummy walks past it (its opportunity attack hits for 4) to me, Dodging.
    $dice_queue = [12, 3, 12, 3, 5, 5];
    $out = run_command(hero('Board'), 'dodge');
    check(says($out, 'Your Guard strikes as the Training Dummy goes past!')
        && says($out, 'misses you (8 vs AC 17, with ') && $dice_queue === [], 'it makes opportunity attacks too');

    // A Mastiff (Bite +3 for 1d6+1, Speed 40) knocks a Medium or smaller foe Prone. Standing costs the dummy half its
    // Speed (Slowed too: 10 ft), still enough to step next to me and stay beside the Mastiff; a d2 of 1 picks me.
    $hire(68, 5);
    q('UPDATE users SET ' . END_FIGHT . ', x = 0, y = 2 WHERE id = ?', [$id]);
    q("UPDATE spawns SET x = 2, y = 2, placed_at = NOW(), conditions = '' WHERE id = 9901");
    $dice_queue = [15, 1, 12, 4, 12, 3, 1, 5];
    $out = run_command(hero('Board'), 'kill dummy');
    check(says($out, "Your Mastiff's Bite hits the Training Dummy (15 vs AC 10) for 4 damage.")
        && says($out, 'The Training Dummy goes down, Prone.') && says($out, 'gets back up')
        && says($out, 'Slam misses you') && $dice_queue === [], "a Mastiff's Bite knocks it Prone");

    // A Priest Acolyte (Mace +4; Radiant Flame +4 for 2d6, 60 ft; AC 13, 11 HP) stays by me and casts. Once a long rest
    // its Divine Aid heals me (Healing Word, 2d4 + 2) when I'm at half HP or less, or blesses or wards me on order.
    $hire(36, 11);
    q('UPDATE users SET ' . END_FIGHT . ', x = 0, y = 2, hp = 3 WHERE id = ?', [$id]);
    q("UPDATE spawns SET x = 7, y = 2, placed_at = NOW(), conditions = '' WHERE id = 9901");
    $dice_queue = [15, 1, 1, 2, 3, 12, 3, 3, 1, 5]; // my natural 1; Healing Word 2+3+2; Radiant Flame 6; d2 1; Slam 8
    $out = run_command(hero('Board'), 'kill dummy');
    check(says($out, 'Your Priest Acolyte casts Healing Word on you: +7 HP.') && hero('Board')['hp'] === 10
        && says($out, "Your Priest Acolyte's Radiant Flame hits the Training Dummy (16 vs AC 10) for 6 damage.")
        && $its() === [1, 2] && hero('Board')['hireling_aid'] === 1 && $dice_queue === [],
        'the acolyte stays by me, heals me when I am hurt, and casts Radiant Flame');
    check(says(run_command(hero('Board'), 'order bless'), 'used its Divine Aid'), 'Divine Aid: once a long rest');
    q('UPDATE users SET hireling_aid = FALSE WHERE id = ?', [$id]);
    check(says(run_command(hero('Board'), 'order bless'), 'Your Priest Acolyte blesses you')
        && says(run_command(hero('Board'), 'score'), 'Bless'), 'order bless: Bless on me, a d4 on attacks and saves');
    q('UPDATE users SET hireling_aid = FALSE WHERE id = ?', [$id]);
    q("DELETE FROM effects WHERE user_id = ?", [$id]);
    check(says(run_command(hero('Board'), 'order sanctuary'), 'Sanctuary'), 'order sanctuary');
    // Its Mace misses; the dummy's d2 picks me, its Wis save fails against Sanctuary, so it turns on the acolyte.
    $dice_queue = [1, 1, 5, 5];
    $out = run_command(hero('Board'), 'dodge');
    check(says($out, "can't bring itself to attack you (Sanctuary, Wis save 5 vs DC 12)")
        && says($out, "Slam misses your Priest Acolyte") && $dice_queue === [], 'Sanctuary turns its attack aside');
    $dice_queue = [1, 1, 1, 1, 5]; // my shot next to it (two 1s), the Mace a 1; a d2 of 1 picks me, Slam 8
    check(says(run_command(hero('Board'), 'kill'), 'Your Sanctuary ends.') && $dice_queue === [],
        'and it ends when I attack');
    check(says(run_command(hero('Board'), 'order heal'), 'used its Divine Aid'), 'still once a long rest');
    q('UPDATE users SET ' . END_FIGHT . ', hireling_monster_id = NULL, hireling_hp = NULL, hired_until = NULL
        WHERE id = ?', [$id]);
    check(says(run_command(hero('Board'), 'order attack'), 'You have no hireling.'), 'order needs a hireling');

    // Battle maps (task 6). Rooms without a layout get one from their terrain, the same every time, with every open
    // square reachable; underground ones are caves whatever Riftshadow said.
    $connected = function (array $b): bool {
        $open = [];
        foreach ($b as $y => $row) {
            foreach (str_split($row) as $x => $ch) {
                if (!in_array($ch, ['#', '%'], true)) {
                    $open["$x,$y"] = true;
                }
            }
        }
        $seen = [array_key_first($open) => true];
        for ($todo = [array_key_first($open)]; $todo;) {
            [$x, $y] = array_map('intval', explode(',', array_pop($todo)));
            foreach (STEPS as [$dx, $dy]) {
                $k = ($x + $dx) . ',' . ($y + $dy);
                if (isset($open[$k]) && !isset($seen[$k])) {
                    [$seen[$k], $todo[]] = [true, $k];
                }
            }
        }
        return count($seen) === count($open);
    };
    $wood = board(6002);
    check($wood === board(6002) && $wood !== board(6003) && count($wood) === 10 && strlen($wood[0]) === 16
        && str_contains(implode('', $wood), '%') && $connected($wood), 'a forest board: trees, the same each time');
    check(!str_contains(implode('', board(7030)), '%') && str_contains(implode('', board(24600)), '#')
        && !str_contains(implode('', board(24600)), '%') && $connected(board(24600)),
        "underground boards are caves': rock and rubble, no trees, even in the Sewers");
    check(board(1)[0][4] === '.' && board(1)[5][4] === '.' && board(1)[2][0] === '.' && board(1)[2][7] === '.',
        'squares by the exits stay open');

    // A hand-drawn board: rock (#) across the middle with a gap, a tree (%) and undergrowth (,). A letter marks where
    // a monster starts.
    q('UPDATE rooms SET layout = ? WHERE id = 9', [implode("\n", [
        '..,,,...',
        '...#....',
        '...#..%.',
        '...#....',
        '.......k',
        '........',
    ])]);
    check(board(9)[4] === '........' && sight(9, [0, 2], [6, 2]) === null && sight(9, [0, 0], [7, 0]) === 0
        && sight(9, [4, 2], [7, 2]) === 2 && sight(9, [4, 3], [7, 3]) === 0,
        'line of sight: rock blocks it, a tree gives half cover (+2 AC)');
    q('UPDATE users SET ' . END_FIGHT . ", room_id = 9, x = 0, y = 2, hp = max_hp, advantage = FALSE,
        hireling_monster_id = NULL, hired_until = NULL WHERE name = 'Board'");
    q("UPDATE spawns SET room_id = 9, x = NULL, y = NULL, conditions = '', grappled_by = NULL, hp = 60
        WHERE id = 9901");
    run_command(hero('Board'), 'equip longbow');
    q('UPDATE users SET fighting = 9901 WHERE id = ?', [hero('Testa')['id']]); // Testa holds it, so it can't move
    q('UPDATE spawns SET grappled_by = ? WHERE id = 9901', [hero('Testa')['id']]);
    // Initiative; no line to it from a3, so I step out to a2 and shoot: 12 + 4 is 16 against its AC 10 + 2 (half cover)
    $dice_queue = [15, 1, 12, 4];
    $out = run_command(hero('Board'), 'shoot dummy');
    check($at() === [7, 4] && hero('Board')['x'] . ',' . hero('Board')['y'] !== '0,2'
        && says($out, '(16 vs AC 10)') && $dice_queue === [],
        "it starts where the board's letter says; with no clear shot I move to get one");
    q('UPDATE users SET x = 4, y = 2 WHERE id = ?', [hero('Board')['id']]);
    q('UPDATE spawns SET x = 7, y = 2 WHERE id = 9901');
    $dice_queue = [7]; // 7 + 4 is 11: it would hit AC 10, but the tree makes it 12
    $out = run_command(hero('Board'), 'shoot');
    check(says($out, 'The Training Dummy has half cover (+2 AC).')
        && says($out, 'You miss the Training Dummy (11 vs AC 12)') && $dice_queue === [],
        'half cover: +2 AC against a shot past a tree');
    q('UPDATE users SET fighting = NULL WHERE id = ?', [hero('Testa')['id']]);
    q('UPDATE spawns SET grappled_by = NULL WHERE id = 9901');
    // Moving: around the rock (a3 to f3 is 30 ft, by d5 and e5, cutting no corners), and undergrowth costs double.
    q("UPDATE users SET x = 0, y = 2, moved = NULL, foe_first = FALSE WHERE name = 'Board'");
    q('UPDATE spawns SET x = 7, y = 5 WHERE id = 9901');
    $out = run_command(hero('Board'), 'move f3');
    check(says($out, 'You move to f3') && hero('Board')['moved'] === 30, 'a path around the rock');
    q("UPDATE users SET x = 0, y = 0, moved = 0 WHERE name = 'Board'");
    check(says(run_command(hero('Board'), 'move e1'), 'and e1 is 35 ft away')
        && says(run_command(hero('Board'), 'move d4'), 'blocked'), 'undergrowth costs double; rock is no square');
    q('UPDATE users SET ' . END_FIGHT . " WHERE name = 'Board'");

    // Several monsters (task 7a): every aggressive one in the room joins the fight, each with its Initiative. Two
    // dummies (AC 10, Slam +3) in the Armorer, on an open board; Board shoots a Longbow (+4, 1d8+2; Dex +2).
    q('UPDATE rooms SET layout = ? WHERE id = 9', [$open(8, 6)]);
    q('INSERT INTO spawns (id, room_id, monster_id, hp, max_hp) VALUES (9902, 9, 9999, 30, 30)');
    $fresh = function (): void {
        q('UPDATE users SET ' . END_FIGHT . ", room_id = 9, x = 0, y = 2, hp = max_hp, advantage = FALSE
            WHERE name = 'Board'");
        q("UPDATE spawns SET x = NULL, y = NULL, init = NULL, hp = 30, dead_since = NULL, conditions = '',
            grappled_by = NULL WHERE id IN (9901, 9902)");
    };
    $fresh();
    q('UPDATE monsters SET speed = 0 WHERE id = 9999'); // so neither moves, for now
    $dice_queue = [15, 1, 12, 4]; // Initiative 17 vs 1; my arrow hits; it can't move
    $out = run_command(hero('Board'), 'kill dummy');
    check(!says($out, 'joins the fight') && count(array_filter($out, fn ($l) => str_contains($l, "can't move"))) === 1
        && $dice_queue === [], 'a peaceful monster stays out of it');
    $fresh();
    q('UPDATE monsters SET aggressive = TRUE WHERE id = 9999');
    $dice_queue = [15, 1, 1, 12, 4]; // Initiative: me 17, my target 1, the other 1; my arrow hits; neither can move
    $out = run_command(hero('Board'), 'kill dummy');
    $map = cmd_map(with_stats(hero('Board')));
    check(says($out, 'The Training Dummy 2 joins the fight (Initiative 1).')
        && count(array_filter($out, fn ($l) => str_contains($l, "can't move"))) === 2
        && says($out, 'Training Dummy: 24 HP.  Training Dummy 2: 30 HP.') && $dice_queue === []
        && str_contains($map[count($map) - 2], 'Training Dummy 2: '), 'an aggressive one joins, and both show');
    q('UPDATE monsters SET speed = 30 WHERE id = 9999');
    // The second dummy is next to me and its Initiative (30) beats mine: it acts first, and stepping away from it
    // draws its opportunity attack though I'm not fighting it.
    q("UPDATE users SET x = 3, y = 2, moved = NULL, foe_first = FALSE WHERE name = 'Board'");
    q("UPDATE spawns SET x = 7, y = 2, conditions = '' WHERE id = 9901");
    q("UPDATE spawns SET x = 2, y = 2, init = 30, conditions = '' WHERE id = 9902");
    $dice_queue = [5, 5]; // its Slam, 8, misses; its opportunity attack, 8, misses
    $out = run_command(hero('Board'), 'move e3');
    check(says($out, 'The Training Dummy 2 strikes as you pull away!') && $dice_queue === [],
        'any monster in the fight takes its opportunity attack');
    // A round in Initiative order: the second dummy (30), me (17), my target (1).
    q("UPDATE users SET x = 0, y = 2, moved = NULL WHERE name = 'Board'");
    q("UPDATE spawns SET x = 1, y = 2, conditions = '' WHERE id = 9902");
    $dice_queue = [5, 12, 15, 4]; // its Slam misses; my shot next to it has Disadvantage (12); Slowed, mine runs
    $out = run_command(hero('Board'), 'kill');
    $first = array_search(true, array_map(fn ($l) => str_contains($l, "Training Dummy 2's Slam"), $out), true);
    $shot = array_search(true, array_map(fn ($l) => str_contains($l, 'You hit'), $out), true);
    $last = array_search(true, array_map(fn ($l) => str_contains($l, 'Training Dummy runs at you'), $out), true);
    check($first !== false && $shot !== false && $last !== false && $first < $shot && $shot < $last
        && says($out, '(16 vs AC 10, with Disadvantage)') && $dice_queue === [], 'each acts in Initiative order');
    // Naming one fights it instead; killing it turns me to the other, and the fight goes on.
    q("UPDATE users SET x = 0, y = 2 WHERE name = 'Board'");
    q("UPDATE spawns SET x = 6, y = 5, conditions = '' WHERE id = 9901");
    q("UPDATE spawns SET x = 1, y = 2, hp = 1, conditions = '' WHERE id = 9902");
    $dice_queue = [5, 12, 15, 4, 3, 2, 5]; // its Slam; I kill it (gold 3, no loot); mine closes in, Slam misses
    $out = run_command(hero('Board'), 'kill dummy 2');
    check(says($out, 'The Training Dummy 2 dies!') && says($out, 'You turn to the Training Dummy.')
        && hero('Board')['fighting'] === 9901 && $dice_queue === [], 'kill <monster> 2; then on to the next');
    q("UPDATE spawns SET hp = 1 WHERE id = 9901");
    $dice_queue = [12, 15, 4, 3, 2]; // next to it: Disadvantage, 12 hits; gold 3, no loot
    $out = run_command(hero('Board'), 'kill');
    check(says($out, 'The Training Dummy dies!') && hero('Board')['fighting'] === null && $dice_queue === [],
        'the fight ends when the last one dies');
    q('UPDATE monsters SET aggressive = FALSE WHERE id = 9999');
    q('UPDATE users SET ' . END_FIGHT . " WHERE name = 'Board'");

    // Shared fights (task 7b). Board (Longbow, Initiative 17 here) fights the dummy; Mage (a Wizard: AC 12, Fire Bolt
    // +4, 1d10) walks in and joins. Turns go in one order, a turn waits for a hero who's here, and an away hero Dodges.
    $fresh();
    q('UPDATE monsters SET speed = 0 WHERE id = 9999');
    q('UPDATE spawns SET dead_since = NOW() WHERE id = 9902');
    q("UPDATE users SET room_id = 4, hp = max_hp, xp = 0, gold = 0 WHERE name = 'Mage'");
    q("UPDATE users SET xp = 0, gold = 0 WHERE name = 'Board'");
    $dice_queue = [15, 1, 12, 4]; // Initiative 17 vs 1; my arrow hits for 6; it can't move
    run_command(hero('Board'), 'kill dummy');
    $dice_queue = [11]; // Mage's Initiative: 12
    $out = run_command(hero('Mage'), 's');
    check(says($out, 'You join the fight (Initiative 12).') && hero('Mage')['fighting'] === 9901
        && $dice_queue === [], 'a hero who walks into a fight joins it');
    check(says(run_command(hero('Mage'), 'cast fire bolt'), "It's Board's turn (") && $dice_queue === [],
        'a turn waits for the hero whose it is');
    $dice_queue = [12, 4];
    run_command(hero('Board'), 'kill');
    $out = run_command(hero('Mage'), '');
    check(says($out, 'Board hits the Training Dummy (16 vs AC 10) for 6 damage.'),
        "the others hear each turn, told from their side");
    $grid = board_map(with_stats(hero('Board')));
    $mage = hero('Mage');
    check($grid[$mage['y'] + 1][$mage['x'] + 1] === 'M', "the board shows the other heroes by initial");
    check(third_person("You're hurt. The Kobold strikes as you pull away! Your Fire Bolt misses you.", 'Ana', 'Bo')
        === "Ana is hurt. The Kobold strikes as Ana pulls away! Ana's Fire Bolt misses Ana."
        && third_person("You catch Bo's arrow and fly.", 'Ana', 'Bo') === "Ana catches your arrow and fly."
        && third_person('You: 7/7 HP.', 'Ana') === 'Ana: 7/7 HP.', 'fight lines retold in the third person');
    // The dummy can move again, and stands by Mage: on its turn it goes for the nearest hero.
    q('UPDATE monsters SET speed = 30 WHERE id = 9999');
    q("UPDATE users SET x = 4, y = 0 WHERE name = 'Mage'");
    q('UPDATE spawns SET x = 5, y = 1 WHERE id = 9901');
    $dice_queue = [15, 15, 5, 5]; // next to it: Disadvantage, 19 hits for 5; its Slam at me, 8, misses
    $out = run_command(hero('Mage'), 'cast fire bolt');
    check(says($out, 'Your Fire Bolt hits the Training Dummy') && says($out, "The Training Dummy's Slam misses you")
        && $dice_queue === [], 'a monster attacks the nearest hero');
    check(says(run_command(hero('Board'), ''), "The Training Dummy's Slam misses Mage"), "and the others hear it");
    // Board's page is open, but Board's 2 minutes are up: Mage's next command passes Board's turn as a Dodge.
    q('UPDATE rooms SET turn_at = NOW() - INTERVAL 121 SECOND WHERE id = 9');
    $dice_queue = [1, 1]; // Mage Dodges; its Slam misses (with Disadvantage)
    $out = run_command(hero('Mage'), 'dodge');
    check(says($out, "Board's 2 minutes are up, so Board takes the Dodge action.") && says($out, 'You Dodge')
        && $dice_queue === [], "a turn passes after 2 minutes");
    // Board's page is closed, so on Mage's next command Board's turn passes as a Dodge. Mage's Fire Bolt kills it: the
    // XP (25) and gold (4) are split.
    q("UPDATE users SET last_seen = NOW() - INTERVAL 1 MINUTE WHERE name = 'Board'");
    q('UPDATE spawns SET hp = 1 WHERE id = 9901');
    $dice_queue = [15, 15, 5, 4, 2]; // Fire Bolt hits and kills; gold 4, no loot
    $out = run_command(hero('Mage'), 'cast fire bolt');
    check(says($out, 'Board is away, so Board takes the Dodge action.') && says($out, 'You gain 12 XP and 2 gold.')
        && hero('Board')['xp'] === 12 && hero('Board')['gold'] === 2 && hero('Mage')['xp'] === 12
        && hero('Board')['fighting'] === null && hero('Mage')['fighting'] === null && $dice_queue === [],
        "an away hero Dodges; XP and gold are split among the heroes in the fight");
    q('UPDATE monsters SET speed = 30 WHERE id = 9999');

    // Choices and skills.
    save_account('Pixie', 'correct horse');
    create('Pixie', 'rogue', 'ok', 'human skilled', 'criminal');  // Criminal: Sleight of Hand, Stealth
    $pid = hero('Pixie')['id'];
    q('DELETE FROM picks WHERE user_id = ?', [$pid]);  // create() has made them; these checks make them by hand
    $o = owed_choices(hero('Pixie'));
    check(array_column($o, 'id') === ['class skills', 'skilled', 'expertise 1'] && $o[0]['pick'] === 4
        && $o[0]['locked'] === ['sleight of hand', 'stealth'] && count($o[0]['options']) === 10,
        'a Rogue owes 4 class skills, Skilled and Expertise; background skills locked');
    foreach (['acrobatics', 'insight', 'perception', 'investigation'] as $s) {
        q("INSERT INTO picks VALUES (?, 'class skills', ?)", [$pid, $s]);
    }
    $o = owed_choices(hero('Pixie'));
    check($o[0]['id'] === 'skilled' && in_array('perception', $o[0]['locked'], true), 'Skilled locks what I have');
    foreach (['athletics', 'history', 'nature'] as $s) {
        q("INSERT INTO picks VALUES (?, 'skilled', ?)", [$pid, $s]);
    }
    $o = owed_choices(hero('Pixie'));
    check($o[0]['id'] === 'expertise 1' && count($o[0]['options']) === 9 && !in_array('arcana', $o[0]['options'], true),
        'Expertise offers only skills I am proficient in');
    $p = with_stats(hero('Pixie'));
    $dex = mod($p['dexterity']);
    check(skill_bonus($p, 'stealth') === $dex + 2 && skill_bonus($p, 'acrobatics') === $dex + 2,
        'proficient: + Proficiency Bonus');
    check(skill_bonus($p, 'arcana') === mod($p['intelligence']), 'not proficient: the modifier alone');
    q("INSERT INTO picks VALUES (?, 'expertise 1', 'stealth'), (?, 'expertise 1', 'perception')", [$pid, $pid]);
    check(skill_bonus(with_stats(hero('Pixie')), 'stealth') === $dex + 4 && owed_choices(hero('Pixie')) === [],
        'Expertise doubles it, and nothing is owed');
    q('UPDATE users SET level = 6 WHERE id = ?', [$pid]);
    check(array_column(owed_choices(hero('Pixie')), 'id') === ['expertise 2', 'feat 4'],
        'Rogue 6 owes a second Expertise, then its level 4 feat');
    save_account('Lute', 'correct horse');
    create('Lute', 'bard');
    q("UPDATE users SET level = 2 WHERE name = 'Lute'");
    $b = with_stats(hero('Lute'));
    check(skill_bonus($b, 'survival') === mod($b['wisdom']) + 1, 'Jack of All Trades: half the bonus, rounded down');
    save_account('Vow', 'correct horse');
    create('Vow', 'cleric', 'ok', 'human alert', 'acolyte');  // Acolyte: Insight, Religion
    q('DELETE FROM picks WHERE user_id = ?', [hero('Vow')['id']]);
    $o = owed_choices(hero('Vow'));
    check($o[0]['pick'] === 2 && $o[0]['locked'] === ['insight', 'religion'], 'Cleric + Acolyte still picks 2');
    save_account('Chs', 'correct horse');
    create('Chs', 'fighter');
    check(hero('Chs')['setup'] === null && count(picks(hero('Chs'))['class skills'] ?? []) === 2,
        'create() makes every owed choice');
    q('DELETE FROM picks WHERE user_id = ?', [hero('Chs')['id']]);
    check(says(run_command(hero('Chs'), 'choose athletics, perception'), 'Rest first (a short rest takes 1 minute).'),
        'outside creation, choosing waits for a rest');
    check(!says(run_command(hero('Chs'), 'look'), 'Choose'), 'and nothing blocks play meanwhile');
    q("UPDATE users SET may_choose = TRUE WHERE name = 'Chs'");
    check(says(run_command(hero('Chs'), 'choose athletics'), 'Choose exactly 2.'), 'a wrong count is refused');
    check(says(run_command(hero('Chs'), 'choose athletics, arcana'), "Arcana isn't one of them."),
        'a skill off the list is refused');
    check(says(run_command(hero('Chs'), 'choose in, athletics'),
        "'in' could be insight, intimidation or investigation."), 'an ambiguous prefix is refused');
    $out = run_command(hero('Chs'), 'choose Animal Handling,ATHLETICS');
    check(says($out, 'You choose Animal Handling and Athletics.') && hero('Chs')['may_choose'] === 0,
        'case, commas and two-word names parse; may_choose clears when nothing is left');
    check(says(run_command(hero('Chs'), 'choose animal handling, athletics'), 'You have nothing to choose.')
        && count(picks(hero('Chs'))['class skills']) === 2, 'a second Confirm changes nothing');
    check(parse_skills('sleight stealth') === ['sleight of hand', 'stealth'], 'prefixes parse');
    $live = (int) q('SELECT id FROM spawns WHERE dead_since IS NULL LIMIT 1')->fetchColumn();
    q("UPDATE users SET may_choose = TRUE, fighting = ? WHERE name = 'Chs'", [$live]);
    check(says(cmd_choose(with_stats(hero('Chs')), ''), 'Not in the middle of a fight.'), 'no choosing mid-fight');
    q("UPDATE users SET fighting = NULL, may_choose = FALSE WHERE name = 'Chs'");
    save_account('Newb', 'correct horse');
    foreach (['Newb', 'rogue', 'ok', 'dwarf', 'criminal'] as $step) {
        $out = run_command(hero('Newb'), $step);
    }
    check(says($out, 'Rogue skills') && says($out, '(yours)'), 'creation asks for skills, background ones marked');
    check(says(run_command(hero('Newb'), 'begin'), 'Choose your skills first.'), 'begin waits for them');
    check(says(run_command(hero('Newb'), 'choose stealth, acrobatics, insight, perception'),
        'You already have Stealth.'), 'a locked skill is refused');
    run_command(hero('Newb'), 'choose acrobatics, insight, perception, investigation');
    foreach (['back', 'rogue', 'ok'] as $step) {
        run_command(hero('Newb'), $step);
    }
    check(picks(hero('Newb')) === [], 'back clears picks');
    q("UPDATE users SET xp = 950, level = 1, hp = max_hp, room_id = 1, resting = 'short',
       rest_until = NOW() + INTERVAL 1 MINUTE WHERE name = 'Chs'");
    run_command(hero('Chs'), 'north'); // leaving cancels the rest
    check(hero('Chs')['level'] === 1, 'a cancelled rest brings no level');
    q("UPDATE users SET room_id = 1, resting = 'short', rest_until = NOW() WHERE name = 'Chs'");
    $out = run_command(hero('Chs'), '');
    check(hero('Chs')['level'] === 3 && says($out, 'You reach level 3!'), 'two levels on one rest');
    q("UPDATE users SET level = 7, hp = max_hp, resting = 'short', rest_until = NOW() WHERE name = 'Chs'");
    run_command(hero('Chs'), '');
    check(hero('Chs')['level'] === 7, 'a level set by hand never drops');
    q("UPDATE users SET level = 5, xp = 14000, hp = max_hp, resting = 'short', rest_until = NOW()
       WHERE name = 'Pixie'");
    $out = run_command(hero('Pixie'), '');
    check(hero('Pixie')['level'] === 6 && hero('Pixie')['may_choose'] === 1 && says($out, "type 'choose'"),
        'a level that owes a choice sets may_choose');
    $s = hero_sheet(with_stats(hero('Pixie')));
    check($s['owed'] && $s['may_choose'] && !$s['level_ready'], 'the sheet knows a choice is owed and may be made');
    q("UPDATE users SET xp = 23000 WHERE name = 'Pixie'");
    $score = cmd_score(with_stats(hero('Pixie')));
    check(hero_sheet(with_stats(hero('Pixie')))['level_ready'] && says($score, 'Level up ready: short rest'),
        'a level waiting shows on the sheet and in score');
    check(says($score, 'Skills  ') && says($score, 'Stealth +') && says($score, '*'),
        'score lists skills, Expertise marked');
    save_account('Pan', 'correct horse');
    run_command(hero('Pan'), 'Pan');
    check(panel(hero('Pan'))['id'] === 'class' && count(panel(hero('Pan'))['groups'][0]['options']) === 12,
        'the class panel lists all 12');
    run_command(hero('Pan'), 'rogue');
    check(panel(hero('Pan'))['assign']['picked'] === CLASSES['rogue']['scores'], 'the Standard Array panel');
    run_command(hero('Pan'), 'ok');
    $p = panel(hero('Pan'));
    check($p['id'] === 'species' && in_array('dragonborn', array_column($p['groups'], 'for'), true),
        "species' kinds hang off their species");
    run_command(hero('Pan'), 'dwarf');
    $soldier = array_values(array_filter(panel(hero('Pan'))['groups'], fn ($g) => ($g['for'] ?? '') === 'soldier'))[0];
    check(count($soldier['options']) === 7 && count(array_filter(array_column($soldier['options'], 'picked'))) === 1,
        'background boosts: all or a pair, the best two picked');
    run_command(hero('Pan'), 'criminal');
    $p = panel(hero('Pan'));
    check($p['id'] === 'class skills' && $p['command'] === 'choose ' && $p['groups'][0]['pick'] === 4
        && count(array_filter(array_column($p['groups'][0]['options'], 'locked'))) === 2 && ($p['back'] ?? false),
        'the skills panel, background skills locked, Back while creating');
    q('DELETE FROM picks WHERE user_id = ?', [hero('Chs')['id']]);
    q("UPDATE users SET may_choose = TRUE, fighting = ? WHERE name = 'Chs'", [$live]);
    check(panel(hero('Chs')) === null, 'no panel over a fight');
    q("UPDATE users SET fighting = NULL WHERE name = 'Chs'");
    check(panel(hero('Chs'))['id'] === 'class skills' && !isset(panel(hero('Chs'))['back']),
        'a rested hero with a choice gets the panel, without Back');

    // Ability Score Improvements and Grappler (task 9). Asi: Str 15 Dex 14 Con 13 Int 8 Wis 10 Cha 12.
    save_account('Asi', 'correct horse');
    create('Asi', 'fighter');
    $aid = hero('Asi')['id'];
    $feats = fn (array $me) => array_values(preg_grep('/^feat/', array_column(owed_choices($me), 'id')));
    check($feats(['level' => 3] + hero('Asi')) === [], 'no feat before level 4');
    check($feats(['level' => 14] + hero('Asi')) === ['feat 4', 'feat 6', 'feat 8', 'feat 12', 'feat 14'],
        'a Fighter 14 owes feats at 4, 6, 8, 12 and 14');
    check($feats(['level' => 10] + hero('Pixie')) === ['feat 4', 'feat 8', 'feat 10'], 'a Rogue 10: 4, 8 and 10');
    check($feats(['level' => 19] + hero('Lute')) === ['feat 4', 'feat 8', 'feat 12', 'feat 16'],
        "a Bard 19: 4, 8, 12 and 16 (the Epic Boon waits)");
    q('UPDATE users SET level = 14 WHERE id = ?', [$aid]);
    check(says(run_command(hero('Asi'), 'choose str'), 'Rest first'), 'a feat waits for a rest too');
    q('UPDATE users SET may_choose = TRUE WHERE id = ?', [$aid]);
    check(owed_choices(hero('Asi'))[0]['options'] === ['asi', 'grappler'], 'Str 13 or more: Grappler is offered');
    check(says(run_command(hero('Asi'), 'choose'), "choose str, dex"), 'choose shows the feat choice');
    check(says(run_command(hero('Asi'), 'choose str, dex, con'), 'one ability for +2 or two for +1'),
        'three abilities are refused');
    check(says(run_command(hero('Asi'), 'choose grappler int'), 'Grappler raises Strength or Dexterity'),
        'Grappler raises only Str or Dex');
    check(says(run_command(hero('Asi'), 'choose bogus'), "There's no ability 'bogus'."), 'an unknown word is refused');
    $p = panel(hero('Asi'));
    check($p['id'] === 'feat 4' && $p['command'] === 'choose ' && array_column($p['groups'][0]['options'], 'key')
        === ['asi', 'grappler'] && $p['groups'][1]['for'] === 'asi' && $p['groups'][1]['pick'] === 2
        && $p['groups'][1]['min'] === 1 && count($p['groups'][1]['options']) === 6
        && $p['groups'][2]['for'] === 'grappler' && array_column($p['groups'][2]['options'], 'key') === ['str', 'dex'],
        'the feat panel: ASI with 1 or 2 of six abilities, or Grappler with Str or Dex');
    $out = run_command(hero('Asi'), 'choose asi str');
    check(says($out, 'Ability Score Improvement: Strength 17.') && hero('Asi')['strength'] === 17
        && picks(hero('Asi'))['feat 4'] === ['asi str'], 'one ability: +2');
    $was = hero('Asi');
    $out = run_command(hero('Asi'), 'choose con, wis');
    $now = hero('Asi');
    check(says($out, 'Constitution 14 and Wisdom 11') && $now['constitution'] === 14 && $now['wisdom'] === 11
        && $now['max_hp'] === $was['max_hp'] + 14 && $now['hp'] === $was['hp'] + 14,
        'two abilities: +1 each; a higher Con modifier adds 1 HP a level');
    q('UPDATE users SET strength = 20, dexterity = 12 WHERE id = ?', [$aid]);
    check(says(run_command(hero('Asi'), 'choose grappler str'), "Strength can't go above 20."), 'never above 20');
    check(!in_array('str', array_column(panel(hero('Asi'))['groups'][1]['options'], 'key'), true),
        'a 20 is left off the panel');
    run_command(hero('Asi'), 'choose grappler dex');
    check(has_feat(hero('Asi'), 'grappler') && hero('Asi')['dexterity'] === 13
        && owed_choices(hero('Asi'))[0]['options'] === ['asi'], 'Grappler is taken once');
    q('UPDATE users SET strength = 12 WHERE id = ?', [$aid]);
    check(array_column(owed_choices(['level' => 4, 'strength' => 8, 'dexterity' => 12] + hero('Lute')), 'options',
        'id')['feat 4'] === ['asi'],
        'Str and Dex under 13: no Grappler');
    run_command(hero('Asi'), 'choose wis');
    $out = run_command(hero('Asi'), 'choose cha');
    check(owed_choices(hero('Asi')) === [] && hero('Asi')['may_choose'] === 0, 'all made, may_choose clears');
    check(says(cmd_score(with_stats(hero('Asi'))), 'Feats  Ability Score Improvement (Str +2), Ability Score '
        . 'Improvement (Con +1, Wis +1), Grappler (Dex +1)'), 'score lists the feats');
    check(hero_sheet(with_stats(['level' => 16] + hero('Asi')))['owed'] === 'a feat'
        && hero_sheet(with_stats(hero('Asi')))['owed'] === null, "the sheet names what's owed");

    // Grappler in a fight. Asi, Fighter 14 with no weapon: three Unarmed Strikes, +8 to hit (Str 16, PB +5), 4
    // damage; a grapple's DC is 16.
    q('DELETE FROM objects WHERE user_id = ?', [$aid]);
    q("UPDATE users SET strength = 16, room_id = 2, x = 3, y = 2, fighting = NULL, hp = max_hp WHERE id = ?", [$aid]);
    q("UPDATE spawns SET room_id = 2, hp = 60, dead_since = NULL, x = 4, y = 2, placed_at = NOW(), conditions = '',
       grappled_by = NULL WHERE id = 9999");
    $a = with_stats(hero('Asi'));
    $foe = ['grappled_by' => $aid, 'conditions' => ''] + q('SELECT * FROM spawns WHERE id = 9999')->fetch();
    $dice_queue = [3, 15];
    check(attack_d20($a, $foe)[0] === 15, 'Attack Advantage against a foe I grapple');
    $foe['grappled_by'] = null;
    $dice_queue = [3, 15];
    check(attack_d20($a, $foe)[0] === 3 && $dice_queue === [15], 'and only against one I grapple');
    $dice_queue = [15, 1, 1, 10, 2, 4, 12, 6, 9, 2]; // initiative; a hit, its Str save fails; two with Advantage; Slam
    $out = run_command(hero('Asi'), 'kill dummy');
    check(says($out, 'Punch and Grab: you grab hold of the Training Dummy, Grappled (Str save 2 vs DC 16).')
        && count(array_filter($out, fn ($l) => str_contains($l, 'Punch and Grab'))) === 1
        && says($out, '(20 vs AC 10, with Advantage)') && says($out, '(17 vs AC 10, with Advantage)')
        && $dice_queue === [], 'Punch and Grab: an Unarmed Strike that hits also grapples, once a turn');
    leave('Asi');

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
    check(q('SELECT 1 FROM visited WHERE user_id = ? AND room_id = ?', [hero('Testa')['id'], DELVE_STAIR + 1])
        ->fetch() !== false, 'walking in records the room as explored');
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
    check(!q('SELECT 1 FROM visited WHERE room_id > ?', [DELVE_STAIR])->fetch(), 'and what was explored is forgotten');
} finally {
    db()->rollBack();
}
exit($failed ? 1 : 0);
