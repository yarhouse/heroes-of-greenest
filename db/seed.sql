-- seed.sql :: world content. Safe to re-run: updates rooms, monsters, levels and items in place and rebuilds exits and spawns
-- (respawning every monster and ending fights in progress), never touches heroes.
-- MySQL 8 syntax (the "AS new" row alias); MariaDB doesn't support it.
-- This work includes material from the System Reference Document 5.2.1 ("SRD 5.2.1") by Wizards of the Coast LLC,
-- available at https://www.dndbeyond.com/srd. The SRD 5.2.1 is licensed under the Creative Commons Attribution 4.0
-- International License, available at https://creativecommons.org/licenses/by/4.0/legalcode.
SET NAMES utf8mb4;

INSERT INTO rooms (id, name, description, safe) VALUES
(1, 'Greenest Town Square',
 'Cobbles worn smooth by a century of market days ring a stone well. Smoke curls from chimneys on every side, and the old keep looms over the rooftops to the north.', TRUE),
(2, 'Before the Keep',
 'The keep''s iron-banded gate is shut tight. Arrow slits watch the square below, and a notice board by the gate is crowded with requests for help.', FALSE),
(3, 'The Swaying Lantern',
 'A low-beamed common room smelling of woodsmoke and spilled ale. A lantern sways over the bar though there is no draft, and nobody here seems to find that strange.', TRUE),
(4, 'Market Lane',
 'Shuttered stalls line a narrow lane. A chalked sign on one reads "Back after the trouble passes."', FALSE),
(5, 'The Mill Bridge',
 'A timber bridge spans the slow brown river beside a waterwheel that has stopped turning. A trapdoor in the mill''s floor stands half open.', FALSE),
(6, 'Southern Fields',
 'Trampled barley stretches toward the treeline. Small clawed footprints criss-cross the mud, far too many of them.', FALSE),
(7, 'Mill Cellar',
 'Damp stone steps end in darkness that smells of wet grain and something worse. Scratching sounds echo from deeper in.', FALSE)
AS new
ON DUPLICATE KEY UPDATE name = new.name, description = new.description, safe = new.safe;

DELETE FROM exits;
INSERT INTO exits (room_id, dir, to_room) VALUES
(1, 'north', 2), (2, 'south', 1),
(1, 'east', 3),  (3, 'west', 1),
(1, 'west', 4),  (4, 'east', 1),
(1, 'south', 5), (5, 'north', 1),
(5, 'south', 6), (6, 'north', 5),
(5, 'down', 7),  (7, 'up', 5);

-- Monsters 1-16 are SRD 5.2.1 stat blocks (page numbers in comments), also the stat blocks bin/convert-area.php borrows.
-- Greenest's own monsters start at 1001 and copy an SRD stat block as noted.
DELETE FROM spawns;
INSERT INTO monsters (id, name, ac, hp, initiative, attack, attacks, to_hit, damage, cr, xp) VALUES
(1, 'Giant Rat', 13, '2d6', 3, 'Bite', 1, 5, '1d4+3', 0.125, 25),                     -- p.353
(2, 'Kobold Warrior', 14, '3d6-3', 2, 'Dagger', 1, 4, '1d4+2', 0.125, 25),             -- p.302
(3, 'Goblin Minion', 12, '2d6', 2, 'Dagger', 1, 4, '1d4+2', 0.125, 25),                -- p.290
(4, 'Bandit', 12, '2d8+2', 1, 'Scimitar', 1, 3, '1d6+1', 0.125, 25),                   -- p.261
(5, 'Goblin Warrior', 15, '3d6', 2, 'Scimitar', 1, 4, '1d6+2', 0.25, 50),              -- p.290
(6, 'Wolf', 12, '2d8+2', 2, 'Bite', 1, 4, '1d6+2', 0.25, 50),                          -- p.364
(7, 'Skeleton', 14, '2d8+4', 3, 'Shortsword', 1, 5, '1d6+3', 0.25, 50),                -- p.325
(8, 'Zombie', 8, '2d8+6', -2, 'Slam', 1, 3, '1d8+1', 0.25, 50),                        -- p.343
(9, 'Hobgoblin Warrior', 18, '2d8+2', 3, 'Longsword', 1, 3, '2d10+1', 0.5, 100),       -- p.298
(10, 'Gnoll Warrior', 15, '6d8', 1, 'Rend', 1, 4, '1d6+2', 0.5, 100),                  -- p.289
(11, 'Goblin Boss', 17, '6d6', 2, 'Scimitar', 2, 4, '1d6+2', 1, 200),                  -- p.290
(12, 'Bugbear Warrior', 14, '6d8+6', 2, 'Light Hammer', 1, 4, '3d4+2', 1, 200),        -- p.272
(13, 'Ogre', 11, '8d10+24', -1, 'Greatclub', 1, 6, '2d8+4', 2, 450),                   -- p.312
(14, 'Bandit Captain', 15, '8d8+16', 3, 'Scimitar', 2, 5, '1d6+3', 2, 450),            -- p.261
(15, 'Hobgoblin Captain', 17, '9d8+18', 4, 'Greatsword', 2, 4, '2d6+1d6+2', 3, 700),   -- p.299
(16, 'Troll', 15, '9d10+45', 1, 'Rend', 3, 7, '2d6+4', 5, 1800),                       -- p.333
(1001, 'Giant Rat', 13, '2d6', 3, 'Bite', 1, 5, '1d4+3', 0.125, 25),                  -- SRD Giant Rat
(1002, 'Kobold', 14, '3d6-3', 2, 'Dagger', 1, 4, '1d4+2', 0.125, 25),                 -- SRD Kobold Warrior
(1003, 'Kobold Skirmisher', 14, '3d6-3', 2, 'Dagger', 1, 4, '1d4+2', 0.125, 25)       -- SRD Kobold Warrior
AS new
ON DUPLICATE KEY UPDATE name = new.name, ac = new.ac, hp = new.hp, initiative = new.initiative, attack = new.attack,
    attacks = new.attacks, to_hit = new.to_hit, damage = new.damage, cr = new.cr, xp = new.xp;

-- Spawns start long dead so each one appears, with rolled HP, the first time its room is read.
INSERT INTO spawns (id, room_id, monster_id, dead_since) VALUES
(1, 6, 1002, '2000-01-01'), (2, 6, 1002, '2000-01-01'),
(3, 7, 1001, '2000-01-01'), (4, 7, 1001, '2000-01-01'), (5, 7, 1003, '2000-01-01');

-- SRD Character Advancement (p.23) and the Fighter Features table (p.47): XP, Proficiency Bonus, Second Wind uses,
-- and attacks per round (Extra Attack at 5, Two Extra Attacks at 11, Three at 20).
INSERT INTO levels (level, xp, prof_bonus, second_wind, attacks) VALUES
(1, 0, 2, 2, 1),
(2, 300, 2, 2, 1),
(3, 900, 2, 2, 1),
(4, 2700, 2, 3, 1),
(5, 6500, 3, 3, 2),
(6, 14000, 3, 3, 2),
(7, 23000, 3, 3, 2),
(8, 34000, 3, 3, 2),
(9, 48000, 4, 3, 2),
(10, 64000, 4, 4, 2),
(11, 85000, 4, 4, 3),
(12, 100000, 4, 4, 3),
(13, 120000, 5, 4, 3),
(14, 140000, 5, 4, 3),
(15, 165000, 5, 4, 3),
(16, 195000, 5, 4, 3),
(17, 225000, 6, 4, 3),
(18, 265000, 6, 4, 3),
(19, 305000, 6, 4, 3),
(20, 355000, 6, 4, 4)
AS new
ON DUPLICATE KEY UPDATE xp = new.xp, prof_bonus = new.prof_bonus, second_wind = new.second_wind, attacks = new.attacks;

-- Items 1-33 are Dragon Knight's items table and 101-132 its drops table, minus the MP-only and exp/gold-bonus
-- drops (MP and those bonuses don't exist here). Skivvies and Clothes are renamed so "A ... lies here" reads.
-- Loot level is DK's drop level for trinkets, and ceil(power / 5) for gear, since DK sold gear in shops.
INSERT INTO items (id, name, slot, level, attack, defense, max_hp, strength, dexterity) VALUES
(1, 'Stick', 'weapon', 1, 2, 0, 0, 0, 0),
(2, 'Branch', 'weapon', 1, 4, 0, 0, 0, 0),
(3, 'Club', 'weapon', 1, 5, 0, 0, 0, 0),
(4, 'Dagger', 'weapon', 2, 8, 0, 0, 0, 0),
(5, 'Hatchet', 'weapon', 3, 12, 0, 0, 0, 0),
(6, 'Axe', 'weapon', 4, 16, 0, 0, 0, 0),
(7, 'Brand', 'weapon', 5, 25, 0, 0, 0, 0),
(8, 'Poleaxe', 'weapon', 7, 35, 0, 0, 0, 0),
(9, 'Broadsword', 'weapon', 9, 45, 0, 0, 0, 0),
(10, 'Battle Axe', 'weapon', 10, 50, 0, 0, 0, 0),
(11, 'Claymore', 'weapon', 12, 60, 0, 0, 0, 0),
(12, 'Dark Axe', 'weapon', 20, 100, 0, 0, 0, 0),
(13, 'Dark Sword', 'weapon', 25, 125, 0, 0, 0, 0),
(14, 'Bright Sword', 'weapon', 20, 100, 0, 0, 0, 0),
(15, 'Magic Sword', 'weapon', 30, 150, 0, 0, 0, 0),
(16, 'Destiny Blade', 'weapon', 50, 250, 0, 0, 50, 0),
(17, 'Loincloth', 'armor', 1, 0, 2, 0, 0, 0),
(18, 'Tunic', 'armor', 1, 0, 5, 0, 0, 0),
(19, 'Leather Armor', 'armor', 2, 0, 10, 0, 0, 0),
(20, 'Hard Leather Armor', 'armor', 5, 0, 25, 0, 0, 0),
(21, 'Chain Mail', 'armor', 6, 0, 30, 0, 0, 0),
(22, 'Bronze Plate', 'armor', 10, 0, 50, 0, 0, 0),
(23, 'Iron Plate', 'armor', 20, 0, 100, 0, 0, 0),
(24, 'Magic Armor', 'armor', 25, 0, 125, 0, 0, 0),
(25, 'Dark Armor', 'armor', 30, 0, 150, 0, 0, 0),
(26, 'Bright Armor', 'armor', 35, 0, 175, 0, 0, 0),
(27, 'Destiny Raiment', 'armor', 40, 0, 200, 0, 0, 50),
(28, 'Reed Shield', 'shield', 1, 0, 2, 0, 0, 0),
(29, 'Buckler', 'shield', 1, 0, 4, 0, 0, 0),
(30, 'Small Shield', 'shield', 2, 0, 10, 0, 0, 0),
(31, 'Large Shield', 'shield', 6, 0, 30, 0, 0, 0),
(32, 'Silver Shield', 'shield', 12, 0, 60, 0, 0, 0),
(33, 'Destiny Aegis', 'shield', 20, 0, 100, 50, 0, 0),
(101, 'Life Pebble', 'trinket', 1, 0, 0, 10, 0, 0),
(102, 'Life Stone', 'trinket', 10, 0, 0, 25, 0, 0),
(103, 'Life Rock', 'trinket', 25, 0, 0, 50, 0, 0),
(107, 'Dragon''s Scale', 'trinket', 10, 0, 25, 0, 0, 0),
(108, 'Dragon''s Plate', 'trinket', 30, 0, 50, 0, 0, 0),
(109, 'Dragon''s Claw', 'trinket', 10, 25, 0, 0, 0, 0),
(110, 'Dragon''s Tooth', 'trinket', 30, 50, 0, 0, 0, 0),
(111, 'Dragon''s Tear', 'trinket', 35, 0, 0, 0, 50, 0),
(112, 'Dragon''s Wing', 'trinket', 35, 0, 0, 0, 0, 50),
(113, 'Demon''s Sin', 'trinket', 35, 0, 0, -50, 50, 0),
(114, 'Demon''s Fall', 'trinket', 35, 0, 0, 0, 50, 0),
(115, 'Demon''s Lie', 'trinket', 45, 0, 0, -100, 100, 0),
(116, 'Demon''s Hate', 'trinket', 45, 0, 0, 0, 100, 0),
(117, 'Angel''s Joy', 'trinket', 25, 0, 0, 25, 25, 0),
(118, 'Angel''s Rise', 'trinket', 30, 0, 0, 50, 50, 0),
(119, 'Angel''s Truth', 'trinket', 35, 0, 0, 75, 75, 0),
(120, 'Angel''s Love', 'trinket', 40, 0, 0, 100, 100, 0),
(121, 'Seraph''s Joy', 'trinket', 25, 0, 0, 0, 0, 25),
(122, 'Seraph''s Rise', 'trinket', 30, 0, 0, 0, 0, 50),
(123, 'Seraph''s Truth', 'trinket', 35, 0, 0, 0, 0, 75),
(124, 'Seraph''s Love', 'trinket', 40, 0, 0, 0, 0, 100),
(125, 'Ruby', 'trinket', 50, 0, 0, 150, 0, 0),
(127, 'Emerald', 'trinket', 50, 0, 0, 0, 150, 0),
(128, 'Topaz', 'trinket', 50, 0, 0, 0, 0, 150),
(129, 'Obsidian', 'trinket', 50, 150, 0, 0, 0, 0),
(130, 'Diamond', 'trinket', 50, 0, 150, 0, 0, 0)
AS new
ON DUPLICATE KEY UPDATE name = new.name, slot = new.slot, level = new.level, attack = new.attack,
    defense = new.defense, max_hp = new.max_hp, strength = new.strength, dexterity = new.dexterity;

-- One-time finds around Greenest. INSERT IGNORE leaves them wherever they are (or whoever has them) on re-runs.
INSERT IGNORE INTO objects (id, item_id, room_id) VALUES
(1, 1, 4),    -- Stick, Market Lane
(2, 18, 3),   -- Tunic, The Swaying Lantern
(3, 28, 2),   -- Reed Shield, Before the Keep
(4, 101, 7);  -- Life Pebble, Mill Cellar
