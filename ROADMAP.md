# Roadmap

What's being built, in order: take the top task. When a task ships, delete it here in the same commit (git log is the
history). Ideas go in `BACKLOG.md` and move here once chosen. This repo copy is the source; the claude.ai Project holds a copy.

## Next

**Characters and the town**, now that fights are done: the heroes Aaron's players already have get the choices and
features they're owed, and the town gets more to say and sell. Take the top task; each ships alone. The town redraw
and roads wait for Aaron's sketch (below).

### Characters

10. **No class short at levels 1-5.** The three classes without a subclass get the SRD's (Cleric: Life Domain, Rogue:
    Thief, Barbarian: Berserker), plus the Cleric's Channel Divinity (2) and spells of 2nd and 3rd level (Spiritual
    Weapon, Prayer of Healing, Bestow Curse, Protection from Energy), the Paladin's Divine Sense and Sacred Weapon (3)
    and Uncanny Dodge (Rogue 5). Can ship a class at a time (the design is in the Decided entry).
11. **Area spells, aimed before they're cast.** The SRD's areas (Cone, Cube, Cylinder, Emanation, Line, Sphere) hit
    every creature in them on the board: monsters, other heroes and hirelings alike, each saving on its own, and only
    those the point of origin has a line to (`sight()`, total cover blocks). A square counts if its centre is inside
    the area. Burning Hands, Thunderwave, Shatter, Fireball and Lightning Bolt become areas (today they hit one foe,
    and `spells.reach` is only how near it must be); new columns for the shape and its size. Aiming: `cast <spell>
    <square>` for an area spell only previews it: the board with the area's squares marked (a new glyph, coloured in
    `MAP_CELLS`) and who it would catch ("Fireball at e5: Kobold 1, Kobold 2 and Ana"), spending nothing. Tapping
    another square re-aims; `cast` alone, or the same square again, casts it. The aim is the only thing kept between
    commands (a `users` column), and any other command drops it. Spheres, Cubes and Cylinders aim at a point within
    range; Cones and Lines start at you, and the square picks the direction; an Emanation around you needs no aim.
    The server works out the squares, so `play.js` only draws them. Then the SRD spells of levels 1-3 whose only new
    rule is an area (which ones decided when picked up). Lasting areas and walls are in the backlog.
12. **Your own picks** where the game picks for you today (the `CLASSES` comment): Fighting Style (Two-Weapon
    Fighting among them), Divine Order, Primal Order, Elemental Fury and the Circle of the Land's land, Eldritch
    Invocations (and those past level 2: Pact of the Blade, Thirsting Blade, Eldritch Smite), Metamagic, Elemental
    Affinity, Weapon Mastery's weapons (today every weapon you wield has its mastery), cantrips known, a Wizard's
    spellbook and a Druid's Known Forms. Choices (`owed_choices()` and the panel).

### The town

13. **Townsfolk who listen.** Townsfolk answer words said in their room (`say`), as Riftshadow's and Luminari's speech
    triggers do, beside `ask`. The hooks the town hints at become topics, some behind a check: the dragon mark on
    kobold gear, the Fields of the Dead, Ysolde's interest in the Wood, the keep's bricked-up stair. A few more
    townsfolk in rooms that have none. Their rows hang off room ids, so the redraw (task 2) moves them with their rooms.
14. **More to inspect, and folk outside town.** `convert-area.php` turns Riftshadow's extra descriptions (`E` blocks,
    67 in all) into `features` rows, and the dungeons get a few people to `ask` (a captive, a hermit). Open (Aaron
    to decide): where their rows live, and whether the Delve gets any. `npcs.room_id` is a foreign key to `rooms`.
    The converted areas' rooms have fixed ids but load after `seed.sql`, so their people would need a hand-edited
    file loaded after the areas (say `db/extras.sql`, which could also hold task 6's hand-drawn boards). The Delve's
    rooms are deleted at each rebuild, which a row pointing at one would block, so a Delve NPC would stand in the
    fixed stair room (50000) or be placed by `delve_build()` each week.
15. **The shops and the Lantern.** Acid, Alchemist's Fire and Holy Water in the shops, thrown in place of an attack (a
    `throw` like `breathe`). More hireling kinds, one line each in `HIRELINGS` (a Scout with a Longbow for an archer,
    a Priest for a real healer, a Tough with a Heavy Crossbow), priced by CR rather than 2 GP a day for all.

Any time: **autocomplete command arguments** in `play.js` (after `kill `, the monsters in the room; items for `get`,
`drop`, `equip`, `drink`; today only command names, from `COMMANDS`).

Any time: **approve account requests** (friends ask on the login page):
`ssh $DEPLOY_HOST "/usr/local/php84/bin/php $DEPLOY_DIR/bin/requests.php"`, then `... requests.php approve <Account>`.

### Waiting on Aaron's sketch

2. **Redraw the town** from Aaron's sketch and feedback: rooms, exits, features, townsfolk and topics in `seed.sql`,
   ids 1-99. Keep the ids the code and data name, or move them together: `START_ROOM` (1), `LANTERN`, `TEMPLE`, the
   shops (`items.shop`, 8-11), the area links (room 6 for the Wood and the mines, 4 for the Sewers, 7 for the Delve
   stair), and rooms heroes stand in on the live server. Takes in the backlog's south gate (a room between the Mill
   Bridge and the Fields, Greenest's walls and a guard to `ask`).
3. **Roads.** Travel is walking ordinary rooms, one command a room, the text changing along the way. The first is
   the road from Greenest to the Wood of Sharp Teeth, its own zone (ids from 100); the Wood re-links to its far end
   (`convert-area.php` with `<last road room> west 6000` in place of `6 west 6000`). Encounters are spawns on road
   rooms (aggressive ones attack as you come in, as today). Open: a random encounter roll on entering a road room
   (through `roll()`, a table per zone), and how long a road can be before walking it is a chore (a speedwalk command
   only if it becomes one).

## Decided

- 2026-10-17 **No class short** (task 10, a class at a time). Design, from Aaron's answers: Action Surge and Channel
  Divinity count in `users.feature_spent` (no migration); turned undead and Sacred Weapon are no-class `spells` rows
  leaving condition effects, as Sanctuary does. Barbarian: Berserker's Frenzy on a raging `reckless`. Rogue: Thief's
  Fast Hands and Second-Story Work only shown (nothing picks locks, climbs or jumps); Uncanny Dodge halves the first
  hit of each monster turn (Deflect Attacks' shortcut). Paladin 3: two Channel Divinities, `sense` (the Celestials,
  Fiends and Undead here and next door) and `sacred <monster>`. Cleric: Channel Divinity from 2 (`spark`, `turn`; a
  turned Undead cowers, as no monster flees yet), Life Domain at 3 (Disciple of Life, its spells, `preserve`), and
  Spiritual Weapon, Prayer of Healing, Bestow Curse and Protection from Energy.
  **Fighter (shipped):** `surge <monster>` (2) is two actions: the Attack action twice, or a Dash and then the
  Attack action when the foe is out of reach (a Dash that falls short spends nothing); once a short rest, twice from
  17. Champion (3): a 19 is a Critical Hit with weapons and Unarmed Strikes (18 from 15), and like a 20 it hits
  whatever the AC (the glossary's Critical Hit). Remarkable Athlete: Advantage on Initiative and Athletics checks, and
  after a Critical Hit a `kite` backs off half my Speed further, none of it drawing opportunity attacks.

- 2026-10-16 **Characters and the town next:** with fights done, the backlog's character and town ideas became tasks
  8-15 in that order (skills and choices first, since later picks build on them). The town redraw and roads wait
  for Aaron's sketch.
- 2026-10-12 **Host:** DreamHost shared hosting; a managed VPS was ruled out on cost. Hence PHP + MySQL 8, one request per
  command, no ticks.
- 2026-10-12 **Sources:** Riftshadow is content only (`.are` files converted to SQL). Dragon Knight's rules and data are
  fully retired.
- 2026-10-12 **Rules:** SRD 5.2.1, so the game plays like the table game. 0 HP wakes you in town with 1/4 HP and half your
  gold (no death saves). `rest`/`sleep` only in safe rooms and take real time. XP all goes to the killer; gold rolls by
  CR. New heroes get their class's kit A. Potion of Healing is in.
- 2026-10-13 **The Delve:** one shared dungeon, rerolled weekly, one floor, room text from themed word lists.
- 2026-10-13 **Character creation:** class and Standard Array on first login; no backgrounds or species yet.
- 2026-10-13 **LuminariMUD not adopted:** 3.5/Pathfinder rules, needs a VPS, and the content built so far wouldn't carry
  over. Its rules map (`luminari-rules-map.md` in the Project) stays as a source of ideas.
- 2026-10-14 **Converted monsters** take an SRD stat block by Rift race or name, tiered by Rift level (goblin: Minion,
  Warrior from 20, Boss from 30; hobgoblin: Warrior, Captain from 30), with CR = Rift level / 12 as the fallback. Their
  Rift weapons, body armor and shields map to SRD items, and a kill's loot comes from those. Kobold Mines is now
  CR 1/8 to 3.
- 2026-10-14 **Haon Dor** (now the Wood of Sharp Teeth) instead of bridge areas: with Kobold Mines at CR 1/8 to 3 there was no gap to bridge, so Haon
  Dor is a second area at the same tier (CR 0 to 2, a Green Dragon Wyrmling as its boss), west of Southern Fields.
  The Sewers wait for an area past the mines.
- 2026-10-14 **Place names** come from the Faerûn map around Greenest (the Greenfields): the forest west of town is
  the Wood of Sharp Teeth, with the Fields of the Dead to its north (screenshot in the Project).
- 2026-10-14 **Edited areas** live as `.are` copies in `db/areas/src/`, converted from there; generated SQL is never
  hand-edited.
- 2026-10-14 **Deployed** at https://yarhouse.dev/heroes-of-greenest with `bin/deploy.sh` (see README).
- 2026-10-14 **Species and backgrounds** come after the Standard Array: the SRD's nine species and its four
  backgrounds (Acolyte, Criminal, Sage, Soldier), each with +2/+1 (or +1 x3) and its Origin feat. The game picks
  Magic Initiate's spells and the Int/Wis/Cha ability for origin spells; Giant Ancestry is used at the first chance.
  Fight traits are ruled, the rest only shown. Background equipment is skipped: heroes start with class kit A.
- 2026-10-14 **Humanoid NPCs and the Sewers:** the converter maps role words in Rift names (guard, priest, thief,
  warrior...) to the SRD's NPC stat blocks, tiered by Rift level, and a creature the SRD lacks to a stand-in of about
  its strength. The Sewers (Rift's Diku sewer, CR 0 to 5) are under Market Lane, Rift names and text kept for now;
  their mind flayers are a Darkmantle, a Grick and an Otyugh.
- 2026-10-14 **Accounts and heroes are separate:** you log in with an account name, never shown in the game, and name
  your hero first at character creation (heroes made before keep their account's name). Friends request an account on
  the login page and the DM approves it with `bin/requests.php` (no email, so no self-service reset: the DM still uses
  `bin/adduser.php`). `account.php` changes your account name and password. One hero per account.
- 2026-10-14 **Hirelings:** `hire <guard|mastiff|acolyte>` at the Swaying Lantern, 2 GP a real day (hire again for
  more), `dismiss`. Three `users` columns; it goes wherever its hero goes and leaves when the paid time runs out. It
  attacks your foe after your action, a foe's turn goes at it on a d2, and at 0 HP it's gone (the gold with it). It
  starts at its stat block's average HP and either rest heals it fully. No `order` and no conditions on its rolls yet.
- 2026-10-14 **Healing:** rests stay as they were (safe rooms, 10 min / 1 h, Hit Dice spent automatically until full HP,
  so none are wasted). What changed is finding them: the hero sheet counts down a rest, an open page sends a command
  when it's up so it finishes by itself, and a fight that ends at half HP or less says where to rest and how many Hit
  Dice are left. Choosing how many Hit Dice to spend is in the backlog.
- 2026-10-14 **Schema migrations:** `db/migrations/*.sql`, applied in name order by `bin/migrate.php` and recorded in a
  `migrations` table; `bin/deploy.sh` runs it locally and on the server before the world reload. `schema.sql` stays
  the whole schema (each change is made there too) and drops `migrations`, so a fresh load marks every file done.
- 2026-10-14 **Shops and the temple:** four shops off Market Lane (rooms 8-11) `list`, `buy` and `sell`; an item's
  `shop` column names the room that sells it, and any shop buys anything for half its cost. Prices are the SRD's,
  rounded up to whole GP (gold has no SP or CP). Magic items are Uncommon only: a +1 version of every weapon the
  Smithy sells (items `bonus` column, ids + 100), the +1 Shield, and the greater Potion of Healing; Rare ones stay out
  of the shops. Loot can now roll them too, by cost (+1 weapons from CR 9). No gear yet: it has no rules until
  crafting. The temple of Chauntea (room 12, safe, east of the Mill Bridge) sells `pray cure` (Cure Wounds, 50 GP),
  `heal` (Cure Wounds until full HP, a casting per 12 HP missing) and `aid` (Aid, 200 GP), cast by the SRD Priest
  (Wis +3).
- 2026-10-14 **Inspect and ask:** `look <thing>` (or `inspect`) shows a room's `features`, and `ask <npc> about <topic>`
  (or `speak`) talks to the townsfolk in `npcs` and `topics`, all original text in `seed.sql`. Townsfolk aren't
  monsters, so nothing can fight them. A feature or topic may hide a secret behind a skill check (a Search, Study or
  Influence, SRD 5.2.1 p.184-189): d20 + ability + Proficiency Bonus for a background's skills. A success stands; a
  failure waits 24 hours (Influence's rule, used for all three), recorded in `checks`. The Lantern's ledger lists every
  hero. Seven townsfolk: Marta (the Lantern), Sergeant Hollis (the keep gate), Brannoc (smithy), Old Pell
  (apothecary), Ysolde Vane (curio loft), Sister Wren (temple) and Tamsin the miller.
- 2026-10-15 **Aggro and stealth**, combining ArchaicQuest's, Riftshadow's and Luminari's: with no ticks, an aggressive
  monster (`monsters.aggressive`) attacks when a hero comes into its room, not after; it leaves alone a hero more than
  5 levels above its CR (Riftshadow's rule). If it wins Initiative it swings at once. `sneak` toggles a Stealth check on
  entering a room with monsters: 15 or more (the Hide action's DC; every room counts as having cover) hides you from
  each monster whose passive Perception (`monsters.perception`, from its stat block) is lower, and the total stays on
  `users.stealth` until a fight starts. A fight, `say` or dying turns sneaking off (`sneak` refuses mid-fight).
  Attacking from hiding is the Invisible condition's Advantage on Initiative and the first attack, and a monster nobody
  is fighting yet is surprised. The Delve's monsters (1-16) and the Southern Fields' kobolds are aggressive; converted
  areas follow Riftshadow's flag, unless the monster starts asleep.
- 2026-10-15 **The map is carved rooms in colour**, after the DCSS-style reference images in `ref/ascii maps/` (local
  only; `ref/` is gitignored). Room placement is unchanged (`MAP_REACH` 2 around you). Each room is a 5x3 floor
  sharing walls with its neighbours, and an exit is a gap in the wall. `cmd_map()` still returns plain text (`#` wall,
  `.` floor, `:` safe floor, `<` `>` stairs in the right-hand corners, `@` you, monsters in your room by initial
  around you), so the API sends no HTML. `play.js` colours it cell by cell (`MAP_CELLS`) in the sidebar and for the
  `map` command, and skips empty rows at the edges. To make room, the hero card dropped the Species and Background
  rows, put AC, Prof and Gold on one line and took the save line off the ability tiles (all still in `score`).
- 2026-10-15 **The map is drawn in Dungeon Mode**, an 8x8 pixel font (CC0, `public/fonts/dungeon-mode.woff2`; the
  source pack is in `ref/dungeonmode`). Map cells only; all text stays JetBrains Mono, which is also its fallback.
  `play.js` turns each `#` into a double-line wall glyph by which neighbours are walls (`MAP_WALLS`), fills empty
  cells with rock hatching and pads rows to the map's width; `cmd_map()` is unchanged. Cells are square at 12px (phones)
  or 13.5px (wide screens) so the pixels stay crisp. The sidebar grew to 25rem and the page to 2000px.
- 2026-10-15 **Rests are shorter:** a short rest takes 1 minute and a long rest 10 (were 10 min / 1 h), so a session
  isn't spent waiting. `SHORT_REST_SECS` / `LONG_REST_SECS` in `game.php`.
- 2026-10-15 **Speech fades, and shouts carry across town:** `say` used to echo to later visitors for 7 days, so an
  hour-old chat greeted everyone at the keep. Now it lingers 10 minutes (`ECHO_MINUTES`). `shout <text>` is heard live
  (on their next command) by every hero in town, rooms 1-99 (`TOWN_LAST_ROOM`); elsewhere a shout reaches only the room.
  Shouts leave no echo. Like `say`, a shout ends sneaking.
- 2026-10-15 **Speech shows up live:** an open, visible play page sends an empty command every 5 seconds
  (`LISTEN_MS` in `play.js`), which returns only what others said or shouted since my last command, so it appears
  without typing. Not during character creation (an empty command repeats the step there), and a failed listen prints
  nothing. It also keeps a hero with the page open "online" in `who`. Fights by other heroes still aren't pushed.
- 2026-10-15 **Other heroes' names stand out in the log:** `api.php` sends every other hero's name (`heroes`), and
  `play.js` sets them in bold Bootstrap `text-info` wherever they appear, still as text nodes, never HTML.
- 2026-10-15 **Battle map rules** (tasks 4, 6, 7), from Aaron's answers. Zones go first, then 4, 6, 7. A fight's
  starting distance depends on the zone: against a Speed 30 melee monster about 2 free Longbow shots outdoors, 1 in
  town, 0 to 1 underground, rolled within the zone's room size. Leaving the room mid-fight is an escape: the monster
  waits where it was for a while, then goes home, so stepping out and back in buys no free shot. `flee` is walking to
  an exit (an opportunity attack if you start next to it, unless you Disengage), no longer a Dex contest. One command
  is one whole turn: `kill` closes in and attacks, `shoot` stays put, `kite` shoots and backs off your Speed (backing
  off first if it's next to you), plus `dash`, `disengage`, `dodge` and `flee <dir>`; on boards `move c3`, which
  tapping a square sends. Monsters with a ranged attack in their stat block shoot from range and melee once adjacent;
  none kite or flee yet. Ammunition and thrown weapons aren't tracked. A hireling has its own square, closes on your
  foe and holds it in melee. Layouts are generated from each room's terrain, with hand-drawn ones for rooms Aaron
  picks. The board replaces the zone map only during a fight; you start at the edge of the exit you came through, and
  a hidden hero picks how close to start. In shared fights aggressive monsters join (peaceful ones only if attacked);
  an away hero Dodges at once if their page is closed, or after 2 minutes if it's open; XP and gold split evenly among
  the heroes in the fight. Also settled: a hero's reactions (opportunity attacks) are automatic, as Deflect Attacks
  is; boards are at most 29 by 19 squares (today's map, so phones don't scroll); the rest is SRD 5.2.1 as written
  (ranges, Reach, each stat block's Speed, monsters Dash when they can't reach, Disengage, Dodge, cover, difficult
  terrain, Slow and Push, spell ranges, touch spells need you adjacent).
  Then, from the SRD stat blocks and Aaron's follow-ups: step 1 is a grid of open rooms rather than a straight line
  (in a line a hero starts at the door, so backing off means leaving), and hirelings get stances and their stat
  blocks' extras (task 4c), with more kinds, such as a Scout with a Longbow, in the backlog.
- 2026-10-15 **Zones:** a zone is a range of room ids (`ZONES` in `game.php`): Greenest 1-99, the Wood of Sharp
  Teeth 6000-6099, the Deep Wood 6100-6199 (Haon Dor's own split: one trail, 6004 west to 6100), the Sewers
  7000-7499, the Kobold Mines 24600-24799 and the Delve 50000-50999; roads get theirs, from 100, as they're built.
  `cmd_map()` doesn't follow an exit into another zone (it's a gap in the wall onto rock), so zones never compete for
  map cells and a road can be any length, and the map's footer names the zone (`Greenest. Here: ...`). Movement and
  shouts are unchanged.
- 2026-10-15 **The battle board** (task 4a): every room in a fight is an open board of 5-ft squares sized by zone
  (`ZONES`: Greenest 8x6, the Woods 16x10, the Sewers, Mines and Delve 6x5). You stand where you came in (the middle
  of that wall, a stair corner for up and down, the middle if you didn't walk in: `users.x`, `y`); a monster stands
  where it last stood, or after 10 minutes untouched (`spawns.placed_at`) at the far side, mirrored from you (not
  rolled, so a fight spends no dice placing it). Distance is the larger gap times 5 ft (SRD p.15). One command is a
  whole turn except `move c3` (`users.moved` holds the feet used; a turn under way skips the foe's first move):
  `kill` closes in and attacks, or Dashes and can't; `shoot` throws a thrown weapon or shoots (`kill` with a bow
  does), Disadvantage within 5 ft or past normal range (`items.normal_range`, `long_range`); `flee [dir]` Dashes for
  an exit and leaves if it gets there (no Dex contest; Rogues and Monks from level 2 and Cloud's Jaunt Disengage).
  Leaving a foe's reach draws its opportunity attack, once a round (`provoked`). Monsters (`speed`, `reach`, and from
  the stat block `shot` ... `shot_long` for 29 of them) walk into reach and attack, Dash when they can't, or shoot
  from where they stand until you're next to them; standing from Prone costs half their Speed. The opening volley is
  gone. `cmd_map()` returns the board during a fight and `api.php` says so (`board`); `play.js` letters the columns,
  numbers the rows, and a tap on a square sends `move`, on the foe `kill`, on an exit `flee`. The migration ends
  fights under way. Shortcuts until 4b and 4c: a hireling fights only when its hero is next to the foe, spells other
  than melee spell attacks work from anywhere, and no monster ever steps out of a hero's reach, so heroes take no
  opportunity attacks yet.
- 2026-10-16 **Kiting** (task 4b): `kite` attacks, then backs off with what's left of my Speed to the square farthest
  from my foe (nearest me on a tie, then straightest, so along a wall I slide sideways); with a ranged attack and the
  foe next to me I back off first. `dash` (twice my Speed) and `disengage` (no opportunity attacks) go as far from
  the foe as they can, or to a square; `dodge` gives attacks on me Disadvantage until my next turn; Rogues and Monks
  from level 2 move away free (`slips()`). Slow (a hit with damage: 10 ft off its Speed until the start of my next
  turn, `spawns.conditions` 'slowed') and Push (10 ft straight away, Large or smaller, on my last attack of the turn
  so it never wastes one) are modeled. Reach weapons attack from 10 ft (`items.reach`); spells have a reach
  (`spells.reach`: range, 5 for Touch, the cone, cube or line of a Self spell; Breath Weapon 15, Land's Aid 60). From
  hiding I creep up as far as my Speed before the first turn. Unarmored Movement (+10 ft at Monk 2, 5 more every 4
  levels) and Fast Movement (Barbarian 5, not in Heavy armor). A monster grappled by a hero still fighting it can't
  move, and my hold breaks when I step out of 5 ft. A monster with a ranged attack now walks up to its normal range
  before it shoots. Left out: a hero standing up from Prone (nothing knocks heroes Prone yet), and a `shoot` button
  (`actions()` sees no weapon stats). Then `kite <monster> [square]`: a square goes there instead of backing off, so
  `move c3` then `kite e5` is move, shoot, move in one turn (Aaron's ask; an attack still ends the turn, so a fight
  never waits on an `end`).
- 2026-10-16 **Hirelings on the board** (task 4c, which finishes task 4): a hireling starts each fight beside me,
  nearest the foe (`users.hireling_x`, `y`), and acts after me by its stance, `order attack|guard|hold`
  (`hireling_order`; the guard and mastiff attack, the acolyte guards): it closes in, comes back beside me, or stays
  put, then attacks in melee within 5 ft, else with its ranged attack (the guard's thrown Spear, the acolyte's Radiant
  Flame). A monster next to it fights it unless it can reach me while staying beside it; if both of us are in its
  reach a d2 picks, as before. Both sides take opportunity attacks: my foe's Reaction is shared between me and the
  hireling (`provoked`), and the hireling strikes a foe that walks out of its reach. The Mastiff's Bite knocks a
  Medium or smaller foe Prone. The acolyte's Divine Aid, once a long rest (`hireling_aid`): Healing Word (2d4 + 2) on
  its own turn when I'm at half HP or less, or `order heal|bless|sanctuary` at any time. Bless is the spell's effect
  on me without Concentration; Sanctuary (a spells row with no class) makes a foe pass a Wis save (DC 12) to attack me,
  else it turns on the hireling beside it, and ends when I attack or cast. No new hireling kinds yet (backlog).
- 2026-10-16 **Fog of war and a bigger map** (task 5): `visited` (hero, room) gets a row each time a hero walks into
  a room. `cmd_map()` loads its zone's exits in one query and places every room reachable from you (no `MAP_REACH`
  any more), then draws what's explored: rooms visited (and the one you're in) in full, rooms next to them as walls
  around rock, the rest left off; all of Greenest always shows. Heroes from before start with nothing explored outside
  town. A Delve rebuild forgets its rooms. `play.js` puts the map's rows in a scrolling box, at most 21 rows high, kept
  centred on `@`, also when the phone drawer opens.
- 2026-10-16 **Battle maps** (task 6): a room's board is its hand-drawn `rooms.layout` (rows of `.` `#` `%` `,` `~`,
  any size up to 26x19, a letter where its monster starts), else generated from `rooms.terrain` (Riftshadow's sector,
  kept by `convert-area.php`; the town's tagged in `seed.sql`; the Sewers, Kobold Mines and Delve are all `cave`, the
  sixth column of `ZONES`) by `TERRAIN`'s mix, `mt_rand` seeded by the room as the Delve is by the week, keeping the
  squares by its exits clear and every open square connected. `#` rock blocks moving and sight; `%` a tree, boulder or
  table blocks moving and gives half cover (+2 AC) to a shot past it; `,` and `~` are difficult terrain. Sight is a
  Bresenham line (`sight()`); `in_reach()` needs it past 5 ft. Every walker (hero, monster, hireling) takes the
  cheapest path (`paths()`, Dijkstra; no cutting corners of rock or trees; `toward()` picks the straightest first step
  among the cheapest), and a shooter walks to a square with a line. No hand-drawn boards yet: Aaron sends rooms (the
  Delve boss room, the Mill Cellar...) and they go in `db/layouts.sql`, a new hand-edited file loaded after the areas.
  The board's glyphs are ASCII in PHP and swapped for the font's in `play.js` (`MAP_CELLS`): Aaron wants Dungeon
  Mode's own trees, rocks and water there later. Shortcuts: half cover doesn't help Dex saves, and creatures give no
  cover.
- 2026-10-16 **Several monsters in a fight** (task 7a): starting a fight, every aggressive monster in the room that
  sees you (and isn't 5 levels below you) joins, rolling Initiative (`spawns.init`, mine `users.init`), where it stood
  or by the first; a peaceful one joins when you attack it. Each round they act in Initiative order around my turn
  (`monsters_act()`; my target still by `foe_first`, ties to the hero), named apart (`Kobold 2`, by id) in the log,
  status line and board legend. `users.fighting` is my target: `kill kobold 2`, or a tap on a monster (`kill c3`),
  switches to it; when it dies I turn to the nearest other (`win()`), and the fight ends with the last. All of them
  block paths, and each takes its opportunity attack on its own Reaction (`spawns.conditions` 'reacted', back at its
  turn; `users.conditions` 'provoked' is gone). A shot has Disadvantage with any of them within 5 ft. An aggressive
  monster's ambush now runs the monsters that beat me, then leaves my turn under way (`moved` 0), instead of making me
  go first ever after. The migration ends fights under way. Shortcuts until 7b: two heroes in one room each run their
  own rounds over the same monsters, and a hireling leaving reach draws only my target's attack.
- 2026-10-16 **Shared fights** (task 7b, which finishes task 7 and the battle map): a fight belongs to its room. Every
  hero fighting there and every monster in it take turns in one Initiative order (`fight_heroes()`,
  `fight_monsters()`; ties to heroes, `foe_first` now only informs). The monsters between one hero and the next act
  when the first one's turn ends, and those ahead of the first hero when that hero's turn begins. `rooms.turn` is
  whose turn it is, since `turn_at`: anyone else's command gets "It's Ana's turn (1:40 left)", unless Ana is away (no
  news asked for in 15 seconds: page closed or tab hidden) or her 2 minutes are up (`TURN_SECS`); then her turn
  passes as the Dodge action, worked out on the next command from anyone in the fight (`my_turn()`, `pass_turn()`;
  play.js's 5-second listen counts). A hero who walks in mid-fight joins (`arrive()`): rolls Initiative, targets the
  nearest monster, and stands where they came in; the monsters keep their Initiative and squares. Each monster goes
  for the nearest hero, the one whose command it is on a tie. A kill's XP and gold are split evenly among the heroes
  in the fight (gold left over to the killer, XP's dropped; `award()`), and everyone whose target died turns to the
  nearest one left. Everything is retold: fight lines become events (`events.about`, the hero whose "you" they are),
  shown to the others in the third person ("Ana hits the Kobold", and "your Hold Person" for my own spell,
  `third_person()`) on their next command or listen, and to Ana as they happened when she's back, even after dying
  in town. Other heroes and their hirelings show on the board in capitals and block squares (`crowd()`). Shortcuts:
  Bonus Actions, potions and orders don't wait for your turn; a hero who flees skips the monsters between them and the
  next hero that round; a pass needs a command from a hero in the fight. Still skipped: cross-room shooting (with no
  ticks, a monster hit from 2 rooms away can't walk to you).
- 2026-10-16 **A bigger map:** on wide screens the sidebar is 34rem (a 13px log was tried and dropped); on both
  layouts the sheet's map fills what's left of the column (or phone drawer), centred, at 18px (16px cells, 2 CSS px a
  font pixel), scrolling when bigger. A fight's board is sized to fill its box instead (`fit()` in `play.js`, up to
  32px cells, never scrolling), so its squares are big enough to tap on a phone. Map rows now take the cell's
  line-height: the body's 1.5 had drawn every glyph a third of a cell below the square it taps. Aaron is trying it on
  his phone. Shortcuts: the `map` command's copy in the log isn't fitted, and a resize doesn't refit until the next
  command.
- 2026-10-17 **Choices and skills** (task 8): a hero's picks are `picks` rows; `owed_choices()` works out what their
  class, level and feats still owe: the class's skill proficiencies (SRD class tables; a Bard any 3), Skilled's three
  (a Human's Versatile), and Expertise (two skills at Rogue 1 and 6, Bard 2 and 9). `skill_bonus()` counts them, twice
  the Proficiency Bonus with Expertise and half (rounded down) for a Bard's Jack of All Trades from 2, so `inspect`,
  `ask` and `sneak` use them. Nothing blocks play outside character creation (Aaron's call, replacing "an owed choice
  blocks play"): levels and choices come when any rest ends, a house rule (SRD 5.2.1 p.23 levels you at once; a short
  rest is 1 minute). The sheet says "Level up ready: short rest" and "Skills to choose: short rest"; after a rest
  (`users.may_choose`) `choose <skills>` makes them, and the panel opens by itself. **The panel** (`panel()`, drawn by
  `play.js` in the log's place, layout A of the mockups): a choice as a form, radios or checkboxes with a line each, a
  counter, Back or Later, and Confirm, which sends the text a player could type. Character creation's class, scores,
  species and background are on it too. A left rail (ArchaicQuest-style) waits until there's more to keep on screen.
  Heroes from before choose their skills after their next rest; until then only their background's count.
- 2026-10-17 **Ability Score Improvements** (task 9): a General feat at levels 4, 8, 12 and 16, Fighter also 6 and
  14, Rogue 10 (`MORE_FEATS`), owed as `feat N` choices in `owed_choices()` and made after a rest, as skills are.
  Ability Score Improvement is +2 to one score or +1 to two; Grappler (once, needing Str or Dex 13) is +1 Str or Dex,
  Advantage on attack rolls against a foe you grapple, and Punch and Grab: once a turn, an Unarmed Strike of the
  Attack action that hits also grapples (`maneuver()`, shared with `grapple`). No score goes above 20. The scores
  change on `users` as a background's do, the pick is kept as `asi str dex`, `asi con` or `grappler dex`, and a
  higher Con modifier adds 1 HP a level. `choose str`, `choose str, dex` or `choose grappler dex`; on the panel, a
  feat and then one ability ticked for +2 or two for +1 (a group's `min`). `score` lists feats, and the sheet's badge
  names what's owed. Left out: Grappler's Fast Wrestler (a grappled foe never moves with you) and level 19's Epic
  Boon. Heroes already at level 4 or more choose after their next rest.
