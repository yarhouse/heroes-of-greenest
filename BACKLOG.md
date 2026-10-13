# Backlog

Unordered ideas. Move an item to a commit when it ships.

- [ ] **Character creation: choose starter options.** Today `bin/adduser.php` makes every hero the same. Let the player pick starter options (class, background, kit, ability scores, etc.) from SRD 5.2.1 choices.
- [x] **Always-visible `score`.** Show the `score` output (abilities, HP, AC, XP) in a panel that stays on screen instead of needing the command. Maybe desktop-only, since a phone screen has no room beside the terminal.
- [ ] **ASCII map?** Draw the rooms around the hero as an ASCII map. Undecided.
- [ ] **Bootstrap as the theme framework.** Bootstrap 5 (5.3 is current; 6 isn't out yet). No build step, so self-host the CSS in `public/` (CSP blocks CDNs). The terminal output stays plain text via `textContent`.
