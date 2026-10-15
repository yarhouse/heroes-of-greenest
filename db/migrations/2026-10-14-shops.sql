-- Shops: which room sells an item, and the bonus of a magic weapon.
ALTER TABLE items ADD COLUMN bonus TINYINT UNSIGNED NOT NULL DEFAULT 0, ADD COLUMN shop INT UNSIGNED NULL,
    ADD FOREIGN KEY (shop) REFERENCES rooms (id);
