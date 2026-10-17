-- Choices (task 8): the skills and other picks a hero's class, level and feats grant, and whether a rest has let
-- them be made.
CREATE TABLE picks (
    user_id INT UNSIGNED NOT NULL,
    choice VARCHAR(20) NOT NULL,
    value VARCHAR(20) NOT NULL,
    PRIMARY KEY (user_id, choice, value),
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE users ADD may_choose BOOLEAN NOT NULL DEFAULT FALSE;
