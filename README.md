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
php bin/migrate.php                     # marks the migrations done; after that, applies new ones
mysql -u greenest -p greenest < db/seed.sql     # world content; safe to re-run (respawns monsters)
cat db/areas/*.sql | mysql -u greenest -p greenest   # converted dungeons; load after seed.sql, every time
php bin/adduser.php Aaron               # an account; prompts for a password
php tests/selftest.php                  # should print all "ok"
php -S localhost:8080 -t public         # play at http://localhost:8080
```

## Deploy to DreamHost (shared hosting)

The code goes to a folder in the SSH user's home, outside the web root, and a symlink in the domain's folder exposes only
`public/`, so `config.php`, `src/`, `bin/` and `var/` are never reachable from the web. Create `deploy.env` (gitignored):

```sh
DEPLOY_HOST=USER@SERVER.dreamhost.com
DEPLOY_DIR=heroes-of-greenest                # the code, in USER's home
DEPLOY_WEB=example.com/heroes-of-greenest    # the URL folder, a symlink to DEPLOY_DIR/public; don't create it yourself
```

One time, in the DreamHost panel: create a **MySQL database** and user and note its hostname (`mysql.example.com`).
The domain needs PHP 8.1 or newer and HTTPS. Your SSH key must be on the server (`ssh-copy-id USER@SERVER`).

One time, on the server (`ssh USER@SERVER`). If `~/.my.cnf` already exists, add the `[client]` lines to it instead:

```sh
mkdir -p ~/heroes-of-greenest
cat > ~/heroes-of-greenest/config.php <<'PHP'
<?php
return [
    'dsn' => 'mysql:host=mysql.example.com;dbname=greenest;charset=utf8mb4',
    'db_user' => 'greenest',
    'db_pass' => 'the password',
];
PHP
printf '[client]\nhost=mysql.example.com\nuser=greenest\npassword=the password\ndatabase=greenest\n' > ~/.my.cnf
chmod 600 ~/heroes-of-greenest/config.php ~/.my.cnf
```

Then from your Mac, the schema (DESTRUCTIVE, first time only), the first deploy and the accounts:

```sh
. ./deploy.env
ssh $DEPLOY_HOST mysql < db/schema.sql
bin/deploy.sh
ssh -t $DEPLOY_HOST "/usr/local/php84/bin/php $DEPLOY_DIR/bin/adduser.php Aaron"   # once per friend
```

After that, every update is `bin/deploy.sh`. It refuses to run with uncommitted changes or a failing local selftest,
copies the code over (never `config.php` or `var/`), makes the symlink, runs the pending schema migrations, reloads
`db/seed.sql` and `db/areas/*.sql` (heroes are untouched; monsters respawn and the Delve rerolls), and runs the selftest
on the server.

## The bulletin board

The board in the Town Square shows the newest three rows of `bulletins`. Post straight into the database, no deploy
needed: until a hero reads it with `inspect bulletin`, the square tells them a new notice is pinned there.
`bin/bulletin.sh` does the SQL (and the quoting), on `local` or the `server`; the VS Code tasks
"Local/Server: list, post, update, delete bulletin" run it. In the text, `\n` starts a new line.

```sh
bin/bulletin.sh server list
bin/bulletin.sh server post "The miller's cellar is clear.\nDrinks at the Lantern tonight."
bin/bulletin.sh server update 2 "The miller's cellar is clear. Drinks at the Lantern tomorrow."
bin/bulletin.sh server delete 2
```

Each post's id is the board's version, compared with every hero's `last_bulletin_id`. So an update isn't announced; to
re-announce a post, delete it and post it again.

## Schema changes

To change a table, add a file to `db/migrations/` named so it sorts after the others, with the ALTERs (one statement
each when you can: MySQL commits each ALTER on its own), and make the same change in `db/schema.sql`, which stays the
whole current schema. `php bin/migrate.php` runs each file not yet in the `migrations` table, in name order, and stops
at the first error; `bin/deploy.sh` runs it locally and then on the server, before the world reload. A database
without that table (a fresh `schema.sql` load, which drops it) is taken to be current, so its files are only marked
done. The selftest fails while a migration is pending.

```sh
printf 'ALTER TABLE users ADD COLUMN title VARCHAR(40) NULL;\n' > db/migrations/2026-10-20-title.sql
php bin/migrate.php                     # applied 2026-10-20-title.sql
```

## Dungeons from Riftshadow

`db/areas/*.sql` are generated from Riftshadow area files (sibling checkout `../riftshadow`) and committed, so the
server never needs Riftshadow. To add or regenerate one, name the Greenest room and direction that lead into it:

```sh
php bin/convert-area.php ../riftshadow/area/koboldmines.are 6 south 24600 > db/areas/koboldmines.sql
php bin/convert-area.php db/areas/src/sharpteeth.are 6 west 6000 > db/areas/sharpteeth.sql
php bin/convert-area.php ../riftshadow/area/sewer.are 4 down 7030 > db/areas/sewer.sql
```

The first links Southern Fields (6) south to the mines' entrance (24600), and back north; the second links it west to
the edge of the Wood of Sharp Teeth (6000), Riftshadow's Haon Dor renamed; the third takes Market Lane (4) down into
the Sewers. To change an area's text (room and monster names, descriptions), copy its `.are` file
into `db/areas/src/`, edit it there, and convert from that copy, so the edits survive every regeneration.

Each monster gets an SRD stat block by its Riftshadow race, or a word of its name, with tiers by Rift level
(`CREATURES` in the converter: a Rift 23 goblin is a Goblin Warrior, a Rift 30 one a Goblin Boss); a race not listed
there falls back to the stat block nearest CR = Rift level / 12, so add the race when converting a new area. Rift
humanoids are mostly race "human", so role words in their names pick the SRD's NPCs (a guard is a Guard, a Rift 30
one a Guard Captain), and a creature the SRD lacks gets a stand-in of about its strength (the Sewers' mind flayers are
a Darkmantle, a Grick and an Otyugh). The
weapons, body armor and shields a monster carries become the SRD items they're closest to (its `gear`), and a kill's
loot comes from those. A monster is aggressive if Riftshadow's is (`ACT AGGRESSIVE`) and it doesn't start asleep.

## The Delve

Below the Mill Cellar, a stair leads down into the Delve, a dungeon that is generated anew each week: a goblin warren,
a crypt or a flooded cave of 15-20 rooms, with harder monsters the deeper you go and a boss guarding a treasure in the
farthest room. It is rebuilt when a hero next reaches the stair in a new week, but never while someone online is inside;
heroes left inside offline wake on the stair. Re-running `db/seed.sql` also rebuilds it. The generator is
`src/delve.php`.

## Rules

The game uses a small subset of the D&D 5e rules from the SRD 5.2.1 (`ref/SRD_CC_v5.2.1.pdf`, gitignored). On first
login a hero chooses a class (Fighter, Barbarian, Rogue, Cleric, Monk, Paladin, Ranger, Sorcerer, Wizard, Bard, Druid or Warlock) and assigns the Standard Array, and starts with
that class's equipment A (no ammunition, packs or tools). Combat is d20 attack rolls against AC, monsters are SRD stat
blocks, and XP and levels follow Character Advancement. Heroes make saving throws with their ability modifier, plus
the Proficiency Bonus for their class's two. Fighters have Second Wind and Extra Attack; Barbarians Rage (with
Advantage on Str saves), Unarmored Defense and from level 2 Reckless Attack and Danger Sense (Advantage on Dex saves);
Rogues Sneak Attack (with Advantage, or another hero on the same foe) and from level 2 always get away with `flee`;
Monks fight unarmored with Martial Arts (Dex attacks, the Martial Arts die, an Unarmed Strike as a Bonus Action) and
from level 2 spend Focus Points (back on a short rest) on `flurry`, `patient` and from 5 `stun`, always get away with
`flee`, get Focus back with Uncanny Metabolism, Deflect Attacks (3), topple with Open Hand Technique and heal with
`wholeness` (6); Paladins `lay` on hands from a pool of 5 HP a level, smite on a melee hit (`cast divine smite`, free
once a long rest, and Shining Smite), cast Divine Favor, Cure Wounds and the Cleric's Bless, Command, Shield of Faith
and Aid, and gain Defense (2), Extra Attack (5), Aura of Protection on their own saves (6) and Radiant Strikes (11);
Rangers cast Hunter's Mark (free a few times a long rest), Ensnaring Strike, Entangle, Cure Wounds and Aid, and gain
Archery (2), Colossus Slayer (3), Extra Attack (5) and Multiattack Defense (7); Wizards know every Wizard cantrip
and spell the game has (Fire Bolt, Ray of Frost, Shocking Grasp, Chill Touch, Acid Splash, Poison Spray, Magic Missile,
Burning Hands, Thunderwave, Mage Armor, Scorching Ray, Shatter, Web, Mind Spike, Hold Person, Blindness, Fireball,
Lightning Bolt), get slots back with Arcane Recovery on a short rest, and from 3 their cantrips deal half damage on a
miss (Potent Cantrip); an area spell hits only the foe you fight; Sorcerers cast from the same arcane spells with
Charisma, unleash `innate` sorcery in a fight (+1 save DC, Advantage on spell attacks), spend Sorcery Points on `font`
(points into slots and back) and Metamagic (`cast heightened ...`, `cast seeking ...`), and as Draconic Sorcerers get
more HP, AC 10 + Dex + Cha, Command, and Fire resistance and damage (6); Bards `inspire` another hero with a die for a
missed attack or failed save, cast Vicious Mockery, Starry Wisp, Dissonant Whispers, Faerie Fire and the Cleric's
healing and hexes, and as College of Lore Bards spend that die on Cutting Words (3), get it back on short rests (5),
prepare Cleric, Druid and Wizard spells (6) and use it on their own misses (14); Druids cast Starry Wisp, Produce
Flame, Faerie Fire, Entangle, Thunderwave, Moonbeam and healing, take `wild` shape as an SRD beast (its AC and attacks,
Temporary Hit Points, no spells), and as Circle of the Land (Arid) Druids always have Fire Bolt, Burning Hands and
Fireball, spend a Wild Shape on `land` (Land's Aid), recover slots on a short rest (6), add a Primal Strike (7) and
resist Fire (10); Warlocks cast with Pact Magic (few slots, all of one level, back on every short rest, and half of
them once a long rest with `cunning`), cast Eldritch Blast (more beams at 5, 11 and 17; + Cha from level 2), Hex
(its 1d6 on every attack hit), Mage Armor free on themselves and Bane, have Advantage on Concentration saves (2), and
as Fiend Warlocks always have Burning Hands, Command, Scorching Ray and Fireball, gain Temporary Hit Points on a kill
(3) and add 1d10 to a failed save (6);
Clerics `prepare` spells after a long rest (four at level 1, nine at 5) and `cast` them: Sacred Flame, Guiding Bolt,
Cure Wounds, Healing Word, Mass Healing Word, Inflict Wounds, Bless, Bane, Command, Shield of Faith, Aid, Hold Person,
Blindness/Deafness and Spirit Guardians. Healing reaches other heroes here. Spells are rows of the `spells` table;
their effects last their real duration and at least the fight they were cast in, and Concentration breaks on a failed
Con save when hurt. Anyone can `shove` a foe Prone or `grapple` it in place of an attack. With a Light weapon in each
hand (`equip dagger offhand`) you get an extra attack each round, using your Bonus Action unless the off-hand weapon
has Nick; in a fight you get one Bonus Action a round (Rage, Second Wind, a potion, Healing Word or that attack).
Weapon masteries: Cleave carries a hit on to a second monster in the room, Graze deals your modifier on a miss, Nick
frees the off-hand attack, Sap gives the foe Disadvantage on its next attack, Topple can knock it Prone and Vex gives
Advantage on your next attack (Push and Slow need distances). Damage has SRD types, and Resistance, Immunity and
Vulnerability apply both ways. Gear is the SRD's weapons, armor, Shield, Potions of Healing and Uncommon +1 Weapons and Shield; AC and attacks come
from what you wear, and you can wear only the armor your class is trained in. There are no distances: a fight started
with a ranged or thrown weapon opens with a free volley while the foe closes in, and after that ranged attacks have
Disadvantage. Aggressive monsters attack a hero they see come into their room (not one more than 5 levels above
their CR). `sneak` makes a Stealth check each time you enter a room with monsters: 15 or more hides you from each one
whose passive Perception is lower (heavy armor and some medium give Disadvantage), and attacking from hiding gives
Advantage on Initiative and your first attack and surprises the monster (Disadvantage on its Initiative). A fight,
speaking or dying ends sneaking. Rest with
`rest` (short, 10 minutes: Hit Dice heal you) or `sleep` (long, an hour: full HP) in a safe
room: the town square, the Swaying Lantern or the temple. The hero sheet counts down the time left, an open page finishes the rest
by itself, and a fight that leaves you at half HP or less ends with where to rest. At
the Lantern you can `hire` a guard, a mastiff or an acolyte (the SRD's Guard, Mastiff and Priest Acolyte) for 2 gold a
day: it follows you, attacks your foe after you, takes some of its attacks, and heals when you rest. The shops off
Market Lane `list`, `buy` and `sell` (at half price): weapons at the Smithy, armor and shields at the Armorer,
potions at the Apothecary and +1 items in the Curio Loft above it (SRD prices; magic items 400 GP plus the item). At
the temple of Chauntea, east of the Mill Bridge, `pray cure`, `heal` or `aid` buys a priest's spell at the SRD's
Spellcasting Services prices. The plan and
what's left out are in the project's `d20-plan.md`. "5E compatible."

This work includes material from the System Reference Document 5.2.1 ("SRD 5.2.1") by Wizards of the Coast LLC,
available at https://www.dndbeyond.com/srd. The SRD 5.2.1 is licensed under the Creative Commons Attribution 4.0
International License, available at https://creativecommons.org/licenses/by/4.0/legalcode.

The font is JetBrains Mono (https://github.com/JetBrains/JetBrainsMono), SIL Open Font License 1.1, see
`public/fonts/OFL.txt`.

The layout uses Bootstrap 5.3.8 (https://getbootstrap.com), MIT License, self-hosted in `public/vendor/bootstrap/` with
its `LICENSE`. To upgrade, copy `bootstrap.min.css` and `bootstrap.bundle.min.js` from the release's `dist/`.

## Accounts

Invite only. An account (the name you log in with, never shown in the game) has one hero, named by its player on
first login before choosing a class. Account and hero names are both 3-16 letters.

Friends ask for an account on the login page ("Request an account", `account.php`), with a note saying who they are.
The DM looks and decides on the server:

```sh
php bin/requests.php                   # list waiting requests
php bin/requests.php approve <Account> # they can log in now
php bin/requests.php deny <Account>
```

A request for a name that's taken is dropped without saying so, so the form can't be used to find account names; at
most 20 wait at once. Logged in, the same page changes your account name or password (it needs your current one, and
wrong tries count toward the login lockout); a new password logs out your other sessions.

`php bin/adduser.php <Account>` creates an account directly, or resets the password of an existing one (which also logs
them out everywhere and clears any lockout).

`php bin/reclass.php <Hero>` sends a hero back to character creation: on their next login they choose a class and
scores again and start over at level 1, keeping only their name and account.

Test heroes are ordinary accounts, named after their account by the selftest. The password can come from a pipe, so one
line makes one:

```sh
echo 'test password' | php bin/adduser.php Testbard
```
