# Backlog

Unordered ideas. Move an item to a commit when it ships.

- [ ] **Character creation: choose starter options.** Today `bin/adduser.php` makes every hero the same. Let the player pick starter options (class, background, kit, ability scores, etc.) from SRD 5.2.1 choices.
- [x] **Always-visible `score`.** Show the `score` output (abilities, HP, AC, XP) in a panel that stays on screen instead of needing the command. Maybe desktop-only, since a phone screen has no room beside the terminal.
- [x] **ASCII map.** Rooms two steps around the hero, under the score panel (and the `map` command on phones).
- [ ] **Map fog of war.** Show a room's shape only once a hero has been next to it, and its contents only once inside. Needs a per-hero visited-rooms table.
- [ ] **Bootstrap as the theme framework.** Bootstrap 5 (5.3 is current; 6 isn't out yet). No build step, so self-host the CSS in `public/` (CSP blocks CDNs). The terminal output stays plain text via `textContent`.
