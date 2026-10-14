<?php

declare(strict_types=1);

// convert-area.php :: turn a Riftshadow area file into seed SQL: rooms, exits, monsters and spawns.
// Usage: php bin/convert-area.php <file.are> <greenest room> <dir> <area room> > db/areas/<name>.sql
// e.g.   php bin/convert-area.php ../riftshadow/area/koboldmines.are 6 south 24600
// links Greenest room 6 south to area room 24600, and 24600 back the opposite way.
//
// Kept: room text, exits inside the area, the monsters each reset places and the gear resets hand them (weapons, body
// armor and shields, as the SRD items they're closest to). Dropped: doors and keys, traps, extra descriptions, scripts
// and flags, floor objects, and gear the SRD items don't cover (helms, rings, potions...).
// Riftshadow ids (vnums) are kept as ids; Greenest's own content stays below 1001 and 24000 isn't used.

if (PHP_SAPI !== 'cli') {
    exit(1);
}

const BACK = [
    'north' => 'south', 'south' => 'north', 'east' => 'west', 'west' => 'east', 'up' => 'down', 'down' => 'up',
];
// A monster's SRD stat block, by its Rift race or else a word of its name ("the great sewer rat" is race human): the
// last tier at or below its Rift level. Every name must be a stat block in seed.sql.
const CREATURES = [
    'goblin' => [0 => 'Goblin Minion', 20 => 'Goblin Warrior', 30 => 'Goblin Boss'],
    'hobgoblin' => [0 => 'Hobgoblin Warrior', 30 => 'Hobgoblin Captain'],
    'kobold' => [0 => 'Kobold Warrior'],
    'rat' => [0 => 'Giant Rat'],
    'wolf' => [0 => 'Wolf', 20 => 'Dire Wolf'],
    'spider' => [0 => 'Giant Spider'],
    'boar' => [0 => 'Boar'],
    'skeleton' => [0 => 'Skeleton'],
    'zombie' => [0 => 'Zombie'],
    'gnoll' => [0 => 'Gnoll Warrior'],
    'bugbear' => [0 => 'Bugbear Warrior'],
    'ogre' => [0 => 'Ogre'],
    'troll' => [0 => 'Troll'],
    'bandit' => [0 => 'Bandit', 30 => 'Bandit Captain'],
];
// Anything else gets the SRD stat block nearest CR = Rift level / this.
const RIFT_LEVELS_PER_CR = 12;
// A Rift weapon whose name names no SRD weapon becomes one by a word of its name, else by its weapon class.
const WEAPON_WORDS = ['curved' => 'Scimitar', 'pickaxe' => 'War Pick'];
const WEAPON_CLASSES = [
    'axe' => 'Battleaxe', 'dagger' => 'Dagger', 'flail' => 'Flail', 'mace' => 'Mace', 'polearm' => 'Halberd',
    'spear' => 'Spear', 'staff' => 'Quarterstaff', 'sword' => 'Longsword', 'whip' => 'Whip',
];

function fail(string $why): never
{
    fwrite(STDERR, "$why\n");
    exit(1);
}

/** Splits a section into [vnum => text] on its "#<vnum>" lines, dropping the "#0" end marker. */
function records(string $section): array
{
    $parts = preg_split('/^#(\d+)\s*$/m', $section, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = [];
    for ($i = 1; $i < count($parts); $i += 2) {
        if ($parts[$i] !== '0') {
            $out[(int) $parts[$i]] = $parts[$i + 1];
        }
    }
    return $out;
}

/** Area text is hard-wrapped with double spaces after sentences; the play page wants one plain line. */
function clean(string $s): string
{
    return trim(preg_replace('/\s+/', ' ', str_replace('\\', '', $s)));
}

/** "a goblin child" -> "Goblin Child", so the game's "A Goblin Child is here." reads right. */
function title(string $short): string
{
    return ucwords(preg_replace('/^(a|an|the|some) /i', '', clean($short)));
}

function str(string $s): string
{
    return "'" . str_replace("'", "''", $s) . "'";
}

/** One multi-row INSERT, or nothing if there are no rows (an empty VALUES list is a syntax error). */
function insert(string $head, array $rows, string $tail): string
{
    return $rows ? "$head VALUES\n" . implode(",\n", $rows) . "\n$tail;\n\n" : '';
}

[, $file, $link_room, $link_dir, $entrance] = $argv + array_fill(0, 5, '');
if (!is_file($file) || !ctype_digit($link_room) || !isset(BACK[$link_dir]) || !ctype_digit($entrance)) {
    fail('Usage: php bin/convert-area.php <file.are> <greenest room> <north|south|east|west|up|down> <area room>');
}
[$link_room, $entrance] = [(int) $link_room, (int) $entrance];

$text = str_replace("\r", '', file_get_contents($file));
if (!mb_check_encoding($text, 'UTF-8')) {
    $text = mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1'); // 2000s area files are Latin-1 where not ASCII
}
$parts = preg_split('/^#([A-Z]+)\s*$/m', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
$sec = [];
for ($i = 1; $i < count($parts); $i += 2) {
    $sec[$parts[$i]] = $parts[$i + 1];
}
foreach (['AREA', 'MOBS', 'OBJS', 'ROOMS', 'RESETS'] as $name) {
    $sec[$name] ?? fail("$file has no #$name section.");
}

// #AREA: file~ name~ builders~ then "<min level> <max level>" and "<low vnum> <high vnum>".
[, $area_name, $builders, $numbers] = explode('~', $sec['AREA'], 4);
preg_match_all('/-?\d+/', $numbers, $n);
[$lo, $hi] = [(int) $n[0][2], (int) $n[0][3]];

// Mobs: name~ short~ long~ description~ "align group xp race~", then a line opening with the level.
$mobs = [];
foreach (records($sec['MOBS']) as $vnum => $body) {
    $s = explode('~', $body);
    $mobs[$vnum] = [
        'name' => title($s[1]),
        'race' => preg_split('/\s+/', trim($s[4]))[3] ?? '',
        'level' => (int) $s[5],
        'gear' => [],
    ];
}

// Objects: name~ short~ long~ "type material~", a values line opening with a weapon's class, the level line, flags.
$objs = [];
foreach (records($sec['OBJS']) as $vnum => $body) {
    $s = explode('~', $body, 5);
    preg_match_all('/^WEAR (\w+)/m', $s[4], $wear);
    $objs[$vnum] = [
        'name' => clean($s[1]),
        'type' => strtok(trim($s[3]), " \n"),
        'class' => strtok(trim($s[4]), " \n"),
        'wear' => $wear[1],
    ];
}

// Rooms: name~ description~ sector, then flag lines; exits are "D <DIR> <to vnum> <flags> <key>".
$rooms = [];
$exits = [];
$left_area = 0;
foreach (records($sec['ROOMS']) as $vnum => $body) {
    $s = explode('~', $body, 3);
    $rooms[$vnum] = ['name' => rtrim(clean($s[0]), '.'), 'description' => clean($s[1])];
    preg_match_all('/^D (\w+) (\d+)\b/m', $body, $d, PREG_SET_ORDER);
    foreach ($d as [, $dir, $to]) {
        $exits[$vnum][strtolower($dir)] = (int) $to;
    }
}
foreach ($exits as $vnum => $list) {
    foreach ($list as $dir => $to) {
        if (!isset($rooms[$to])) {
            unset($exits[$vnum][$dir]);
            $left_area += $to !== 0 ? 1 : 0; // vnum 0 is ROM's look-only exit, not a way out
        }
    }
}
isset($rooms[$entrance]) || fail("Room $entrance isn't in $file.");
$exits[$entrance][BACK[$link_dir]] = $link_room;

// Resets: "MOB <vnum> TO <room> ..." places a monster; the EQUIP and GIVE lines after it hand that monster gear.
preg_match_all('/^M\w* (\d+) TO (\d+)/m', $sec['RESETS'], $placed, PREG_SET_ORDER);
preg_match_all('/^(?:M\w* (\d+) TO|[EG]\w* (\d+))/m', $sec['RESETS'], $lines, PREG_SET_ORDER);
$mob = null;
foreach ($lines as $l) {
    if ($l[1] !== '') {
        $mob = (int) $l[1];
    } elseif (isset($mobs[$mob], $objs[(int) $l[2]])) {
        $mobs[$mob]['gear'][] = $objs[(int) $l[2]];
    }
}

// The SRD stat blocks (monsters below Greenest's own 1001) from seed.sql, to borrow stats from by CR. Every column is
// copied as seed.sql writes it, so a new monsters column needs no change here.
preg_match(
    '/^INSERT INTO monsters \(([^)]+)\) VALUES\n(.*?)\nAS new\n(ON DUPLICATE KEY UPDATE .*?);/ms',
    file_get_contents(__DIR__ . '/../db/seed.sql'),
    $seed
) || fail('Found no monsters INSERT in db/seed.sql.');
$columns = preg_split('/,\s*/', trim($seed[1]));
preg_match_all("/\\(((?:'(?:[^']|'')*'|[^'()])*)\\)/", preg_replace('/--[^\n]*/', '', $seed[2]), $tuples);
$srd = [];
foreach ($tuples[1] as $tuple) {
    preg_match_all("/'(?:[^']|'')*'|[^,\\s]+/", $tuple, $values);
    count($values[0]) === count($columns) || fail("A monster in db/seed.sql doesn't match its columns: ($tuple)");
    $r = array_combine($columns, $values[0]);
    if ((int) $r['id'] < 1001) {
        $srd[] = $r;
    }
}
$srd || fail('Found no SRD stat blocks in db/seed.sql.');

// The SRD items from seed.sql, to map gear to: [id, name, slot].
preg_match('/^INSERT INTO items \(.*?\nAS new/ms', file_get_contents(__DIR__ . '/../db/seed.sql'), $seed_items)
    || fail('Found no items INSERT in db/seed.sql.');
preg_match_all("/^\((\d+), '([^']+)', '(\w+)'/m", $seed_items[0], $items, PREG_SET_ORDER);

/** The SRD stat block for a Rift monster: by race, then by a word of its name, then nearest in CR to its level. */
function borrow(array $mob, array $srd): array
{
    $words = preg_split('/[^a-z]+/', strtolower($mob['name']));
    $tiers = CREATURES[$mob['race']] ?? null;
    foreach ($words as $w) {
        $tiers ??= CREATURES[$w] ?? null;
    }
    if ($tiers !== null) {
        $name = array_filter($tiers, fn ($min) => $min <= $mob['level'], ARRAY_FILTER_USE_KEY);
        $name = "'" . str_replace("'", "''", end($name) ?: reset($tiers)) . "'";
        foreach ($srd as $r) {
            if ($r['name'] === $name) {
                return $r;
            }
        }
        fail("CREATURES names $name, which isn't an SRD stat block in db/seed.sql.");
    }
    $cr = $mob['level'] / RIFT_LEVELS_PER_CR;
    $best = $srd[0];
    foreach ($srd as $r) {
        if (abs((float) $r['cr'] - $cr) < abs((float) $best['cr'] - $cr)) {
            $best = $r;
        }
    }
    return $best;
}

/**
 * The SRD item ids for a monster's Rift gear: weapons by the SRD weapon their name names ("a curved scimitar") or else
 * by weapon class, shields as the Shield, and body armor (not capes and cloaks) by the SRD armor their name names.
 */
function gear(array $gear, array $items): string
{
    $ids = [];
    foreach ($gear as $o) {
        $slot = match (true) { // by item type first: some shields are also flagged wieldable
            $o['type'] === 'weapon' => 'weapon',
            in_array('SHIELD', $o['wear'], true) => 'shield',
            in_array('BODY', $o['wear'], true) && !in_array('ABOUT', $o['wear'], true) => 'armor',
            default => null,
        };
        $name = str_replace('-', '', strtolower($o['name'])); // "battle-axe" is the Battleaxe
        [$id, $key_len] = [null, 0];
        foreach ($items as [, $item_id, $srd_name, $srd_slot]) {
            $key = strtolower(preg_replace('/ Armor$/', '', $srd_name));
            if ($srd_slot === $slot && ($slot === 'shield' || preg_match('/\b' . $key . '\b/', $name))
                && strlen($key) > $key_len) { // "studded leather" over "leather"
                [$id, $key_len] = [(int) $item_id, strlen($key)];
            }
        }
        if ($id === null && $slot === 'weapon') {
            $hint = array_intersect_key(WEAPON_WORDS, array_flip(explode(' ', $name)));
            $hint = $hint ? reset($hint) : WEAPON_CLASSES[$o['class']] ?? null;
            $by_class = array_filter($items, fn ($i) => $i[2] === $hint);
            $id = $by_class ? (int) reset($by_class)[1] : null;
        }
        if ($id !== null) {
            $ids[$id] = $id;
        }
    }
    sort($ids);
    return implode(',', $ids);
}

$sql_rooms = [];
foreach ($rooms as $vnum => $r) {
    ($vnum >= $lo && $vnum <= $hi) || fail("Room $vnum is outside the area's range $lo-$hi.");
    $sql_rooms[] = "($vnum, " . str($r['name']) . ",\n " . str($r['description']) . ')';
}
$sql_exits = ["($link_room, '$link_dir', $entrance)"];
foreach ($exits as $vnum => $list) {
    foreach ($list as $dir => $to) {
        $sql_exits[] = "($vnum, '$dir', $to)";
    }
}
$sql_spawns = [];
$used_mobs = [];
foreach ($placed as [, $mob, $room]) {
    if (isset($rooms[$room], $mobs[$mob])) {
        $id = $lo * 10 + count($sql_spawns); // ids stay inside this area's own block of 10 per vnum
        $sql_spawns[] = "($id, $room, $mob, '2000-01-01')";
        $used_mobs[$mob] = true;
    }
}
count($sql_spawns) < ($hi - $lo + 1) * 10 || fail('Too many spawns for the id block.');
ksort($used_mobs);
$sql_mobs = [];
foreach (array_keys($used_mobs) as $vnum) {
    $m = $mobs[$vnum];
    $r = borrow($m, $srd);
    $srd_name = str_replace("''", "'", substr($r['name'], 1, -1));
    $r = ['id' => $vnum, 'name' => str($m['name'])] + $r + ['gear' => str(gear($m['gear'], $items))];
    $sql_mobs[] = '(' . implode(', ', $r) . ") /* SRD $srd_name, Rift {$m['race']} level {$m['level']} */";
}

$base = basename($file, ".are");
$builders = clean($builders);
$area_name = clean($area_name);
echo <<<SQL
-- $base.sql :: GENERATED by bin/convert-area.php from Riftshadow's area/$base.are. Edit the converter, not this file.
-- "$area_name" by $builders. Riftshadow (MIT License, Copyright (c) 2019 Cyber Blacksmith) is based on ROM 2.4,
-- Merc and DikuMUD (Hans Staerfeldt, Katya Nyboe, Tom Madsen, Michael Seifert, Sebastian Hammer); keep these credits.
-- This work includes material from the System Reference Document 5.2.1 ("SRD 5.2.1") by Wizards of the Coast LLC,
-- available at https://www.dndbeyond.com/srd. The SRD 5.2.1 is licensed under the Creative Commons Attribution 4.0
-- International License, available at https://creativecommons.org/licenses/by/4.0/legalcode.
-- Load after seed.sql (which clears every exit and spawn). Safe to re-run: updates rows in place and rebuilds this
-- area's exits and spawns (respawning its monsters and ending fights in it), never touches heroes.
-- Monster stats are SRD stat blocks by Riftshadow race (the converter's CREATURES), else nearest CR = level / 12.
-- A monster's gear is the SRD items its Riftshadow gear maps to; a kill's loot comes from it.
SET NAMES utf8mb4;

DELETE FROM spawns WHERE room_id BETWEEN $lo AND $hi;
DELETE FROM exits WHERE room_id BETWEEN $lo AND $hi OR (room_id = $link_room AND dir = '$link_dir');


SQL;
echo insert('INSERT INTO rooms (id, name, description)', $sql_rooms,
    "AS new\nON DUPLICATE KEY UPDATE name = new.name, description = new.description");
echo insert('INSERT INTO exits (room_id, dir, to_room)', $sql_exits, '');
echo insert('INSERT INTO monsters (' . implode(', ', $columns) . ', gear)', $sql_mobs,
    "AS new\n{$seed[3]}, gear = new.gear");
echo "-- Spawns start long dead so each one appears, with rolled HP, the first time its room is read.\n";
echo insert('INSERT INTO spawns (id, room_id, monster_id, dead_since)', $sql_spawns, '');

fprintf(STDERR, "%s: %d rooms, %d exits, %d monsters, %d spawns. Dropped %d exits leaving the area.\n", $base,
    count($sql_rooms), count($sql_exits), count($sql_mobs), count($sql_spawns), $left_area);
