# Roadmap

What's being built, in order: take the top task. When a task ships, delete it here in the same commit (git log is the
history). Ideas go in `BACKLOG.md` and move here once chosen. This repo copy is the source; the claude.ai Project holds a copy.

## Next

1. **Converter maps by creature, not level.** `bin/convert-area.php` picks the SRD stat block nearest CR = Rift level / 12,
   so Kobold Mines is Goblin Bosses, Ogres and Hobgoblin Captains under Rift names. Map Rift race to an SRD stat block
   (kobold → Kobold Warrior, goblin → Goblin Minion/Warrior/Boss by level, hobgoblin → Hobgoblin Warrior, rat → Giant Rat,
   ...), keeping the CR rule as the fallback. Map Rift gear by type to SRD items (the old object parsing is in fd6aa89's
   converter). Regenerate Kobold Mines; expect it to land around 5e level 3-8. Stat blocks: SRD Monsters A–Z p.258,
   Animals p.344.
2. **Bridge areas.** Choose a Rift-level-to-hero-level target, then convert Haon Dor (Rift 5-10), maybe the Sewers
   (Rift 5-30), each hung off a Greenest exit, so there's something between Greenest and Kobold Mines.

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
- 2026-10-14 **Deployed** at https://yarhouse.dev/heroes-of-greenest with `bin/deploy.sh` (see README).
