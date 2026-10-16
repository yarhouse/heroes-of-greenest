-- Several monsters in a fight (task 7a): each side's Initiative, and a monster's Reaction tracked on the monster.
ALTER TABLE spawns MODIFY COLUMN conditions SET('prone', 'sapped', 'stunned', 'reeling', 'slowed', 'reacted')
    NOT NULL DEFAULT '',
    ADD COLUMN init TINYINT NULL AFTER placed_at;
UPDATE users SET conditions = '' WHERE FIND_IN_SET('provoked', conditions);
ALTER TABLE users MODIFY COLUMN conditions SET('reckless', 'dodging') NOT NULL DEFAULT '',
    ADD COLUMN init TINYINT NULL AFTER moved;
-- Fights under way have no Initiative stored, so they end (as END_FIGHT in game.php).
UPDATE users SET fighting = NULL, raging = FALSE, innate = FALSE, advantage = FALSE, conditions = '',
    bonus_used = FALSE, moved = NULL WHERE fighting IS NOT NULL;
