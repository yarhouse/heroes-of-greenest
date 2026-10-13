<?php

declare(strict_types=1);

// game.php :: command parser and handlers. Handlers return lines of plain text; the client never renders HTML.

const DIRS = ['n' => 'north', 's' => 'south', 'e' => 'east', 'w' => 'west', 'u' => 'up', 'd' => 'down'];
const ONLINE_MINUTES = 10;
const ECHO_DAYS = 7;
const START_ROOM = 1;
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
    'say <text>' => "speak to the room ('<text> works too)",
    'who' => 'every hero, and when last seen',
    'help' => 'this list',
    'quit' => 'log out',
];

/** Runs one command for $me (a users row) and returns the output lines. */
function run_command(array $me, string $input): array
{
    q('UPDATE users SET last_seen = NOW() WHERE id = ?', [$me['id']]);
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
        $a = preg_match('/^[aeiou]/i', $m['name']) ? 'An' : 'A';
        $lines[] = "$a {$m['name']} is here" . ($m['id'] === $me['fighting'] ? ', fighting you.' : '.');
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
        'SELECT s.id, s.hp, m.name, m.max_dam, m.armor, m.max_exp, m.max_gold FROM spawns s
         JOIN monsters m ON m.id = s.monster_id WHERE s.room_id = ? AND s.dead_since IS NULL ORDER BY s.id',
        [$room_id]
    )->fetchAll();
}

/** The monster I'm fighting, or else the first one here with a word in its name starting with $target. */
function find_foe(array $me, string $target): ?array
{
    foreach (room_monsters((int) $me['room_id']) as $m) {
        $match = $me['fighting'] !== null
            ? $m['id'] === $me['fighting']
            : $target !== '' && preg_match('/\\b' . preg_quote($target, '/') . '/i', $m['name']);
        if ($match) {
            return $m;
        }
    }
    if ($me['fighting'] !== null) { // never expected (a kill clears everyone fighting it), but don't strand the hero
        q('UPDATE users SET fighting = NULL WHERE id = ?', [$me['id']]);
    }
    return null;
}

// Combat follows Dragon Knight's fight.php: one round per command, same rolls.
// shortcut: attack = strength and defense = dexterity until weapons and armor exist (items step).

function cmd_kill(array $me, string $target): array
{
    $foe = find_foe($me, $target);
    if (!$foe) {
        return [$target === '' || $me['fighting'] !== null ? 'Kill what?' : "You don't see a $target here."];
    }
    $lines = [];
    $hero_first = true;
    if ($me['fighting'] === null) {
        q('UPDATE users SET fighting = ? WHERE id = ?', [$foe['id'], $me['id']]);
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
    q('UPDATE users SET fighting = NULL WHERE id = ?', [$me['id']]);
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
    $attack = $me['strength'];
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
    q('UPDATE users SET fighting = NULL WHERE fighting = ?', [$foe['id']]);
    $exp = max(1, random_int(intdiv($foe['max_exp'] * 5, 6), $foe['max_exp']));
    $gold = max(1, random_int(intdiv($foe['max_gold'] * 5, 6), $foe['max_gold']));
    q('UPDATE users SET exp = exp + ?, gold = gold + ? WHERE id = ?', [$exp, $gold, $me['id']]);
    $lines[] = "The {$foe['name']} dies! You gain $exp experience and $gold gold.";
    return true;
}

/** $foe's swing at me. Returns true if I died (and woke in town). */
function monster_attacks(array &$me, array $foe, array &$lines): bool
{
    if (random_int(1, 150) <= sqrt($me['dexterity'])) {
        $lines[] = "You dodge the {$foe['name']}'s attack.";
        return false;
    }
    $defense = $me['dexterity'];
    $max = $foe['max_dam'];
    $dmg = max(1, random_int(intdiv($max, 2), $max) - (int) ceil(random_int(intdiv($defense * 3, 4), $defense) / 4));
    $me['hp'] -= $dmg;
    $lines[] = "The {$foe['name']} hits you for $dmg damage.";
    if ($me['hp'] > 0) {
        q('UPDATE users SET hp = ? WHERE id = ?', [$me['hp'], $me['id']]);
        return false;
    }
    // Dragon Knight's death: back to town with a quarter of your HP and half your gold.
    $me['hp'] = (int) ceil($me['max_hp'] / 4);
    q(
        'UPDATE users SET hp = ?, gold = gold - gold DIV 2, fighting = NULL, room_id = ? WHERE id = ?',
        [$me['hp'], START_ROOM, $me['id']]
    );
    array_push($lines, 'You have died!', 'You wake in the town square, bruised and lighter of purse.');
    array_push($lines, ...cmd_look(['room_id' => START_ROOM, 'fighting' => null] + $me));
    return true;
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
