-- The Town Square's bulletin board (schema.sql has the comments). IF NOT EXISTS makes a rerun after a failed ALTER safe.
CREATE TABLE IF NOT EXISTS bulletins (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    text TEXT NOT NULL,
    posted DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE users ADD COLUMN last_bulletin_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_event_id;
