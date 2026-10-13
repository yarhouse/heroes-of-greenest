-- schema.sql :: all tables. DESTRUCTIVE: drops existing tables. Load seed.sql after this.
SET NAMES utf8mb4;
DROP TABLE IF EXISTS events, users, exits, rooms;

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
    FOREIGN KEY (room_id) REFERENCES rooms (id)
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
