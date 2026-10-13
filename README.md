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
php bin/adduser.php Aaron               # prompts for a password
php tests/selftest.php                  # should print all "ok"
php -S localhost:8080 -t public         # play at http://localhost:8080
```

## Deploy to DreamHost (shared hosting)

1. Panel: create a MySQL database and user. Enable SSH for the site's user. Set the site's PHP to 8.1 or newer.
2. SSH in and `git clone` this repo into your home directory, e.g. `~/greenest`.
3. Panel > Websites > your domain: set the **web directory** to `greenest/public`, so `config.php`, `src/`, `bin/` and `var/` are not reachable from the web.
4. Turn on the free Let's Encrypt certificate and force HTTPS.
5. On the server: create `config.php` with the DreamHost MySQL hostname, load `db/schema.sql` and `db/seed.sql`, run `php tests/selftest.php`, then `php bin/adduser.php <Name>` for each friend.

Update later with `git pull` (and re-run `db/seed.sql` if rooms changed).

## Accounts

Invite only. `php bin/adduser.php <Name>` creates a hero, or resets the password of an existing one (which also logs them out everywhere and clears any lockout).
