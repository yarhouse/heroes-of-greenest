<?php

declare(strict_types=1);

// convert-area.php :: turn a Riftshadow area file into seed SQL: rooms, exits, monsters, spawns and gear.
// Usage: php bin/convert-area.php <file.are> <greenest room> <dir> <area room> > db/areas/<name>.sql
// e.g.   php bin/convert-area.php ../riftshadow/area/koboldmines.are 6 south 24600
// links Greenest room 6 south to area room 24600, and 24600 back the opposite way.
//
// Kept: room text, exits inside the area, the monsters each reset places, and the gear they wear or carry
// (added to the loot pool). Dropped: doors and keys, traps, extra descriptions, scripts and flags, floor objects.
// Riftshadow ids (vnums) are kept as ids; Greenest's own content stays below 1001 and 24000 isn't used.

if (PHP_SAPI !== 'cli') {
    exit(1);
}

const BACK = [
    'north' => 'south', 'south' => 'north', 'east' => 'west', 'west' => 'east', 'up' => 'down', 'down' => 'up',
];
const DK_RARE = [9, 31, 60]; // Silver Slime, Silver Scorpion, Siren: DK's jackpot monsters, never borrowed
const GEAR_POWER = 5; // attack or defense per item level, the inverse of seed.sql's loot level ceil(power / 5)

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
    $mobs[$vnum] = ['name' => title($s[1]), 'level' => (int) $s[5]];
}

// Objects: name~ short~ long~ "type material~", a values line, "level weight cost condition", then flag lines.
$objs = [];
foreach (records($sec['OBJS']) as $vnum => $body) {
    $s = explode('~', $body, 5);
    preg_match_all('/^WEAR (\w+)/m', $s[4], $wear);
    $objs[$vnum] = [
        'name' => title($s[1]),
        'level' => (int) explode("\n", trim($s[4]))[1],
        'slot' => match (true) { // by item type first: some shields are also flagged wieldable
            strtok(trim($s[3]), " \n") === 'weapon' => 'weapon',
            in_array('SHIELD', $wear[1], true) => 'shield',
            in_array('BODY', $wear[1], true) => 'armor',
            default => null, // rings, helms and the rest have no slot here
        },
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

// Resets: "MOB <vnum> TO <room> ..." places a monster; EQUIP/GIVE lines that follow hand it gear.
preg_match_all('/^M\w* (\d+) TO (\d+)/m', $sec['RESETS'], $placed, PREG_SET_ORDER);
preg_match_all('/^[EG]\w* (\d+)/m', $sec['RESETS'], $carried);
$floor_objects = preg_match_all('/^[OP]\w* \d+/m', $sec['RESETS']);

// Dragon Knight's monsters (ids 1-151) from seed.sql, to borrow stats from by level.
preg_match_all(
    "/^\((\d+), '((?:[^']|'')*)', (\d+), (\d+), (\d+), (\d+), (\d+), (\d+)\)/m",
    file_get_contents(__DIR__ . '/../db/seed.sql'),
    $rows,
    PREG_SET_ORDER
);
$dk = array_values(array_filter($rows, fn ($r) => $r[1] <= 151 && !in_array((int) $r[1], DK_RARE, true)));
count($dk) === 148 || fail('Expected 148 Dragon Knight monsters in db/seed.sql, found ' . count($dk) . '.');

/** The first DK monster nearest in level. */
function borrow(int $level, array $dk): array
{
    $best = $dk[0];
    foreach ($dk as $r) {
        if (abs($r[6] - $level) < abs($best[6] - $level)) {
            $best = $r;
        }
    }
    return $best;
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
    [, , $dk_name, $hp, $dam, $armor, $level, $exp, $gold] = borrow($m['level'], $dk);
    $sql_mobs[] = "($vnum, " . str($m['name']) . ", $hp, $dam, $armor, $level, $exp, $gold) /* DK $dk_name, "
        . "Rift level {$m['level']} */";
}
$sql_items = [];
foreach (array_unique(array_map('intval', $carried[1])) as $vnum) {
    $o = $objs[$vnum] ?? null;
    if ($o !== null && $o['slot'] !== null && $o['level'] > 0) {
        $power = $o['level'] * GEAR_POWER;
        [$attack, $defense] = $o['slot'] === 'weapon' ? [$power, 0] : [0, $power];
        $sql_items[] = "($vnum, " . str($o['name']) . ", '{$o['slot']}', {$o['level']}, $attack, $defense)";
    }
}
sort($sql_items);

$base = basename($file, ".are");
$builders = clean($builders);
$area_name = clean($area_name);
echo <<<SQL
-- $base.sql :: GENERATED by bin/convert-area.php from Riftshadow's area/$base.are. Edit the converter, not this file.
-- "$area_name" by $builders. Riftshadow (MIT License, Copyright (c) 2019 Cyber Blacksmith) is based on ROM 2.4,
-- Merc and DikuMUD (Hans Staerfeldt, Katya Nyboe, Tom Madsen, Michael Seifert, Sebastian Hammer); keep these credits.
-- Load after seed.sql (which clears every exit and spawn). Safe to re-run: updates rows in place and rebuilds this
-- area's exits and spawns (respawning its monsters and ending fights in it), never touches heroes.
-- Monster stats are the Dragon Knight monster nearest the Riftshadow level; gear power is level x 5, like seed.sql.
SET NAMES utf8mb4;

DELETE FROM spawns WHERE room_id BETWEEN $lo AND $hi;
DELETE FROM exits WHERE room_id BETWEEN $lo AND $hi OR (room_id = $link_room AND dir = '$link_dir');


SQL;
echo insert('INSERT INTO rooms (id, name, description)', $sql_rooms,
    "AS new\nON DUPLICATE KEY UPDATE name = new.name, description = new.description");
echo insert('INSERT INTO exits (room_id, dir, to_room)', $sql_exits, '');
echo insert('INSERT INTO monsters (id, name, max_hp, max_dam, armor, level, max_exp, max_gold)', $sql_mobs,
    "AS new\nON DUPLICATE KEY UPDATE name = new.name, max_hp = new.max_hp, max_dam = new.max_dam, armor = new.armor,\n"
    . "    level = new.level, max_exp = new.max_exp, max_gold = new.max_gold");
echo "-- Spawns start long dead so each one appears, with rolled HP, the first time its room is read.\n";
echo insert('INSERT INTO spawns (id, room_id, monster_id, dead_since)', $sql_spawns, '');
echo "-- Gear the area's monsters wear or carry, added to the loot pool (no floor finds).\n";
echo insert('INSERT INTO items (id, name, slot, level, attack, defense)', $sql_items,
    "AS new\nON DUPLICATE KEY UPDATE name = new.name, slot = new.slot, level = new.level, attack = new.attack,\n"
    . '    defense = new.defense');

fprintf(STDERR, "%s: %d rooms, %d exits, %d monsters, %d spawns, %d items. Dropped %d exits leaving the area and %d "
    . "floor-object resets.\n", $base, count($sql_rooms), count($sql_exits), count($sql_mobs), count($sql_spawns),
    count($sql_items), $left_area, $floor_objects);
