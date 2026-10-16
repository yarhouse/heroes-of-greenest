-- The battle board (task 4a): squares for heroes and monsters, monsters' Speed, reach and ranged attacks, and
-- weapons' ranges. seed.sql fills the monster and item columns.
ALTER TABLE monsters ADD COLUMN speed TINYINT UNSIGNED NOT NULL DEFAULT 30 AFTER aggressive,
    ADD COLUMN reach TINYINT UNSIGNED NOT NULL DEFAULT 5 AFTER speed,
    ADD COLUMN shot VARCHAR(30) NULL AFTER reach,
    ADD COLUMN shot_hit TINYINT NULL AFTER shot,
    ADD COLUMN shot_damage VARCHAR(30) NULL AFTER shot_hit,
    ADD COLUMN shot_type ENUM('acid', 'bludgeoning', 'cold', 'fire', 'force', 'lightning', 'necrotic',
        'piercing', 'poison', 'psychic', 'radiant', 'slashing', 'thunder') NULL AFTER shot_damage,
    ADD COLUMN shot_range SMALLINT UNSIGNED NULL AFTER shot_type,
    ADD COLUMN shot_long SMALLINT UNSIGNED NULL AFTER shot_range;
ALTER TABLE spawns ADD COLUMN x TINYINT UNSIGNED NULL AFTER grappled_by,
    ADD COLUMN y TINYINT UNSIGNED NULL AFTER x,
    ADD COLUMN placed_at DATETIME NULL AFTER y;
ALTER TABLE items ADD COLUMN normal_range SMALLINT UNSIGNED NULL AFTER loading,
    ADD COLUMN long_range SMALLINT UNSIGNED NULL AFTER normal_range;
ALTER TABLE users MODIFY COLUMN conditions SET('reckless', 'dodging', 'provoked') NOT NULL DEFAULT '',
    ADD COLUMN x TINYINT UNSIGNED NULL AFTER bonus_used,
    ADD COLUMN y TINYINT UNSIGNED NULL AFTER x,
    ADD COLUMN moved SMALLINT UNSIGNED NULL AFTER y;
-- Fights under way have no squares, so they end (as END_FIGHT in game.php); everything else stays.
UPDATE users SET fighting = NULL, raging = FALSE, innate = FALSE, advantage = FALSE, conditions = '',
    bonus_used = FALSE WHERE fighting IS NOT NULL;
