-- Battle maps (task 6): a room's terrain, for generated boards, and a hand-drawn board. seed.sql and the converted
-- areas fill terrain.
ALTER TABLE rooms ADD COLUMN terrain VARCHAR(20) NULL AFTER safe,
    ADD COLUMN layout TEXT NULL AFTER terrain;
