-- Shouts: heard all over town, not just in the room.
ALTER TABLE events ADD COLUMN shout BOOLEAN NOT NULL DEFAULT FALSE AFTER text;
