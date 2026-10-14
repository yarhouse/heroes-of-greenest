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
const ABILITIES = ['strength', 'dexterity', 'constitution', 'intelligence', 'wisdom', 'charisma'];
const STANDARD_ARRAY = [15, 14, 13, 12, 10, 8];
/** The Weapon Mastery properties the game models (SRD 5.2.1 p.89); items list each weapon's own. */
const MASTERIES = ['cleave', 'graze', 'nick', 'sap', 'topple', 'vex'];
/** Ends my fight, and what lasts only for one: Rage, saved Advantage, conditions. A SET clause for UPDATE users. */
const END_FIGHT = "fighting = NULL, raging = FALSE, advantage = FALSE, conditions = '', bonus_used = FALSE";
const BONUS_USED = "You've already used your Bonus Action this round.";

/**
 * The classes a hero can choose, from SRD 5.2.1 (Barbarian p.28, Cleric p.36, Fighter p.47, Rogue p.61): Hit Die,
 * level 1 HP and fixed HP per level (both before the Con modifier), the Standard Array by Class (p.21) in Str Dex Con
 * Int Wis Cha order, saving throw proficiencies, a caster's spellcasting ability, armor training, and starting
 * equipment A as [item id, worn]. Kits
 * leave out ammunition, packs, tools and focuses. Every class but the Rogue is trained with every weapon (the
 * Fighter's Fighting Style is Defense, the Cleric's Divine Order is Protector).
 */
const CLASSES = [
    'fighter' => [
        'name' => 'Fighter', 'die' => 10, 'hp' => 10, 'per_level' => 6, 'scores' => [15, 14, 13, 8, 10, 12],
        'saves' => ['strength', 'constitution'],
        'armor' => ['light', 'medium', 'heavy', 'shield'], 'gold' => 4,
        'kit' => [ // Chain Mail, Greatsword, Flail, 8 Javelins
            [40, true], [15, true], [12, false], [5, false], [5, false], [5, false], [5, false], [5, false], [5, false],
            [5, false], [5, false],
        ],
        'about' => 'd10 HP, any armor. Second Wind heals you; more attacks as you level.',
    ],
    'barbarian' => [
        'name' => 'Barbarian', 'die' => 12, 'hp' => 12, 'per_level' => 7, 'scores' => [15, 13, 14, 10, 12, 8],
        'saves' => ['strength', 'constitution'],
        'armor' => ['light', 'medium', 'shield'], 'gold' => 15,
        'kit' => [[14, true], [4, false], [4, false], [4, false], [4, false]], // Greataxe, 4 Handaxes
        'about' => 'd12 HP, tough even unarmored. Rage to hit harder and shrug off weapon blows.',
    ],
    'rogue' => [
        'name' => 'Rogue', 'die' => 8, 'hp' => 8, 'per_level' => 5, 'scores' => [12, 15, 13, 14, 10, 8],
        'saves' => ['dexterity', 'intelligence'],
        'armor' => ['light'], 'gold' => 8,
        'kit' => [[32, true], [24, true], [2, false], [2, false], [72, false]], // Leather Armor, Shortsword, 2 Daggers,
        // Shortbow
        'about' => 'd8 HP, light armor. Sneak Attack with a finesse or ranged weapon when you have Advantage or help.',
    ],
    'cleric' => [
        'name' => 'Cleric', 'die' => 8, 'hp' => 8, 'per_level' => 5, 'scores' => [14, 8, 13, 10, 15, 12],
        'saves' => ['wisdom', 'charisma'], 'casting' => 'wisdom',
        'armor' => ['light', 'medium', 'heavy', 'shield'], 'gold' => 7,
        'kit' => [[35, true], [50, true], [7, true]], // Chain Shirt, Shield, Mace
        'about' => 'd8 HP, armor and shield. Spells that burn foes and heal you.',
    ],
];

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
    'reckless <monster>' => 'Barbarian 2: attack with Advantage, but attacks on you get it too',
    'shove <monster>' => 'knock a foe Prone, in place of one attack: attacks on it get Advantage',
    'grapple <monster>' => 'hold a foe, in place of one attack: it has Disadvantage against other heroes',
    'flee' => 'try to escape a fight to a random exit',
    'second' => 'Fighter: Second Wind, regain 1d10 + your level in HP',
    'rage' => 'Barbarian: Rage for the rest of a fight; costs no turn',
    'cast <spell>' => 'cast a spell, at a monster if it needs one (cast alone lists them)',
    'rest' => 'short rest in a safe place (10 min): spend Hit Dice to heal',
    'sleep' => 'long rest in a safe place (1 h): recover everything',
    'score' => 'your abilities, HP, AC and XP',
    'map' => 'rooms near you (also under score on wide screens)',
    'inventory' => 'what you carry and wear (or i)',
    'get <item>' => 'pick something up',
    'drop <item>' => 'put something down',
    'equip <item>' => "wield or wear something you carry; 'equip <item> offhand' for a second Light weapon",
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

/**
 * Adds the stats derived from class, level and gear to a `users` row. 'uses' is Second Wind or Rage uses, and
 * 'slots' the Cleric's spell slots by spell level.
 */
function with_stats(array $me): array
{
    $lv = q('SELECT * FROM levels WHERE level = ?', [$me['level']])->fetch();
    $class = $me['class'];
    $me += [
        'prof_bonus' => $lv['prof_bonus'],
        'attacks' => match ($class) {
            'fighter' => $lv['attacks'],
            'barbarian' => $me['level'] >= 5 ? 2 : 1, // Extra Attack
            default => 1,
        },
        'uses' => match ($class) { 'fighter' => $lv['second_wind'], 'barbarian' => $lv['rages'], default => 0 },
        'rage_damage' => $lv['rage_damage'],
        'slots' => isset(CLASSES[$class]['casting']) ? array_map('intval', explode(',', $lv['slots'])) : [],
        'resistances' => $me['raging'] ? 'bludgeoning,piercing,slashing' : '',
        'immunities' => '',
        'vulnerabilities' => '',
    ];
    foreach (ABILITIES as $a) { // str_save to cha_save, as on a monster, so save() takes either
        $proficient = in_array($a, CLASSES[$class]['saves'], true);
        $me[substr($a, 0, 3) . '_save'] = mod($me[$a]) + ($proficient ? $lv['prof_bonus'] : 0);
    }
    $me += gear($me);
    $me['attacks'] = $me['loading'] ? 1 : $me['attacks']; // Loading: one shot a turn, Extra Attack or not
    return $me;
}

/** Runs one command for $me (a users row) and returns the output lines. */
function run_command(array $me, string $input): array
{
    q('UPDATE users SET last_seen = NOW() WHERE id = ?', [$me['id']]);
    $input = trim(mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $input) ?? '', 0, 300));
    if ($me['setup'] !== null) {
        return cmd_setup($me, $input);
    }
    $me = with_stats($me);
    $rested = $me['resting'] !== null ? finish_rest($me) : [];
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
        $verb === 'reckless' => cmd_reckless($me, trim($arg)),
        $verb === 'shove' || $verb === 'grapple' => cmd_maneuver($me, $verb, trim($arg)),
        $verb === 'flee' => cmd_flee($me),
        $verb === 'second' => cmd_second($me),
        $verb === 'rage' => cmd_rage($me),
        $verb === 'cast' => cmd_cast($me, trim($arg)),
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

/**
 * Character creation, one step per command until the hero sets out: choose a class, then assign the Standard Array
 * (which gives the class's kit and a fresh level 1 hero, even one who was played before), then 'begin'.
 */
function cmd_setup(array $me, string $input): array
{
    $word = strtolower($input);
    $word = $word === 'l' || $word === 'look' ? '' : $word; // the play page sends 'look' on load: show the step again
    if ($word === 'back' || $me['setup'] === 'class') {
        return setup_class($me, $word === 'back' ? '' : $word);
    }
    if ($me['setup'] === 'abilities') {
        return setup_abilities($me, $word);
    }
    if ($word !== 'begin') {
        return setup_summary($me);
    }
    q('UPDATE users SET setup = NULL WHERE id = ?', [$me['id']]);
    return ["You set out, {$me['name']}. Type 'help' for commands.", '', ...cmd_look($me)];
}

function setup_class(array $me, string $word): array
{
    foreach (CLASSES as $class => $c) {
        if ($word !== '' && str_starts_with($class, $word)) {
            q("UPDATE users SET class = ?, setup = 'abilities' WHERE id = ?", [$class, $me['id']]);
            return ["You will be a {$c['name']}.", ...ability_prompt($class)];
        }
    }
    q("UPDATE users SET setup = 'class' WHERE id = ?", [$me['id']]);
    $lines = $word === '' ? [] : ["There's no '$word' class here."];
    $lines[] = "Welcome to Greenest, {$me['name']}! Choose your class by typing its name:";
    foreach (CLASSES as $class => $c) {
        $lines[] = sprintf('  %-10s %s', $class, $c['about']);
    }
    return $lines;
}

function ability_prompt(string $class): array
{
    $c = CLASSES[$class];
    $suggested = array_map(fn ($a, $s) => ucfirst(substr($a, 0, 3)) . " $s", ABILITIES, $c['scores']);
    return [
        'Now give each ability one of the scores ' . implode(' ', STANDARD_ARRAY) . '.',
        "Suggested for a {$c['name']}: " . implode('  ', $suggested),
        "Type 'ok' for that, or six numbers in Str Dex Con Int Wis Cha order (like '" . implode(' ', $c['scores'])
            . "'). 'back' chooses another class.",
    ];
}

function setup_abilities(array $me, string $word): array
{
    $c = CLASSES[$me['class']];
    $words = preg_split('/[\s,]+/', $word, -1, PREG_SPLIT_NO_EMPTY);
    $scores = $word === 'ok' ? $c['scores'] : array_map('intval', array_filter($words, 'ctype_digit'));
    $sorted = $scores;
    rsort($sorted);
    if ($sorted !== STANDARD_ARRAY) {
        $error = $word === '' ? [] : ['Use each of ' . implode(' ', STANDARD_ARRAY) . ' exactly once.'];
        return [...$error, ...ability_prompt($me['class'])];
    }
    $hp = $c['hp'] + mod($scores[2]);
    q(
        "UPDATE users SET setup = 'confirm', strength = ?, dexterity = ?, constitution = ?, intelligence = ?,
         wisdom = ?, charisma = ?, level = 1, xp = 0, hp = ?, max_hp = ?, hit_dice_spent = 0, uses_spent = 0,
         slots_spent = '', gold = ?, room_id = ?, resting = NULL, rest_until = NULL, " . END_FIGHT . ' WHERE id = ?',
        [...$scores, $hp, $hp, $c['gold'], START_ROOM, $me['id']]
    );
    q('DELETE FROM objects WHERE user_id = ?', [$me['id']]);
    foreach ($c['kit'] as [$item, $worn]) {
        q('INSERT INTO objects (item_id, user_id, worn) VALUES (?, ?, ?)', [$item, $me['id'], (int) $worn]);
    }
    return setup_summary(q('SELECT * FROM users WHERE id = ?', [$me['id']])->fetch());
}

function setup_summary(array $me): array
{
    return [
        ...cmd_score(with_stats($me)),
        ...cmd_inventory($me),
        "Type 'begin' to set out, or 'back' to start over.",
    ];
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
        q("UPDATE spawns SET dead_since = NULL, hp = ?, conditions = '', grappled_by = NULL
           WHERE id = ? AND dead_since IS NOT NULL", [
            max(1, roll($s['dice'])), $s['id'],
        ]);
    }
    return q(
        // The spawn's columns come last, so its id and hp win over the template's (the HP dice).
        'SELECT m.*, s.* FROM spawns s JOIN monsters m ON m.id = s.monster_id
         WHERE s.room_id = ? AND s.dead_since IS NULL ORDER BY s.id',
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
        q('UPDATE users SET ' . END_FIGHT . ' WHERE id = ?', [$me['id']]);
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
    return fight($me, $target, 'hero_turn', 'Kill what?', $me['ranged'] || $me['thrown']);
}

/**
 * One round against $target (or the monster I'm fighting), starting the fight if need be, with $act as my action:
 * a function (array &$me, array &$foe, array &$lines): bool that returns true if the monster died. A $ranged attack
 * that starts a fight is an opening volley: the foe spends the round closing in, so it doesn't attack, and the shots
 * have no Disadvantage. After that a foe is always next to you (shortcut: no distances, until tactical step 1).
 */
function fight(array $me, string $target, callable $act, string $what = 'Kill what?', bool $ranged = false): array
{
    $foe = find_foe($me, $target);
    if (!$foe) {
        return [$target === '' || $me['fighting'] !== null ? $what : "You don't see a $target here."];
    }
    $lines = [];
    $me['volley'] = $ranged && $me['fighting'] === null;
    if ($me['fighting'] === null) {
        $mine = roll('1d20') + mod($me['dexterity']);
        $theirs = roll('1d20') + $foe['initiative'];
        $me['foe_first'] = $theirs > $mine; // ties go to the hero
        q(
            'UPDATE users SET fighting = ?, foe_first = ?, resting = NULL, rest_until = NULL WHERE id = ?',
            [$foe['id'], (int) $me['foe_first'], $me['id']]
        );
        $lines[] = "You attack the {$foe['name']}! Initiative: you $mine, it $theirs.";
        // What's left on it from fights nobody is still in has worn off, and my own old hold is gone.
        if (!q('SELECT 1 FROM users WHERE fighting = ? AND id <> ?', [$foe['id'], $me['id']])->fetch()) {
            [$foe['conditions'], $foe['grappled_by']] = ['', null];
        } elseif ($foe['grappled_by'] === $me['id']) {
            $foe['grappled_by'] = null;
        }
        q('UPDATE spawns SET conditions = ?, grappled_by = ? WHERE id = ?',
            [$foe['conditions'], $foe['grappled_by'], $foe['id']]);
    }
    // In a volley the foe spends this round closing in, so my shots go unanswered.
    $over = !$me['volley'] && $me['foe_first'] && monster_turn($me, $foe, $lines);
    if (!$over) {
        start_turn($me);
        if (has($foe, 'sapped')) { // shortcut: Sap ends at the start of my turn, though another hero may have sapped it
            set_condition($foe, 'sapped', false);
        }
        $over = $act($me, $foe, $lines);
        if ($me['bonus_used'] && !$over) { // my turn is over; a Bonus Action before my next one counts for that one
            $me['bonus_used'] = false;
            q('UPDATE users SET bonus_used = FALSE WHERE id = ?', [$me['id']]);
        }
        if ($me['volley'] && !$over) {
            $lines[] = "The {$foe['name']} closes in!";
        }
        $over = $over || (!$me['volley'] && !$me['foe_first'] && monster_turn($me, $foe, $lines));
    }
    if (!$over) {
        $lines[] = "You: {$me['hp']}/{$me['max_hp']} HP.  {$foe['name']}: {$foe['hp']} HP.";
    }
    return $lines;
}

/** My turn starts: every condition modeled so far ends here. */
function start_turn(array &$me): void
{
    if ($me['conditions'] !== '') {
        $me['conditions'] = '';
        q("UPDATE users SET conditions = '' WHERE id = ?", [$me['id']]);
    }
}

/**
 * Fleeing is a contest: d20 + Dex modifier against the monster's d20 + initiative. Failing draws an attack. A Rogue
 * from level 2 always gets away, with Cunning Action's Disengage.
 */
function cmd_flee(array $me): array
{
    $foe = $me['fighting'] === null ? null : find_foe($me, '');
    if (!$foe) {
        return ["You aren't fighting anything."];
    }
    start_turn($me);
    if ($me['class'] !== 'rogue' || $me['level'] < 2) {
        $mine = roll('1d20') + mod($me['dexterity']);
        $theirs = roll('1d20') + $foe['initiative'];
        if ($mine < $theirs) {
            $lines = ["You try to break away, but the {$foe['name']} cuts you off ($mine vs $theirs)!"];
            if (!monster_turn($me, $foe, $lines, 1)) {
                $lines[] = "You: {$me['hp']}/{$me['max_hp']} HP.";
            }
            return $lines;
        }
    }
    q('UPDATE users SET ' . END_FIGHT . ' WHERE id = ?', [$me['id']]);
    $dir = q('SELECT dir FROM exits WHERE room_id = ? ORDER BY RAND() LIMIT 1', [$me['room_id']])->fetchColumn();
    if ($dir === false) {
        return ["You break away from the {$foe['name']}, but there's nowhere to run."];
    }
    return ["You flee $dir from the {$foe['name']}!", ...cmd_move(['fighting' => null] + $me, $dir)];
}

/**
 * My weapon attacks on $foe this round (more than one with Extra Attack). Returns true if it died. Rage adds damage to
 * Strength attacks. A Rogue's Sneak Attack, once a turn with a Finesse or Ranged weapon, needs Advantage or another
 * hero on the same foe; Steady Aim gives them Advantage from level 3 (in a fight you never move). A Vex weapon's hit
 * gives Advantage on the next attack, and a Topple weapon's hit can knock it Prone (shortcut: MASTERIES are modeled for
 * any weapon you wield, not the few your class picks). A Ranged weapon has Disadvantage except in an opening volley.
 */
function hero_turn(array &$me, array &$foe, array &$lines): bool
{
    $swings = array_fill(0, $me['attacks'], $me);
    if ($me['offhand'] !== null && $me['attacks'] > 0) {
        $swings[] = ['off' => true] + $me['offhand'];
    }
    $sneak = $me['class'] === 'rogue';
    $cleaved = false;
    $helped = $sneak && q('SELECT 1 FROM users WHERE fighting = ? AND id <> ?', [$foe['id'], $me['id']])->fetch();
    foreach ($swings as $w) {
        $off = isset($w['off']);
        // Light: the extra attack takes my Bonus Action, unless its weapon's Nick makes it part of the Attack action.
        if ($off && $w['mastery'] !== 'nick' && !spend_bonus($me)) {
            break;
        }
        $aim = ($me['class'] === 'rogue' && $me['level'] >= 3) || (has($me, 'reckless') && $w['str_based']);
        [$d20, $edge] = attack_d20($me, $foe, $aim, $w['ranged'] && !$me['volley']);
        $total = $d20 + $w['to_hit'];
        $with = with($edge);
        [$you, $s] = $off ? ["Your off-hand {$w['weapon']}", 'es'] : ['You', ''];
        if ($d20 === 1 || ($d20 < 20 && $total < $foe['ac'])) {
            $why = $d20 === 1 ? 'natural 1' : "$total vs AC {$foe['ac']}";
            $lines[] = "$you miss$s the {$foe['name']} ($why$with).";
            if ($w['mastery'] === 'graze' && $w['attack_mod'] > 0) { // the modifier only: no Rage, no dice
                [$dmg, $note] = typed($w['attack_mod'], $w['damage_type'], $foe);
                $lines[] = "The blow grazes it for $dmg damage$note.";
                if (hurt_foe($me, $foe, $dmg, $lines)) {
                    return true;
                }
            }
            continue;
        }
        $crit = $d20 === 20;
        $rage = $me['raging'] && $w['str_based'] ? $me['rage_damage'] : 0;
        // The extra attack adds no ability modifier unless it's negative (shortcut: no Two-Weapon Fighting style yet).
        $dmg = roll($w['damage'], $crit) + ($off ? min(0, $w['attack_mod']) : $w['attack_mod']) + $rage;
        $sneaking = $sneak && ($w['finesse'] || $w['ranged']) && ($edge > 0 || $helped);
        if ($sneaking) {
            $dmg += roll(intdiv($me['level'] + 1, 2) . 'd6', $crit);
            $sneak = false;
        }
        [$dmg, $note] = typed(max(0, $dmg), $w['damage_type'], $foe);
        $lines[] = ($crit ? 'Critical hit! ' : '') . "$you hit" . ($off ? 's' : '') . " the {$foe['name']} "
            . "($total vs AC {$foe['ac']}$with) for $dmg damage$note" . ($sneaking ? ', with Sneak Attack.' : '.');
        if (hurt_foe($me, $foe, $dmg, $lines)) {
            return true;
        }
        if ($w['mastery'] === 'topple' && !has($foe, 'prone')) {
            $dc = 8 + $w['attack_mod'] + $me['prof_bonus'];
            [$save, $saved] = save($foe, 'con', $dc);
            $lines[] = $saved ? "The {$foe['name']} keeps its feet (Con save $save vs DC $dc)."
                : "The {$foe['name']} topples over, Prone (Con save $save vs DC $dc).";
            if (!$saved) {
                set_condition($foe, 'prone');
            }
        }
        if ($w['mastery'] === 'sap' && !has($foe, 'sapped')) {
            set_condition($foe, 'sapped');
        }
        if ($w['mastery'] === 'cleave' && !$w['ranged'] && !$me['volley'] && !$cleaved) {
            $cleaved = true;
            cleave($me, $w, $foe, $lines);
        }
        if ($w['mastery'] === 'vex' && !$me['advantage']) {
            $me['advantage'] = true;
            q('UPDATE users SET advantage = TRUE WHERE id = ?', [$me['id']]);
        }
    }
    return false;
}

/**
 * Cleave: after a melee hit, one attack a turn with the same weapon at a second monster here (shortcut: no
 * positions, so every monster in the room is within 5 feet of the first). Its damage adds no ability modifier unless
 * negative. The second monster doesn't join the fight.
 */
function cleave(array &$me, array $w, array $foe, array &$lines): void
{
    $other = array_values(array_filter(room_monsters((int) $me['room_id']), fn ($m) => $m['id'] !== $foe['id']))[0]
        ?? null;
    if ($other === null) {
        return;
    }
    $aim = has($me, 'reckless') && $w['str_based'];
    [$d20, $edge] = attack_d20($me, $other, $aim);
    $total = $d20 + $w['to_hit'];
    $with = with($edge);
    if ($d20 === 1 || ($d20 < 20 && $total < $other['ac'])) {
        $why = $d20 === 1 ? 'natural 1' : "$total vs AC {$other['ac']}";
        $lines[] = "You cleave at the {$other['name']} and miss ($why$with).";
        return;
    }
    $rage = $me['raging'] && $w['str_based'] ? $me['rage_damage'] : 0;
    $dmg = roll($w['damage'], $d20 === 20) + min(0, $w['attack_mod']) + $rage;
    [$dmg, $note] = typed(max(0, $dmg), $w['damage_type'], $other);
    $lines[] = ($d20 === 20 ? 'Critical hit! ' : '') . "You cleave into the {$other['name']} ($total vs AC "
        . "{$other['ac']}$with) for $dmg damage$note.";
    hurt_foe($me, $other, $dmg, $lines);
}

/** Spends my Bonus Action for this round of a fight, or returns false if it's spent. Outside a fight it's free. */
function spend_bonus(array &$me): bool
{
    if ($me['fighting'] === null) {
        return true;
    }
    if ($me['bonus_used']) {
        return false;
    }
    $me['bonus_used'] = true;
    q('UPDATE users SET bonus_used = TRUE WHERE id = ?', [$me['id']]);
    return true;
}

/**
 * The d20 for my attack roll at $foe, and its edge: 1 with Advantage ($adv, my saved one, or a Prone foe next to me),
 * -1 with Disadvantage ($dis, or a Prone foe shot at from afar), 0 with neither or both.
 */
function attack_d20(array &$me, array $foe, bool $adv = false, bool $dis = false): array
{
    $prone = has($foe, 'prone');
    [$adv, $dis] = [$adv || ($prone && !$me['volley']), $dis || ($prone && $me['volley'])];
    if ($me['advantage']) { // used up by this roll
        $adv = true;
        $me['advantage'] = false;
        q('UPDATE users SET advantage = FALSE WHERE id = ?', [$me['id']]);
    }
    $edge = $adv <=> $dis;
    return [d20($edge), $edge];
}

/** A d20 for a D20 Test: with Advantage (edge 1) the higher of two, with Disadvantage (-1) the lower. */
function d20(int $edge): int
{
    return match ($edge) {
        1 => max(roll('1d20'), roll('1d20')),
        -1 => min(roll('1d20'), roll('1d20')),
        default => roll('1d20'),
    };
}

/** How a combat line says a roll's edge. */
function with(int $edge): string
{
    return [-1 => ', with Disadvantage', 0 => '', 1 => ', with Advantage'][$edge];
}

/**
 * A saving throw by $who, a hero or a monster, with its 'str' to 'cha' save against $dc: [the total, whether it saved,
 * its edge]. No natural 1 or 20 (SRD 5.2.1 p.6). Rage gives Advantage on Str saves, and a Barbarian's Danger Sense
 * (level 2) on Dex saves (shortcut: nothing Incapacitates yet, which would turn Danger Sense off).
 */
function save(array $who, string $ability, int $dc): array
{
    $barbarian = ($who['class'] ?? null) === 'barbarian';
    $edge = (int) (($ability === 'str' && ($who['raging'] ?? false))
        || ($ability === 'dex' && $barbarian && $who['level'] >= 2));
    $total = d20($edge) + $who[$ability . '_save'];
    return [$total, $total >= $dc, $edge];
}

/** True if $who (a hero or a monster) has $condition. */
function has(array $who, string $condition): bool
{
    return in_array($condition, explode(',', $who['conditions']), true);
}

/** Gives $foe (a spawn) $condition, or takes it away. */
function set_condition(array &$foe, string $condition, bool $on = true): void
{
    $set = array_diff(array_filter(explode(',', $foe['conditions'])), [$condition]);
    $foe['conditions'] = implode(',', $on ? [...$set, $condition] : $set);
    q('UPDATE spawns SET conditions = ? WHERE id = ?', [$foe['conditions'], $foe['id']]);
}

/**
 * shove or grapple (SRD 5.2.1 Unarmed Strike, p.190) in place of one attack of my turn; any others follow with my
 * weapon. The foe makes a Str or Dex save, the better of its two, against 8 + my Str + PB. Failing a shove, it's
 * Prone; failing a grapple, it's Grappled by me, so it has Disadvantage attacking any other hero (Grappled p.182).
 * shortcut: no sizes or free hands, so anything can be shoved or grappled; add them with species and monster sizes.
 * Shove's push and the foe escaping a grapple are left out: there are no distances, and monsters never try.
 */
function cmd_maneuver(array $me, string $how, string $target): array
{
    return fight($me, $target, function (array &$me, array &$foe, array &$lines) use ($how): bool {
        $done = $how === 'shove' ? has($foe, 'prone') : $foe['grappled_by'] === $me['id'];
        if ($done) {
            $lines[] = "The {$foe['name']} is already " . ($how === 'shove' ? 'Prone.' : 'in your grip.');
            return hero_turn($me, $foe, $lines);
        }
        $dc = 8 + mod($me['strength']) + $me['prof_bonus'];
        $ability = $foe['str_save'] >= $foe['dex_save'] ? 'str' : 'dex';
        [$save, $saved] = save($foe, $ability, $dc);
        $roll = ucfirst($ability) . " save $save vs DC $dc";
        if ($saved) {
            $lines[] = "The {$foe['name']} " . ($how === 'shove' ? 'stands firm' : 'slips your grip') . " ($roll).";
        } elseif ($how === 'shove') {
            $lines[] = "You shove the {$foe['name']} to the ground, Prone ($roll).";
            set_condition($foe, 'prone');
        } else {
            $lines[] = "You grab hold of the {$foe['name']}, Grappled ($roll).";
            $foe['grappled_by'] = $me['id'];
            q('UPDATE spawns SET grappled_by = ? WHERE id = ?', [$me['id'], $foe['id']]);
        }
        $me['attacks']--;
        return hero_turn($me, $foe, $lines);
    }, ucfirst($how) . ' what?');
}

/**
 * Reckless Attack (Barbarian 2): a round of attacks with Advantage on Strength attack rolls, but attack rolls against
 * me have Advantage until the start of my next turn.
 */
function cmd_reckless(array $me, string $target): array
{
    if ($me['class'] !== 'barbarian' || $me['level'] < 2) {
        return ['Only Barbarians of level 2 or more attack recklessly.'];
    }
    return fight($me, $target, function (array &$me, array &$foe, array &$lines): bool {
        $me['conditions'] = 'reckless'; // fight() just cleared the others
        q("UPDATE users SET conditions = 'reckless' WHERE id = ?", [$me['id']]);
        $lines[] = 'You throw aside all concern for defense!';
        return hero_turn($me, $foe, $lines);
    }, 'Kill what?', $me['ranged'] || $me['thrown']);
}

/**
 * $dmg of $type dealt to $who (a hero or monster with resistances, immunities and vulnerabilities as SET strings),
 * after SRD 5.2.1 p.17: Immunity takes it all, Resistance halves it (rounded down), then Vulnerability doubles it.
 * Returns [the damage, a note for the combat line].
 */
function typed(int $dmg, string $type, array $who): array
{
    $is = fn (string $key): bool => in_array($type, explode(',', $who[$key]), true);
    if ($is('immunities')) {
        return [0, ' (immune)'];
    }
    $note = '';
    if ($is('resistances')) {
        [$dmg, $note] = [intdiv($dmg, 2), ' (resisted)'];
    }
    if ($is('vulnerabilities')) {
        [$dmg, $note] = [$dmg * 2, $note . ' (vulnerable)'];
    }
    return [$dmg, $note];
}

/** Deals $dmg to $foe. Returns true if that killed it. */
function hurt_foe(array &$me, array &$foe, int $dmg, array &$lines): bool
{
    q('UPDATE spawns SET hp = hp - ? WHERE id = ?', [$dmg, $foe['id']]);
    $foe['hp'] -= $dmg;
    if ($foe['hp'] > 0) {
        return false;
    }
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

/** The killer's reward: the stat block's full XP, a little gold by CR, levels, and maybe loot. */
function win(array &$me, array $foe, array &$lines): void
{
    q('UPDATE users SET ' . END_FIGHT . ' WHERE fighting = ?', [$foe['id']]);
    $cr = max(1, (int) ceil((float) $foe['cr']));
    $gold = roll($cr . 'd6');
    $xp = $me['xp'] + $foe['xp'];
    // Never lower: a level set by hand (bin scripts, tests) can be ahead of the XP.
    $level = max($me['level'], (int) q('SELECT MAX(level) FROM levels WHERE xp <= ?', [$xp])->fetchColumn());
    $gain = ($level - $me['level']) * (CLASSES[$me['class']]['per_level'] + mod($me['constitution'])); // fixed HP
    q(
        'UPDATE users SET xp = ?, gold = gold + ?, level = ?, max_hp = max_hp + ?, hp = hp + ? WHERE id = ?',
        [$xp, $gold, $level, $gain, $gain, $me['id']]
    );
    $lines[] = "The {$foe['name']} dies! You gain {$foe['xp']} XP and $gold gold.";
    if ($level > $me['level']) {
        $lines[] = "You reach level $level! +$gain max HP.";
    }
    // A Cleave can kill a second foe in the same turn, so later writes must start from these.
    [$me['xp'], $me['level'], $me['max_hp'], $me['hp']] = [$xp, $level, $me['max_hp'] + $gain, $me['hp'] + $gain];
    if (roll('1d' . LOOT_CHANCE) === 1 && ($item = loot((float) $foe['cr'], (int) $me['room_id'])) !== null) {
        $lines[] = "The {$foe['name']} drops " . lcfirst(a_an($item)) . '.';
    }
}

/** $foe attacks me: $n times, its Multiattack by default. Returns true if I died (and woke in town). */
function monster_turn(array &$me, array &$foe, array &$lines, ?int $n = null): bool
{
    if ($n === null && has($foe, 'prone')) { // its turn starts (a free attack when I flee is a Reaction, not a turn)
        set_condition($foe, 'prone', false);
        $lines[] = "The {$foe['name']} gets back up.";
    }
    // Held by another hero still fighting it, it has Disadvantage against me.
    $held = $foe['grappled_by'] !== null && $foe['grappled_by'] !== $me['id']
        && q('SELECT 1 FROM users WHERE id = ? AND fighting = ?', [$foe['grappled_by'], $foe['id']])->fetch();
    for ($i = 0; $i < ($n ?? $foe['attacks']); $i++) {
        $edge = has($me, 'reckless') <=> (has($foe, 'prone') || $held || has($foe, 'sapped'));
        if (has($foe, 'sapped')) { // Sap: only its next attack roll
            set_condition($foe, 'sapped', false);
        }
        $d20 = d20($edge);
        $total = $d20 + $foe['to_hit'];
        $its = "The {$foe['name']}'s {$foe['attack']}";
        $with = with($edge);
        if ($d20 === 1 || ($d20 < 20 && $total < $me['ac'])) {
            $lines[] = "$its misses you (" . ($d20 === 1 ? 'natural 1' : "$total vs AC {$me['ac']}") . "$with).";
            continue;
        }
        [$dmg, $note] = typed(max(0, roll($foe['damage'], $d20 === 20)), $foe['damage_type'], $me);
        if ($foe['extra_damage'] !== null) {
            [$extra, $extra_note] = typed(roll($foe['extra_damage'], $d20 === 20), $foe['extra_type'], $me);
            $dmg += $extra;
            $note = implode('', array_unique([$note, $extra_note]));
        }
        $me['hp'] -= $dmg;
        $lines[] = ($d20 === 20 ? 'Critical hit! ' : '')
            . "$its hits you ($total vs AC {$me['ac']}$with) for $dmg damage$note.";
        if ($me['hp'] <= 0) {
            // Dragon Knight's death, kept for async play: back to town with a quarter of your HP and half your gold.
            $me['hp'] = (int) ceil($me['max_hp'] / 4);
            q(
                'UPDATE users SET hp = ?, gold = gold - gold DIV 2, ' . END_FIGHT . ', room_id = ? WHERE id = ?',
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
    if ($me['class'] !== 'fighter') {
        return ['Only Fighters have Second Wind.'];
    }
    $left = $me['uses'] - $me['uses_spent'];
    if ($left <= 0) {
        return ['You have no Second Wind left. Rest to recover it.'];
    }
    if ($me['hp'] >= $me['max_hp']) {
        return ["You're already at full health."];
    }
    if (!spend_bonus($me)) {
        return [BONUS_USED];
    }
    $hp = min($me['max_hp'], $me['hp'] + roll('1d10') + $me['level']);
    q('UPDATE users SET hp = ?, uses_spent = uses_spent + 1 WHERE id = ?', [$hp, $me['id']]);
    return [
        'You catch your second wind and regain ' . ($hp - $me['hp']) . " HP. HP $hp/{$me['max_hp']}, "
            . ($left - 1) . ' Second Wind left.',
    ];
}

/** Rage: a Bonus Action, so it costs no turn. It lasts until the fight ends (the SRD's limit is 10 minutes). */
function cmd_rage(array $me): array
{
    if ($me['class'] !== 'barbarian') {
        return ['Only Barbarians can Rage.'];
    }
    if ($me['fighting'] === null) {
        return ['There is nothing here to rage at. Start a fight first.'];
    }
    if ($me['raging']) {
        return ['You are already raging!'];
    }
    $left = $me['uses'] - $me['uses_spent'];
    if ($left <= 0) {
        return ['You have no Rage left. Rest to recover it.'];
    }
    if (!spend_bonus($me)) {
        return [BONUS_USED];
    }
    q('UPDATE users SET raging = TRUE, uses_spent = uses_spent + 1 WHERE id = ?', [$me['id']]);
    return [
        "You fly into a Rage! +{$me['rage_damage']} damage with Strength attacks and Resistance to Bludgeoning, "
            . 'Piercing and Slashing damage until the fight ends. ' . ($left - 1) . ' Rage left.',
    ];
}

/** Spell slots I've spent, one count per spell level I have slots for. */
function slots_spent(array $me): array
{
    $spent = array_map('intval', array_filter(explode(',', $me['slots_spent']), 'strlen'));
    return array_pad($spent, count($me['slots']), 0);
}

/** "2/2 1/3": spell slots left of each level. */
function slots_left(array $me): string
{
    return implode(' ', array_map(fn ($max, $used) => ($max - $used) . "/$max", $me['slots'], slots_spent($me)));
}

/**
 * cast <spell> [monster]: the spell's words can be shortened ("cast sac rat"). Spells are rows of `spells`, resolved
 * by kind, the way Luminari's spell routines are: an attack rolls against AC, a save against 8 + PB + the spellcasting
 * modifier, healing adds that modifier. A spell uses the lowest slot left of its level or higher, and a higher slot
 * adds its upcast dice; a cantrip's dice grow with character level. shortcut: casters know every spell of their class
 * they have slots for, until `prepare` arrives; healing targets only yourself.
 */
function cmd_cast(array $me, string $arg): array
{
    $ability = CLASSES[$me['class']]['casting'] ?? null;
    if ($ability === null) {
        return ["You don't know any spells."];
    }
    $known = q(
        'SELECT * FROM spells WHERE FIND_IN_SET(?, classes) AND level <= ? ORDER BY level, name',
        [$me['class'], count($me['slots'])]
    )->fetchAll();
    [$spell, $target] = find_spell($known, $arg);
    $mod = mod($me[$ability]);
    $dc = 8 + $me['prof_bonus'] + $mod;
    if ($spell === null) {
        $lines = $arg === '' ? [] : ["You don't know a spell called '$arg'."];
        $lines[] = "Your spells (save DC $dc, spell attack +" . ($dc - 8) . '; slots ' . slots_left($me) . '):';
        foreach ($known as $k) {
            $usage = strtolower($k['name']) . ($k['kind'] === 'heal' ? '' : ' <monster>');
            $lines[] = sprintf('  %-24s %s', $usage, ($k['level'] === 0 ? 'cantrip: ' : '') . $k['about']);
        }
        return $lines;
    }

    $slot = 0; // the lowest slot left of the spell's level or higher; cantrips need none
    if ($spell['level'] > 0) {
        foreach (slots_spent($me) as $i => $used) {
            if ($i + 1 >= $spell['level'] && $used < $me['slots'][$i]) {
                $slot = $i + 1;
                break;
            }
        }
        if ($slot === 0) {
            return ['You have no spell slots left for that. A long rest restores them.'];
        }
    }
    $tier = 1 + intdiv($me['level'] + 1, 6); // cantrips: twice the dice at 5, three times at 11, four at 17
    $dice = $spell['level'] === 0
        ? preg_replace_callback('/^\d+/', fn ($n) => (string) ($n[0] * $tier), $spell['dice'])
        : $spell['dice'] . str_repeat('+' . $spell['upcast'], $spell['upcast'] === null ? 0 : $slot - $spell['level']);
    $name = $spell['name'] . ($slot > $spell['level'] ? " (level $slot)" : '');
    $spend = function (array &$me) use ($slot): void {
        if ($slot > 0) {
            $spent = slots_spent($me);
            $spent[$slot - 1]++;
            $me['slots_spent'] = implode(',', $spent);
            q('UPDATE users SET slots_spent = ? WHERE id = ?', [$me['slots_spent'], $me['id']]);
        }
    };

    if ($spell['kind'] === 'heal') {
        if ($me['hp'] >= $me['max_hp']) {
            return ["You're already at full health."];
        }
        $heal = function (array &$me) use ($spend, $dice, $mod, $name): string {
            $spend($me);
            $hp = min($me['max_hp'], $me['hp'] + max(0, roll($dice) + $mod));
            q('UPDATE users SET hp = ? WHERE id = ?', [$hp, $me['id']]);
            $line = "You cast $name and regain " . ($hp - $me['hp']) . " HP. HP $hp/{$me['max_hp']}. Slots "
                . slots_left($me) . '.';
            $me['hp'] = $hp;
            return $line;
        };
        if ($spell['casting'] === 'bonus' && !spend_bonus($me)) {
            return [BONUS_USED];
        }
        if ($spell['casting'] === 'bonus' || $me['fighting'] === null) { // a Bonus Action costs no turn
            return [$heal($me)];
        }
        return fight($me, '', function (array &$me, array &$foe, array &$lines) use ($heal): bool {
            $lines[] = $heal($me);
            return false;
        });
    }
    $act = function (array &$me, array &$foe, array &$lines) use ($spell, $spend, $dice, $mod, $dc, $name): bool {
        $spend($me);
        $type = $spell['damage_type'];
        if ($spell['kind'] === 'save') {
            $ability = $spell['save'];
            [$save, $saved] = save($foe, $ability, $dc);
            $roll = ucfirst($ability) . " save ($save vs DC $dc) against your $name";
            if ($saved && !$spell['half']) {
                $lines[] = "The {$foe['name']} makes its $roll and takes no damage.";
                return false;
            }
            $dmg = $saved ? intdiv(roll($dice), 2) : roll($dice);
            [$dmg, $note] = typed($dmg, $type, $foe);
            $lines[] = "The {$foe['name']} " . ($saved ? 'makes' : 'fails')
                . " its $roll and takes $dmg damage$note.";
            return hurt_foe($me, $foe, $dmg, $lines);
        }
        [$d20, $edge] = attack_d20($me, $foe, false, $spell['ranged'] && !$me['volley']);
        $total = $d20 + $me['prof_bonus'] + $mod;
        $with = with($edge);
        if ($d20 === 1 || ($d20 < 20 && $total < $foe['ac'])) {
            $why = $d20 === 1 ? 'natural 1' : "$total vs AC {$foe['ac']}";
            $lines[] = "Your $name misses the {$foe['name']} ($why$with).";
            return false;
        }
        [$dmg, $note] = typed(roll($dice, $d20 === 20), $type, $foe);
        $lines[] = ($d20 === 20 ? 'Critical hit! ' : '') . "Your $name hits the {$foe['name']} ($total vs AC "
            . "{$foe['ac']}$with) for $dmg damage$note.";
        if (hurt_foe($me, $foe, $dmg, $lines)) {
            return true;
        }
        if ($spell['name'] === 'Guiding Bolt') { // its rider: Advantage on the next attack roll against it
            $me['advantage'] = true; // shortcut: only on mine
            q('UPDATE users SET advantage = TRUE WHERE id = ?', [$me['id']]);
        }
        return false;
    };
    return fight($me, $target, $act, 'Cast it at what?', $spell['kind'] === 'attack' && $spell['ranged']);
}

/**
 * The spell among $known whose name the words of $arg start with, each word a prefix of the name's next word ("sac
 * fl"), and the words left over, the target: [spell or null, target]. The spell matching the most words wins.
 */
function find_spell(array $known, string $arg): array
{
    $words = preg_split('/\s+/', strtolower($arg), -1, PREG_SPLIT_NO_EMPTY);
    [$best, $used] = [null, 0];
    foreach ($known as $k) {
        $n = 0;
        foreach (explode(' ', strtolower($k['name'])) as $i => $word) {
            if (!isset($words[$i]) || !str_starts_with($word, $words[$i])) {
                break;
            }
            $n++;
        }
        if ($n > $used) {
            [$best, $used] = [$k, $n];
        }
    }
    return [$best, implode(' ', array_slice($words, $used))];
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
    $use = ['fighter' => 'Second Wind', 'barbarian' => 'Rage'][$me['class']] ?? null;
    if ($me['resting'] === 'long') {
        [$me['hp'], $me['hit_dice_spent'], $me['uses_spent'], $me['slots_spent']] = [$me['max_hp'], 0, 0, ''];
        $line = 'You finish a long rest: full HP, all Hit Dice' . ($use ? " and $use" : '')
            . ($me['slots'] ? ' and spell slots' : '') . ' back.';
    } else {
        $spent = 0;
        while ($me['hp'] < $me['max_hp'] && $me['hit_dice_spent'] < $me['level']) {
            $die = '1d' . CLASSES[$me['class']]['die'];
            $me['hp'] = min($me['max_hp'], $me['hp'] + max(0, roll($die) + mod($me['constitution'])));
            $me['hit_dice_spent']++;
            $spent++;
        }
        $me['uses_spent'] = max(0, $me['uses_spent'] - 1);
        $line = "You finish a short rest, spending $spent Hit " . ($spent === 1 ? 'Die' : 'Dice')
            . ($use ? " and regaining a $use." : '.');
    }
    $me['resting'] = null;
    q(
        'UPDATE users SET hp = ?, hit_dice_spent = ?, uses_spent = ?, slots_spent = ? WHERE id = ?',
        [$me['hp'], $me['hit_dice_spent'], $me['uses_spent'], $me['slots_spent'], $me['id']]
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
 * heavy) and +1 for a Fighter's Defense fighting style; unarmored, 10 + Dex (+ Con for a Barbarian's Unarmored
 * Defense); +2 for a shield. The weapon keys are wielding()'s for my main hand, and 'offhand' is a second Light
 * weapon's, or null.
 */
function gear(array $me): array
{
    $worn = [];
    foreach (objects_here('user_id', (int) $me['id']) as $o) {
        if ($o['worn']) {
            $worn[$o['offhand'] ? 'offhand' : $o['slot']] = $o;
        }
    }
    $dex = mod($me['dexterity']);
    $armor = $worn['armor'] ?? null;
    $ac = match (true) {
        $armor !== null => $armor['ac'] + ($me['class'] === 'fighter' ? 1 : 0)
            + match ($armor['armor']) { 'light' => $dex, 'medium' => min($dex, 2), 'heavy' => 0 },
        $me['class'] === 'barbarian' => 10 + $dex + mod($me['constitution']),
        default => 10 + $dex,
    };
    return [
        'ac' => $ac + ($worn['shield']['ac'] ?? 0),
        ...wielding($me, $worn['weapon'] ?? null, isset($worn['shield']) || isset($worn['offhand'])),
        'offhand' => isset($worn['offhand']) ? wielding($me, $worn['offhand'], true) : null,
    ];
}

/**
 * How I attack with $weapon (null: an Unarmed Strike, 1 + Str). A Versatile weapon does its bigger die unless the
 * other hand is busy, Finesse uses the better of Str and Dex, a Ranged weapon Dex. The Proficiency Bonus is added only
 * for a trained weapon.
 */
function wielding(array $me, ?array $weapon, bool $one_hand): array
{
    [$dex, $str] = [mod($me['dexterity']), mod($me['strength'])];
    $finesse = $weapon !== null && $weapon['finesse'];
    $ranged = $weapon !== null && $weapon['ranged'];
    $attack_mod = $finesse ? max($str, $dex) : ($ranged ? $dex : $str);
    return [
        'weapon' => $weapon['name'] ?? 'Unarmed Strike',
        'damage' => $weapon === null ? '1' : ($one_hand ? $weapon['dice'] : $weapon['versatile'] ?? $weapon['dice']),
        'damage_type' => $weapon['damage_type'] ?? 'bludgeoning', // an Unarmed Strike's
        'attack_mod' => $attack_mod,
        'to_hit' => $attack_mod + ($weapon === null || trained($me, $weapon) ? $me['prof_bonus'] : 0),
        'finesse' => $finesse,
        'str_based' => $finesse ? $str >= $dex : !$ranged,
        'ranged' => $ranged,
        'thrown' => $weapon !== null && $weapon['thrown'],
        'loading' => $weapon !== null && $weapon['loading'],
        'mastery' => $me['class'] === 'cleric' ? null : $weapon['mastery'] ?? null, // Clerics have no Weapon Mastery
    ];
}

/** If my class is trained with an item: armor and shields per CLASSES; Rogues use Simple, Finesse or Light weapons. */
function trained(array $me, array $o): bool
{
    return match ($o['slot']) {
        'armor' => in_array($o['armor'], CLASSES[$me['class']]['armor'], true),
        'shield' => in_array('shield', CLASSES[$me['class']]['armor'], true),
        'weapon' => $me['class'] !== 'rogue' || !$o['martial'] || $o['finesse'] || $o['light'],
        'potion' => true,
    };
}

/** An item's stats in a few words, for the inventory. */
function describe(array $o): string
{
    return match ($o['slot']) {
        'weapon' => "weapon, {$o['dice']}" . ($o['versatile'] ? " ({$o['versatile']} two-handed)" : '')
            . ($o['two_handed'] ? ', two-handed' : '') . ($o['finesse'] ? ', finesse' : '')
            . ($o['light'] ? ', light' : '') . (in_array($o['mastery'], MASTERIES, true) ? ", {$o['mastery']}" : '')
            . ($o['ranged'] ? ', ranged' : '') . ($o['thrown'] ? ', thrown' : '') . ($o['loading'] ? ', loading' : ''),
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
        // o.id comes last, so it wins over i.id.
        "SELECT i.*, o.id, o.worn, o.offhand FROM objects o JOIN items i ON i.id = o.item_id WHERE o.$col = ?
         ORDER BY i.name, o.id",
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
        $worn = $o['worn'] ? ($o['offhand'] ? '  (off hand)' : '  (equipped)') : '';
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
    $off = (bool) preg_match('/\s+off-?\s?hand$/i', $target, $m);
    $target = $off ? substr($target, 0, -strlen($m[0])) : $target;
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
    if ($off && ($o['slot'] !== 'weapon' || !$o['light'])) {
        return ["Only a Light weapon goes in your off hand, and the {$o['name']} isn't one."];
    }
    if ($o['worn']) {
        return ["You already have the {$o['name']} equipped."];
    }
    $trained = trained($me, $o);
    if (!$trained && $o['slot'] !== 'weapon') {
        return ["A " . CLASSES[$me['class']]['name'] . " isn't trained to use the {$o['name']}."];
    }
    if ($o['slot'] === 'armor' && $me['fighting'] !== null) { // the SRD's quickest armor takes a minute to don
        return ["You can't change armor in the middle of a fight!"];
    }
    // Light (SRD 5.2.1 p.89): the off hand takes a second Light weapon, with a Light one in the main hand.
    $main = fn ($c) => $c['worn'] && $c['slot'] === 'weapon' && !$c['offhand'];
    if ($off && !array_filter($carried, fn ($c) => $main($c) && $c['light'])) {
        return ['Wield a Light weapon in your main hand first.'];
    }
    // One of each slot, a Two-Handed weapon leaves no hand for a shield, and an off-hand weapon none for either.
    $two_hands = fn ($c) => $c['slot'] === 'weapon' && $c['two_handed'];
    $in_off = fn ($c) => $c['worn'] && $c['offhand'];
    $clashes = fn ($c) => $c['worn'] && match (true) {
        $off => $in_off($c) || $c['slot'] === 'shield',
        $o['slot'] === 'weapon' => $main($c) || ($in_off($c) && !$o['light'])
            || ($c['slot'] === 'shield' && $two_hands($o)),
        $o['slot'] === 'shield' => $c['slot'] === 'shield' || $two_hands($c) || $in_off($c),
        default => $c['slot'] === $o['slot'],
    };
    $lines = [];
    foreach (array_filter($carried, $clashes) as $old) {
        q('UPDATE objects SET worn = FALSE, offhand = FALSE WHERE id = ?', [$old['id']]);
        $lines[] = "You put away the {$old['name']}.";
    }
    q('UPDATE objects SET worn = TRUE, offhand = ? WHERE id = ?', [(int) $off, $o['id']]);
    $lines[] = "You equip the {$o['name']}" . ($off ? ' in your off hand.' : '.')
        . ($trained ? '' : " You aren't trained with it: no Proficiency Bonus.");
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
    if (!spend_bonus($me)) {
        return [BONUS_USED];
    }
    // Deleting by owner first means two requests at once can't both drink it.
    if (q('DELETE FROM objects WHERE id = ? AND user_id = ?', [$o['id'], $me['id']])->rowCount() === 0) {
        return ["You aren't carrying a $target to drink."];
    }
    $hp = min($me['max_hp'], $me['hp'] + roll($o['dice']));
    q('UPDATE users SET hp = ? WHERE id = ?', [$hp, $me['id']]);
    return ["You drink the {$o['name']} and regain " . ($hp - $me['hp']) . " HP. HP $hp/{$me['max_hp']}."];
}

/** The hero sheet as data: the score panel in play.js draws it, `score` prints it. Takes a with_stats() row. */
function hero_sheet(array $me): array
{
    $xp = q('SELECT level, xp FROM levels WHERE level IN (?, ?)', [$me['level'], $me['level'] + 1])
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    $c = CLASSES[$me['class']];
    $uses = max(0, $me['uses'] - $me['uses_spent']) . "/{$me['uses']}";
    return [
        'name' => $me['name'],
        'level' => $me['level'],
        'class' => $c['name'],
        'hp' => $me['hp'],
        'max_hp' => $me['max_hp'],
        'ac' => $me['ac'],
        'prof' => $me['prof_bonus'],
        'abilities' => array_map(
            fn ($a) => [ucfirst(substr($a, 0, 3)), $me[$a], mod($me[$a]), $me[substr($a, 0, 3) . '_save']],
            ABILITIES
        ),
        'weapon' => $me['weapon'],
        'to_hit' => $me['to_hit'],
        'damage' => sprintf('%s%+d', $me['damage'], $me['attack_mod']),
        'attacks' => $me['attacks'],
        'resources' => [
            ['Hit Dice', ($me['level'] - $me['hit_dice_spent']) . "/{$me['level']} d{$c['die']}"],
            match ($me['class']) {
                'fighter' => ['Second Wind', $uses],
                'barbarian' => ['Rage', $uses],
                'rogue' => ['Sneak Attack', intdiv($me['level'] + 1, 2) . 'd6'],
                'cleric' => ['Spell slots', slots_left($me)],
            },
            ...($me['offhand'] === null ? [] : [['Off hand', sprintf('%s %+d to hit, %s damage%s',
                $me['offhand']['weapon'], $me['offhand']['to_hit'], $me['offhand']['damage'],
                $me['offhand']['mastery'] === 'nick' ? ' (Nick)' : ', a Bonus Action')]]),
        ],
        'status' => array_values(array_filter([
            $me['raging'] ? 'raging' : null,
            ...explode(',', $me['conditions']),
            $me['resting'] !== null ? "taking a {$me['resting']} rest" : null,
        ])),
        'xp' => $me['xp'],
        'xp_from' => $xp[$me['level']],
        'xp_next' => $xp[$me['level'] + 1] ?? null,
        'gold' => $me['gold'],
    ];
}

function cmd_score(array $me): array
{
    $s = hero_sheet($me);
    return [
        "{$s['name']}, level {$s['level']} {$s['class']}",
        implode('  ', array_map(fn ($a) => sprintf('%s %d (%+d)', ...$a), $s['abilities'])),
        'Saves  ' . implode('  ', array_map(fn ($a) => sprintf('%s %+d', $a[0], $a[3]), $s['abilities'])),
        "HP {$s['hp']}/{$s['max_hp']}   AC {$s['ac']}   Proficiency Bonus +{$s['prof']}",
        sprintf('%s %+d to hit, %s damage', $s['weapon'], $s['to_hit'], $s['damage'])
            . ($s['attacks'] > 1 ? ", {$s['attacks']} attacks a round" : ''),
        implode('   ', [...array_map(fn ($r) => implode(' ', $r), $s['resources']), ...$s['status']]),
        "XP {$s['xp']}"
            . ($s['xp_next'] === null ? ' (max level)' : " ({$s['xp_next']} for level " . ($s['level'] + 1) . ')')
            . "   Gold {$s['gold']}",
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
