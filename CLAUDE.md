# CLAUDE.md

Heroes of Greenest: a tiny MUD-style text RPG played in the browser by a private D&D group (at most ~8 invite-only accounts, rarely online together).
Hosted on DreamHost shared hosting, so: PHP 8.1+ and MySQL 8 (not MariaDB; write MySQL 8 SQL and test against it), no long-running processes, no websockets, no build step on the server.

## Layout

- `public/` — the web root (point the domain's web directory here). `index.php` login + play page, `api.php` one command per POST, `play.js` terminal UI, `style.css`.
- `src/boot.php` — config, `db()`/`q()`, `web_init()` (headers, session, Origin check), login/logout/`session_user()`/`save_hero()`.
- `src/game.php` — `run_command()` parser and `cmd_*` handlers. Handlers take the `users` row and return lines of plain text.
- `bin/adduser.php` — the only way to create a hero or reset a password (no public signup, no email).
- `bin/convert-area.php` — turns a Riftshadow `.are` file into `db/areas/<name>.sql` (see README). Run locally only.
- `db/schema.sql` — all tables, DESTRUCTIVE. `db/seed.sql` — world content, re-runnable, never touches heroes.
- `db/areas/*.sql` — GENERATED dungeons; never hand-edit. Load after `seed.sql`, which clears all exits and spawns.
- `tests/selftest.php` — end-to-end checks, run in a rolled-back transaction.
- `config.php` (gitignored, from `config.php.example`), `var/sessions/` (gitignored, created on first request).

## Design rules

- No background ticks. The world changes only when someone sends a command; anything time-based (respawn, rests) is computed from timestamps when read.
- Rules are SRD 5.2.1 (5e), from `ref/SRD_CC_v5.2.1.pdf`; monster stats always come from an SRD stat block, never invented. Keep the SRD attribution (README, `seed.sql`, generated areas, login page).
- Every random number goes through `roll()` in `game.php`; tests queue exact die faces in `$dice_queue`.
- Combat and other multi-step actions resolve one round per command.
- Group play is asynchronous: shared world, `say` echoes persist in rooms, `who` shows last seen.
- Borrow freely from `../riftshadow/area/*.are` (rooms, mobs, objects; ROM/Diku license: keep credits, non-commercial) and ideas from `../dragon-knight`. Their code is reference only.

## Security

- All SQL through `q()` with bound parameters. Never interpolate input into SQL.
- Output is plain text rendered with `textContent` in `play.js`; never send HTML from `api.php`. `index.php` escapes with `$e()`.
- Passwords: `password_hash`, 8-72 bytes. 5 failed logins lock an account for 15 minutes.
- Sessions: private save path, HttpOnly + SameSite=Strict (+ Secure on HTTPS), 30 days, regenerated on login, invalidated when the password changes.
- CSRF: SameSite=Strict, Origin check on every POST, and `api.php` accepts only `application/json`.

## Code rules

- PSR-12, 4 spaces, `declare(strict_types=1)`, one `// file :: purpose` header per file. No framework, no Composer.
- `play.js` is plain JS with `// @ts-check` so there's nothing to build or deploy beyond copying files.
- Add a command: a `cmd_*` function in `game.php`, a branch in `run_command()`, a line in `cmd_help()`, a check in `tests/selftest.php`.
- Validate by running `php tests/selftest.php` and playing it in a browser via `php -S`.
