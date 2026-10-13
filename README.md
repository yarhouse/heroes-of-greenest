# Heroes of Greenest

A small text adventure for our D&D group. Log in on the website, type commands, explore Greenest together.

## Run it locally

Needs PHP 8.1+ (with pdo_mysql and mbstring) and MySQL 8, which is what DreamHost shared hosting runs. Don't use MariaDB: `db/seed.sql` uses MySQL 8 syntax.

```sh
# macOS, one time
brew install php mysql@8.4 && brew link --force mysql@8.4
brew services start mysql@8.4
mysql -u root -e "CREATE DATABASE greenest; CREATE USER 'greenest'@'localhost' IDENTIFIED BY 'devpass';
  GRANT ALL ON greenest.* TO 'greenest'@'localhost';"

cp config.php.example config.php        # then edit the DB settings
mysql -u greenest -p greenest < db/schema.sql   # DESTRUCTIVE: wipes all tables
mysql -u greenest -p greenest < db/seed.sql     # world content; safe to re-run (respawns monsters)
cat db/areas/*.sql | mysql -u greenest -p greenest   # converted dungeons; load after seed.sql, every time
php bin/adduser.php Aaron               # prompts for a password
php tests/selftest.php                  # should print all "ok"
php -S localhost:8080 -t public         # play at http://localhost:8080
```

## Deploy to DreamHost (shared hosting)

1. Panel: create a MySQL database and user. Enable SSH for the site's user. Set the site's PHP to 8.1 or newer.
2. SSH in and `git clone` this repo into your home directory, e.g. `~/greenest`.
3. Panel > Websites > your domain: set the **web directory** to `greenest/public`, so `config.php`, `src/`, `bin/` and `var/` are not reachable from the web.
4. Turn on the free Let's Encrypt certificate and force HTTPS.
5. On the server: create `config.php` with the DreamHost MySQL hostname, load `db/schema.sql`, `db/seed.sql` and `db/areas/*.sql`, run `php tests/selftest.php`, then `php bin/adduser.php <Name>` for each friend.

Update later with `git pull` (and re-run `db/seed.sql` then `db/areas/*.sql` if the world changed).

## Dungeons from Riftshadow

`db/areas/*.sql` are generated from Riftshadow area files (sibling checkout `../riftshadow`) and committed, so the
server never needs Riftshadow. To add or regenerate one, name the Greenest room and direction that lead into it:

```sh
php bin/convert-area.php ../riftshadow/area/koboldmines.are 6 south 24600 > db/areas/koboldmines.sql
```

That links Southern Fields (6) south to the mines' entrance (24600), and back north.

## Rules

The game uses a small subset of the D&D 5e rules from the SRD 5.2.1 (`ref/SRD_CC_v5.2.1.pdf`, gitignored): heroes are
Fighters with the Standard Array, combat is d20 attack rolls against AC, monsters are SRD stat blocks, and XP and
levels follow Character Advancement. Rest with `rest` (short) or `sleep` (long) in a safe room: the town square or the
Swaying Lantern. The plan and what's left out are in the project's `d20-plan.md`. "5E compatible."

This work includes material from the System Reference Document 5.2.1 ("SRD 5.2.1") by Wizards of the Coast LLC,
available at https://www.dndbeyond.com/srd. The SRD 5.2.1 is licensed under the Creative Commons Attribution 4.0
International License, available at https://creativecommons.org/licenses/by/4.0/legalcode.

## Accounts

Invite only. `php bin/adduser.php <Name>` creates a hero, or resets the password of an existing one (which also logs them out everywhere and clears any lockout).
