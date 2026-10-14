# Backlog

Unordered ideas. Move an item to a commit when it ships.

- [x] **Character creation: choose starter options.** Class (Fighter, Barbarian, Rogue, Cleric, Monk, Paladin, Ranger) and Standard Array on first login, with the class's kit A.
- [ ] **More creation choices.** Backgrounds (their +2/+1 ability boost), species, point buy, Fighting Style and Divine Order picks, weapon mastery choices.
- [ ] **Class features past level 2.** Not modeled yet: Action Surge (Fighter 2), Cleric Channel Divinity (2) and spells above 1st level, subclasses (3), Ability Score Improvements (4), Uncanny Dodge (Rogue 5). Monk: Patient Defense's Temporary Hit Points (10), Evasion (7, no monster forces Dex saves yet), Disciplined Survivor's reroll (14), Superior Defense (18), Quivering Palm (17), Body and Mind (20); the Push and Addle options of Open Hand Technique, Stunning Strike and Flurry in one turn. Paladin: Channel Divinity (Divine Sense, Sacred Weapon, Abjure Foes), Heroism, Searing Smite and the other spells a fight can't use yet, Faithful Steed, Aura of Protection for allies (today only the Paladin's own saves), Lay On Hands curing Poisoned and Restoring Touch. Ranger: Hunter's Mark on one quarry (today it marks whatever you fight) and the Bonus Action to move it, Ensnaring Strike's 1d6 a turn and Large foes' Advantage on its save, Horde Breaker, Tireless, Nature's Veil, Superior Hunter's Prey and Defense, Barkskin and the other spells a fight can't use yet. The Push and Slow masteries (they need distances), and masteries for only the weapons your class picks (today every weapon you wield has its mastery). Fighting Style choices such as Two-Weapon Fighting. Only one slot spell a turn when one was a Bonus Action.
- [x] **Heal other heroes.** `cast cure <hero>` heals a hero in the room; Mass Healing Word heals everyone here.
- [x] **Always-visible `score`.** Show the `score` output (abilities, HP, AC, XP) in a panel that stays on screen instead of needing the command. Maybe desktop-only, since a phone screen has no room beside the terminal.
- [x] **ASCII map.** Rooms two steps around the hero, under the score panel (and the `map` command on phones).
- [ ] **Map fog of war.** Show a room's shape only once a hero has been next to it, and its contents only once inside. Needs a per-hero visited-rooms table.
- [x] **Bootstrap as the theme framework.** Bootstrap 5.3.8, self-hosted in `public/vendor/bootstrap/` (CSP blocks CDNs) and themed to the terminal palette. The terminal output stays plain text via `textContent`. First use: the hero sheet card, a drawer on phones.
- [ ] **Project scripts.** Nothing is on DreamHost yet.
  - [ ] **DreamHost SSH deployment.** Copy `public/`, `src/`, `bin/` and `db/` up over SSH; never overwrite `config.php` or `var/`.
  - [ ] **DreamHost SQL seed script.** Load `schema.sql` (first install only, it's destructive), then `seed.sql` and `db/areas/*.sql`, against DreamHost's MySQL.
  - [ ] **Seed script that doesn't require passwords.** Run unattended, without typing a password at a prompt.
- [ ] **Autocomplete command arguments.** After `kill `, suggest the monsters in the room; likewise items for `get`/`drop`/`equip`/`drink`. Today `play.js` only completes command names, from `COMMANDS`.
- [ ] **Healing is hard to find.** "Where are Hit Dice to spend? My character never seems to heal." Today Hit Dice are spent only automatically at the end of a `rest` (short rest): it works only in a safe room (Town Square, Swaying Lantern), takes 10 real minutes, moving cancels it, and it only resolves on your next command after the time is up. There's no natural healing. Ideas: show the time left in the score panel, say where to rest when HP is low, maybe let `rest` spend Hit Dice one at a time as in the SRD.

## Tactical combat: distance and room grids

Rooms as small boards (5-ft squares, like chess) so distance, movement, shooting, disengaging, retreating and cover matter. SRD 5.2.1 already has the rules: Speed, reach, weapon ranges, Disadvantage on ranged attacks within 5 ft of an enemy, opportunity attacks, Disengage/Dash/Dodge, cover (+2/+5 AC), difficult terrain. Today combat is melee only, one hero against one spawn, with fight state on `users`.

Example board (`cmd_map` already draws ASCII and the font is monospace):

```
#########
#..k....#    k kobold     @ you
#...##..+    # wall       + exit (east)
#.......#    T table (half cover)
#..T..@.#
#########
```

Each step ships alone and doesn't block the next.

- [ ] **Step 1: distance on the fight.** One `distance` (feet) column with the fight state. In a 1-on-1 fight with no obstacles a 2D grid reduces to this number. Gives ranged and thrown weapons (Disadvantage once adjacent; a free shot while the monster closes), advance-and-attack in one command, reach weapons, Disengage, Dash, retreat to an exit with an opportunity attack, monsters that close or kite. About one `fight()` change plus a few commands.
- [ ] **Step 2: optional room layouts.** A layout column on `rooms` (ASCII like above). Rooms with one fight on (x, y) with line of sight (Bresenham) and cover; rooms without one stay a plain distance. Distance becomes computed from positions, so step 1's commands keep working. The Delve generator draws its own layouts.
- [ ] **Step 3: group fights on a shared board.** Several heroes and monsters, one initiative order, across players who aren't online together. Needs a per-room encounter table and a turn timeout (play-by-post style: no action in N hours means you Dodge), computed on read.
- Skipped: **cross-room shooting** (`shoot north goblin`). Finding the target is easy, but with no ticks a monster hit from 2 rooms away can't walk to you, and "feet per room" is made up. **Coordinate math** is the cheap part (BFS and line of sight on ~100 cells).

Costs, most expensive first: room layout content (Kobold Mines' 174 rooms, the Delve, the seed rooms have none); positions with no ticks and async play; making one command a whole 5e turn (move + action) so fights aren't tedious; monster AI (close, kite, flee), which needs selftest coverage.

### Open questions

- [x] Ranged combat at all? Yes, before step 1: a ranged or thrown weapon opens a fight with a free volley while the foe closes in, then has Disadvantage. Rogue kit A has its Shortbow, Fighter kit A its 8 Javelins. Ammunition isn't tracked.
- [ ] Start with step 1, or go straight to a grid?
- [ ] How far apart does a fight start? Fixed, by room size, or by which side you entered from? Surprise or stealth?
- [ ] Command vocabulary: bundled turns (`kill` paths and swings, `shoot` stays put, `back off`, `cover`) and/or chess-style `move c3`?
- [ ] Does `flee` stay a Dex contest, or become moving to an exit (Disengage or take the opportunity attack)?
- [ ] Ammunition and thrown weapons: track arrows and bolts, and pick daggers and javelins back up after the fight (SRD recovers half the ammunition)?
- [ ] Out of combat, does a monster sit at its layout spot (computed on read)? Where does a hero appear when entering: at the exit they came through?
- [ ] Two heroes fighting the same spawn at different times: separate boards per hero (fits today's per-user fight state) or one shared position?
- [ ] Layout authoring: ASCII column in `seed.sql`, a default open box sized from the room text, and does `convert-area.php` produce anything for Riftshadow rooms?
- [ ] Which SRD monsters in the seed and Delve pools have ranged attacks we currently ignore? Which AI per monster: close, kite, flee when hurt?
- [ ] Hero reactions (opportunity attacks against a fleeing monster) when the player isn't online: automatic?
- [ ] Display: draw the board every round, only on `look`, or in the side panel? Does it fit a phone width?
- [ ] Group fights: turn timeout length, and whether step 3 is wanted at all (the plan says party combat only if people play together).
