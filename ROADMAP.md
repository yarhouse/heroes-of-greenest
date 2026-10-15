# Roadmap

What's being built, in order: take the top task. When a task ships, delete it here in the same commit (git log is the
history). Ideas go in `BACKLOG.md` and move here once chosen. This repo copy is the source; the claude.ai Project holds a copy.

## Next

1. **Distance on the fight** (tactical step 1 from `BACKLOG.md`). One `distance` (feet) column with the fight state;
   in a 1-on-1 fight with no obstacles a 2D grid reduces to this number. Gives ranged and thrown weapons
   (Disadvantage once adjacent; a free shot while the monster closes), advance-and-attack in one command, reach
   weapons, Disengage, Dash, retreat to an exit with an opportunity attack, monsters that close or kite. About one
   `fight()` change plus a few commands. Show it on the map: a room's floor is 5 squares across, so a foe 15 ft away
   can stand 3 cells from `@`, stepping in as it closes (and `Here:` can say "kobold, 15 ft").
2. **Fog of war and a bigger map.** A per-hero visited-rooms table, written on each move. A room's shape shows once
   you've been next to it, its contents only while you're in it. With that, the map can grow past `MAP_REACH` 2 to the
   explored part of the floor, centred on `@` and scrolling in the sidebar, like the reference maps in `ref/ascii maps/`.

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
