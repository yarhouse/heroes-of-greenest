# Roadmap

What's being built, in order: take the top task. When a task ships, delete it here in the same commit (git log is the
history). Ideas go in `BACKLOG.md` and move here once chosen. This repo copy is the source; the claude.ai Project holds a copy.

## Next

Three goals, in any order: **the town redrawn** from Aaron's sketch and feedback, **travel** over distances as ordinary
rooms until something attacks, and **encounters on a battle map** with positions, so a ranged hero can kite (Aaron
plans to, with a Longbow). The tasks below are in a sensible order, and each ships alone.

### Travel and the town

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

### Encounters on a battle map

Rooms as small boards (5-ft squares, like chess) so distance, movement, shooting, disengaging, retreating and cover
matter. SRD 5.2.1 already has the rules: Speed, reach, weapon ranges, Disadvantage on ranged attacks within 5 ft of an
enemy, opportunity attacks, Disengage/Dash/Dodge, cover (+2/+5 AC), difficult terrain. Today a fight is one hero
against one spawn with no distance (ranged weapons get an opening volley), and fight state is on `users`. Kiting works
by the rules only with a speed edge or a slowed foe: at equal Speed a monster closes and attacks every turn, so room
size decides how many free shots you get, and the Slow mastery decides whether you can keep them coming.

5. **Fog of war and a bigger map.** A per-hero visited-rooms table, written on each move. A room's shape shows once
   you've been next to it, its contents only while you're in it. With that, the map can grow past `MAP_REACH` 2 to the
   explored part of the zone, centred on `@` and scrolling in the sidebar, like the reference maps in
   `ref/ascii maps/`. Load the zone's exits in one query, not one per ring as `cmd_map()` does now.
6. **Battle maps** (step 2). A layout column on `rooms` (ASCII like below), so rooms come in any size: a hall can be
   20 by 12 squares and still be one cell on the zone map. Step 1's open boards gain walls, cover and terrain:
   line of sight (Bresenham), cover, difficult terrain, monsters walking around obstacles (BFS), and monsters' spots
   in the layout. Step 1's commands keep working. Rooms without a hand-drawn layout get one generated from their
   terrain, the same every time: takes in the backlog's terrain idea (`convert-area.php` keeps Riftshadow's sector in `rooms.terrain`, the town's are tagged by hand;
   forest gives trees as half cover, water difficult terrain). The Delve generator draws its own layouts.
7. **Shared fights others can join** (step 3). The fight belongs to the room, not the hero: a per-room encounter
   table, one initiative order for every hero and monster in it. A hero who walks into a room mid-fight rolls
   Initiative, is slotted into the order and appears at the edge of the exit they came through. Players aren't online
   together, so an away hero's turn passes as a Dodge (see Decided), computed on read.

```
#########
#..k....#    k kobold     @ you
#...##..+    # wall       + exit (east)
#.......#    T table (half cover)
#..T..@.#
#########
```

Skipped: **cross-room shooting** (`shoot north goblin`). Finding the target is easy, but with no ticks a monster hit
from 2 rooms away can't walk to you, and "feet per room" is made up. **Coordinate math** is the cheap part (BFS and
line of sight on ~100 cells).

Costs, most expensive first: room layout content (Kobold Mines' 174 rooms, the Delve, the seed rooms have none);
positions with no ticks and async play; making one command a whole 5e turn (move + action) so fights aren't tedious;
monster AI (close, kite, flee), which needs selftest coverage.

Any time: **approve account requests** (friends ask on the login page):
`ssh $DEPLOY_HOST "/usr/local/php84/bin/php $DEPLOY_DIR/bin/requests.php"`, then `... requests.php approve <Account>`.

## Decided

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
