-- Shared fights (task 7b): whose turn it is in a room's fight, and fight lines told to the other heroes there.
ALTER TABLE rooms ADD COLUMN turn INT UNSIGNED NULL AFTER layout,
    ADD COLUMN turn_at DATETIME NULL AFTER turn;
ALTER TABLE events ADD COLUMN about INT UNSIGNED NULL AFTER shout;
