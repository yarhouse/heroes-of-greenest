-- Aggro and stealth: a monster's passive Perception, and whether it attacks heroes it sees come in.
ALTER TABLE monsters ADD COLUMN perception TINYINT UNSIGNED NOT NULL DEFAULT 10 AFTER type,
    ADD COLUMN aggressive BOOLEAN NOT NULL DEFAULT FALSE AFTER perception;
