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
Vulnerability apply both ways. Gear is the SRD's weapons, armor, Shield and Potion of Healing; AC and attacks come
from what you wear, and you can wear only the armor your class is trained in. There are no distances: a fight started
with a ranged or thrown weapon opens with a free volley while the foe closes in, and after that ranged attacks have
Disadvantage. Rest with `rest` (short) or `sleep` (long) in a safe room: the town square or the Swaying Lantern. The
plan and what's left out are in the project's `d20-plan.md`. "5E compatible."

This work includes material from the System Reference Document 5.2.1 ("SRD 5.2.1") by Wizards of the Coast LLC,
available at https://www.dndbeyond.com/srd. The SRD 5.2.1 is licensed under the Creative Commons Attribution 4.0
International License, available at https://creativecommons.org/licenses/by/4.0/legalcode.

The font is JetBrains Mono (https://github.com/JetBrains/JetBrainsMono), SIL Open Font License 1.1, see
`public/fonts/OFL.txt`.

The layout uses Bootstrap 5.3.8 (https://getbootstrap.com), MIT License, self-hosted in `public/vendor/bootstrap/` with
its `LICENSE`. To upgrade, copy `bootstrap.min.css` and `bootstrap.bundle.min.js` from the release's `dist/`.

## Accounts

Invite only. `php bin/adduser.php <Name>` creates a hero (who chooses a class on first login), or resets the password of an existing one (which also logs them out everywhere and clears any lockout).
