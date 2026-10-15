-- Sneaking: whether a hero moves quietly, and the Stealth total hiding them in their room.
ALTER TABLE users ADD COLUMN sneaking BOOLEAN NOT NULL DEFAULT FALSE AFTER foe_first,
    ADD COLUMN stealth TINYINT NULL AFTER sneaking;
