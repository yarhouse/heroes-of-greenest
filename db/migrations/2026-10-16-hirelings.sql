-- Hirelings on the board (task 4c): a hireling's square, stance and Divine Aid. seed.sql adds the Sanctuary spell.
ALTER TABLE users ADD COLUMN hireling_x TINYINT UNSIGNED NULL AFTER hired_until,
    ADD COLUMN hireling_y TINYINT UNSIGNED NULL AFTER hireling_x,
    ADD COLUMN hireling_order ENUM('attack', 'guard', 'hold') NULL AFTER hireling_y,
    ADD COLUMN hireling_aid BOOLEAN NOT NULL DEFAULT FALSE AFTER hireling_order;
