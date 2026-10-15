-- schema.sql :: all tables. DESTRUCTIVE: drops existing tables. Load seed.sql after this.
SET NAMES utf8mb4;
-- Dropping migrations tells bin/migrate.php this database matches this file, so it marks every migration done.
DROP TABLE IF EXISTS migrations, requests, checks, topics, npcs, features, effects, spells, delve, objects, events, users, items, levels, spawns,
    monsters, exits, rooms;

CREATE TABLE rooms (
    id INT UNSIGNED PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    description TEXT NOT NULL,
    safe BOOLEAN NOT NULL DEFAULT FALSE -- heroes can rest here
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exits (
    room_id INT UNSIGNED NOT NULL,
    dir ENUM('north', 'south', 'east', 'west', 'up', 'down') NOT NULL,
    to_room INT UNSIGNED NOT NULL,
    PRIMARY KEY (room_id, dir),
    FOREIGN KEY (room_id) REFERENCES rooms (id),
    FOREIGN KEY (to_room) REFERENCES rooms (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Monster templates, each from an SRD 5.2.1 stat block. Only the main attack is kept, made `attacks` times
-- (Multiattack), its damage types, its six saving throws, and its damage Resistances, Immunities and
-- Vulnerabilities (SRD 5.2.1 p.17); traits, reactions, bonus actions, spells and condition immunities are skipped.
CREATE TABLE monsters (
    id INT UNSIGNED PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    ac TINYINT UNSIGNED NOT NULL,
    hp VARCHAR(20) NOT NULL, -- dice rolled at each spawn, e.g. '3d6-3'
    initiative TINYINT NOT NULL,
    attack VARCHAR(30) NOT NULL,
    attacks TINYINT UNSIGNED NOT NULL,
    to_hit TINYINT NOT NULL,
    damage VARCHAR(30) NOT NULL, -- dice, e.g. '2d6+4'
    damage_type ENUM('acid', 'bludgeoning', 'cold', 'fire', 'force', 'lightning', 'necrotic',
        'piercing', 'poison', 'psychic', 'radiant', 'slashing', 'thunder') NOT NULL,
    extra_damage VARCHAR(30) NULL, -- a second roll of another type, e.g. the Hobgoblin Captain's 1d6 Poison
    extra_type ENUM('acid', 'bludgeoning', 'cold', 'fire', 'force', 'lightning', 'necrotic',
        'piercing', 'poison', 'psychic', 'radiant', 'slashing', 'thunder') NULL,
    cr DECIMAL(5, 3) NOT NULL,
    xp INT UNSIGNED NOT NULL,
    str_save TINYINT NOT NULL,
    dex_save TINYINT NOT NULL,
    con_save TINYINT NOT NULL,
    int_save TINYINT NOT NULL,
    wis_save TINYINT NOT NULL,
    cha_save TINYINT NOT NULL,
    resistances SET('acid', 'bludgeoning', 'cold', 'fire', 'force', 'lightning', 'necrotic',
        'piercing', 'poison', 'psychic', 'radiant', 'slashing', 'thunder') NOT NULL DEFAULT '',
    immunities SET('acid', 'bludgeoning', 'cold', 'fire', 'force', 'lightning', 'necrotic',
        'piercing', 'poison', 'psychic', 'radiant', 'slashing', 'thunder') NOT NULL DEFAULT '',
    vulnerabilities SET('acid', 'bludgeoning', 'cold', 'fire', 'force', 'lightning', 'necrotic',
        'piercing', 'poison', 'psychic', 'radiant', 'slashing', 'thunder') NOT NULL DEFAULT '',
    size ENUM('tiny', 'small', 'medium', 'large', 'huge', 'gargantuan') NOT NULL,
    type ENUM('aberration', 'beast', 'celestial', 'construct', 'dragon', 'elemental', 'fey', 'fiend',
        'giant', 'humanoid', 'monstrosity', 'ooze', 'plant', 'undead') NOT NULL,
    gear VARCHAR(100) NOT NULL DEFAULT '' -- items ids it carries, e.g. '23,50'; a kill's loot comes from these
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A monster living in a room. Alive while dead_since is NULL; respawns on the first read
-- after dead_since + respawn_secs (no background ticks).
CREATE TABLE spawns (
    id INT UNSIGNED PRIMARY KEY,
    room_id INT UNSIGNED NOT NULL,
    monster_id INT UNSIGNED NOT NULL,
    respawn_secs INT UNSIGNED NOT NULL DEFAULT 600,
    hp SMALLINT NOT NULL DEFAULT 0,
    max_hp SMALLINT NOT NULL DEFAULT 0, -- as rolled when it spawned
    dead_since DATETIME NULL,
    -- Prone ends at the start of its next turn, Sapped at its next attack roll or the start of my next turn, Stunned
    -- at the start of my next turn, and reeling (a Stunning Strike saved against) then or at the next attack on it.
    conditions SET('prone', 'sapped', 'stunned', 'reeling') NOT NULL DEFAULT '',
    grappled_by INT UNSIGNED NULL, -- the hero holding it; it counts only while that hero still fights it
    KEY (room_id),
    FOREIGN KEY (room_id) REFERENCES rooms (id),
    FOREIGN KEY (monster_id) REFERENCES monsters (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SRD Character Advancement (XP, Proficiency Bonus) and the class tables' columns that grow with level.
CREATE TABLE levels (
    level TINYINT UNSIGNED PRIMARY KEY,
    xp INT UNSIGNED NOT NULL,
    prof_bonus TINYINT UNSIGNED NOT NULL,
    second_wind TINYINT UNSIGNED NOT NULL, -- Fighter
    attacks TINYINT UNSIGNED NOT NULL, -- Fighter; a Barbarian gets 2 from level 5
    rages TINYINT UNSIGNED NOT NULL, -- Barbarian
    rage_damage TINYINT UNSIGNED NOT NULL, -- Barbarian
    martial_arts TINYINT UNSIGNED NOT NULL, -- Monk: the Martial Arts die's sides
    slots VARCHAR(30) NOT NULL, -- a full caster's (Cleric) spell slots by spell level, e.g. '4,3,2'
    half_slots VARCHAR(30) NOT NULL, -- a half caster's (Paladin, Ranger)
    pact_slots VARCHAR(30) NOT NULL -- a Warlock's Pact Magic slots, all of one level: '0,2' is two of level 2
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Item templates: the SRD 5.2.1 weapons, armor, Shield and Potion of Healing. Ammunition, weapon ranges, Strength
-- requirements and Stealth are left out. Which classes are trained with what is CLASSES in game.php.
CREATE TABLE items (
    id INT UNSIGNED PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    slot ENUM('weapon', 'armor', 'shield', 'potion') NOT NULL,
    cost DECIMAL(7, 2) NOT NULL, -- in gp; a kill's loot costs at most LOOT_GP_PER_CR x the monster's CR
    dice VARCHAR(10) NULL, -- a weapon's damage, or what a potion heals
    versatile VARCHAR(10) NULL, -- a Versatile weapon's damage when no shield is worn
    martial BOOLEAN NOT NULL DEFAULT FALSE, -- a Martial weapon, not Simple
    finesse BOOLEAN NOT NULL DEFAULT FALSE, -- attacks with the better of Str and Dex
    light BOOLEAN NOT NULL DEFAULT FALSE,
    -- Its Weapon Mastery property (p.89); MASTERIES in game.php lists the ones modeled.
    mastery ENUM('cleave', 'graze', 'nick', 'push', 'sap', 'slow', 'topple', 'vex') NULL,
    two_handed BOOLEAN NOT NULL DEFAULT FALSE, -- can't be used with a shield
    ac TINYINT UNSIGNED NOT NULL DEFAULT 0, -- armor's base AC, or a shield's bonus
    armor ENUM('light', 'medium', 'heavy') NULL, -- Dex adds all, up to +2, or nothing to the armor's AC
    damage_type ENUM('acid', 'bludgeoning', 'cold', 'fire', 'force', 'lightning', 'necrotic',
        'piercing', 'poison', 'psychic', 'radiant', 'slashing', 'thunder') NULL, -- a weapon's
    ranged BOOLEAN NOT NULL DEFAULT FALSE, -- a Ranged weapon: Dex to hit, Disadvantage once a foe is next to you
    thrown BOOLEAN NOT NULL DEFAULT FALSE, -- can be thrown, so it can open a fight from afar
    loading BOOLEAN NOT NULL DEFAULT FALSE, -- one shot a turn, however many attacks you have
    bonus TINYINT UNSIGNED NOT NULL DEFAULT 0, -- a magic weapon's bonus to attack and damage rolls (+1 Weapon)
    shop INT UNSIGNED NULL, -- the room that sells it; NULL if none does
    FOREIGN KEY (shop) REFERENCES rooms (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Name collation is case-insensitive, so "aaron" logs in as "Aaron", and two heroes can't be "Mira" and "MIRA".
CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account VARCHAR(16) NOT NULL UNIQUE, -- the login name, never shown in the game
    name VARCHAR(16) NULL UNIQUE, -- the hero's name, chosen first at character creation
    pass_hash VARCHAR(255) NOT NULL,
    room_id INT UNSIGNED NOT NULL DEFAULT 1,
    failed_logins TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    last_seen DATETIME NULL,
    last_event_id INT UNSIGNED NOT NULL DEFAULT 0,
    -- Character creation step, or NULL once the hero is in the world. New heroes name themselves, then (like any set
    -- back to 'class') choose a class, ability scores, species and background; the defaults below are overwritten then.
    setup ENUM('name', 'class', 'abilities', 'species', 'background', 'confirm') NULL DEFAULT 'name',
    class ENUM('fighter', 'barbarian', 'rogue', 'cleric', 'monk', 'paladin', 'ranger', 'wizard', 'sorcerer', 'bard',
        'druid', 'warlock') NOT NULL DEFAULT 'fighter',
    -- SPECIES and BACKGROUNDS in game.php; NULL for a hero made before there were any, until bin/reclass.php
    species ENUM('dragonborn', 'dwarf', 'elf', 'gnome', 'goliath', 'halfling', 'human', 'orc', 'tiefling') NULL,
    lineage VARCHAR(10) NULL, -- the species' choice: a dragon, lineage, giant, legacy, or a Human's Origin feat
    background ENUM('acolyte', 'criminal', 'sage', 'soldier') NULL,
    -- Uses of Breath Weapon or Giant Ancestry, or a Human's Heroic Inspiration or an Orc's Relentless Endurance used
    species_spent TINYINT UNSIGNED NOT NULL DEFAULT 0,
    origin_spent VARCHAR(30) NOT NULL DEFAULT '', -- lineage and Magic Initiate spell ids cast free since a long rest
    level TINYINT UNSIGNED NOT NULL DEFAULT 1,
    xp INT UNSIGNED NOT NULL DEFAULT 0,
    hp SMALLINT NOT NULL DEFAULT 11,
    max_hp SMALLINT UNSIGNED NOT NULL DEFAULT 11,
    temp_hp SMALLINT UNSIGNED NOT NULL DEFAULT 0, -- Temporary Hit Points, lost first; gone after a long rest
    shape INT UNSIGNED NULL, -- a Druid's Wild Shape: the monsters row (a Beast) whose form I'm in
    strength TINYINT UNSIGNED NOT NULL DEFAULT 15,
    dexterity TINYINT UNSIGNED NOT NULL DEFAULT 14,
    constitution TINYINT UNSIGNED NOT NULL DEFAULT 13,
    intelligence TINYINT UNSIGNED NOT NULL DEFAULT 8,
    wisdom TINYINT UNSIGNED NOT NULL DEFAULT 10,
    charisma TINYINT UNSIGNED NOT NULL DEFAULT 12,
    hit_dice_spent TINYINT UNSIGNED NOT NULL DEFAULT 0, -- a hero has one Hit Die per level
    -- Second Wind, Rage, Focus Points, Lay On Hands HP, a Ranger's free Hunter's Marks, Sorcery Points or Bardic
    -- Inspiration, Wild Shape or Dark One's Own Luck
    uses_spent TINYINT UNSIGNED NOT NULL DEFAULT 0,
    -- A once-a-long-rest feature used since the last one: a Monk's Uncanny Metabolism, a Paladin's free Divine Smite,
    -- a Wizard's Arcane Recovery, a Sorcerer's Sorcerous Restoration, a Druid's Natural Recovery, a Warlock's Magical
    -- Cunning.
    once_used BOOLEAN NOT NULL DEFAULT FALSE,
    feature_spent TINYINT UNSIGNED NOT NULL DEFAULT 0, -- a Monk's Wholeness of Body or a Sorcerer's Innate Sorcery
    slots_spent VARCHAR(30) NOT NULL DEFAULT '', -- spell slots spent by spell level, e.g. '2,1'
    raging BOOLEAN NOT NULL DEFAULT FALSE, -- until the fight ends
    innate BOOLEAN NOT NULL DEFAULT FALSE, -- a Sorcerer's Innate Sorcery, until the fight ends
    advantage BOOLEAN NOT NULL DEFAULT FALSE, -- on my next attack roll (Vex, Guiding Bolt); cleared when a fight ends
    -- Conditions and effects on me (SRD 5.2.1 p.177-191). Each ends at the start of my next turn or with the fight.
    conditions SET('reckless', 'dodging') NOT NULL DEFAULT '',
    bonus_used BOOLEAN NOT NULL DEFAULT FALSE, -- my Bonus Action this round of a fight; my turn's end clears it
    prepared VARCHAR(255) NOT NULL DEFAULT '', -- spell ids, e.g. '5,2'; empty means the class's defaults
    may_prepare BOOLEAN NOT NULL DEFAULT TRUE, -- a long rest lets me change them
    gold INT UNSIGNED NOT NULL DEFAULT 0,
    fighting INT UNSIGNED NULL,
    foe_first BOOLEAN NOT NULL DEFAULT FALSE, -- the monster won initiative, so it acts first each round
    resting ENUM('short', 'long') NULL, -- finishes on the first command after rest_until
    rest_until DATETIME NULL,
    -- A hireling (HIRELINGS in game.php) always at my side, its monsters row; it leaves once hired_until passes.
    hireling_monster_id INT UNSIGNED NULL,
    hireling_hp SMALLINT NULL,
    hired_until DATETIME NULL,
    FOREIGN KEY (room_id) REFERENCES rooms (id),
    FOREIGN KEY (hireling_monster_id) REFERENCES monsters (id),
    FOREIGN KEY (fighting) REFERENCES spawns (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Things said in a room. Shown live to others there, and as echoes to later visitors.
CREATE TABLE events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    text VARCHAR(500) NOT NULL,
    created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY room_recent (room_id, id),
    FOREIGN KEY (room_id) REFERENCES rooms (id),
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- An item in the world: on a room's floor (room_id) or carried by a hero (user_id), never both.
CREATE TABLE objects (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id INT UNSIGNED NOT NULL,
    room_id INT UNSIGNED NULL,
    user_id INT UNSIGNED NULL,
    worn BOOLEAN NOT NULL DEFAULT FALSE,
    offhand BOOLEAN NOT NULL DEFAULT FALSE, -- a worn weapon held in the off hand (a second Light weapon)
    KEY (room_id),
    KEY (user_id),
    FOREIGN KEY (item_id) REFERENCES items (id),
    FOREIGN KEY (room_id) REFERENCES rooms (id),
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row: the seed the Delve (src/delve.php) was last generated from. seed.sql sets it to 0 to force a rebuild.
CREATE TABLE delve (
    seed INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Spells the game can resolve (SRD 5.2.1 p.107+), by kind: a spell attack roll against AC, a saving throw, healing
-- (dice + the spellcasting modifier), or a buff that needs no roll. Attacks and saves may deal damage, and a spell may
-- leave an effect (see effects). Which classes cast what, and their spellcasting ability, is CLASSES in game.php. A row
-- with no classes is a class feature that leaves an effect (Bardic Inspiration), never cast.
CREATE TABLE spells (
    id INT UNSIGNED PRIMARY KEY,
    name VARCHAR(40) NOT NULL UNIQUE,
    level TINYINT UNSIGNED NOT NULL, -- 0 is a cantrip
    classes SET('bard', 'cleric', 'druid', 'paladin', 'ranger', 'sorcerer', 'warlock', 'wizard') NOT NULL,
    casting ENUM('action', 'bonus') NOT NULL, -- a Bonus Action costs no turn
    -- smite: a Bonus Action cast on my first melee hit of the round (any weapon hit if `ranged`), adding its dice or
    -- its save and effect to that hit; auto: damage with no roll to hit (Magic Missile)
    kind ENUM('attack', 'save', 'heal', 'buff', 'smite', 'auto') NOT NULL,
    -- ally: you or a hero here you name; allies: you and up to `count` - 1 heroes here
    targets ENUM('foe', 'self', 'ally', 'allies') NOT NULL DEFAULT 'foe',
    count TINYINT UNSIGNED NOT NULL DEFAULT 1, -- also an attack's rays (Scorching Ray), each rolled on its own
    upcast_targets TINYINT UNSIGNED NOT NULL DEFAULT 0, -- more targets (or rays) per slot level above its own
    creature ENUM('aberration', 'beast', 'celestial', 'construct', 'dragon', 'elemental', 'fey', 'fiend',
        'giant', 'humanoid', 'monstrosity', 'ooze', 'plant', 'undead') NULL, -- a foe must be this type
    ranged BOOLEAN NOT NULL DEFAULT FALSE, -- a ranged spell attack: Disadvantage next to a foe; a smite: any weapon
    dice VARCHAR(20) NULL, -- damage or healing at its own level; a cantrip's dice multiply at levels 5, 11 and 17
    upcast VARCHAR(20) NULL, -- per slot level above its own: more dice, or more of a max_hp effect
    damage_type ENUM('acid', 'bludgeoning', 'cold', 'fire', 'force', 'lightning', 'necrotic',
        'piercing', 'poison', 'psychic', 'radiant', 'slashing', 'thunder') NULL,
    save ENUM('str', 'dex', 'con', 'int', 'wis', 'cha') NULL,
    half BOOLEAN NOT NULL DEFAULT FALSE, -- a made save still takes half the damage
    -- What it leaves on a failed save, a hit, or a buff's targets: a condition, a die added to d20 tests ('1d4',
    -- '-1d4'), an AC bonus, a base AC + Dex without armor (Mage Armor), a Hit Point maximum bonus, an aura (`value`
    -- dice, `save` for half) that hurts the foe at the end of each of its turns, or extra damage dice of its
    -- damage_type on my weapon hits, or a Bardic Inspiration die.
    effect ENUM('condition', 'd20', 'ac', 'base_ac', 'max_hp', 'aura', 'damage', 'inspiration') NULL,
    value VARCHAR(20) NULL,
    repeat_save BOOLEAN NOT NULL DEFAULT FALSE, -- the target repeats the save at the end of each of its turns
    concentration BOOLEAN NOT NULL DEFAULT FALSE,
    minutes SMALLINT UNSIGNED NULL, -- how long its effect lasts, and at least until the fight it was cast in ends
    about VARCHAR(100) NOT NULL -- for the spell list
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A spell's lasting effect on a hero (user_id) or a monster (spawn_id). It lasts until `until`, and also while its
-- caster is still in the fight it was cast in (during: the spawn fought). No ticks: effects past both are ignored when
-- read (LIVE_EFFECT in game.php). Concentration ones also end when the caster's Concentration does.
CREATE TABLE effects (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    spawn_id INT UNSIGNED NULL,
    caster_id INT UNSIGNED NOT NULL,
    spell_id INT UNSIGNED NOT NULL,
    effect ENUM('condition', 'd20', 'ac', 'base_ac', 'max_hp', 'aura', 'damage', 'inspiration') NOT NULL,
    value VARCHAR(20) NOT NULL,
    -- The save the target repeats at the end of each of its turns, or an aura's save for half.
    save ENUM('str', 'dex', 'con', 'int', 'wis', 'cha') NULL,
    dc TINYINT UNSIGNED NULL,
    concentration BOOLEAN NOT NULL,
    during INT UNSIGNED NULL,
    until DATETIME NULL,
    KEY (user_id),
    KEY (spawn_id),
    KEY (caster_id),
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    FOREIGN KEY (spawn_id) REFERENCES spawns (id) ON DELETE CASCADE,
    FOREIGN KEY (caster_id) REFERENCES users (id) ON DELETE CASCADE,
    FOREIGN KEY (spell_id) REFERENCES spells (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Accounts asked for on account.php, waiting for the DM: bin/requests.php lists, approves and denies them.
CREATE TABLE requests (
    account VARCHAR(16) PRIMARY KEY,
    pass_hash VARCHAR(255) NOT NULL,
    note VARCHAR(200) NOT NULL, -- who's asking, so the DM knows them
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Something in a room to look closer at (`look <thing>`), and townsfolk to talk to (`ask <npc> about <topic>`). A
-- feature or topic with a skill reveals its `secret` on a check against `dc`: a Search or Study (SRD 5.2.1 p.187, p.189)
-- or an Influence (p.184). World content: seed.sql rewrites these, keeping their ids.
CREATE TABLE features (
    id INT UNSIGNED PRIMARY KEY,
    room_id INT UNSIGNED NOT NULL,
    keywords VARCHAR(80) NOT NULL, -- words that name it; the first is how the room lists it
    text TEXT NOT NULL, -- a line per line; {roster} becomes a line per hero
    skill VARCHAR(20) NULL, -- a SKILLS key in game.php
    dc TINYINT UNSIGNED NULL,
    secret TEXT NULL,
    KEY (room_id),
    FOREIGN KEY (room_id) REFERENCES rooms (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Townsfolk: never fought, never moved. Not monsters rows, so no attack or spell can reach them.
CREATE TABLE npcs (
    id INT UNSIGNED PRIMARY KEY,
    room_id INT UNSIGNED NOT NULL,
    name VARCHAR(60) NOT NULL, -- 'Marta Dunmore, the innkeeper': any word of it finds her
    about TEXT NOT NULL, -- what `look <npc>` shows
    KEY (room_id),
    FOREIGN KEY (room_id) REFERENCES rooms (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE topics (
    id INT UNSIGNED PRIMARY KEY,
    npc_id INT UNSIGNED NOT NULL,
    keywords VARCHAR(80) NOT NULL, -- as for features
    reply TEXT NOT NULL,
    skill VARCHAR(20) NULL,
    dc TINYINT UNSIGNED NULL,
    secret TEXT NULL,
    FOREIGN KEY (npc_id) REFERENCES npcs (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A hero's skill check on a feature or topic. A success stands; a failure can be tried again after 24 hours (p.184).
CREATE TABLE checks (
    user_id INT UNSIGNED NOT NULL,
    what VARCHAR(20) NOT NULL, -- 'feature 3' or 'topic 12': no foreign key, so seed.sql can rewrite them
    passed BOOLEAN NOT NULL,
    at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, what),
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
