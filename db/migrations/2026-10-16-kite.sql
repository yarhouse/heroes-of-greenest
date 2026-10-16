-- Kiting (task 4b): Reach weapons, spell ranges, and Slow on monsters. seed.sql fills the item and spell columns.
ALTER TABLE items ADD COLUMN reach BOOLEAN NOT NULL DEFAULT FALSE AFTER loading;
ALTER TABLE spells ADD COLUMN reach SMALLINT UNSIGNED NULL AFTER about;
ALTER TABLE spawns MODIFY COLUMN conditions SET('prone', 'sapped', 'stunned', 'reeling', 'slowed') NOT NULL DEFAULT '';
