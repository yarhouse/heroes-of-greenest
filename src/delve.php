<?php

declare(strict_types=1);

// delve.php :: the Delve, a dungeon under the mill that is generated anew each week from a seed.
// Rebuilt lazily (no background ticks) when a hero arrives at its stair and the week has changed.

const DELVE_STAIR = 50000; // fixed room in seed.sql; the generated rooms are DELVE_STAIR + 1 .. DELVE_LAST
const DELVE_LAST = 50999;
const DELVE_GRID = 6; // rooms sit on a DELVE_GRID x DELVE_GRID grid, joined north/south/east/west
const DELVE_MIN_ROOMS = 15;
const DELVE_MAX_ROOMS = 20;
const DELVE_LOOPS = 3; // extra passages beyond the spanning tree, so not every route is a dead end
const DELVE_MONSTER_CHANCE = 60; // percent of rooms, other than the first and the boss's, with a monster

/**
 * Themes: room text, and monsters (SRD stat block ids from seed.sql) by how deep the room is.
 * 'near' is the first third of the way to the farthest room, 'mid' the second, 'deep' the rest; 'boss' the farthest.
 */
const DELVE_THEMES = [
    'warren' => [
        'title' => 'a goblin warren',
        'names' => ['Muddy Burrow', 'Goblin Den', 'Refuse Pit', 'Low Tunnel', 'Gnawed-Bone Hollow', 'Lookout Nook'],
        'sights' => [
            'Crude tunnels have been hacked through the clay here, barely tall enough to stand in.',
            'Filthy bedrolls and broken crockery litter a low, smoky chamber.',
            'Someone has daubed crude red handprints all over these walls.',
            'Roots hang from the ceiling of this cramped burrow, tangled with stolen ribbons.',
        ],
        'details' => [
            'A cookfire smoulders in a ring of stones.',
            'Stolen chickens\' feathers drift underfoot.',
            'A tally of scratches covers one wall, too many to count.',
            'A rusty helmet serves someone as a cooking pot.',
        ],
        'senses' => [
            'Cackling echoes from somewhere ahead.',
            'It reeks of wet dog and old stew.',
            'Small feet patter away into the dark.',
        ],
        'boss_name' => 'The Boss\'s Hall',
        'monsters' => ['near' => [3, 1], 'mid' => [5, 6], 'deep' => [9, 5], 'boss' => [11]],
    ],
    'crypt' => [
        'title' => 'a forgotten crypt',
        'names' => ['Burial Niches', 'Ossuary', 'Collapsed Vault', 'Crypt Passage', 'Embalming Room', 'Sealed Alcove'],
        'sights' => [
            'Shelves of bones line this narrow passage from floor to ceiling.',
            'Cracked stone coffins stand open, their lids pushed aside from within.',
            'Faded murals of a funeral procession cover the walls.',
            'Dust lies thick here, broken only by a trail of dragging footprints.',
        ],
        'details' => [
            'A rusted censer hangs from a chain.',
            'Someone has chiselled the names off every plaque.',
            'Grave robbers left a broken shovel behind.',
            'Candle stubs ring a cracked altar.',
        ],
        'senses' => [
            'The air is cold and tastes of dust.',
            'Something shuffles in the dark beyond.',
            'A faint smell of rot hangs in the stillness.',
        ],
        'boss_name' => 'The Robbers\' Hoard',
        'monsters' => ['near' => [1, 4], 'mid' => [7, 8], 'deep' => [7, 8, 4], 'boss' => [14]],
    ],
    'cave' => [
        'title' => 'a flooded cave',
        'names' => ['Dripping Grotto', 'Narrow Crawl', 'Underground Pool', 'Fungus Cavern', 'Echoing Chamber', 'Ledge'],
        'sights' => [
            'Water drips steadily from stalactites into black pools.',
            'Pale fungus glows faintly along the walls of this damp cavern.',
            'The passage narrows to a crawl between slick boulders.',
            'A wide cavern opens up, its roof lost in darkness.',
        ],
        'details' => [
            'Gnawed bones lie in a heap by the wall.',
            'An old miner\'s lantern lies smashed on the rocks.',
            'Claw marks score the stone at shoulder height.',
            'Cold water swirls around your ankles.',
        ],
        'senses' => [
            'A low growl rumbles somewhere ahead.',
            'The steady drip of water is the only sound.',
            'It smells of wet stone and musk.',
        ],
        'boss_name' => 'The Lair',
        'monsters' => ['near' => [1, 2], 'mid' => [6, 2], 'deep' => [10, 6], 'boss' => [12]],
    ],
];

/** This week's seed, e.g. 202642 for ISO week 42 of 2026. */
function delve_week(): int
{
    return (int) date('oW');
}

/** A random element of $list, from the seeded mt_rand. */
function delve_pick(array $list): mixed
{
    return $list[mt_rand(0, count($list) - 1)];
}

/**
 * The dungeon for $seed, without touching the database: the same seed always gives the same dungeon.
 * Returns ['theme' => key, 'rooms' => [id => [name, description]], 'exits' => [[from, dir, to], ...],
 * 'spawns' => [room id => monster id], 'boss' => room id]. The first room is DELVE_STAIR + 1.
 * Uses mt_rand seeded with $seed, not roll(): roll() is for play, where tests queue dice; layout must be repeatable.
 */
function delve_layout(int $seed): array
{
    mt_srand($seed);
    $theme_key = delve_pick(array_keys(DELVE_THEMES));
    $theme = DELVE_THEMES[$theme_key];
    $steps = ['north' => [0, -1], 'south' => [0, 1], 'east' => [1, 0], 'west' => [-1, 0]];
    $back = ['north' => 'south', 'south' => 'north', 'east' => 'west', 'west' => 'east'];

    // Grow a random spanning tree on the grid from its middle, so every room is reachable.
    $mid = intdiv(DELVE_GRID, 2);
    $cells = ["$mid,$mid" => DELVE_STAIR + 1]; // "x,y" => room id
    $exits = [];
    $want = mt_rand(DELVE_MIN_ROOMS, DELVE_MAX_ROOMS);
    $neighbours = function (string $cell) use ($steps): array {
        [$x, $y] = array_map('intval', explode(',', $cell));
        $out = [];
        foreach ($steps as $dir => [$dx, $dy]) {
            if ($x + $dx >= 0 && $x + $dx < DELVE_GRID && $y + $dy >= 0 && $y + $dy < DELVE_GRID) {
                $out[$dir] = ($x + $dx) . ',' . ($y + $dy);
            }
        }
        return $out;
    };
    while (count($cells) < $want) {
        $from = delve_pick(array_keys($cells));
        $open = array_filter($neighbours($from), fn ($c) => !isset($cells[$c]));
        if (!$open) {
            continue;
        }
        $dir = delve_pick(array_keys($open));
        $cells[$open[$dir]] = DELVE_STAIR + 1 + count($cells);
        $exits[$cells[$from]][$dir] = $cells[$open[$dir]];
        $exits[$cells[$open[$dir]]][$back[$dir]] = $cells[$from];
    }
    // A few loops between neighbours that aren't joined yet. Bounded tries, since a tiny dungeon may have none.
    for ($loops = 0, $tries = 0; $loops < DELVE_LOOPS && $tries < 100; $tries++) {
        $from = delve_pick(array_keys($cells));
        $dir = delve_pick(array_keys($steps));
        $to = $neighbours($from)[$dir] ?? null;
        if ($to !== null && isset($cells[$to]) && !isset($exits[$cells[$from]][$dir])) {
            $exits[$cells[$from]][$dir] = $cells[$to];
            $exits[$cells[$to]][$back[$dir]] = $cells[$from];
            $loops++;
        }
    }

    // Distance from the first room by breadth-first search; the farthest room is the boss's.
    $first = DELVE_STAIR + 1;
    $dist = [$first => 0];
    for ($queue = [$first]; $queue; ) {
        $id = array_shift($queue);
        foreach ($exits[$id] as $to) {
            if (!isset($dist[$to])) {
                $dist[$to] = $dist[$id] + 1;
                $queue[] = $to;
            }
        }
    }
    $far = max($dist);
    $boss = array_search($far, $dist, true);

    $rooms = [];
    $spawns = [];
    foreach ($cells as $id) {
        $rooms[$id] = [
            delve_pick($theme['names']),
            delve_pick($theme['sights']) . ' ' . delve_pick($theme['details']) . ' ' . delve_pick($theme['senses']),
        ];
        $tier = match (true) {
            $id === $boss => 'boss',
            $dist[$id] * 3 < $far => 'near',
            $dist[$id] * 3 < $far * 2 => 'mid',
            default => 'deep',
        };
        if ($id === $boss || ($id !== $first && mt_rand(1, 100) <= DELVE_MONSTER_CHANCE)) {
            $spawns[$id] = delve_pick($theme['monsters'][$tier]);
        }
    }
    $rooms[$boss][0] = $theme['boss_name'];
    $rooms[$first][1] = 'At the foot of the stair from the mill. ' . $rooms[$first][1];

    $flat = [];
    foreach ($exits as $from => $dirs) {
        foreach ($dirs as $dir => $to) {
            $flat[] = [$from, $dir, $to];
        }
    }
    mt_srand(); // back to an unpredictable sequence for anything else that uses mt_rand
    return ['theme' => $theme_key, 'rooms' => $rooms, 'exits' => $flat, 'spawns' => $spawns, 'boss' => $boss];
}

/**
 * Rebuilds the Delve if it isn't this week's, unless a hero who is online is inside it (they keep the old one until
 * they leave). Heroes offline inside it are moved to the stair. Floor items, speech and fights in it are lost.
 */
function delve_refresh(): void
{
    $week = delve_week();
    $own = !db()->inTransaction(); // tests run everything inside their own transaction
    if ($own) {
        db()->beginTransaction();
    }
    try {
        // FOR UPDATE: if two heroes arrive at once, the second waits and then sees this week's seed.
        $seed = q('SELECT seed FROM delve FOR UPDATE')->fetchColumn();
        $busy = q(
            'SELECT 1 FROM users WHERE room_id BETWEEN ? AND ? AND last_seen > NOW() - INTERVAL ? MINUTE LIMIT 1',
            [DELVE_STAIR + 1, DELVE_LAST, ONLINE_MINUTES]
        )->fetchColumn();
        if ($seed !== false && (int) $seed !== $week && $busy === false) {
            delve_build($week);
        }
        if ($own) {
            db()->commit();
        }
    } catch (Throwable $e) {
        if ($own) {
            db()->rollBack();
        }
        throw $e;
    }
}

/** Replaces the Delve's rooms with delve_layout($seed). Call inside a transaction. */
function delve_build(int $seed): void
{
    $d = delve_layout($seed);
    $range = [DELVE_STAIR + 1, DELVE_LAST];
    q(
        'UPDATE users SET room_id = ?, x = NULL, y = NULL, ' . END_FIGHT . ', resting = NULL, rest_until = NULL
         WHERE room_id BETWEEN ? AND ?',
        [DELVE_STAIR, ...$range]
    );
    q('DELETE FROM events WHERE room_id BETWEEN ? AND ?', $range);
    q('DELETE FROM objects WHERE room_id BETWEEN ? AND ?', $range);
    q('DELETE FROM spawns WHERE room_id BETWEEN ? AND ?', $range);
    q('DELETE FROM exits WHERE room_id BETWEEN ? AND ? OR (room_id = ? AND dir = ?)', [...$range, DELVE_STAIR, 'down']);
    q('DELETE FROM rooms WHERE id BETWEEN ? AND ?', $range);

    foreach ($d['rooms'] as $id => [$name, $description]) {
        q('INSERT INTO rooms (id, name, description) VALUES (?, ?, ?)', [$id, $name, $description]);
    }
    $first = DELVE_STAIR + 1;
    q('INSERT INTO exits (room_id, dir, to_room) VALUES (?, ?, ?), (?, ?, ?)', [
        DELVE_STAIR, 'down', $first, $first, 'up', DELVE_STAIR,
    ]);
    foreach ($d['exits'] as $exit) {
        q('INSERT INTO exits (room_id, dir, to_room) VALUES (?, ?, ?)', $exit);
    }
    // Spawn id = room id. Long dead, so each appears with rolled HP the first time its room is read, as in seed.sql.
    foreach ($d['spawns'] as $room => $monster) {
        q('INSERT INTO spawns (id, room_id, monster_id, dead_since) VALUES (?, ?, ?, ?)', [
            $room, $room, $monster, '2000-01-01',
        ]);
    }
    $boss_cr = (float) q('SELECT cr FROM monsters WHERE id = ?', [$d['spawns'][$d['boss']]])->fetchColumn();
    loot($boss_cr, $d['boss']); // the boss guards one treasure, like a kill's loot
    q('UPDATE rooms SET description = ? WHERE id = ?', [
        'Worn steps spiral down beneath the mill into ' . DELVE_THEMES[$d['theme']]['title']
            . '. The tunnels below are never the same from one week to the next.',
        DELVE_STAIR,
    ]);
    q('UPDATE delve SET seed = ?', [$seed]);
}
