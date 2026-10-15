# Backlog

Unordered ideas, not committed to. When one is picked up, move it to `ROADMAP.md`; when it ships, delete it (the commit is the record).
This repo copy is the source; the claude.ai Project holds a copy.

- [ ] **More creation choices.** Point buy, Fighting Style and Divine Order picks, weapon mastery choices, Small Humans and Tieflings, choosing Magic Initiate's spells and casting ability, background equipment (or its 50 GP), skills. Species traits not ruled yet: Adrenaline Rush, Large Form, Draconic Flight, Stonecunning, Brave and Fey Ancestry (no Frightened or Charmed on heroes), the lineage spells the game lacks (Dancing Lights, Misty Step, False Life...), Alert's Initiative Swap.
- [ ] **Class features past level 2.** Not modeled yet: Action Surge (Fighter 2), Cleric Channel Divinity (2) and spells above 1st level, subclasses (3), Ability Score Improvements (4), Uncanny Dodge (Rogue 5). Monk: Patient Defense's Temporary Hit Points (10), Evasion (7, no monster forces Dex saves yet), Disciplined Survivor's reroll (14), Superior Defense (18), Quivering Palm (17), Body and Mind (20); the Push and Addle options of Open Hand Technique, Stunning Strike and Flurry in one turn. Paladin: Channel Divinity (Divine Sense, Sacred Weapon, Abjure Foes), Heroism, Searing Smite and the other spells a fight can't use yet, Faithful Steed, Aura of Protection for allies (today only the Paladin's own saves), Lay On Hands curing Poisoned and Restoring Touch. Ranger: Hunter's Mark on one quarry (today it marks whatever you fight) and the Bonus Action to move it, Ensnaring Strike's 1d6 a turn and Large foes' Advantage on its save, Horde Breaker, Tireless, Nature's Veil, Superior Hunter's Prey and Defense, Barkskin and the other spells a fight can't use yet. Wizard: a spellbook and choosing cantrips (today a Wizard knows every one the game has), Memorize Spell, Evoker's Sculpt Spells, Empowered Evocation and Overchannel, Shield, Sleep, Chromatic Orb, Ice Knife and other spells with riders. Area spells against every monster in the room. Sorcerer: choosing Metamagic (today Heightened and Seeking) and an Elemental Affinity type (today Fire), Sorcerous Burst, the other Draconic Spells, Dragon Wings. Bard: Bardic Inspiration on ability checks (flee), Font of Inspiration's slot for a use, Cutting Words against damage and for allies, Countercharm, Magical Discoveries as two fixed always-prepared spells (today they're prepared like the others), Words of Creation, Charm Person, Color Spray, Sleep, Heroism and Hideous Laughter, Expertise and Jack of All Trades (no skills yet). Druid: choosing Known Forms (today every eligible Beast), Wild Shape lasting half your level in hours (today until you leave it, drop or long rest), a mixed Multiattack in a form (Brown Bear), Wild Companion, Wild Resurgence, choosing a Primal Order, an Elemental Fury and a land (today Warden, Primal Strike and Arid), Natural Recovery's free Circle spell, Nature's Sanctuary, Archdruid, Shillelagh, Barkskin, Call Lightning, Moonbeam on the turn it's cast. Warlock: choosing Eldritch Invocations (today Armor of Shadows, Agonizing Blast and Eldritch Mind; none past level 2, so no Pact of the Blade, Thirsting Blade or Eldritch Smite), Hex moving to a new foe and its ability-check Disadvantage, Fiendish Resilience, Hurl Through Hell, Contact Patron, Mystic Arcanum, Armor of Agathys, Charm Person and Suggestion, Dark One's Blessing when another hero makes the kill. The Push and Slow masteries (they need distances), and masteries for only the weapons your class picks (today every weapon you wield has its mastery). Fighting Style choices such as Two-Weapon Fighting. Only one slot spell a turn when one was a Bonus Action.
- [ ] **The Sewers in Greenest's story.** Rename and re-text them as the Wood of Sharp Teeth was (an edited copy in `db/areas/src/`): concrete pipes, the dump, and monsters the SRD lacks (mind flayers, the morkoth, the chreffn, the mud monster) now fighting as stand-ins.
- [ ] **Choose Hit Dice to spend.** The SRD lets you spend them one at a time during a short rest; today `rest` spends
  them automatically until full HP. Worth it only if saving dice for later starts to matter.
- [ ] **Map fog of war.** Show a room's shape only once a hero has been next to it, and its contents only once inside. Needs a per-hero visited-rooms table.
- [ ] **Autocomplete command arguments.** After `kill `, suggest the monsters in the room; likewise items for `get`/`drop`/`equip`/`drink`. Today `play.js` only completes command names, from `COMMANDS`.
- [ ] **Monster traits.** Pack Tactics, Undead Fortitude and the other stat-block traits are ignored; monsters use their main attack × Multiattack.
- [ ] **Deeper Delve.** Stairs to deeper floors with higher CR, more themes, rerolling when the boss dies.
- [ ] **Shops**, if the group wants them (gold already drops by CR).
- [ ] **Live updates**, only if people play together: polling so `say` and fights show up without a command. Party combat is tactical step 3 below.

## Tactical combat: distance and room grids

Rooms as small boards (5-ft squares, like chess) so distance, movement, shooting, disengaging, retreating and cover matter. SRD 5.2.1 already has the rules: Speed, reach, weapon ranges, Disadvantage on ranged attacks within 5 ft of an enemy, opportunity attacks, Disengage/Dash/Dodge, cover (+2/+5 AC), difficult terrain. Today a fight is one hero against one spawn with no distance (ranged weapons get an opening volley), and fight state is on `users`.

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
