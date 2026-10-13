-- schema.sql :: all tables. DESTRUCTIVE: drops existing tables. Load seed.sql after this.
SET NAMES utf8mb4;
DROP TABLE IF EXISTS objects, events, users, items, levels, spawns, monsters, exits, rooms;

CREATE TABLE rooms (
    id INT UNSIGNED PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    description TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exits (
    room_id INT UNSIGNED NOT NULL,
    dir ENUM('north', 'south', 'east', 'west', 'up', 'down') NOT NULL,
    to_room INT UNSIGNED NOT NULL,
    PRIMARY KEY (room_id, dir),
    FOREIGN KEY (room_id) REFERENCES rooms (id),
    FOREIGN KEY (to_room) REFERENCES rooms (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Monster templates. Stats and columns follow Dragon Knight's monsters table.
CREATE TABLE monsters (
    id INT UNSIGNED PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    max_hp SMALLINT UNSIGNED NOT NULL,
    max_dam SMALLINT UNSIGNED NOT NULL,
    armor SMALLINT UNSIGNED NOT NULL,
    level SMALLINT UNSIGNED NOT NULL,
    max_exp SMALLINT UNSIGNED NOT NULL,
    max_gold SMALLINT UNSIGNED NOT NULL
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

-- Experience needed for each level and the stats gained on reaching it (Dragon Knight's levels table).
CREATE TABLE levels (
    level SMALLINT UNSIGNED PRIMARY KEY,
    exp INT UNSIGNED NOT NULL,
    hp SMALLINT UNSIGNED NOT NULL,
    strength SMALLINT UNSIGNED NOT NULL,
    dexterity SMALLINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Item templates: Dragon Knight's items and drops tables in one. Worn bonuses are added to the hero's stats when read.
CREATE TABLE items (
    id INT UNSIGNED PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    slot ENUM('weapon', 'armor', 'shield', 'trinket') NOT NULL,
    level SMALLINT UNSIGNED NOT NULL, -- dropped as loot by monsters of this level or higher
    attack SMALLINT NOT NULL DEFAULT 0,
    defense SMALLINT NOT NULL DEFAULT 0,
    max_hp SMALLINT NOT NULL DEFAULT 0,
    strength SMALLINT NOT NULL DEFAULT 0,
    dexterity SMALLINT NOT NULL DEFAULT 0
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
    -- Dragon Knight's starting stats. hp is as of hp_at; regen since then is worked out when read.
    level SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    hp SMALLINT NOT NULL DEFAULT 15,
    hp_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    max_hp SMALLINT UNSIGNED NOT NULL DEFAULT 15,
    strength SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    dexterity SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    gold INT UNSIGNED NOT NULL DEFAULT 100,
    exp INT UNSIGNED NOT NULL DEFAULT 0,
    fighting INT UNSIGNED NULL,
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
