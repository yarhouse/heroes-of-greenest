# Roadmap

What's being built, in order: take the top task. When a task ships, delete it here in the same commit (git log is the
history). Ideas go in `BACKLOG.md` and move here once chosen. This repo copy is the source; the claude.ai Project holds a copy.

## Next

1. **Species and backgrounds at character creation** (SRD 5.2.1). Species: Dragonborn, Dwarf, Elf, Gnome, Goliath,
   Halfling, Human, Orc, Tiefling. Backgrounds carry the ability boosts (+2/+1) and an Origin feat under these rules,
   so they come in the same pass. Model the traits a fight uses (Halfling Luck, Relentless Endurance, Dwarven
   Toughness, breath weapons, damage resistances); show the rest (darkvision, skills, speed) without rules. Existing
   heroes pick theirs via `bin/reclass.php`.
2. **Humanoid NPCs in the converter.** Rift humanoids are mostly race "human", so map them by role words to the SRD's
   NPC stat blocks (Guard, Cultist, Priest, Mage, Scout, Spy, Tough, Knight, Noble, Pirate...). Do it with the Sewers,
   which also need stand-ins for monsters the SRD lacks (no mind flayers).

Any time: **accounts for the friends**, once each:
`ssh -t $DEPLOY_HOST "/usr/local/php84/bin/php $DEPLOY_DIR/bin/adduser.php <Name>"`.

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
