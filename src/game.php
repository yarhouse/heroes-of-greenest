<?php

declare(strict_types=1);

// game.php :: command parser and handlers. Handlers return lines of plain text; the client never renders HTML.

const DIRS = ['n' => 'north', 's' => 'south', 'e' => 'east', 'w' => 'west', 'u' => 'up', 'd' => 'down'];
const ONLINE_MINUTES = 10;
const ECHO_DAYS = 7;
const START_ROOM = 1;
const REGEN_SECS = 600; // out of combat, a hero heals from 0 to full HP in this long
const LOOT_CHANCE = 30; // a kill drops an item 1 time in this many, as in Dragon Knight
const MAX_TRINKETS = 3; // Dragon Knight's three drop slots
/** Every command, as usage => what it does. Drives help and the play page's autocomplete. */
const COMMANDS = [
    'look' => 'describe where you are (or l)',
    'north' => 'move north (or n)',
    'south' => 'move south (or s)',
    'east' => 'move east (or e)',
    'west' => 'move west (or w)',
    'up' => 'move up (or u)',
    'down' => 'move down (or d)',
    'kill <monster>' => 'attack; one round per command (or k)',
    'flee' => 'try to escape a fight to a random exit',
    'score' => 'your level, HP and stats',
    'inventory' => 'what you carry and wear (or i)',
    'get <item>' => 'pick something up',
    'drop <item>' => 'put something down',
    'equip <item>' => 'wield or wear something you carry',
    'say <text>' => "speak to the room ('<text> works too)",
    'who' => 'every hero, and when last seen',
    'help' => 'this list',
    'quit' => 'log out',
];

/** Runs one command for $me (a users row) and returns the output lines. */
function run_command(array $me, string $input): array
{
    q('UPDATE users SET last_seen = NOW() WHERE id = ?', [$me['id']]);
    // Worn gear adds to stats here, never in the users row. Attack and defense follow Dragon Knight:
    // strength + weapon, dexterity + armor + shield.
    $gear = q(
        'SELECT SUM(i.attack), SUM(i.defense), SUM(i.max_hp), SUM(i.strength), SUM(i.dexterity)
         FROM objects o JOIN items i ON i.id = o.item_id WHERE o.user_id = ? AND o.worn',
        [$me['id']]
    )->fetch(PDO::FETCH_NUM);
    [$attack, $defense, $max_hp, $strength, $dexterity] = array_map('intval', $gear);
    $me['max_hp'] = max(1, $me['max_hp'] + $max_hp);
    $me['strength'] = max(0, $me['strength'] + $strength);
    $me['dexterity'] = max(0, $me['dexterity'] + $dexterity);
    $me['attack'] = $me['strength'] + $attack;
    $me['defense'] = $me['dexterity'] + $defense;
    // Regen since HP last changed, frozen while fighting. Every write of hp or fighting resets hp_at.
    $me['hp'] = (int) q(
        'SELECT IF(fighting IS NULL, LEAST(?, hp + FLOOR(TIMESTAMPDIFF(SECOND, hp_at, NOW()) * ? / ?)), hp)
         FROM users WHERE id = ?',
        [$me['max_hp'], $me['max_hp'], REGEN_SECS, $me['id']]
    )->fetchColumn();
    $input = trim(mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $input) ?? '', 0, 300));
    if (str_starts_with($input, "'")) {
        $input = 'say ' . substr($input, 1);
    }
    [$verb, $arg] = array_pad(preg_split('/\s+/', $input, 2), 2, '');
    $verb = strtolower($verb);
    $verb = DIRS[$verb] ?? $verb;

    $heard = heard_since_last_command($me);
    $out = match (true) {
        $verb === '' => [],
        in_array($verb, DIRS, true) => cmd_move($me, $verb),
        $verb === 'l' || $verb === 'look' => cmd_look($me),
        $verb === 'k' || $verb === 'kill' => cmd_kill($me, trim($arg)),
        $verb === 'flee' => cmd_flee($me),
        $verb === 'score' => cmd_score($me),
        $verb === 'i' || $verb === 'inventory' => cmd_inventory($me),
        $verb === 'get' => cmd_get($me, trim($arg)),
        $verb === 'drop' => cmd_drop($me, trim($arg)),
        $verb === 'equip' => cmd_equip($me, trim($arg)),
        $verb === 'say' => cmd_say($me, trim($arg)),
        $verb === 'who' => cmd_who(),
        $verb === 'help' => cmd_help(),
        default => ["Huh? Type 'help' for commands."],
    };
    // look already lists what was said in the room, so don't repeat it.
    $shows_room = $verb === 'l' || $verb === 'look' || in_array($verb, DIRS, true);
    return $shows_room ? $out : array_merge($heard, $out);
}

/** What others said in my room since my last command. Advances my read pointer either way. */
function heard_since_last_command(array $me): array
{
    $max = (int) q('SELECT COALESCE(MAX(id), 0) FROM events')->fetchColumn();
    $rows = q(
        'SELECT u.name, e.text FROM events e JOIN users u ON u.id = e.user_id
         WHERE e.room_id = ? AND e.id > ? AND e.id <= ? AND e.user_id <> ? ORDER BY e.id',
        [$me['room_id'], $me['last_event_id'], $max, $me['id']]
    )->fetchAll();
    q('UPDATE users SET last_event_id = ? WHERE id = ?', [$max, $me['id']]);
    return array_map(fn ($r) => "{$r['name']} says: {$r['text']}", $rows);
}

function cmd_look(array $me): array
{
    $room = q('SELECT name, description FROM rooms WHERE id = ?', [$me['room_id']])->fetch();
    $exits = q('SELECT dir FROM exits WHERE room_id = ? ORDER BY dir', [$me['room_id']])->fetchAll(PDO::FETCH_COLUMN);
    $lines = [$room['name'], $room['description'], 'Exits: ' . ($exits ? implode(' ', $exits) : 'none')];

    $others = q(
        'SELECT name FROM users WHERE room_id = ? AND id <> ? AND last_seen > NOW() - INTERVAL ? MINUTE ORDER BY name',
        [$me['room_id'], $me['id'], ONLINE_MINUTES]
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($others as $name) {
        $lines[] = "$name is here.";
    }
    foreach (room_monsters((int) $me['room_id']) as $m) {
        $lines[] = a_an($m['name']) . ' is here' . ($m['id'] === $me['fighting'] ? ', fighting you.' : '.');
    }
    foreach (objects_here('room_id', (int) $me['room_id']) as $o) {
        $lines[] = a_an($o['name']) . ' lies here.';
    }

    $said = q(
        'SELECT u.name, e.text, TIMESTAMPDIFF(MINUTE, e.created, NOW()) AS mins FROM events e
         JOIN users u ON u.id = e.user_id
         WHERE e.room_id = ? AND e.created > NOW() - INTERVAL ? DAY ORDER BY e.id DESC LIMIT 5',
        [$me['room_id'], ECHO_DAYS]
    )->fetchAll();
    foreach (array_reverse($said) as $s) {
        $lines[] = "{$s['name']} said here " . ago((int) $s['mins']) . ": {$s['text']}";
    }
    return $lines;
}

function cmd_move(array $me, string $dir): array
{
    if ($me['fighting'] !== null) {
        return ["You're in a fight! Flee first."];
    }
    $to = q('SELECT to_room FROM exits WHERE room_id = ? AND dir = ?', [$me['room_id'], $dir])->fetchColumn();
    if ($to === false) {
        return ["You can't go $dir."];
    }
    q('UPDATE users SET room_id = ? WHERE id = ?', [$to, $me['id']]);
    return cmd_look(['room_id' => (int) $to] + $me);
}

/** Respawns monsters in the room whose timer ran out, then returns the living ones, oldest spawn first. */
function room_monsters(int $room_id): array
{
    // Dragon Knight rolls a fresh monster's HP between 80% and 100% of its max.
    q(
        'UPDATE spawns s JOIN monsters m ON m.id = s.monster_id
         SET s.dead_since = NULL, s.hp = m.max_hp - FLOOR(RAND() * (m.max_hp - FLOOR(m.max_hp * 0.8) + 1))
         WHERE s.room_id = ? AND s.dead_since < NOW() - INTERVAL s.respawn_secs SECOND',
        [$room_id]
    );
    return q(
        'SELECT s.id, s.hp, m.name, m.level, m.max_dam, m.armor, m.max_exp, m.max_gold FROM spawns s
         JOIN monsters m ON m.id = s.monster_id WHERE s.room_id = ? AND s.dead_since IS NULL ORDER BY s.id',
        [$room_id]
    )->fetchAll();
}

/** The monster I'm fighting, or else the first one here with a word in its name starting with $target. */
function find_foe(array $me, string $target): ?array
{
    foreach (room_monsters((int) $me['room_id']) as $m) {
        if ($me['fighting'] !== null ? $m['id'] === $me['fighting'] : names($target, $m['name'])) {
            return $m;
        }
    }
    if ($me['fighting'] !== null) { // never expected (a kill clears everyone fighting it), but don't strand the hero
        q('UPDATE users SET fighting = NULL, hp_at = NOW() WHERE id = ?', [$me['id']]);
    }
    return null;
}

/** True if some word of $name starts with $target, so "rat" matches "Giant Rat". */
function names(string $target, string $name): bool
{
    return $target !== '' && preg_match('/\\b' . preg_quote($target, '/') . '/i', $name) === 1;
}

function a_an(string $name): string
{
    return (preg_match('/^[aeiou]/i', $name) ? 'An ' : 'A ') . $name;
}

// Combat follows Dragon Knight's fight.php: one round per command, same rolls.

function cmd_kill(array $me, string $target): array
{
    $foe = find_foe($me, $target);
    if (!$foe) {
        return [$target === '' || $me['fighting'] !== null ? 'Kill what?' : "You don't see a $target here."];
    }
    $lines = [];
    $hero_first = true;
    if ($me['fighting'] === null) {
        q('UPDATE users SET fighting = ?, hp = ?, hp_at = NOW() WHERE id = ?', [$foe['id'], $me['hp'], $me['id']]);
        $lines[] = "You attack the {$foe['name']}!";
        $hero_first = beats(random_int(1, 10), $me['dexterity'], random_int(1, 7), $foe['max_dam']);
        if (!$hero_first) {
            $lines[] = "The {$foe['name']} strikes before you are ready!";
        }
    }
    if (!$hero_first && monster_attacks($me, $foe, $lines)) {
        return $lines;
    }
    if (hero_attacks($me, $foe, $lines) || ($hero_first && monster_attacks($me, $foe, $lines))) {
        return $lines;
    }
    $lines[] = "You: {$me['hp']}/{$me['max_hp']} HP.  {$foe['name']}: {$foe['hp']} HP.";
    return $lines;
}

function cmd_flee(array $me): array
{
    $foe = $me['fighting'] === null ? null : find_foe($me, '');
    if (!$foe) {
        return ["You aren't fighting anything."];
    }
    if (!beats(random_int(4, 10), $me['dexterity'], random_int(1, 5), $foe['max_dam'])) {
        $lines = ["You try to flee, but the {$foe['name']} blocks your way!"];
        if (!monster_attacks($me, $foe, $lines)) {
            $lines[] = "You: {$me['hp']}/{$me['max_hp']} HP.";
        }
        return $lines;
    }
    q('UPDATE users SET fighting = NULL, hp_at = NOW() WHERE id = ?', [$me['id']]);
    $dir = q('SELECT dir FROM exits WHERE room_id = ? ORDER BY RAND() LIMIT 1', [$me['room_id']])->fetchColumn();
    if ($dir === false) {
        return ["You break away from the {$foe['name']}, but there's nowhere to run."];
    }
    return ["You flee $dir from the {$foe['name']}!", ...cmd_move(['fighting' => null] + $me, $dir)];
}

/** Dragon Knight's initiative and escape check: my roll + sqrt(dexterity) against theirs + sqrt(max damage). */
function beats(int $roll, int $dexterity, int $their_roll, int $max_dam): bool
{
    return $roll + ceil(sqrt($dexterity)) > $their_roll + ceil(sqrt($max_dam));
}

/** My swing at $foe. Returns true if it died. */
function hero_attacks(array $me, array &$foe, array &$lines): bool
{
    $armor = $foe['armor'];
    if (random_int(1, 200) <= sqrt($armor)) {
        $lines[] = "The {$foe['name']} dodges your attack.";
        return false;
    }
    $attack = $me['attack'];
    $hit = (int) ceil(random_int(intdiv($attack * 3, 4), $attack) / 3);
    if (random_int(1, 150) <= sqrt($me['strength'])) {
        $hit *= 2;
        $lines[] = 'Excellent hit!';
    }
    $dmg = max(1, $hit - (int) ceil(random_int(intdiv($armor * 3, 4), $armor) / 3));
    $lines[] = "You hit the {$foe['name']} for $dmg damage.";
    q('UPDATE spawns SET hp = hp - ? WHERE id = ?', [$dmg, $foe['id']]);
    $foe['hp'] -= $dmg;
    // The kill goes to whoever marks it dead first, in case two heroes land the last blow at once.
    $won = q(
        'UPDATE spawns SET dead_since = NOW() WHERE id = ? AND dead_since IS NULL AND hp <= 0',
        [$foe['id']]
    )->rowCount() === 1;
    if (!$won) {
        return $foe['hp'] <= 0;
    }
    q('UPDATE users SET fighting = NULL, hp_at = NOW() WHERE fighting = ?', [$foe['id']]);
    $exp = max(1, random_int(intdiv($foe['max_exp'] * 5, 6), $foe['max_exp']));
    $gold = max(1, random_int(intdiv($foe['max_gold'] * 5, 6), $foe['max_gold']));
    q('UPDATE users SET exp = exp + ?, gold = gold + ? WHERE id = ?', [$exp, $gold, $me['id']]);
    $lines[] = "The {$foe['name']} dies! You gain $exp experience and $gold gold.";
    // Like Dragon Knight: at most one level per kill, and no heal on levelling.
    $up = q('SELECT * FROM levels WHERE level = ? AND exp <= ?', [$me['level'] + 1, $me['exp'] + $exp])->fetch();
    if ($up) {
        q(
            'UPDATE users SET level = ?, max_hp = max_hp + ?, strength = strength + ?, dexterity = dexterity + ?
             WHERE id = ?',
            [$up['level'], $up['hp'], $up['strength'], $up['dexterity'], $me['id']]
        );
        $lines[] = "You reach level {$up['level']}! +{$up['hp']} max HP, +{$up['strength']} strength, "
            . "+{$up['dexterity']} dexterity.";
    }
    if (random_int(1, LOOT_CHANCE) === 1) {
        $lines[] = "The {$foe['name']} drops " . lcfirst(a_an(loot((int) $foe['level'], (int) $me['room_id']))) . '.';
    }
    return true;
}

/** $foe's swing at me. Returns true if I died (and woke in town). */
function monster_attacks(array &$me, array $foe, array &$lines): bool
{
    if (random_int(1, 150) <= sqrt($me['dexterity'])) {
        $lines[] = "You dodge the {$foe['name']}'s attack.";
        return false;
    }
    $defense = $me['defense'];
    $max = $foe['max_dam'];
    $dmg = max(1, random_int(intdiv($max, 2), $max) - (int) ceil(random_int(intdiv($defense * 3, 4), $defense) / 4));
    $me['hp'] -= $dmg;
    $lines[] = "The {$foe['name']} hits you for $dmg damage.";
    if ($me['hp'] > 0) {
        q('UPDATE users SET hp = ?, hp_at = NOW() WHERE id = ?', [$me['hp'], $me['id']]);
        return false;
    }
    // Dragon Knight's death: back to town with a quarter of your HP and half your gold.
    $me['hp'] = (int) ceil($me['max_hp'] / 4);
    q(
        'UPDATE users SET hp = ?, hp_at = NOW(), gold = gold - gold DIV 2, fighting = NULL, room_id = ? WHERE id = ?',
        [$me['hp'], START_ROOM, $me['id']]
    );
    array_push($lines, 'You have died!', 'You wake in the town square, bruised and lighter of purse.');
    array_push($lines, ...cmd_look(['room_id' => START_ROOM, 'fighting' => null] + $me));
    return true;
}

/** Drops a random item of up to $level on $room_id's floor and returns its name. */
function loot(int $level, int $room_id): string
{
    $item = q('SELECT id, name FROM items WHERE level <= ? ORDER BY RAND() LIMIT 1', [$level])->fetch();
    q('INSERT INTO objects (item_id, room_id) VALUES (?, ?)', [$item['id'], $room_id]);
    return $item['name'];
}

/** Objects on a room's floor ('room_id') or carried by a hero ('user_id'), with their item's columns. */
function objects_here(string $where, int $id): array
{
    $col = match ($where) { 'room_id' => 'room_id', 'user_id' => 'user_id' }; // the only two allowed in SQL
    return q(
        "SELECT o.id, o.worn, i.name, i.slot, i.attack, i.defense, i.max_hp, i.strength, i.dexterity
         FROM objects o JOIN items i ON i.id = o.item_id WHERE o.$col = ? ORDER BY i.name, o.id",
        [$id]
    )->fetchAll();
}

/** The first object in $objects that names($target) matches. */
function find_object(array $objects, string $target): ?array
{
    foreach ($objects as $o) {
        if (names($target, $o['name'])) {
            return $o;
        }
    }
    return null;
}

/** "+5 attack, -50 max HP" */
function item_bonuses(array $item): string
{
    $parts = [];
    foreach (['attack' => 'attack', 'defense' => 'defense', 'max_hp' => 'max HP', 'strength' => 'strength',
        'dexterity' => 'dexterity'] as $col => $label) {
        if ($item[$col] !== 0) {
            $parts[] = sprintf('%+d %s', $item[$col], $label);
        }
    }
    return implode(', ', $parts);
}

function cmd_inventory(array $me): array
{
    $carried = objects_here('user_id', (int) $me['id']);
    if (!$carried) {
        return ['You are carrying nothing.'];
    }
    $lines = ['You are carrying:'];
    foreach ($carried as $o) {
        $worn = $o['worn'] ? '  (equipped)' : '';
        $lines[] = sprintf('  %-20s %-8s %s', $o['name'], $o['slot'], item_bonuses($o)) . $worn;
    }
    return $lines;
}

function cmd_get(array $me, string $target): array
{
    if ($target === '') {
        return ['Get what?'];
    }
    $o = find_object(objects_here('room_id', (int) $me['room_id']), $target);
    // The room_id condition makes sure only one hero gets it if two try at once.
    if (!$o || q(
        'UPDATE objects SET room_id = NULL, user_id = ? WHERE id = ? AND room_id = ?',
        [$me['id'], $o['id'], $me['room_id']]
    )->rowCount() === 0) {
        return ["You don't see a $target here."];
    }
    return ["You pick up the {$o['name']}."];
}

function cmd_drop(array $me, string $target): array
{
    if ($target === '') {
        return ['Drop what?'];
    }
    $o = find_object(objects_here('user_id', (int) $me['id']), $target);
    if (!$o) {
        return ["You aren't carrying a $target."];
    }
    q('UPDATE objects SET user_id = NULL, worn = FALSE, room_id = ? WHERE id = ?', [$me['room_id'], $o['id']]);
    return ["You drop the {$o['name']}."];
}

function cmd_equip(array $me, string $target): array
{
    if ($target === '') {
        return ['Equip what?'];
    }
    $carried = objects_here('user_id', (int) $me['id']);
    // Prefer one not already worn, so "equip charm" works with several charms.
    $o = find_object(array_filter($carried, fn ($c) => !$c['worn']), $target) ?? find_object($carried, $target);
    if (!$o) {
        return ["You aren't carrying a $target."];
    }
    if ($o['worn']) {
        return ["You already have the {$o['name']} equipped."];
    }
    $same_slot = array_filter($carried, fn ($c) => $c['worn'] && $c['slot'] === $o['slot']);
    $lines = [];
    if ($o['slot'] === 'trinket') {
        if (count($same_slot) >= MAX_TRINKETS) {
            return ['You already wear ' . MAX_TRINKETS . ' trinkets. Drop one first.'];
        }
    } else {
        foreach ($same_slot as $old) {
            q('UPDATE objects SET worn = FALSE WHERE id = ?', [$old['id']]);
            $lines[] = "You put away the {$old['name']}.";
        }
    }
    q('UPDATE objects SET worn = TRUE WHERE id = ?', [$o['id']]);
    $lines[] = "You equip the {$o['name']}.";
    return $lines;
}

function cmd_score(array $me): array
{
    $next = q('SELECT exp FROM levels WHERE level = ?', [$me['level'] + 1])->fetchColumn();
    return [
        "{$me['name']}, level {$me['level']}",
        "HP {$me['hp']}/{$me['max_hp']}   Strength {$me['strength']}   Dexterity {$me['dexterity']}",
        "Attack {$me['attack']}   Defense {$me['defense']}",
        "Experience {$me['exp']}" . ($next === false ? ' (max level)' : " ($next for level " . ($me['level'] + 1) . ')')
            . "   Gold {$me['gold']}",
    ];
}

function cmd_say(array $me, string $text): array
{
    if ($text === '') {
        return ['Say what?'];
    }
    q('INSERT INTO events (room_id, user_id, text) VALUES (?, ?, ?)', [$me['room_id'], $me['id'], $text]);
    return ["You say: $text"];
}

function cmd_who(): array
{
    $rows = q(
        'SELECT u.name, r.name AS room, TIMESTAMPDIFF(MINUTE, u.last_seen, NOW()) AS mins
         FROM users u JOIN rooms r ON r.id = u.room_id ORDER BY u.last_seen IS NULL, u.last_seen DESC'
    )->fetchAll();
    $lines = ['Heroes of Greenest:'];
    foreach ($rows as $r) {
        $seen = $r['mins'] === null ? 'never' : ago((int) $r['mins']);
        $lines[] = sprintf('  %-16s %-24s %s', $r['name'], $r['room'], $seen);
    }
    return $lines;
}

function cmd_help(): array
{
    $lines = ['Commands (start typing for suggestions, Tab to complete):'];
    foreach (COMMANDS as $usage => $what) {
        $lines[] = sprintf('  %-14s %s', $usage, $what);
    }
    return $lines;
}

function ago(int $mins): string
{
    return match (true) {
        $mins < 1 => 'just now',
        $mins < 60 => "$mins min ago",
        $mins < 1440 => intdiv($mins, 60) . ' h ago',
        default => intdiv($mins, 1440) . ' d ago',
    };
}
