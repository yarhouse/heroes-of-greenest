-- Things to look at and townsfolk to talk to, with skill checks behind some (schema.sql has the comments).
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

CREATE TABLE checks (
    user_id INT UNSIGNED NOT NULL,
    what VARCHAR(20) NOT NULL, -- 'feature 3' or 'topic 12': no foreign key, so seed.sql can rewrite them
    passed BOOLEAN NOT NULL,
    at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, what),
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
