-- seed.sql :: world content. Safe to re-run: updates rooms in place and rebuilds exits, never touches heroes.
-- MySQL 8 syntax (the "AS new" row alias); MariaDB doesn't support it.
SET NAMES utf8mb4;

INSERT INTO rooms (id, name, description) VALUES
(1, 'Greenest Town Square',
 'Cobbles worn smooth by a century of market days ring a stone well. Smoke curls from chimneys on every side, and the old keep looms over the rooftops to the north.'),
(2, 'Before the Keep',
 'The keep''s iron-banded gate is shut tight. Arrow slits watch the square below, and a notice board by the gate is crowded with requests for help.'),
(3, 'The Swaying Lantern',
 'A low-beamed common room smelling of woodsmoke and spilled ale. A lantern sways over the bar though there is no draft, and nobody here seems to find that strange.'),
(4, 'Market Lane',
 'Shuttered stalls line a narrow lane. A chalked sign on one reads "Back after the trouble passes."'),
(5, 'The Mill Bridge',
 'A timber bridge spans the slow brown river beside a waterwheel that has stopped turning. A trapdoor in the mill''s floor stands half open.'),
(6, 'Southern Fields',
 'Trampled barley stretches toward the treeline. Small clawed footprints criss-cross the mud, far too many of them.'),
(7, 'Mill Cellar',
 'Damp stone steps end in darkness that smells of wet grain and something worse. Scratching sounds echo from deeper in.')
AS new
ON DUPLICATE KEY UPDATE name = new.name, description = new.description;

DELETE FROM exits;
INSERT INTO exits (room_id, dir, to_room) VALUES
(1, 'north', 2), (2, 'south', 1),
(1, 'east', 3),  (3, 'west', 1),
(1, 'west', 4),  (4, 'east', 1),
(1, 'south', 5), (5, 'north', 1),
(5, 'south', 6), (6, 'north', 5),
(5, 'down', 7),  (7, 'up', 5);
