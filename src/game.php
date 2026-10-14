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
/** SQL: effect e (joined to its caster c) still lasts: not yet run out, or cast in a fight the caster is still in. */
const LIVE_EFFECT = '(e.until > NOW() OR (e.during IS NOT NULL AND c.fighting = e.during))';
const SIZES = ['tiny', 'small', 'medium', 'large', 'huge', 'gargantuan'];

/**
 * The classes a hero can choose, from SRD 5.2.1 (Barbarian p.28, Cleric p.36, Fighter p.47, Monk p.49, Paladin p.52,
 * Ranger p.56, Rogue p.61, Wizard p.77): Hit Die, level 1 HP and fixed HP per level (both before the Con modifier),
 * the Standard Array by Class (p.21) in Str Dex Con Int Wis Cha order, saving throw proficiencies, Weapon Mastery, a
 * caster's spellcasting ability and slot table (full or half), prepared spells by level, the ones a new hero starts
 * with and the ones always prepared (name => from level), armor training, and starting equipment A as [item id, worn].
 * Kits leave out ammunition, packs, tools and focuses. Weapon training is trained()'s (the Fighter's and Paladin's
 * Fighting Style is Defense, the Ranger's Archery, the Cleric's Divine Order is Protector, the subclasses are the
 * SRD's: Open Hand, Devotion, Hunter with Colossus Slayer and Multiattack Defense, Evoker). shortcut: casters know
 * every cantrip of their class, and a Wizard's spellbook holds every Wizard spell, until there are more to choose
 * from.
 */
const CLASSES = [
    'fighter' => [
        'name' => 'Fighter', 'die' => 10, 'hp' => 10, 'per_level' => 6, 'scores' => [15, 14, 13, 8, 10, 12],
        'saves' => ['strength', 'constitution'], 'mastery' => true,
        'armor' => ['light', 'medium', 'heavy', 'shield'], 'gold' => 4,
        'kit' => [ // Chain Mail, Greatsword, Flail, 8 Javelins
            [40, true], [15, true], [12, false], [5, false], [5, false], [5, false], [5, false], [5, false], [5, false],
            [5, false], [5, false],
        ],
        'about' => 'd10 HP, any armor. Second Wind heals you; more attacks as you level.',
    ],
    'barbarian' => [
        'name' => 'Barbarian', 'die' => 12, 'hp' => 12, 'per_level' => 7, 'scores' => [15, 13, 14, 10, 12, 8],
        'saves' => ['strength', 'constitution'], 'mastery' => true,
        'armor' => ['light', 'medium', 'shield'], 'gold' => 15,
        'kit' => [[14, true], [4, false], [4, false], [4, false], [4, false]], // Greataxe, 4 Handaxes
        'about' => 'd12 HP, tough even unarmored. Rage to hit harder and shrug off weapon blows.',
    ],
    'rogue' => [
        'name' => 'Rogue', 'die' => 8, 'hp' => 8, 'per_level' => 5, 'scores' => [12, 15, 13, 14, 10, 8],
        'saves' => ['dexterity', 'intelligence'], 'mastery' => true,
        'armor' => ['light'], 'gold' => 8,
        'kit' => [[32, true], [24, true], [2, false], [2, false], [72, false]], // Leather Armor, Shortsword, 2 Daggers,
        // Shortbow
        'about' => 'd8 HP, light armor. Sneak Attack with a finesse or ranged weapon when you have Advantage or help.',
    ],
    'cleric' => [
        'name' => 'Cleric', 'die' => 8, 'hp' => 8, 'per_level' => 5, 'scores' => [14, 8, 13, 10, 15, 12],
        'saves' => ['wisdom', 'charisma'], 'casting' => 'wisdom', 'slots' => 'slots',
        'prepared' => [4, 5, 6, 7, 9, 10, 11, 12, 14, 15, 16, 16, 17, 17, 18, 18, 19, 20, 21, 22], // by level
        'spells' => ['Bless', 'Cure Wounds', 'Guiding Bolt', 'Shield of Faith'], // the SRD's suggested first four
        'armor' => ['light', 'medium', 'heavy', 'shield'], 'gold' => 7,
        'kit' => [[35, true], [50, true], [7, true]], // Chain Shirt, Shield, Mace
        'about' => 'd8 HP, armor and shield. Spells that burn foes and heal you.',
    ],
    'monk' => [
        'name' => 'Monk', 'die' => 8, 'hp' => 8, 'per_level' => 5, 'scores' => [12, 15, 13, 10, 14, 8],
        'saves' => ['strength', 'dexterity'],
        'armor' => [], 'gold' => 11,
        'kit' => [[10, true], [2, false], [2, false], [2, false], [2, false], [2, false]], // Spear, 5 Daggers
        'about' => 'd8 HP, no armor. Fast fists and Focus: Flurry of Blows, Patient Defense, Stunning Strike.',
    ],
    'paladin' => [
        'name' => 'Paladin', 'die' => 10, 'hp' => 10, 'per_level' => 6, 'scores' => [15, 10, 13, 8, 12, 14],
        'saves' => ['wisdom', 'charisma'], 'mastery' => true, 'casting' => 'charisma', 'slots' => 'half_slots',
        'prepared' => [2, 3, 4, 5, 6, 6, 7, 7, 9, 9, 10, 10, 11, 11, 12, 12, 14, 14, 15, 15],
        'spells' => ['Divine Favor', 'Cure Wounds'], // the SRD suggests Heroism and Searing Smite, not modeled
        'always' => ['Divine Smite' => 2, 'Shield of Faith' => 3, 'Aid' => 5], // Paladin's Smite, Oath of Devotion
        'armor' => ['light', 'medium', 'heavy', 'shield'], 'gold' => 9,
        'kit' => [ // Chain Mail, Shield, Longsword, 6 Javelins
            [40, true], [50, true], [18, true], [5, false], [5, false], [5, false], [5, false], [5, false], [5, false],
        ],
        'about' => 'd10 HP, any armor. Lay On Hands, Divine Smite and holy spells.',
    ],
    'ranger' => [
        'name' => 'Ranger', 'die' => 10, 'hp' => 10, 'per_level' => 6, 'scores' => [12, 15, 13, 8, 14, 10],
        'saves' => ['strength', 'dexterity'], 'mastery' => true, 'casting' => 'wisdom', 'slots' => 'half_slots',
        'prepared' => [2, 3, 4, 5, 6, 6, 7, 7, 9, 9, 10, 10, 11, 11, 12, 12, 14, 14, 15, 15],
        'spells' => ['Cure Wounds', 'Ensnaring Strike'], // the SRD's suggested two
        'always' => ["Hunter's Mark" => 1], // Favored Enemy
        'armor' => ['light', 'medium', 'shield'], 'gold' => 7,
        'kit' => [[33, true], [23, true], [24, false], [77, false]], // Studded Leather, Scimitar, Shortsword, Longbow
        'about' => "d10 HP, light or medium armor. Bow or blades, Hunter's Mark and nature's spells.",
    ],
    'wizard' => [
        'name' => 'Wizard', 'die' => 6, 'hp' => 6, 'per_level' => 4, 'scores' => [8, 12, 13, 15, 14, 10],
        'saves' => ['intelligence', 'wisdom'], 'casting' => 'intelligence', 'slots' => 'slots',
        'prepared' => [4, 5, 6, 7, 9, 10, 11, 12, 14, 15, 16, 16, 17, 18, 19, 21, 22, 23, 24, 25],
        'spells' => ['Mage Armor', 'Magic Missile', 'Burning Hands', 'Thunderwave'],
        'armor' => [], 'gold' => 5,
        'kit' => [[8, true], [2, false], [2, false]], // Quarterstaff, 2 Daggers
        'about' => 'd6 HP, no armor. Fire Bolt, Magic Missile, Fireball: the most spells of all.',
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
    'flurry <monster>' => 'Monk 2: attack, then two Unarmed Strikes as your Bonus Action (1 Focus)',
    'patient <monster>' => 'Monk 2: attack, and Dodge as your Bonus Action: attacks on you have Disadvantage (1 Focus)',
    'stun <monster>' => 'Monk 5: attack; your first hit tries a Stunning Strike (1 Focus)',
    'second' => 'Fighter: Second Wind, regain 1d10 + your level in HP',
    'rage' => 'Barbarian: Rage for the rest of a fight; costs no turn',
    'lay [hero]' => 'Paladin: Lay On Hands, heal yourself or a hero here from your pool; costs no turn',
    'wholeness' => 'Monk 6: Wholeness of Body, regain a Martial Arts die + Wis in HP; costs no turn',
    'cast <spell>' => 'cast a spell, at a monster or a hero if it needs one (cast alone lists them)',
    'prepare <spells>' => 'after a long rest, choose your spells, with commas (prepare alone lists them)',
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
 * Adds the stats derived from class, level and gear to a `users` row. 'uses' is Second Wind, Rage, Focus Points, the
 * Lay On Hands pool or free Hunter's Marks, and 'slots' a caster's spell slots by spell level.
 */
function with_stats(array $me): array
{
    $lv = q('SELECT * FROM levels WHERE level = ?', [$me['level']])->fetch();
    $class = $me['class'];
    $table = CLASSES[$class]['slots'] ?? null; // a caster's column of spell slots in `levels`
    $me += [
        'prof_bonus' => $lv['prof_bonus'],
        'attacks' => match ($class) {
            'fighter' => $lv['attacks'],
            'barbarian', 'monk', 'paladin', 'ranger' => $me['level'] >= 5 ? 2 : 1, // Extra Attack
            default => 1,
        },
        'uses' => match ($class) {
            'fighter' => $lv['second_wind'],
            'barbarian' => $lv['rages'],
            'monk' => $me['level'] >= 2 ? $me['level'] : 0, // Focus Points
            'paladin' => 5 * $me['level'], // Lay On Hands
            'ranger' => 2 + intdiv($me['level'] - 1, 4), // Favored Enemy: free Hunter's Marks
            default => 0,
        },
        'rage_damage' => $lv['rage_damage'],
        'martial_arts' => $lv['martial_arts'],
        'slots' => $table === null ? [] : array_map('intval', explode(',', $lv[$table])),
        'resistances' => $me['raging'] ? 'bludgeoning,piercing,slashing' : '',
        'immunities' => '',
        'vulnerabilities' => '',
    ];
    $aura = $class === 'paladin' && $me['level'] >= 6 ? max(1, mod($me['charisma'])) : 0; // Aura of Protection
    foreach (ABILITIES as $a) { // str_save to cha_save, as on a monster, so save() takes either
        $proficient = in_array($a, CLASSES[$class]['saves'], true) || ($class === 'monk' && $me['level'] >= 14);
        $me[substr($a, 0, 3) . '_save'] = mod($me[$a]) + ($proficient ? $lv['prof_bonus'] : 0) + $aura;
    }
    $me += gear($me) + ['effects' => effects_on('user_id', (int) $me['id'])];
    $me['attacks'] = $me['loading'] ? 1 : $me['attacks']; // Loading: one shot a turn, Extra Attack or not
    foreach ($me['effects'] as $e) { // Mage Armor first: a base AC, if it beats mine
        if ($e['effect'] === 'base_ac' && !$me['armored']) {
            $me['ac'] = max($me['ac'], (int) $e['value'] + mod($me['dexterity']) + $me['shield_ac']);
        }
    }
    foreach ($me['effects'] as $e) {
        $me['ac'] += $e['effect'] === 'ac' ? (int) $e['value'] : 0; // Shield of Faith
        $me['max_hp'] += $e['effect'] === 'max_hp' ? (int) $e['value'] : 0; // Aid
    }
    $me['hp'] = min($me['hp'], $me['max_hp']); // an Aid that ran out
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
        in_array($verb, ['flurry', 'patient', 'stun'], true) => cmd_technique($me, $verb, trim($arg)),
        $verb === 'wholeness' => cmd_wholeness($me),
        $verb === 'flee' => cmd_flee($me),
        $verb === 'second' => cmd_second($me),
        $verb === 'rage' => cmd_rage($me),
        $verb === 'lay' => cmd_lay($me, trim($arg)),
        $verb === 'cast' => cmd_cast($me, trim($arg)),
        $verb === 'prepare' => cmd_prepare($me, trim($arg)),
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
         slots_spent = '', prepared = '', may_prepare = TRUE, once_used = FALSE, wholeness_spent = 0, gold = ?,
         room_id = ?, resting = NULL,
         rest_until = NULL, " . END_FIGHT . ' WHERE id = ?',
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
        q('DELETE FROM effects WHERE spawn_id = ?', [$s['id']]);
        // "dead_since IS NOT NULL" so two heroes arriving at once can't both respawn it.
        $hp = max(1, roll($s['dice']));
        q("UPDATE spawns SET dead_since = NULL, hp = ?, max_hp = ?, conditions = '', grappled_by = NULL
           WHERE id = ? AND dead_since IS NOT NULL", [$hp, $hp, $s['id']]);
    }
    $monsters = q(
        // The spawn's columns come last, so its id and hp win over the template's (the HP dice).
        'SELECT m.*, s.* FROM spawns s JOIN monsters m ON m.id = s.monster_id
         WHERE s.room_id = ? AND s.dead_since IS NULL ORDER BY s.id',
        [$room_id]
    )->fetchAll();
    return array_map(fn ($m) => $m + ['effects' => effects_on('spawn_id', $m['id'])], $monsters);
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
        $me['fighting'] = $foe['id'];
        q(
            'UPDATE users SET fighting = ?, foe_first = ?, resting = NULL, rest_until = NULL WHERE id = ?',
            [$foe['id'], (int) $me['foe_first'], $me['id']]
        );
        $lines[] = "You attack the {$foe['name']}! Initiative: you $mine, it $theirs.";
        if ($me['class'] === 'monk' && $me['level'] >= 2) {
            focus_on_initiative($me, $lines);
        }
        // My effects from earlier fights now last only their own time, even if I'm back against the same spawn.
        q('UPDATE effects SET during = NULL WHERE caster_id = ? AND during IS NOT NULL', [$me['id']]);
        $foe['effects'] = effects_on('spawn_id', $foe['id']);
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
        // shortcut: these end at the start of my turn, though another hero may have caused them
        foreach (['sapped', 'stunned', 'reeling'] as $c) {
            if (has($foe, $c)) {
                set_condition($foe, $c, false);
            }
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
 * or Monk from level 2 always gets away, with Cunning Action's or Step of the Wind's Disengage.
 */
function cmd_flee(array $me): array
{
    $foe = $me['fighting'] === null ? null : find_foe($me, '');
    if (!$foe) {
        return ["You aren't fighting anything."];
    }
    start_turn($me);
    $held = has($foe, 'paralyzed') || has($foe, 'stunned'); // a foe that can't act can't stop you
    if ((!in_array($me['class'], ['rogue', 'monk'], true) || $me['level'] < 2) && !$held) {
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
 * My weapon attacks on $foe this round (more than one with Extra Attack), then any off-hand attack and a Monk's
 * Unarmed Strikes (see cmd_technique() for what Focus adds). Returns true if it died. Rage adds damage to
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
    // A Monk's Bonus Action Unarmed Strike (Martial Arts), or Flurry of Blows' two or three, already paid for.
    $flurry = $me['flurry'] ?? 0;
    if (($me['arts'] || $flurry) && !$me['volley']) {
        for ($i = 0; $i < max(1, $flurry); $i++) {
            $swings[] = ['strike' => $flurry ? 'flurry' : 'bonus'] + $me['unarmed'];
        }
    }
    $sneak = $me['class'] === 'rogue';
    $colossus = $me['class'] === 'ranger' && $me['level'] >= 3; // Colossus Slayer, once a turn
    $cleaved = false;
    $helped = $sneak && q('SELECT 1 FROM users WHERE fighting = ? AND id <> ?', [$foe['id'], $me['id']])->fetch();
    foreach ($swings as $w) {
        $off = isset($w['off']);
        // Light: the extra attack takes my Bonus Action, unless its weapon's Nick makes it part of the Attack action.
        if ((($off && $w['mastery'] !== 'nick') || ($w['strike'] ?? '') === 'bonus') && !spend_bonus($me)) {
            break;
        }
        $aim = ($me['class'] === 'rogue' && $me['level'] >= 3) || (has($me, 'reckless') && $w['str_based']);
        [$d20, $edge, $bonus, $with] = attack_d20($me, $foe, $aim, $w['ranged'] && !$me['volley']);
        $total = $d20 + $w['to_hit'] + $bonus;
        [$you, $s] = match (true) {
            $off => ["Your off-hand {$w['weapon']}", 'es'],
            isset($w['strike']) => ['Your Unarmed Strike', 'es'],
            default => ['You', ''],
        };
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
        // Paralyzed: a hit from within 5 feet is a Critical Hit (shortcut: a melee one, as everything is that close).
        $crit = $d20 === 20 || (has($foe, 'paralyzed') && !$w['ranged'] && !$me['volley']);
        $rage = $me['raging'] && $w['str_based'] ? $me['rage_damage'] : 0;
        // The extra attack adds no ability modifier unless it's negative (shortcut: no Two-Weapon Fighting style yet).
        $dmg = roll($w['damage'], $crit) + ($off ? min(0, $w['attack_mod']) : $w['attack_mod']) + $rage;
        $sneaking = $sneak && ($w['finesse'] || $w['ranged']) && ($edge > 0 || $helped);
        if ($sneaking) {
            $dmg += roll(intdiv($me['level'] + 1, 2) . 'd6', $crit);
            $sneak = false;
        }
        $typed = typed(max(0, $dmg), $w['damage_type'], $foe);
        if ($w['weapon'] === 'Unarmed Strike' && $me['class'] === 'monk' && $me['level'] >= 6) { // Empowered Strikes
            $force = typed(max(0, $dmg), 'force', $foe);
            $typed = $force[0] > $typed[0] ? [$force[0], $force[1] . ' (force)'] : $typed;
        }
        [$dmg, $note] = $typed;
        $melee = !$w['ranged'] && !$me['volley'];
        $slayer = $colossus && $foe['hp'] < $foe['max_hp'];
        foreach (extra_damage($me, $foe, $melee, $crit, $slayer ? $w['damage_type'] : null) as [$more, $more_note]) {
            $dmg += $more;
            $note .= $more_note;
        }
        $colossus = $colossus && !$slayer;
        $lines[] = ($crit ? 'Critical hit! ' : '') . "$you hit" . ($s === '' ? '' : 's') . " the {$foe['name']} "
            . "($total vs AC {$foe['ac']}$with) for $dmg damage$note" . ($sneaking ? ', with Sneak Attack.' : '.');
        if (($me['smite'] ?? null) !== null && ($melee || $me['smite']['ranged'])) {
            $dmg += smite($me, $foe, $crit, $lines);
        }
        if (hurt_foe($me, $foe, $dmg, $lines)) {
            return true;
        }
        if (($me['stun'] ?? false) && $w['monk'] && spend_focus($me)) { // Stunning Strike, on my first such hit
            $me['stun'] = false;
            $dc = focus_dc($me);
            [$save, $saved] = save($foe, 'con', $dc);
            $roll = 'Con save ' . vs($save, $dc);
            $lines[] = $saved
                ? "The {$foe['name']} reels but isn't Stunned ($roll); the next attack on it has Advantage."
                : "The {$foe['name']} is Stunned until your next turn ($roll)!";
            set_condition($foe, $saved ? 'reeling' : 'stunned');
        }
        if (($w['strike'] ?? '') === 'flurry' && $me['level'] >= 3 && !has($foe, 'prone')) { // Open Hand Technique
            $dc = focus_dc($me);
            [$save, $saved] = save($foe, 'dex', $dc);
            $lines[] = $saved ? "The {$foe['name']} keeps its feet (Dex save " . vs($save, $dc) . ').'
                : "Your open hand topples the {$foe['name']}, Prone (Dex save " . vs($save, $dc) . ').';
            if (!$saved) {
                set_condition($foe, 'prone');
            }
        }
        if ($w['mastery'] === 'topple' && !has($foe, 'prone')) {
            $dc = 8 + $w['attack_mod'] + $me['prof_bonus'];
            [$save, $saved] = save($foe, 'con', $dc);
            $lines[] = $saved ? "The {$foe['name']} keeps its feet (Con save " . vs($save, $dc) . ').'
                : "The {$foe['name']} topples over, Prone (Con save " . vs($save, $dc) . ').';
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
    [$d20, $edge, $bonus, $with] = attack_d20($me, $other, $aim);
    $total = $d20 + $w['to_hit'] + $bonus;
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
 * The d20 for my attack roll at $foe, its edge, any dice my effects add (Bless), and how a combat line says those:
 * [d20, edge, bonus, note]. The edge is 1 with Advantage ($adv, my saved one, a Paralyzed, Stunned, Blinded,
 * Restrained or outlined (Shining Smite) foe, a reeling one (used up), a Prone foe next to me, or my quarry once I'm a
 * level 17 Ranger), -1 with Disadvantage ($dis, or a Prone foe shot at from afar), 0 with neither or both.
 */
function attack_d20(array &$me, array &$foe, bool $adv = false, bool $dis = false): array
{
    $prone = has($foe, 'prone');
    $adv = $adv || ($prone && !$me['volley']) || has($foe, 'paralyzed') || has($foe, 'stunned')
        || has($foe, 'blinded') || has($foe, 'reeling') || has($foe, 'outlined') || has($foe, 'restrained')
        || ($me['class'] === 'ranger' && $me['level'] >= 17 && marking($me)); // Precise Hunter
    if (has($foe, 'reeling')) { // a Stunning Strike it saved against: only the next attack roll
        set_condition($foe, 'reeling', false);
    }
    $dis = $dis || ($prone && $me['volley']);
    if ($me['advantage']) { // used up by this roll
        $adv = true;
        $me['advantage'] = false;
        q('UPDATE users SET advantage = FALSE WHERE id = ?', [$me['id']]);
    }
    $edge = $adv <=> $dis;
    $d20 = d20($edge);
    [$bonus, $note] = d20_bonus($me);
    return [$d20, $edge, $bonus, with($edge) . $note];
}

/** The dice $who's effects add to a d20 test, rolled (Bless +1d4, Bane -1d4), and a note like ', +3 Bless'. */
function d20_bonus(array $who): array
{
    [$sum, $note] = [0, ''];
    foreach ($who['effects'] ?? [] as $e) {
        if ($e['effect'] === 'd20') {
            $n = str_starts_with($e['value'], '-') ? -roll(substr($e['value'], 1)) : roll($e['value']);
            $sum += $n;
            $note .= sprintf(', %+d %s', $n, $e['spell']);
        }
    }
    return [$sum, $note];
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
 * A saving throw by $who, a hero or a monster, with its 'str' to 'cha' save against $dc: [the total (null when it
 * fails automatically), whether it saved, its edge]. No natural 1 or 20 (SRD 5.2.1 p.6). Paralyzed and Stunned fail
 * Str and Dex saves. Rage gives Advantage on Str saves, and a Barbarian's Danger Sense (level 2) on Dex saves unless
 * Incapacitated; Restrained gives Disadvantage on Dex saves. Effects like Bless add their dice.
 */
function save(array $who, string $ability, int $dc): array
{
    if (in_array($ability, ['str', 'dex'], true) && (has($who, 'paralyzed') || has($who, 'stunned'))) {
        return [null, false, 0];
    }
    $barbarian = ($who['class'] ?? null) === 'barbarian';
    $edge = (($ability === 'str' && ($who['raging'] ?? false))
        || ($ability === 'dex' && $barbarian && $who['level'] >= 2 && !has($who, 'paralyzed')))
        <=> ($ability === 'dex' && has($who, 'restrained'));
    $total = d20($edge) + $who[$ability . '_save'] + d20_bonus($who)[0];
    return [$total, $total >= $dc, $edge];
}

/** A save's roll for a combat line: '11 vs DC 12', or that it failed automatically. */
function vs(?int $total, int $dc): string
{
    return $total === null ? "automatic fail, DC $dc" : "$total vs DC $dc";
}

/** True if $who (a hero or a monster) has $condition, its own or from a spell's effect. */
function has(array $who, string $condition): bool
{
    foreach ($who['effects'] ?? [] as $e) {
        if ($e['effect'] === 'condition' && $e['value'] === $condition) {
            return true;
        }
    }
    return in_array($condition, explode(',', $who['conditions']), true);
}

/** The live effects on a hero ($col 'user_id') or a monster ('spawn_id'), each with its spell's name. */
function effects_on(string $col, int $id): array
{
    $col = match ($col) { 'user_id' => 'user_id', 'spawn_id' => 'spawn_id' }; // the only two allowed in SQL
    return q(
        "SELECT e.*, s.name AS spell, s.damage_type, s.half FROM effects e JOIN users c ON c.id = e.caster_id
         JOIN spells s ON s.id = e.spell_id WHERE e.$col = ? AND " . LIVE_EFFECT . ' ORDER BY e.id',
        [$id]
    )->fetchAll();
}

/** The spell I'm concentrating on, or null. */
function concentrating(array $me): ?string
{
    $name = q(
        'SELECT s.name FROM effects e JOIN users c ON c.id = e.caster_id JOIN spells s ON s.id = e.spell_id
         WHERE e.caster_id = ? AND e.concentration AND ' . LIVE_EFFECT . ' LIMIT 1',
        [$me['id']]
    )->fetchColumn();
    return $name === false ? null : $name;
}

/** Ends my Concentration: every effect I hold with it, live or not, is gone. */
function end_concentration(array $me): void
{
    q('DELETE FROM effects WHERE caster_id = ? AND concentration', [$me['id']]);
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
 * weapon. The foe makes a Str or Dex save, the better of its two, against 8 + my Str (or a Monk's Dex) + PB. Failing a
 * shove, it's Prone; failing a grapple, it's Grappled by me, so it has Disadvantage attacking any other hero (Grappled
 * p.182).
 * The foe can be at most one size larger than me (shortcut: heroes are all Medium until species, and free hands aren't
 * checked).
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
        if (array_search($foe['size'], SIZES, true) > array_search('large', SIZES, true)) { // Medium heroes
            $lines[] = "The {$foe['name']} is too big to " . ($how === 'shove' ? 'shove.' : 'grab.');
            return hero_turn($me, $foe, $lines);
        }
        $dc = 8 + max(mod($me['strength']), $me['arts'] ? mod($me['dexterity']) : -5) + $me['prof_bonus'];
        $ability = $foe['str_save'] >= $foe['dex_save'] ? 'str' : 'dex';
        [$save, $saved] = save($foe, $ability, $dc);
        $roll = ucfirst($ability) . ' save ' . vs($save, $dc);
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
    if (has($foe, 'paralyzed') || has($foe, 'stunned') || has($foe, 'incapacitated')) { // no turn, no Reaction
        if ($n !== null) {
            return false;
        }
        $why = has($foe, 'paralyzed') ? 'Paralyzed' : (has($foe, 'stunned') ? 'Stunned' : 'halted');
        $lines[] = "The {$foe['name']} is $why and can't act.";
        foreach ($foe['effects'] as $i => $e) { // Command lasts that one turn
            if ($e['value'] === 'incapacitated') {
                q('DELETE FROM effects WHERE id = ?', [$e['id']]);
                unset($foe['effects'][$i]);
            }
        }
        return end_of_turn($me, $foe, $lines);
    }
    // Held by another hero still fighting it, it has Disadvantage against me.
    $held = $foe['grappled_by'] !== null && $foe['grappled_by'] !== $me['id']
        && q('SELECT 1 FROM users WHERE id = ? AND fighting = ?', [$foe['grappled_by'], $foe['id']])->fetch();
    $deflect = $me['class'] === 'monk' && $me['level'] >= 3; // my Reaction, once a turn of theirs
    $hit_me = false; // Multiattack Defense (Ranger 7): after one hit, its other attacks this turn have Disadvantage
    for ($i = 0; $i < ($n ?? $foe['attacks']); $i++) {
        $edge = has($me, 'reckless') <=> (has($foe, 'prone') || $held || has($foe, 'sapped') || has($foe, 'blinded')
            || has($foe, 'restrained') || has($me, 'dodging') || $hit_me);
        if (has($foe, 'sapped')) { // Sap: only its next attack roll
            set_condition($foe, 'sapped', false);
        }
        $d20 = d20($edge);
        [$bonus, $note] = d20_bonus($foe); // Bane
        $total = $d20 + $foe['to_hit'] + $bonus;
        $its = "The {$foe['name']}'s {$foe['attack']}";
        $with = with($edge) . $note;
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
        // Deflect Attacks (Monk 3): my Reaction cuts a Bludgeoning, Piercing or Slashing hit (any, from level 13).
        $deflected = 0;
        $bps = array_intersect([$foe['damage_type'], $foe['extra_type']], ['bludgeoning', 'piercing', 'slashing']);
        if ($deflect && $dmg > 0 && ($bps || $me['level'] >= 13)) {
            $deflect = false;
            $deflected = min($dmg, roll('1d10') + mod($me['dexterity']) + $me['level']);
            $dmg -= $deflected;
        }
        $me['hp'] -= $dmg;
        $hit_me = $me['class'] === 'ranger' && $me['level'] >= 7;
        $lines[] = ($d20 === 20 ? 'Critical hit! ' : '') . "$its hits you ($total vs AC {$me['ac']}$with) for $dmg "
            . "damage$note" . ($deflected ? ", $deflected deflected." : '.');
        if ($deflected && $dmg === 0 && spend_focus($me) && redirect($me, $foe, $lines)) {
            q('UPDATE users SET hp = ? WHERE id = ?', [$me['hp'], $me['id']]);
            return true;
        }
        if ($me['hp'] <= 0) {
            // Dragon Knight's death, kept for async play: back to town with a quarter of your HP and half your gold.
            $me['hp'] = (int) ceil($me['max_hp'] / 4);
            q(
                'UPDATE users SET hp = ?, gold = gold - gold DIV 2, ' . END_FIGHT . ', room_id = ? WHERE id = ?',
                [$me['hp'], START_ROOM, $me['id']]
            );
            end_concentration($me);
            array_push($lines, 'You have died!', 'You wake in the town square, bruised and lighter of purse.');
            array_push($lines, ...cmd_look(['room_id' => START_ROOM, 'fighting' => null] + $me));
            return true;
        }
        $relentless = $me['class'] === 'ranger' && $me['level'] >= 13; // Hunter's Mark holds
        if ($dmg > 0 && ($spell = concentrating($me)) !== null && !($relentless && $spell === "Hunter's Mark")) {
            $dc = min(30, max(10, intdiv($dmg, 2)));
            [$save, $saved] = save($me, 'con', $dc);
            $lines[] = $saved ? "You keep your Concentration on $spell (Con save " . vs($save, $dc) . ').'
                : "You lose your Concentration on $spell (Con save " . vs($save, $dc) . ').';
            if (!$saved) {
                end_concentration($me);
                $foe['effects'] = effects_on('spawn_id', $foe['id']);
                $me['effects'] = effects_on('user_id', $me['id']);
            }
        }
    }
    q('UPDATE users SET hp = ? WHERE id = ?', [$me['hp'], $me['id']]);
    return $n === null && end_of_turn($me, $foe, $lines);
}

/**
 * The end of $foe's turn: my auras hurt it (Spirit Guardians: its save for half), then it repeats each save its
 * effects allow, and one it makes ends that effect. Returns true if the aura killed it. shortcut: only the auras of
 * the hero it fights this turn, as each hero's command is a turn of its own.
 */
function end_of_turn(array &$me, array &$foe, array &$lines): bool
{
    foreach ($me['effects'] as $e) {
        if ($e['effect'] !== 'aura') {
            continue;
        }
        [$save, $saved] = save($foe, $e['save'], $e['dc']);
        $dmg = roll($e['value']);
        [$dmg, $note] = typed($saved && $e['half'] ? intdiv($dmg, 2) : $dmg, $e['damage_type'], $foe);
        $lines[] = "Your {$e['spell']} sear" . ($e['spell'] === 'Spirit Guardians' ? '' : 's') . " the {$foe['name']} ("
            . ucfirst($e['save']) . ' save ' . vs($save, $e['dc']) . ") for $dmg damage$note.";
        if (hurt_foe($me, $foe, $dmg, $lines)) {
            return true;
        }
    }
    repeat_saves($foe, $lines);
    return false;
}

/** $foe repeats each save its effects allow, and one it makes ends that effect. */
function repeat_saves(array &$foe, array &$lines): void
{
    foreach ($foe['effects'] as $i => $e) {
        if ($e['save'] === null || $e['effect'] === 'aura') {
            continue;
        }
        [$save, $saved] = save($foe, $e['save'], $e['dc']);
        $roll = ucfirst($e['save']) . ' save ' . vs($save, $e['dc']);
        if ($saved) {
            q('DELETE FROM effects WHERE id = ?', [$e['id']]);
            unset($foe['effects'][$i]);
            $lines[] = "The {$foe['name']} shakes off your {$e['spell']} ($roll).";
        } else {
            $lines[] = "The {$foe['name']} is still held by your {$e['spell']} ($roll).";
        }
    }
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

/** Spends one of my Focus Points (Monk 2), or returns false if none are left. */
function spend_focus(array &$me): bool
{
    if ($me['uses_spent'] >= $me['uses']) {
        return false;
    }
    $me['uses_spent']++;
    q('UPDATE users SET uses_spent = ? WHERE id = ?', [$me['uses_spent'], $me['id']]);
    return true;
}

/** The save DC of a Monk's Focus features: 8 + Wis + PB. */
function focus_dc(array $me): int
{
    return 8 + mod($me['wisdom']) + $me['prof_bonus'];
}

/**
 * A Monk rolling Initiative: Uncanny Metabolism (level 2, once a long rest) gets back every Focus Point and a Martial
 * Arts die + level in HP, used once any point is spent; failing that, Perfect Focus (15) tops points up to 4.
 */
function focus_on_initiative(array &$me, array &$lines): void
{
    if (!$me['once_used'] && $me['uses_spent'] > 0) {
        $me['hp'] = min($me['max_hp'], $me['hp'] + roll('1d' . $me['martial_arts']) + $me['level']);
        [$me['uses_spent'], $me['once_used']] = [0, 1];
        q('UPDATE users SET hp = ?, uses_spent = 0, once_used = TRUE WHERE id = ?', [$me['hp'], $me['id']]);
        $lines[] = "Uncanny Metabolism: your Focus Points return, and HP {$me['hp']}/{$me['max_hp']}.";
    } elseif ($me['level'] >= 15 && $me['uses'] - $me['uses_spent'] < 4) {
        $me['uses_spent'] = $me['uses'] - 4;
        q('UPDATE users SET uses_spent = ? WHERE id = ?', [$me['uses_spent'], $me['id']]);
        $lines[] = 'Perfect Focus: you have 4 Focus Points.';
    }
}

/**
 * Deflect Attacks took a hit to 0 and I spent a Focus Point: $foe makes a Dex save or takes two Martial Arts dice + my
 * Dex of the attack's type. Returns true if that killed it.
 */
function redirect(array &$me, array &$foe, array &$lines): bool
{
    $dc = focus_dc($me);
    [$save, $saved] = save($foe, 'dex', $dc);
    $roll = 'Dex save ' . vs($save, $dc);
    if ($saved) {
        $lines[] = "You turn the blow back, but the {$foe['name']} dodges it ($roll).";
        return false;
    }
    $dice = '2d' . $me['martial_arts'] . sprintf('%+d', mod($me['dexterity']));
    [$dmg, $note] = typed(max(0, roll($dice)), $foe['damage_type'], $foe);
    $lines[] = "You turn the blow back on the {$foe['name']} ($roll) for $dmg damage$note.";
    return hurt_foe($me, $foe, $dmg, $lines);
}

/**
 * A round of attacks with a Monk technique, each 1 Focus Point (SRD 5.2.1 p.49-52): flurry (Flurry of Blows, the
 * Bonus Action is two Unarmed Strikes, three from level 10; from level 3 Open Hand Technique lets each hit topple),
 * patient (Patient Defense, the Bonus Action is a Dodge: attacks on me have Disadvantage until my next turn) and stun
 * (Stunning Strike, level 5: my first hit with a Monk weapon or Unarmed Strike spends the point, and the foe makes a
 * Con save or is Stunned until my next turn; if it saves, the next attack on it has Advantage). Patient Defense's
 * Temporary Hit Points (level 10) are left out: there are none yet.
 */
function cmd_technique(array $me, string $how, string $target): array
{
    $level = $how === 'stun' ? 5 : 2;
    if ($me['class'] !== 'monk' || $me['level'] < $level) {
        return ["Only Monks of level $level or more know that technique."];
    }
    if ($me['uses_spent'] >= $me['uses']) {
        return ['You have no Focus Points left. Rest to recover them.'];
    }
    if ($how !== 'stun' && $me['bonus_used']) {
        return [BONUS_USED];
    }
    return fight($me, $target, function (array &$me, array &$foe, array &$lines) use ($how): bool {
        if ($how === 'stun') {
            $me['stun'] = true;
            return hero_turn($me, $foe, $lines);
        }
        spend_bonus($me);
        spend_focus($me);
        if ($how === 'flurry') {
            $me['flurry'] = $me['level'] >= 10 ? 3 : 2;
            $lines[] = 'You unleash a Flurry of Blows!';
        } else {
            $me['conditions'] = 'dodging'; // fight() just cleared the others
            q("UPDATE users SET conditions = 'dodging' WHERE id = ?", [$me['id']]);
            $lines[] = 'You settle into Patient Defense, Dodging.';
        }
        return hero_turn($me, $foe, $lines);
    }, ucfirst($how) . ' what?');
}

/**
 * Wholeness of Body (Open Hand 6): a Bonus Action, so it costs no turn. Heals a Martial Arts die + Wis (at least 1),
 * Wis modifier times (at least once) a long rest.
 */
function cmd_wholeness(array $me): array
{
    if ($me['class'] !== 'monk' || $me['level'] < 6) {
        return ['Only Monks of level 6 or more know Wholeness of Body.'];
    }
    $left = max(1, mod($me['wisdom'])) - $me['wholeness_spent'];
    if ($left <= 0) {
        return ['You have no Wholeness of Body left. A long rest restores it.'];
    }
    if ($me['hp'] >= $me['max_hp']) {
        return ["You're already at full health."];
    }
    if (!spend_bonus($me)) {
        return [BONUS_USED];
    }
    $hp = min($me['max_hp'], $me['hp'] + max(1, roll('1d' . $me['martial_arts']) + mod($me['wisdom'])));
    q('UPDATE users SET hp = ?, wholeness_spent = wholeness_spent + 1 WHERE id = ?', [$hp, $me['id']]);
    return ['Your breath steadies and you regain ' . ($hp - $me['hp']) . " HP. HP $hp/{$me['max_hp']}, "
        . ($left - 1) . ' Wholeness of Body left.'];
}

/**
 * Lay On Hands (Paladin 1): a Bonus Action, so it costs no turn. Heals me or a hero here I name from a pool of 5 HP a
 * level, as much as they're missing; a long rest refills it.
 */
function cmd_lay(array $me, string $target): array
{
    if ($me['class'] !== 'paladin') {
        return ['Only Paladins Lay On Hands.'];
    }
    $who = $me;
    if ($target !== '') {
        $others = q('SELECT * FROM users WHERE room_id = ? AND id <> ? AND setup IS NULL ORDER BY name',
            [$me['room_id'], $me['id']])->fetchAll();
        $who = array_values(array_filter($others, fn ($o) => names($target, $o['name'])))[0] ?? null;
        if ($who === null) {
            return ["There's no hero called '$target' here."];
        }
        $who = with_stats($who);
    }
    $pool = $me['uses'] - $me['uses_spent'];
    $heal = min($pool, $who['max_hp'] - $who['hp']);
    if ($heal <= 0) {
        return [$pool <= 0 ? 'Your Lay On Hands pool is empty. A long rest refills it.'
            : ($who['id'] === $me['id'] ? "You're" : "{$who['name']} is") . ' already at full health.'];
    }
    if (!spend_bonus($me)) {
        return [BONUS_USED];
    }
    q('UPDATE users SET hp = hp + ? WHERE id = ?', [$heal, $who['id']]);
    q('UPDATE users SET uses_spent = uses_spent + ? WHERE id = ?', [$heal, $me['id']]);
    $hp = $who['hp'] + $heal;
    $line = ($who['id'] === $me['id'] ? 'You regain' : "{$who['name']} regains") . " $heal HP. HP $hp/{$who['max_hp']}";
    return ["$line, " . ($pool - $heal) . ' left in your Lay On Hands pool.'];
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

/** My spell save DC: 8 + PB + my spellcasting ability's modifier. */
function spell_dc(array $me): int
{
    return 8 + $me['prof_bonus'] + mod($me[CLASSES[$me['class']]['casting']]);
}

/** True while my Hunter's Mark lasts. */
function marking(array $me): bool
{
    return in_array("Hunter's Mark", array_column($me['effects'], 'spell'), true);
}

/** The lowest spell slot I have left of $level or higher, or 0 (none, or a cantrip). */
function lowest_slot(array $me, int $level): int
{
    foreach (slots_spent($me) as $i => $used) {
        if ($level > 0 && $i + 1 >= $level && $used < $me['slots'][$i]) {
            return $i + 1;
        }
    }
    return 0;
}

/** Spends one of my spell slots of level $slot (0: none). */
function spend_slot(array &$me, int $slot): void
{
    if ($slot > 0) {
        $spent = slots_spent($me);
        $spent[$slot - 1]++;
        $me['slots_spent'] = implode(',', $spent);
        q('UPDATE users SET slots_spent = ? WHERE id = ?', [$me['slots_spent'], $me['id']]);
    }
}

/**
 * The smite I readied ($me['smite'], a spell of kind smite) lands with my melee hit on $foe: a Bonus Action that
 * spends the lowest slot left of its level or higher (a Paladin's first Divine Smite after a long rest is free), for
 * its dice and its upcast dice per level above (twice on a critical hit), and leaves its effect, after a save if it
 * has one (Ensnaring Strike). Divine Smite adds 1d8 against a Fiend or Undead. Returns the damage, for the hit to deal.
 */
function smite(array &$me, array &$foe, bool $crit, array &$lines): int
{
    [$spell, $me['smite']] = [$me['smite'], null];
    $free = $spell['name'] === 'Divine Smite' && !$me['once_used'];
    $slot = $free ? $spell['level'] : lowest_slot($me, $spell['level']);
    if ($slot === 0 || !spend_bonus($me)) { // an off-hand attack took the Bonus Action
        return 0;
    }
    if ($free) {
        $me['once_used'] = 1;
        q('UPDATE users SET once_used = TRUE WHERE id = ?', [$me['id']]);
    } else {
        spend_slot($me, $slot);
    }
    $name = $spell['name'] . ($slot > $spell['level'] ? " (level $slot)" : '') . ($free ? ' (free)' : '');
    $slots = ' Slots ' . slots_left($me) . '.';
    [$dmg, $saved] = [0, false];
    if ($spell['dice'] !== null) {
        $dice = $spell['dice'] . str_repeat('+' . $spell['upcast'], $slot - $spell['level'])
            . ($spell['name'] === 'Divine Smite' && in_array($foe['type'], ['fiend', 'undead'], true) ? '+1d8' : '');
        [$dmg, $note] = typed(roll($dice, $crit), $spell['damage_type'], $foe);
        $lines[] = "Your $name sears the {$foe['name']} for $dmg more damage$note.$slots";
    } else {
        $dc = spell_dc($me);
        [$save, $saved] = save($foe, $spell['save'], $dc);
        $roll = ucfirst($spell['save']) . ' save ' . vs($save, $dc);
        $lines[] = "Your $name: the {$foe['name']} " . ($saved ? "breaks free ($roll)."
            : 'is ' . ucfirst($spell['value']) . " ($roll).") . $slots;
    }
    if ($spell['effect'] !== null && !$saved) { // Shining Smite: attacks on it have Advantage
        start_effect($me, $spell, spell_dc($me), 'spawn_id', [$foe], $foe['id']);
        $foe['effects'] = effects_on('spawn_id', $foe['id']);
    }
    return $dmg;
}

/**
 * Damage my effects and features add to a weapon hit on $foe, each [damage, note]: Divine Favor's and Hunter's Mark's
 * dice (a d10 for a level 20 Ranger's Foe Slayer), from Paladin level 11 Radiant Strikes' 1d8 Radiant on a $melee
 * hit, and Colossus Slayer's 1d8 of the weapon's damage type $slayer. Twice the dice on a $crit. shortcut: Hunter's
 * Mark marks whatever I fight, and moving it costs no Bonus Action.
 */
function extra_damage(array $me, array $foe, bool $melee, bool $crit, ?string $slayer = null): array
{
    $extra = array_map(fn ($e) => [$e['value'], $e['damage_type'], $e['spell']],
        array_filter($me['effects'], fn ($e) => $e['effect'] === 'damage'));
    foreach ($extra as &$x) {
        $x[0] = $x[2] === "Hunter's Mark" && $me['class'] === 'ranger' && $me['level'] >= 20 ? '1d10' : $x[0];
    }
    unset($x);
    if ($me['class'] === 'paladin' && $me['level'] >= 11 && $melee) {
        $extra[] = ['1d8', 'radiant', 'Radiant Strikes'];
    }
    if ($slayer !== null) {
        $extra[] = ['1d8', $slayer, 'Colossus Slayer'];
    }
    return array_map(function ($x) use ($foe, $crit) {
        [$dmg, $note] = typed(roll($x[0], $crit), $x[1], $foe);
        return [$dmg, " (+$dmg {$x[2]}" . ($note === '' ? '' : ',' . substr($note, 2, -1)) . ')'];
    }, $extra);
}

/**
 * cast <spell> [monster]: the spell's words can be shortened ("cast sac rat"). Spells are rows of `spells`, resolved
 * by kind, the way Luminari's spell routines are: an attack rolls against AC (once per ray), a save against 8 + PB +
 * the spellcasting modifier, healing adds that modifier, an auto spell just hits, a smite waits for my weapon hit. I
 * know cantrips, my prepared spells and the ones always prepared. A spell uses the lowest slot left of its level or
 * higher, and a higher slot adds its upcast dice; a cantrip's dice grow with character level.
 */
function cmd_cast(array $me, string $arg): array
{
    $ability = CLASSES[$me['class']]['casting'] ?? null;
    if ($ability === null) {
        return ["You don't know any spells."];
    }
    $known = q( // cantrips and my prepared spells I have slots for
        'SELECT * FROM spells WHERE FIND_IN_SET(?, classes) AND level <= ? AND (level = 0 OR FIND_IN_SET(id, ?))
         ORDER BY level, name',
        [$me['class'], count($me['slots']), implode(',', [...prepared_ids($me), ...always_ids($me)])]
    )->fetchAll();
    [$spell, $target] = find_spell($known, $arg);
    $mod = mod($me[$ability]);
    $dc = spell_dc($me);
    if ($spell === null) {
        $lines = $arg === '' ? [] : ["You don't know a spell called '$arg'."];
        $lines[] = "Your spells (save DC $dc, spell attack +" . ($dc - 8) . '; slots ' . slots_left($me) . '):';
        foreach ($known as $k) {
            $usage = strtolower($k['name']) . (['foe' => ' <monster>', 'ally' => ' [hero]'][$k['targets']] ?? '');
            $lines[] = sprintf('  %-28s %s', $usage, ($k['level'] === 0 ? 'cantrip: ' : '') . $k['about']);
        }
        return [...$lines, "'prepare' shows the others."];
    }

    // Favored Enemy: a Ranger casts Hunter's Mark free a few times a long rest.
    $free = $spell['name'] === "Hunter's Mark" && $me['class'] === 'ranger' && $me['uses_spent'] < $me['uses'];
    $slot = $free ? 0 : lowest_slot($me, $spell['level']); // cantrips need none
    if ($spell['kind'] === 'smite') { // its slot is spent on the hit
        $free = $spell['name'] === 'Divine Smite' && !$me['once_used']; // Paladin's Smite
        if (!$free && $slot === 0) {
            return ['You have no spell slots left for that. A long rest restores them.'];
        }
        if ($me['bonus_used']) {
            return [BONUS_USED];
        }
        return fight($me, $target, function (array &$me, array &$foe, array &$lines) use ($spell): bool {
            $me['smite'] = $spell;
            return hero_turn($me, $foe, $lines);
        }, 'Cast it at what?');
    }
    if ($spell['level'] > 0 && $slot === 0 && !$free) {
        return ['You have no spell slots left for that. A long rest restores them.'];
    }
    $tier = 1 + intdiv($me['level'] + 1, 6); // cantrips: twice the dice at 5, three times at 11, four at 17
    $dice = match (true) {
        $spell['dice'] === null => null,
        $spell['level'] === 0 => preg_replace_callback('/^\d+/', fn ($n) => (string) ($n[0] * $tier), $spell['dice']),
        default => $spell['dice'] . str_repeat('+' . $spell['upcast'], $spell['upcast'] === null ? 0
            : $slot - $spell['level']),
    };
    $name = $spell['name'] . ($slot > $spell['level'] ? " (level $slot)" : '') . ($free ? ' (free)' : '');
    $up = max(0, $slot - $spell['level']); // slot levels above the spell's
    $spend = function (array &$me) use ($slot, $free): void {
        spend_slot($me, $slot);
        if ($free) {
            $me['uses_spent']++;
            q('UPDATE users SET uses_spent = ? WHERE id = ?', [$me['uses_spent'], $me['id']]);
        }
    };

    if ($spell['targets'] !== 'foe') { // healing or a buff, on myself or heroes here
        $others = q(
            'SELECT * FROM users WHERE room_id = ? AND id <> ? AND setup IS NULL ORDER BY name',
            [$me['room_id'], $me['id']]
        )->fetchAll();
        $named = array_values(array_filter($others, fn ($o) => names($target, $o['name'])));
        if ($spell['targets'] === 'ally' && $target !== '' && !$named) {
            return ["There's no hero called '$target' here."];
        }
        $self = $spell['targets'] !== 'ally' || $target === '';
        $who = match ($spell['targets']) {
            'self' => [],
            'ally' => array_slice($named, 0, 1),
            'allies' => array_slice($others, 0, $spell['count'] - 1 + $spell['upcast_targets'] * $up),
        };
        if ($spell['kind'] === 'heal' && $self && !$who && $me['hp'] >= $me['max_hp']) {
            return ["You're already at full health."];
        }
        $cast = function (array &$me) use ($spell, $spend, $dice, $mod, $name, $slot, $dc, $up, $self, $who): string {
            $spend($me);
            $slots = $slot > 0 ? ' Slots ' . slots_left($me) . '.' : '';
            $targets = [...($self ? [$me] : []), ...$who];
            if ($spell['kind'] === 'buff') {
                $value = match ($spell['effect']) {
                    'max_hp' => (string) ((int) $spell['value'] + (int) $spell['upcast'] * $up),
                    'aura' => $spell['value'] . str_repeat('+' . $spell['upcast'], $spell['upcast'] === null ? 0 : $up),
                    default => $spell['value'],
                };
                start_effect($me, $spell, $dc, 'user_id', $targets, $me['fighting'], $value);
                if ($spell['effect'] === 'max_hp') { // Aid raises current HP as well
                    q('UPDATE users SET hp = hp + ? WHERE FIND_IN_SET(id, ?)', [
                        (int) $value, implode(',', array_column($targets, 'id')),
                    ]);
                    [$me['hp'], $me['max_hp']] = $self ? [$me['hp'] + $value, $me['max_hp'] + $value]
                        : [$me['hp'], $me['max_hp']];
                }
                $me['effects'] = effects_on('user_id', $me['id']);
                $names = array_map(fn ($t) => $t['id'] === $me['id'] ? 'you' : $t['name'], $targets);
                return "You cast $name on " . implode(', ', $names) . ".$slots";
            }
            $amount = max(0, roll($dice) + $mod); // one roll for every target
            $parts = [];
            foreach ($targets as $t) {
                $t = $t['id'] === $me['id'] ? $me : with_stats($t);
                $hp = min($t['max_hp'], $t['hp'] + $amount);
                q('UPDATE users SET hp = ? WHERE id = ?', [$hp, $t['id']]);
                $parts[] = $t['id'] === $me['id'] ? 'you regain ' . ($hp - $t['hp']) . ' HP'
                    : "{$t['name']} regains " . ($hp - $t['hp']) . ' HP';
                if ($t['id'] === $me['id']) {
                    $line = "You cast $name and regain " . ($hp - $me['hp']) . " HP. HP $hp/{$me['max_hp']}.$slots";
                    $me['hp'] = $hp;
                }
            }
            return count($targets) === 1 && $self ? $line : "You cast $name: " . implode(', ', $parts) . ".$slots";
        };
        if ($spell['casting'] === 'bonus' && !spend_bonus($me)) {
            return [BONUS_USED];
        }
        if ($spell['casting'] === 'bonus' || $me['fighting'] === null) { // a Bonus Action costs no turn
            return [$cast($me)];
        }
        return fight($me, '', function (array &$me, array &$foe, array &$lines) use ($cast): bool {
            $lines[] = $cast($me);
            $foe['effects'] = effects_on('spawn_id', $foe['id']); // a new Concentration may have ended one on it
            return false;
        });
    }
    $foe = $spell['creature'] === null ? null : find_foe($me, $target);
    if ($foe !== null && $foe['type'] !== $spell['creature']) {
        return ["$spell[name] works only on a " . ucfirst($spell['creature']) . ", and the $foe[name] is "
            . lcfirst(a_an(ucfirst($foe['type']))) . '.'];
    }
    $act = function (array &$me, array &$foe, array &$lines) use ($spell, $spend, $dice, $mod, $dc, $name, $up): bool {
        $spend($me);
        $type = $spell['damage_type'];
        // Potent Cantrip (Evoker 3): a cantrip that misses or is saved against still deals half damage.
        $potent = $spell['level'] === 0 && $me['class'] === 'wizard' && $me['level'] >= 3;
        if ($spell['kind'] === 'auto') {
            [$dmg, $note] = typed(roll($dice), $type, $foe);
            $lines[] = "Your $name strikes the {$foe['name']} for $dmg damage$note.";
            return hurt_foe($me, $foe, $dmg, $lines);
        }
        if ($spell['kind'] === 'save') {
            $ability = $spell['save'];
            [$save, $saved] = save($foe, $ability, $dc);
            $roll = ucfirst($ability) . ' save (' . vs($save, $dc) . ") against your $name";
            if ($spell['effect'] !== null && !$saved) {
                start_effect($me, $spell, $dc, 'spawn_id', [$foe], $foe['id']);
                $foe['effects'] = effects_on('spawn_id', $foe['id']);
            }
            if ($dice === null) {
                $lines[] = "The {$foe['name']} " . ($saved ? "makes its $roll." : "fails its $roll and is "
                    . ($spell['effect'] === 'condition' ? ucfirst($spell['value']) : "under your $name") . '.');
                return false;
            }
            if ($saved && !$spell['half'] && !$potent) {
                $lines[] = "The {$foe['name']} makes its $roll and takes no damage.";
                return false;
            }
            $dmg = $saved ? intdiv(roll($dice), 2) : roll($dice);
            [$dmg, $note] = typed($dmg, $type, $foe);
            $lines[] = "The {$foe['name']} " . ($saved ? 'makes' : 'fails')
                . " its $roll and takes $dmg damage$note.";
            return hurt_foe($me, $foe, $dmg, $lines);
        }
        for ($ray = 0; $ray < $spell['count'] + $spell['upcast_targets'] * $up; $ray++) { // Scorching Ray: three
            [$d20, $edge, $bonus, $with] = attack_d20($me, $foe, false, $spell['ranged'] && !$me['volley']);
            $total = $d20 + $me['prof_bonus'] + $mod + $bonus;
            if ($d20 === 1 || ($d20 < 20 && $total < $foe['ac'])) {
                $why = $d20 === 1 ? 'natural 1' : "$total vs AC {$foe['ac']}";
                if (!$potent) {
                    $lines[] = "Your $name misses the {$foe['name']} ($why$with).";
                    continue;
                }
                [$dmg, $note] = typed(intdiv(roll($dice), 2), $type, $foe);
                $lines[] = "Your $name misses the {$foe['name']} ($why$with), but still deals $dmg damage$note.";
            } else {
                [$dmg, $note] = typed(roll($dice, $d20 === 20), $type, $foe);
                $lines[] = ($d20 === 20 ? 'Critical hit! ' : '') . "Your $name hits the {$foe['name']} ($total vs AC "
                    . "{$foe['ac']}$with) for $dmg damage$note.";
            }
            if (hurt_foe($me, $foe, $dmg, $lines)) {
                return true;
            }
        }
        if ($d20 === 1 || ($d20 < 20 && $total < $foe['ac'])) {
            return false;
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
 * Starts $spell's effect on $targets (heroes for 'user_id', monsters for 'spawn_id'), cast by me, with $value in place
 * of the spell's (an upcast). It lasts the spell's minutes, and while I'm still in the fight it was cast in ($during:
 * the spawn I fight). A Concentration spell first ends the one I was concentrating on.
 */
function start_effect(
    array $me,
    array $spell,
    int $dc,
    string $col,
    array $targets,
    ?int $during,
    ?string $value = null
): void {
    $col = match ($col) { 'user_id' => 'user_id', 'spawn_id' => 'spawn_id' }; // the only two allowed in SQL
    if ($spell['concentration']) {
        end_concentration($me);
    }
    $keep_save = $spell['repeat_save'] || $spell['effect'] === 'aura';
    foreach ($targets as $t) {
        q(
            "INSERT INTO effects ($col, caster_id, spell_id, effect, value, save, dc, concentration, during, until)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW() + INTERVAL ? MINUTE)",
            [$t['id'], $me['id'], $spell['id'], $spell['effect'], $value ?? $spell['value'],
                $keep_save ? $spell['save'] : null, $keep_save ? $dc : null,
                (int) $spell['concentration'], $during, (int) $spell['minutes']]
        );
    }
}

/** The ids of my prepared spells: my own list, or the class's suggested ones until I make one. */
function prepared_ids(array $me): array
{
    $ids = array_map('intval', array_filter(explode(',', $me['prepared'])));
    return $ids ?: spell_ids(CLASSES[$me['class']]['spells'] ?? []);
}

/** The ids of the spells my class always has prepared at my level; they don't count against the others. */
function always_ids(array $me): array
{
    return spell_ids(array_keys(array_filter(CLASSES[$me['class']]['always'] ?? [], fn ($lv) => $me['level'] >= $lv)));
}

/** The ids of the spells named. */
function spell_ids(array $names): array
{
    if (!$names) {
        return [];
    }
    $in = implode(', ', array_fill(0, count($names), '?'));
    return array_map('intval', q("SELECT id FROM spells WHERE name IN ($in)", $names)->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * prepare <spell>, <spell>...: my list of prepared spells of level 1+ (cantrips are always ready), up to my class's
 * count for my level, of levels I have slots for (SRD 5.2.1 Cleric p.36). It can change after a long rest; between
 * rests I can only add to it, as when a new level raises the count. prepare alone lists what I can choose.
 */
function cmd_prepare(array $me, string $arg): array
{
    $max = CLASSES[$me['class']]['prepared'][$me['level'] - 1] ?? null;
    if ($max === null) {
        return ["You don't prepare spells."];
    }
    $all = q(
        'SELECT * FROM spells WHERE FIND_IN_SET(?, classes) AND level BETWEEN 1 AND ? AND NOT FIND_IN_SET(id, ?)
         ORDER BY level, name',
        [$me['class'], count($me['slots']), implode(',', always_ids($me))]
    )->fetchAll();
    $now = prepared_ids($me);
    if ($arg === '') {
        $lines = ['Prepared spells (' . count($now) . "/$max), * marks one; change them after a long rest:"];
        foreach ($all as $k) {
            $mark = in_array($k['id'], $now, true) ? '*' : ' ';
            $lines[] = sprintf(' %s %-22s level %d: %s', $mark, strtolower($k['name']), $k['level'], $k['about']);
        }
        return $lines;
    }
    $chosen = [];
    foreach (preg_split('/\s*,\s*/', $arg, -1, PREG_SPLIT_NO_EMPTY) as $part) {
        [$spell, $rest] = find_spell($all, $part);
        if ($spell === null || $rest !== '') {
            return ["You can't prepare '$part'. Type 'prepare' for the list."];
        }
        $chosen[$spell['id']] = $spell['name'];
    }
    if (count($chosen) > $max) {
        return ["You can prepare $max spells at your level, not " . count($chosen) . '.'];
    }
    if (!$me['may_prepare'] && array_diff($now, array_keys($chosen))) {
        return ['You can only add spells until your next long rest.'];
    }
    q('UPDATE users SET prepared = ?, may_prepare = FALSE WHERE id = ?', [
        implode(',', array_keys($chosen)), $me['id'],
    ]);
    return ['You prepare ' . implode(', ', $chosen) . '.'];
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
    $use = $me['uses'] ? ['fighter' => 'Second Wind', 'barbarian' => 'Rage', 'monk' => 'Focus Points',
        'paladin' => 'Lay On Hands', 'ranger' => "free Hunter's Marks"][$me['class']] : null;
    if ($me['resting'] === 'long') {
        [$me['hp'], $me['hit_dice_spent'], $me['uses_spent'], $me['slots_spent']] = [$me['max_hp'], 0, 0, ''];
        $line = 'You finish a long rest: full HP, all Hit Dice' . ($use ? " and $use" : '')
            . ($me['slots'] ? ' and spell slots back, and you may change your prepared spells' : ' back') . '.';
        q('UPDATE users SET may_prepare = TRUE, once_used = FALSE, wholeness_spent = 0 WHERE id = ?', [$me['id']]);
    } else {
        $spent = 0;
        while ($me['hp'] < $me['max_hp'] && $me['hit_dice_spent'] < $me['level']) {
            $die = '1d' . CLASSES[$me['class']]['die'];
            $me['hp'] = min($me['max_hp'], $me['hp'] + max(0, roll($die) + mod($me['constitution'])));
            $me['hit_dice_spent']++;
            $spent++;
        }
        $recovered = $me['class'] === 'wizard' && !$me['once_used'] ? arcane_recovery($me) : '';
        // One Second Wind or Rage comes back, every Focus Point, and nothing else.
        $back = match ($me['class']) { 'fighter', 'barbarian' => 1, 'monk' => $me['uses_spent'], default => 0 };
        $me['uses_spent'] = max(0, $me['uses_spent'] - $back);
        $line = "You finish a short rest, spending $spent Hit " . ($spent === 1 ? 'Die' : 'Dice') . $recovered
            . match (true) {
                !$use || !in_array($me['class'], ['fighter', 'barbarian', 'monk'], true) => '.',
                $me['class'] === 'monk' => " and regaining your $use.",
                default => " and regaining a $use.",
            };
    }
    $me['resting'] = null;
    q(
        'UPDATE users SET hp = ?, hit_dice_spent = ?, uses_spent = ?, slots_spent = ? WHERE id = ?',
        [$me['hp'], $me['hit_dice_spent'], $me['uses_spent'], $me['slots_spent'], $me['id']]
    );
    return [$line . " HP {$me['hp']}/{$me['max_hp']}."];
}

/**
 * Arcane Recovery (Wizard 1), on a short rest once a long rest: spent slots back, highest first, up to a combined level
 * of half my Wizard level (rounded up), none of level 6 or more. Returns how the rest line says it.
 */
function arcane_recovery(array &$me): string
{
    $spent = slots_spent($me);
    $budget = intdiv($me['level'] + 1, 2);
    $back = [];
    for ($lv = min(5, count($spent)); $lv >= 1; $lv--) {
        while ($spent[$lv - 1] > 0 && $lv <= $budget) {
            $spent[$lv - 1]--;
            $budget -= $lv;
            $back[] = $lv;
        }
    }
    if (!$back) {
        return '';
    }
    $me['slots_spent'] = implode(',', $spent);
    $me['once_used'] = 1;
    q('UPDATE users SET once_used = TRUE WHERE id = ?', [$me['id']]); // slots_spent is saved with the rest
    return ', with Arcane Recovery bringing back slots of level ' . implode(', ', $back);
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
 * heavy) and +1 for a Fighter's or Paladin's (level 2) Defense fighting style; unarmored, 10 + Dex (+ Con for a
 * Barbarian's Unarmored Defense, + Wis for a Monk's without a shield); +2 for a shield. The weapon keys are
 * wielding()'s for my main hand, 'offhand' is a second Light weapon's, or null, and 'unarmed' my Unarmed Strike's.
 * 'arts' is true while a Monk's Martial Arts work: no armor or shield, and only Monk weapons in hand.
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
    $bare = $armor === null && !isset($worn['shield']);
    $defense = $me['class'] === 'fighter' || ($me['class'] === 'paladin' && $me['level'] >= 2) ? 1 : 0;
    $ac = match (true) {
        $armor !== null => $armor['ac'] + $defense
            + match ($armor['armor']) { 'light' => $dex, 'medium' => min($dex, 2), 'heavy' => 0 },
        $me['class'] === 'barbarian' => 10 + $dex + mod($me['constitution']),
        $me['class'] === 'monk' && $bare => 10 + $dex + mod($me['wisdom']),
        default => 10 + $dex,
    };
    $arts = $me['class'] === 'monk' && $bare && monk_weapon($worn['weapon'] ?? null)
        && monk_weapon($worn['offhand'] ?? null);
    return [
        'ac' => $ac + ($worn['shield']['ac'] ?? 0),
        'armored' => $armor !== null,
        'shield_ac' => $worn['shield']['ac'] ?? 0,
        ...wielding($me, $worn['weapon'] ?? null, isset($worn['shield']) || isset($worn['offhand']), $arts),
        'offhand' => isset($worn['offhand']) ? wielding($me, $worn['offhand'], true, $arts) : null,
        'unarmed' => wielding($me, null, true, $arts),
        'arts' => $arts,
    ];
}

/** A Monk weapon (or none): a Simple melee weapon, or a Martial melee one that's Light. */
function monk_weapon(?array $weapon): bool
{
    return $weapon === null || (!$weapon['ranged'] && (!$weapon['martial'] || $weapon['light']));
}

/**
 * How I attack with $weapon (null: an Unarmed Strike, 1 + Str). A Versatile weapon does its bigger die unless the
 * other hand is busy, Finesse uses the better of Str and Dex, a Ranged weapon Dex. The Proficiency Bonus is added only
 * for a trained weapon. With a Monk's Martial Arts ($arts), a Monk weapon or Unarmed Strike may use Dex and the
 * Martial Arts die.
 */
function wielding(array $me, ?array $weapon, bool $one_hand, bool $arts = false): array
{
    [$dex, $str] = [mod($me['dexterity']), mod($me['strength'])];
    $finesse = $weapon !== null && $weapon['finesse'];
    $ranged = $weapon !== null && $weapon['ranged'];
    $nimble = $finesse || $arts;
    $attack_mod = $nimble ? max($str, $dex) : ($ranged ? $dex : $str);
    $damage = $weapon === null ? '1' : ($one_hand ? $weapon['dice'] : $weapon['versatile'] ?? $weapon['dice']);
    // The Martial Arts die if it's bigger (Monk weapons all do one die).
    if ($arts && ($weapon === null || (int) substr($damage, 2) < $me['martial_arts'])) {
        $damage = '1d' . $me['martial_arts'];
    }
    return [
        'weapon' => $weapon['name'] ?? 'Unarmed Strike',
        'damage' => $damage,
        'damage_type' => $weapon['damage_type'] ?? 'bludgeoning', // an Unarmed Strike's
        'attack_mod' => $attack_mod,
        'to_hit' => $attack_mod + ($weapon === null || trained($me, $weapon) ? $me['prof_bonus'] : 0)
            + ($ranged && $me['class'] === 'ranger' && $me['level'] >= 2 ? 2 : 0), // Archery
        'finesse' => $finesse,
        'str_based' => $nimble ? $str >= $dex : !$ranged,
        'ranged' => $ranged,
        'thrown' => $weapon !== null && $weapon['thrown'],
        'loading' => $weapon !== null && $weapon['loading'],
        'mastery' => (CLASSES[$me['class']]['mastery'] ?? false) ? ($weapon['mastery'] ?? null) : null,
        'monk' => monk_weapon($weapon),
    ];
}

/**
 * If my class is trained with an item: armor and shields per CLASSES; Rogues use Simple, Finesse or Light weapons,
 * Monks Simple or Light ones, Wizards Simple ones, the rest every weapon.
 */
function trained(array $me, array $o): bool
{
    return match ($o['slot']) {
        'armor' => in_array($o['armor'], CLASSES[$me['class']]['armor'], true),
        'shield' => in_array('shield', CLASSES[$me['class']]['armor'], true),
        'weapon' => !$o['martial'] || match ($me['class']) {
            'rogue' => $o['finesse'] || $o['light'],
            'monk' => $o['light'],
            'wizard' => false,
            default => true,
        },
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
            ...match ($me['class']) {
                'fighter' => [['Second Wind', $uses]],
                'barbarian' => [['Rage', $uses]],
                'rogue' => [['Sneak Attack', intdiv($me['level'] + 1, 2) . 'd6']],
                'cleric' => [['Spell slots', slots_left($me)]],
                'monk' => [['Martial Arts', "d{$me['martial_arts']}"], ...($me['uses'] ? [['Focus', $uses]] : [])],
                'paladin' => [['Lay On Hands', $uses], ['Spell slots', slots_left($me)]],
                'ranger' => [['Favored Enemy', $uses], ['Spell slots', slots_left($me)]],
                'wizard' => [['Spell slots', slots_left($me)]],
            },
            ...($me['offhand'] === null ? [] : [['Off hand', sprintf('%s %+d to hit, %s damage%s',
                $me['offhand']['weapon'], $me['offhand']['to_hit'], $me['offhand']['damage'],
                $me['offhand']['mastery'] === 'nick' ? ' (Nick)' : ', a Bonus Action')]]),
        ],
        'status' => array_values(array_filter([
            $me['raging'] ? 'raging' : null,
            $me['class'] === 'monk' && !$me['arts'] ? 'no Martial Arts' : null,
            ...explode(',', $me['conditions']),
            ...array_column($me['effects'], 'spell'),
            ($spell = concentrating($me)) === null ? null : "concentrating on $spell",
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
