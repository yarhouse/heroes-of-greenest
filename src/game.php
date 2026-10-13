<?php

declare(strict_types=1);

// game.php :: command parser and handlers. Handlers return lines of plain text; the client never renders HTML.

require_once __DIR__ . '/delve.php';

const DIRS = ['n' => 'north', 's' => 'south', 'e' => 'east', 'w' => 'west', 'u' => 'up', 'd' => 'down'];
const MAP_STEPS = ['north' => [0, -1], 'south' => [0, 1], 'east' => [1, 0], 'west' => [-1, 0]]; // up/down: marks only
const MAP_REACH = 2; // the map shows rooms up to this many grid steps each way
const ONLINE_MINUTES = 10;
const ECHO_DAYS = 7;
const START_ROOM = 1;
const SHORT_REST_SECS = 600;
const LONG_REST_SECS = 3600;
const LOOT_CHANCE = 30; // a kill drops an item 1 time in this many, as in Dragon Knight
const LOOT_GP_PER_CR = 50; // and the item costs at most this x the monster's CR: Potions of Healing from CR 1
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
    'second' => 'Second Wind: regain 1d10 + your level in HP',
    'rest' => 'short rest in a safe place (10 min): spend Hit Dice to heal',
    'sleep' => 'long rest in a safe place (1 h): recover everything',
    'score' => 'your abilities, HP, AC and XP',
    'map' => 'rooms near you (also under score on wide screens)',
    'inventory' => 'what you carry and wear (or i)',
    'get <item>' => 'pick something up',
    'drop <item>' => 'put something down',
    'equip <item>' => 'wield or wear something you carry',
    'drink <potion>' => 'drink a potion; like Second Wind, it costs no turn',
    'say <text>' => "speak to the room ('<text> works too)",
    'who' => 'every hero, and when last seen',
    'help' => 'this list',
    'quit' => 'log out',
];

/** Faces for the next dice rolled, used in order before any real rolls. Only tests fill it. */
$dice_queue = [];

/** Rolls dice like '1d20', '2d6+3' or '2d6+1d6-1'; a critical hit rolls every die twice. All game randomness. */
function roll(string $dice, bool $crit = false): int
{
    global $dice_queue;
    if (!preg_match('/^\d+(d\d+)?([+-]\d+(d\d+)?)*$/', $dice)) {
        throw new LogicException("Bad dice: $dice");
    }
    preg_match_all('/([+-]?)(\d+)(?:d(\d+))?/', $dice, $terms, PREG_SET_ORDER);
    $total = 0;
    foreach ($terms as $t) {
        $value = (int) $t[2];
        if (isset($t[3])) {
            $sides = (int) $t[3];
            $value = 0;
            for ($i = (int) $t[2] * ($crit ? 2 : 1); $i > 0; $i--) {
                $face = array_shift($dice_queue) ?? random_int(1, $sides);
                if ($face < 1 || $face > $sides) {
                    throw new LogicException("Queued a $face for a d$sides");
                }
                $value += $face;
            }
        }
        $total += $t[1] === '-' ? -$value : $value;
    }
    return $total;
}

/** An ability score's modifier: 8 is -1, 10 is +0, 15 is +2. */
function mod(int $score): int
{
    return (int) floor(($score - 10) / 2);
}

/** Runs one command for $me (a users row) and returns the output lines. */
/** Adds the stats derived from level and gear to a `users` row. */
function with_stats(array $me): array
{
    $me += q('SELECT prof_bonus, second_wind, attacks FROM levels WHERE level = ?', [$me['level']])->fetch();
    return $me + gear($me);
}

function run_command(array $me, string $input): array
{
    q('UPDATE users SET last_seen = NOW() WHERE id = ?', [$me['id']]);
    $me = with_stats($me);
    $rested = $me['resting'] !== null ? finish_rest($me) : [];
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
        $verb === 'second' => cmd_second($me),
        $verb === 'rest' => cmd_rest($me, 'short'),
        $verb === 'sleep' => cmd_rest($me, 'long'),
        $verb === 'score' => cmd_score($me),
        $verb === 'map' => cmd_map($me),
        $verb === 'i' || $verb === 'inventory' => cmd_inventory($me),
        $verb === 'get' => cmd_get($me, trim($arg)),
        $verb === 'drop' => cmd_drop($me, trim($arg)),
        $verb === 'equip' => cmd_equip($me, trim($arg)),
        $verb === 'drink' => cmd_drink($me, trim($arg)),
        $verb === 'say' => cmd_say($me, trim($arg)),
        $verb === 'who' => cmd_who(),
        $verb === 'help' => cmd_help(),
        default => ["Huh? Type 'help' for commands."],
    };
    // look already lists what was said in the room, so don't repeat it.
    $shows_room = $verb === 'l' || $verb === 'look' || in_array($verb, DIRS, true);
    return array_merge($rested, $shows_room ? [] : $heard, $out);
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
    if ((int) $to === DELVE_STAIR) {
        delve_refresh();
    }
    q('UPDATE users SET room_id = ?, resting = NULL, rest_until = NULL WHERE id = ?', [$to, $me['id']]);
    $stop = $me['resting'] !== null ? ['You stop resting.'] : [];
    return [...$stop, ...cmd_look(['room_id' => (int) $to] + $me)];
}

/** Respawns monsters in the room whose timer ran out (rolling their HP), then returns the living ones, oldest first. */
function room_monsters(int $room_id): array
{
    $due = q(
        'SELECT s.id, m.hp AS dice FROM spawns s JOIN monsters m ON m.id = s.monster_id
         WHERE s.room_id = ? AND s.dead_since < NOW() - INTERVAL s.respawn_secs SECOND ORDER BY s.id',
        [$room_id]
    )->fetchAll();
    foreach ($due as $s) {
        // "dead_since IS NOT NULL" so two heroes arriving at once can't both respawn it.
        q('UPDATE spawns SET dead_since = NULL, hp = ? WHERE id = ? AND dead_since IS NOT NULL', [
            max(1, roll($s['dice'])), $s['id'],
        ]);
    }
    return q(
        'SELECT s.id, s.hp, m.name, m.ac, m.initiative, m.attack, m.attacks, m.to_hit, m.damage, m.cr, m.xp
         FROM spawns s JOIN monsters m ON m.id = s.monster_id WHERE s.room_id = ? AND s.dead_since IS NULL
         ORDER BY s.id',
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
        q('UPDATE users SET fighting = NULL WHERE id = ?', [$me['id']]);
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

// Combat follows SRD 5.2.1: initiative when a fight starts, then one round per command in initiative order.
// Attack rolls are d20 + bonus against AC; a natural 20 is a critical hit (damage dice twice), a natural 1 misses.

function cmd_kill(array $me, string $target): array
{
    $foe = find_foe($me, $target);
    if (!$foe) {
        return [$target === '' || $me['fighting'] !== null ? 'Kill what?' : "You don't see a $target here."];
    }
    $lines = [];
    if ($me['fighting'] === null) {
        $mine = roll('1d20') + mod($me['dexterity']);
        $theirs = roll('1d20') + $foe['initiative'];
        $me['foe_first'] = $theirs > $mine; // ties go to the hero
        q(
            'UPDATE users SET fighting = ?, foe_first = ?, resting = NULL, rest_until = NULL WHERE id = ?',
            [$foe['id'], (int) $me['foe_first'], $me['id']]
        );
        $lines[] = "You attack the {$foe['name']}! Initiative: you $mine, it $theirs.";
    }
    $over = $me['foe_first']
        ? monster_turn($me, $foe, $lines) || hero_turn($me, $foe, $lines)
        : hero_turn($me, $foe, $lines) || monster_turn($me, $foe, $lines);
    if (!$over) {
        $lines[] = "You: {$me['hp']}/{$me['max_hp']} HP.  {$foe['name']}: {$foe['hp']} HP.";
    }
    return $lines;
}

/** Fleeing is a contest: d20 + Dex modifier against the monster's d20 + initiative. Failing draws an attack. */
function cmd_flee(array $me): array
{
    $foe = $me['fighting'] === null ? null : find_foe($me, '');
    if (!$foe) {
        return ["You aren't fighting anything."];
    }
    $mine = roll('1d20') + mod($me['dexterity']);
    $theirs = roll('1d20') + $foe['initiative'];
    if ($mine < $theirs) {
        $lines = ["You try to break away, but the {$foe['name']} cuts you off ($mine vs $theirs)!"];
        if (!monster_turn($me, $foe, $lines, 1)) {
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

/** My attacks on $foe this round (more than one with Extra Attack). Returns true if it died. */
function hero_turn(array $me, array &$foe, array &$lines): bool
{
    for ($i = 0; $i < $me['attacks']; $i++) {
        $d20 = roll('1d20');
        $total = $d20 + $me['attack_mod'] + $me['prof_bonus'];
        if ($d20 === 1 || ($d20 < 20 && $total < $foe['ac'])) {
            $why = $d20 === 1 ? 'natural 1' : "$total vs AC {$foe['ac']}";
            $lines[] = "You miss the {$foe['name']} ($why).";
            continue;
        }
        $dmg = max(0, roll($me['damage'], $d20 === 20) + $me['attack_mod']);
        $crit = $d20 === 20 ? 'Critical hit! ' : '';
        $lines[] = "{$crit}You hit the {$foe['name']} ($total vs AC {$foe['ac']}) for $dmg damage.";
        q('UPDATE spawns SET hp = hp - ? WHERE id = ?', [$dmg, $foe['id']]);
        $foe['hp'] -= $dmg;
        if ($foe['hp'] <= 0) {
            // The kill goes to whoever marks it dead first, in case two heroes land the last blow at once.
            $won = q(
                'UPDATE spawns SET dead_since = NOW() WHERE id = ? AND dead_since IS NULL AND hp <= 0',
                [$foe['id']]
            )->rowCount() === 1;
            if ($won) {
                win($me, $foe, $lines);
            }
            return true;
        }
    }
    return false;
}

/** The killer's reward: the stat block's full XP, a little gold by CR, levels, and maybe loot. */
function win(array $me, array $foe, array &$lines): void
{
    q('UPDATE users SET fighting = NULL WHERE fighting = ?', [$foe['id']]);
    $cr = max(1, (int) ceil((float) $foe['cr']));
    $gold = roll($cr . 'd6');
    $xp = $me['xp'] + $foe['xp'];
    $level = (int) q('SELECT MAX(level) FROM levels WHERE xp <= ?', [$xp])->fetchColumn();
    $gain = ($level - $me['level']) * (6 + mod($me['constitution'])); // the SRD's fixed Fighter HP per level
    q(
        'UPDATE users SET xp = ?, gold = gold + ?, level = ?, max_hp = max_hp + ?, hp = hp + ? WHERE id = ?',
        [$xp, $gold, $level, $gain, $gain, $me['id']]
    );
    $lines[] = "The {$foe['name']} dies! You gain {$foe['xp']} XP and $gold gold.";
    if ($level > $me['level']) {
        $lines[] = "You reach level $level! +$gain max HP.";
    }
    if (roll('1d' . LOOT_CHANCE) === 1 && ($item = loot((float) $foe['cr'], (int) $me['room_id'])) !== null) {
        $lines[] = "The {$foe['name']} drops " . lcfirst(a_an($item)) . '.';
    }
}

/** $foe attacks me: $n times, its Multiattack by default. Returns true if I died (and woke in town). */
function monster_turn(array &$me, array $foe, array &$lines, ?int $n = null): bool
{
    for ($i = 0; $i < ($n ?? $foe['attacks']); $i++) {
        $d20 = roll('1d20');
        $total = $d20 + $foe['to_hit'];
        $its = "The {$foe['name']}'s {$foe['attack']}";
        if ($d20 === 1 || ($d20 < 20 && $total < $me['ac'])) {
            $lines[] = "$its misses you (" . ($d20 === 1 ? 'natural 1' : "$total vs AC {$me['ac']}") . ').';
            continue;
        }
        $dmg = max(0, roll($foe['damage'], $d20 === 20));
        $me['hp'] -= $dmg;
        $lines[] = ($d20 === 20 ? 'Critical hit! ' : '') . "$its hits you ($total vs AC {$me['ac']}) for $dmg damage.";
        if ($me['hp'] <= 0) {
            // Dragon Knight's death, kept for async play: back to town with a quarter of your HP and half your gold.
            $me['hp'] = (int) ceil($me['max_hp'] / 4);
            q(
                'UPDATE users SET hp = ?, gold = gold - gold DIV 2, fighting = NULL, room_id = ? WHERE id = ?',
                [$me['hp'], START_ROOM, $me['id']]
            );
            array_push($lines, 'You have died!', 'You wake in the town square, bruised and lighter of purse.');
            array_push($lines, ...cmd_look(['room_id' => START_ROOM, 'fighting' => null] + $me));
            return true;
        }
    }
    q('UPDATE users SET hp = ? WHERE id = ?', [$me['hp'], $me['id']]);
    return false;
}

/** Second Wind: a bonus action, so it costs no turn and the monster doesn't swing. */
function cmd_second(array $me): array
{
    $left = $me['second_wind'] - $me['second_wind_spent'];
    if ($left <= 0) {
        return ['You have no Second Wind left. Rest to recover it.'];
    }
    if ($me['hp'] >= $me['max_hp']) {
        return ["You're already at full health."];
    }
    $hp = min($me['max_hp'], $me['hp'] + roll('1d10') + $me['level']);
    q('UPDATE users SET hp = ?, second_wind_spent = second_wind_spent + 1 WHERE id = ?', [$hp, $me['id']]);
    return [
        'You catch your second wind and regain ' . ($hp - $me['hp']) . " HP. HP $hp/{$me['max_hp']}, "
            . ($left - 1) . ' Second Wind left.',
    ];
}

/** Starts a short or long rest. It finishes on the first command after it's done, unless I leave the room. */
function cmd_rest(array $me, string $kind): array
{
    if ($me['resting'] !== null) {
        $mins = q('SELECT CEIL(TIMESTAMPDIFF(SECOND, NOW(), rest_until) / 60) FROM users WHERE id = ?', [$me['id']])
            ->fetchColumn();
        return ["You are already taking a {$me['resting']} rest ($mins min to go)."];
    }
    if ($me['fighting'] !== null) {
        return ["You can't rest in the middle of a fight!"];
    }
    if (!q('SELECT safe FROM rooms WHERE id = ?', [$me['room_id']])->fetchColumn()) {
        return ["It isn't safe to rest here. Try the town square or the Swaying Lantern."];
    }
    $secs = $kind === 'long' ? LONG_REST_SECS : SHORT_REST_SECS;
    q('UPDATE users SET resting = ?, rest_until = NOW() + INTERVAL ? SECOND WHERE id = ?', [$kind, $secs, $me['id']]);
    return ["You settle in for a $kind rest. It takes " . intdiv($secs, 60) . ' minutes; leaving cancels it.'];
}

/** If my rest's time is up, applies it and returns what it did. */
function finish_rest(array &$me): array
{
    // Claiming the rest in one UPDATE means two requests at once can't both apply it.
    $done = q(
        'UPDATE users SET resting = NULL, rest_until = NULL
         WHERE id = ? AND resting IS NOT NULL AND rest_until <= NOW()',
        [$me['id']]
    )->rowCount() === 1;
    if (!$done) {
        return [];
    }
    if ($me['resting'] === 'long') {
        [$me['hp'], $me['hit_dice_spent'], $me['second_wind_spent']] = [$me['max_hp'], 0, 0];
        $line = 'You finish a long rest: full HP, all Hit Dice and Second Wind back.';
    } else {
        $spent = 0;
        while ($me['hp'] < $me['max_hp'] && $me['hit_dice_spent'] < $me['level']) {
            $me['hp'] = min($me['max_hp'], $me['hp'] + max(0, roll('1d10') + mod($me['constitution'])));
            $me['hit_dice_spent']++;
            $spent++;
        }
        $me['second_wind_spent'] = max(0, $me['second_wind_spent'] - 1);
        $line = "You finish a short rest, spending $spent Hit " . ($spent === 1 ? 'Die' : 'Dice')
            . " and regaining a Second Wind.";
    }
    $me['resting'] = null;
    q(
        'UPDATE users SET hp = ?, hit_dice_spent = ?, second_wind_spent = ? WHERE id = ?',
        [$me['hp'], $me['hit_dice_spent'], $me['second_wind_spent'], $me['id']]
    );
    return [$line . " HP {$me['hp']}/{$me['max_hp']}."];
}

/** Drops a random item a monster of $cr could have on $room_id's floor and returns its name (null if none can). */
function loot(float $cr, int $room_id): ?string
{
    $item = q('SELECT id, name FROM items WHERE cost <= ? ORDER BY RAND() LIMIT 1', [$cr * LOOT_GP_PER_CR])->fetch();
    if (!$item) {
        return null;
    }
    q('INSERT INTO objects (item_id, room_id) VALUES (?, ?)', [$item['id'], $room_id]);
    return $item['name'];
}

/**
 * My AC and attack from what I wear, by the SRD: armor's AC plus Dex (all for light, up to +2 for medium, none for
 * heavy) and +1 for the Defense fighting style, or 10 + Dex unarmored; +2 for a shield. A Versatile weapon does its
 * bigger die without a shield, Finesse uses the better of Str and Dex, and no weapon is an Unarmed Strike (1 + Str).
 */
function gear(array $me): array
{
    $worn = [];
    foreach (objects_here('user_id', (int) $me['id']) as $o) {
        if ($o['worn']) {
            $worn[$o['slot']] = $o;
        }
    }
    [$dex, $str] = [mod($me['dexterity']), mod($me['strength'])];
    $armor = $worn['armor'] ?? null;
    $ac = $armor === null ? 10 + $dex
        : $armor['ac'] + 1 + match ($armor['armor']) { 'light' => $dex, 'medium' => min($dex, 2), 'heavy' => 0 };
    $weapon = $worn['weapon'] ?? null;
    return [
        'ac' => $ac + ($worn['shield']['ac'] ?? 0),
        'weapon' => $weapon['name'] ?? 'Unarmed Strike',
        'damage' => $weapon === null ? '1'
            : (isset($worn['shield']) ? $weapon['dice'] : $weapon['versatile'] ?? $weapon['dice']),
        'attack_mod' => $weapon !== null && $weapon['finesse'] ? max($str, $dex) : $str,
    ];
}

/** An item's stats in a few words, for the inventory. */
function describe(array $o): string
{
    return match ($o['slot']) {
        'weapon' => "weapon, {$o['dice']}" . ($o['versatile'] ? " ({$o['versatile']} two-handed)" : '')
            . ($o['two_handed'] ? ', two-handed' : '') . ($o['finesse'] ? ', finesse' : ''),
        'armor' => "{$o['armor']} armor, AC {$o['ac']}"
            . ['light' => ' + Dex', 'medium' => ' + Dex (max 2)', 'heavy' => ''][$o['armor']],
        'shield' => "shield, +{$o['ac']} AC",
        'potion' => "potion, heals {$o['dice']}",
    };
}

/** Objects on a room's floor ('room_id') or carried by a hero ('user_id'), with their item's columns. */
function objects_here(string $where, int $id): array
{
    $col = match ($where) { 'room_id' => 'room_id', 'user_id' => 'user_id' }; // the only two allowed in SQL
    return q(
        "SELECT o.id, o.worn, i.name, i.slot, i.dice, i.versatile, i.finesse, i.two_handed, i.ac, i.armor
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

function cmd_inventory(array $me): array
{
    $carried = objects_here('user_id', (int) $me['id']);
    if (!$carried) {
        return ['You are carrying nothing.'];
    }
    $lines = ['You are carrying:'];
    foreach ($carried as $o) {
        $worn = $o['worn'] ? '  (equipped)' : '';
        $lines[] = sprintf('  %-22s %s', $o['name'], describe($o)) . $worn;
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
    // Prefer one not already worn, so "equip dagger" works with two daggers.
    $o = find_object(array_filter($carried, fn ($c) => !$c['worn']), $target) ?? find_object($carried, $target);
    if (!$o) {
        return ["You aren't carrying a $target."];
    }
    if ($o['slot'] === 'potion') {
        return ["You can't equip the {$o['name']}. Drink it instead."];
    }
    if ($o['worn']) {
        return ["You already have the {$o['name']} equipped."];
    }
    if ($o['slot'] === 'armor' && $me['fighting'] !== null) { // the SRD's quickest armor takes a minute to don
        return ["You can't change armor in the middle of a fight!"];
    }
    // One of each slot, and a Two-Handed weapon leaves no hand for a shield.
    $two_hands = fn ($c) => $c['slot'] === 'weapon' && $c['two_handed'];
    $clashes = fn ($c) => $c['worn'] && ($c['slot'] === $o['slot']
        || ($o['slot'] === 'shield' && $two_hands($c)) || ($c['slot'] === 'shield' && $two_hands($o)));
    $lines = [];
    foreach (array_filter($carried, $clashes) as $old) {
        q('UPDATE objects SET worn = FALSE WHERE id = ?', [$old['id']]);
        $lines[] = "You put away the {$old['name']}.";
    }
    q('UPDATE objects SET worn = TRUE WHERE id = ?', [$o['id']]);
    $lines[] = "You equip the {$o['name']}.";
    return $lines;
}

/** Drinking a potion is a Bonus Action, so like Second Wind it costs no turn. */
function cmd_drink(array $me, string $target): array
{
    if ($target === '') {
        return ['Drink what?'];
    }
    $potions = array_filter(objects_here('user_id', (int) $me['id']), fn ($o) => $o['slot'] === 'potion');
    $o = find_object($potions, $target);
    if (!$o) {
        return ["You aren't carrying a $target to drink."];
    }
    if ($me['hp'] >= $me['max_hp']) {
        return ["You're already at full health."];
    }
    // Deleting by owner first means two requests at once can't both drink it.
    if (q('DELETE FROM objects WHERE id = ? AND user_id = ?', [$o['id'], $me['id']])->rowCount() === 0) {
        return ["You aren't carrying a $target to drink."];
    }
    $hp = min($me['max_hp'], $me['hp'] + roll($o['dice']));
    q('UPDATE users SET hp = ? WHERE id = ?', [$hp, $me['id']]);
    return ["You drink the {$o['name']} and regain " . ($hp - $me['hp']) . " HP. HP $hp/{$me['max_hp']}."];
}

function cmd_score(array $me): array
{
    $abilities = [];
    foreach (['strength', 'dexterity', 'constitution', 'intelligence', 'wisdom', 'charisma'] as $ability) {
        $abilities[] = sprintf('%s %d (%+d)', ucfirst(substr($ability, 0, 3)), $me[$ability], mod($me[$ability]));
    }
    $next = q('SELECT xp FROM levels WHERE level = ?', [$me['level'] + 1])->fetchColumn();
    return [
        "{$me['name']}, level {$me['level']} Fighter",
        implode('  ', $abilities),
        "HP {$me['hp']}/{$me['max_hp']}   AC {$me['ac']}   Proficiency Bonus +{$me['prof_bonus']}",
        sprintf('%s %+d to hit, %s%+d damage', $me['weapon'], $me['attack_mod'] + $me['prof_bonus'], $me['damage'],
            $me['attack_mod'])
            . ($me['attacks'] > 1 ? ", {$me['attacks']} attacks a round" : ''),
        'Hit Dice ' . ($me['level'] - $me['hit_dice_spent']) . "/{$me['level']} d10   Second Wind "
            . max(0, $me['second_wind'] - $me['second_wind_spent']) . "/{$me['second_wind']}"
            . ($me['resting'] !== null ? "   Taking a {$me['resting']} rest" : ''),
        "XP {$me['xp']}" . ($next === false ? ' (max level)' : " ($next for level " . ($me['level'] + 1) . ')')
            . "   Gold {$me['gold']}",
    ];
}

/**
 * Rooms within MAP_REACH steps on this floor, placed on a grid by walking exits out from the hero. Shows the
 * shape (rooms, links, stairs, safe rooms) everywhere, but what's inside only for the room you're in.
 */
function cmd_map(array $me): array
{
    $here = (int) $me['room_id'];
    $at = [$here => [0, 0]]; // room id => [x, y]; north is -y
    $taken = ['0,0' => true];
    $exits = [];
    for ($todo = [$here]; $todo;) {
        $rows = q(
            'SELECT room_id, dir, to_room FROM exits WHERE room_id IN ('
                . implode(',', array_fill(0, count($todo), '?')) . ')',
            $todo
        )->fetchAll();
        $todo = [];
        foreach ($rows as $e) {
            $exits[$e['room_id']][] = $e['dir'];
            if (!isset(MAP_STEPS[$e['dir']]) || isset($at[$e['to_room']])) {
                continue;
            }
            $x = $at[$e['room_id']][0] + MAP_STEPS[$e['dir']][0];
            $y = $at[$e['room_id']][1] + MAP_STEPS[$e['dir']][1];
            // shortcut: a room whose cell is already taken is left off (a few in koboldmines), fine while rare.
            if (abs($x) <= MAP_REACH && abs($y) <= MAP_REACH && !isset($taken["$x,$y"])) {
                $at[$e['to_room']] = [$x, $y];
                $taken["$x,$y"] = true;
                $todo[] = $e['to_room'];
            }
        }
    }
    $ids = array_keys($at);
    $safe = q(
        'SELECT id FROM rooms WHERE safe AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
        $ids
    )->fetchAll(PDO::FETCH_COLUMN);

    // Rooms are 3 characters wide, 4 apart; links sit between them and poke past the edge for exits off the map.
    $grid = array_fill(0, 4 * MAP_REACH + 3, array_fill(0, 8 * MAP_REACH + 5, ' '));
    foreach ($at as $id => [$x, $y]) {
        $row = 2 * ($y + MAP_REACH) + 1;
        $col = 4 * ($x + MAP_REACH) + 2;
        $dirs = $exits[$id] ?? [];
        $up = in_array('up', $dirs, true);
        $down = in_array('down', $dirs, true);
        $grid[$row][$col] = match (true) {
            $id === $here => '@',
            $up && $down => '↕',
            $up => '^',
            $down => 'v',
            default => ' ',
        };
        [$grid[$row][$col - 1], $grid[$row][$col + 1]] = in_array($id, $safe, true) ? ['(', ')'] : ['[', ']'];
        foreach ($dirs as $dir) {
            match ($dir) {
                'north' => $grid[$row - 1][$col] = '│',
                'south' => $grid[$row + 1][$col] = '│',
                'east' => $grid[$row][$col + 2] = '─',
                'west' => $grid[$row][$col - 2] = '─',
                default => null,
            };
        }
    }
    $lines = array_map(fn ($chars) => rtrim(implode('', $chars)), $grid);

    // Not room_monsters(): its respawn rolls HP, and drawing the map must not spend dice.
    $foes = q(
        'SELECT m.name FROM spawns s JOIN monsters m ON m.id = s.monster_id WHERE s.room_id = ?
         AND (s.dead_since IS NULL OR s.dead_since < NOW() - INTERVAL s.respawn_secs SECOND) ORDER BY s.id',
        [$here]
    )->fetchAll(PDO::FETCH_COLUMN);
    $lines[] = $foes ? 'Here: ' . implode(', ', $foes) : 'Here: no monsters';
    $lines[] = '@ you  ^ v stairs  ( ) safe';
    return $lines;
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
