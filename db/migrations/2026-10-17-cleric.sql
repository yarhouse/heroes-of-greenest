-- The Cleric (task 10): Spiritual Weapon's force and Protection from Energy's Resistance are new kinds of effect.
ALTER TABLE spells MODIFY COLUMN effect ENUM('condition', 'd20', 'ac', 'base_ac', 'max_hp', 'aura', 'damage',
    'inspiration', 'weapon', 'resist') NULL;
ALTER TABLE effects MODIFY COLUMN effect ENUM('condition', 'd20', 'ac', 'base_ac', 'max_hp', 'aura', 'damage',
    'inspiration', 'weapon', 'resist') NOT NULL;
