-- schema.sql :: all tables. DESTRUCTIVE: drops existing tables. Load seed.sql after this.
SET NAMES utf8mb4;
DROP TABLE IF EXISTS delve, objects, events, users, items, levels, spawns, monsters, exits, rooms;

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
-- (Multiattack); traits, reactions, bonus actions, saves and spells are skipped.
CREATE TABLE monsters (
    id INT UNSIGNED PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    ac TINYINT UNSIGNED NOT NULL,
    hp VARCHAR(20) NOT NULL, -- dice rolled at each spawn, e.g. '3d6-3'
    initiative TINYINT NOT NULL,
    attack VARCHAR(30) NOT NULL,
    attacks TINYINT UNSIGNED NOT NULL,
    to_hit TINYINT NOT NULL,
    damage VARCHAR(30) NOT NULL, -- dice, with any extra damage added in, e.g. '2d6+1d6+2'
    cr DECIMAL(5, 3) NOT NULL,
    xp INT UNSIGNED NOT NULL,
    dex_save TINYINT NOT NULL -- for Sacred Flame
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A monster living in a room. Alive while dead_since is NULL; respawns on the first read
-- after dead_since + respawn_secs (no background ticks).
CREATE TABLE spawns (
    id INT UNSIGNED PRIMARY KEY,
    room_id INT UNSIGNED NOT NULL,
    monster_id INT UNSIGNED NOT NULL,
    respawn_secs INT UNSIGNED NOT NULL DEFAULT 600,
    hp SMALLINT NOT NULL DEFAULT 0,
    dead_since DATETIME NULL,
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
    slots VARCHAR(30) NOT NULL -- Cleric spell slots by spell level, e.g. '4,3,2'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Item templates: the SRD 5.2.1 melee weapons, armor, Shield and Potion of Healing. Ranged weapons, Strength
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
    vex BOOLEAN NOT NULL DEFAULT FALSE, -- the Vex mastery: a hit gives Advantage on your next attack
    two_handed BOOLEAN NOT NULL DEFAULT FALSE, -- can't be used with a shield
    ac TINYINT UNSIGNED NOT NULL DEFAULT 0, -- armor's base AC, or a shield's bonus
    armor ENUM('light', 'medium', 'heavy') NULL -- Dex adds all, up to +2, or nothing to the armor's AC
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Name collation is case-insensitive, so "aaron" logs in as "Aaron".
CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(16) NOT NULL UNIQUE,
    pass_hash VARCHAR(255) NOT NULL,
    room_id INT UNSIGNED NOT NULL DEFAULT 1,
    failed_logins TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    last_seen DATETIME NULL,
    last_event_id INT UNSIGNED NOT NULL DEFAULT 0,
    -- Character creation step, or NULL once the hero is in the world. New heroes (and any set back to 'class') choose
    -- a class and ability scores on their next login; the defaults below are overwritten then.
    setup ENUM('class', 'abilities', 'confirm') NULL DEFAULT 'class',
    class ENUM('fighter', 'barbarian', 'rogue', 'cleric') NOT NULL DEFAULT 'fighter',
    level TINYINT UNSIGNED NOT NULL DEFAULT 1,
    xp INT UNSIGNED NOT NULL DEFAULT 0,
    hp SMALLINT NOT NULL DEFAULT 11,
    max_hp SMALLINT UNSIGNED NOT NULL DEFAULT 11,
    strength TINYINT UNSIGNED NOT NULL DEFAULT 15,
    dexterity TINYINT UNSIGNED NOT NULL DEFAULT 14,
    constitution TINYINT UNSIGNED NOT NULL DEFAULT 13,
    intelligence TINYINT UNSIGNED NOT NULL DEFAULT 8,
    wisdom TINYINT UNSIGNED NOT NULL DEFAULT 10,
    charisma TINYINT UNSIGNED NOT NULL DEFAULT 12,
    hit_dice_spent TINYINT UNSIGNED NOT NULL DEFAULT 0, -- a hero has one Hit Die per level
    uses_spent TINYINT UNSIGNED NOT NULL DEFAULT 0, -- Second Wind or Rage
    slots_spent VARCHAR(30) NOT NULL DEFAULT '', -- spell slots spent by spell level, e.g. '2,1'
    raging BOOLEAN NOT NULL DEFAULT FALSE, -- until the fight ends
    advantage BOOLEAN NOT NULL DEFAULT FALSE, -- on my next attack roll (Vex, Guiding Bolt); cleared when a fight ends
    gold INT UNSIGNED NOT NULL DEFAULT 0,
    fighting INT UNSIGNED NULL,
    foe_first BOOLEAN NOT NULL DEFAULT FALSE, -- the monster won initiative, so it acts first each round
    resting ENUM('short', 'long') NULL, -- finishes on the first command after rest_until
    rest_until DATETIME NULL,
    FOREIGN KEY (room_id) REFERENCES rooms (id),
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
