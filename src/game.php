<?php

declare(strict_types=1);

// game.php :: command parser and handlers. Handlers return lines of plain text; the client never renders HTML.

require_once __DIR__ . '/delve.php';

const DIRS = ['n' => 'north', 's' => 'south', 'e' => 'east', 'w' => 'west', 'u' => 'up', 'd' => 'down'];
const MAP_STEPS = ['north' => [0, -1], 'south' => [0, 1], 'east' => [1, 0], 'west' => [-1, 0]]; // up/down: marks only
// Zones: ranges of room ids, each its own map (cmd_map() stops at an exit into another zone), the battle board its
// rooms get in a fight, wide and deep in 5-ft squares (against a Speed 30 monster, about 1 free shot in town, 2 in the
// Woods, 0 to 1 underground), and the terrain every room there has, if not each its own. Roads get theirs, from 100,
// as they're built.
const ZONES = [
    [1, 99, 'Greenest', 8, 6, null],
    [6000, 6099, 'Wood of Sharp Teeth', 16, 10, null],
    [6100, 6199, 'Deep Wood', 16, 10, null],
    [7000, 7499, 'The Sewers', 6, 5, 'cave'], // Riftshadow's sectors there are mountains, forest...
    [24600, 24799, 'Kobold Mines', 6, 5, 'cave'],
    [50000, 50999, 'The Delve', 6, 5, 'cave'],
];
// What a generated battle board holds, by terrain, as percents of its squares: # rock (blocks moving and sight), % a
// tree, boulder or table (blocks moving; half cover), , undergrowth or rubble and ~ shallows (difficult terrain).
const TERRAIN = [
    'forest' => ['%' => 12, ',' => 10],
    'field' => ['%' => 4, ',' => 4],
    'road' => ['%' => 3],
    'city' => ['%' => 4],
    'hills' => ['%' => 6, ',' => 6],
    'mountains' => ['#' => 6, '%' => 6],
    'inside' => ['%' => 5],
    'cave' => ['#' => 8, ',' => 8],
    'water' => ['~' => 35, '%' => 3],
];
const STEPS = [[0, -1], [1, 0], [0, 1], [-1, 0], [1, -1], [1, 1], [-1, 1], [-1, -1]]; // a square's eight neighbours
const ONLINE_MINUTES = 10;
const TURN_SECS = 120; // how long a hero whose page is open has for a turn in a fight with others, before it passes
const ECHO_MINUTES = 10; // how long speech lingers in a room for those who come in after
const TOWN_LAST_ROOM = 99; // the town is seed.sql's rooms 1-99 (areas and the Delve are numbered higher): shouts carry
const START_ROOM = 1;
const LANTERN = 3; // the Swaying Lantern, where hirelings wait for work
/** Skilled hirelings (SRD 5.2.1 p.102: 2 GP a day), as `hire` word => their monsters row. */
const HIRELINGS = ['guard' => 32, 'mastiff' => 68, 'acolyte' => 36];
const HIRE_GP = 2;
const TEMPLE = 12; // the Temple of Chauntea, where priests cast for a donation
/**
 * What `pray` buys at the temple, as word => [spell id, GP a casting, what it does]: Spellcasting Services (SRD 5.2.1
 * p.102). 'heal' is Cure Wounds cast until full HP, charged for the castings an average roll needs.
 */
const PRAYERS = [
    'cure' => [3, 50, 'Cure Wounds: regain 2d8 + 3 HP'],
    'heal' => [3, 50, "Cure Wounds until you're whole, a casting each 12 HP missing"],
    'aid' => [11, 200, '+5 HP and max HP for 8 hours'],
];
const PRIEST_WIS = 3; // the SRD Priest's (p.316), the temple's healer
const SHORT_REST_SECS = 60;
const LONG_REST_SECS = 600;
const LOOT_CHANCE = 30; // a kill drops an item 1 time in this many, as in Dragon Knight
const LOOT_GP_PER_CR = 50; // and the item costs at most this x the monster's CR: Potions of Healing from CR 1
const ABILITIES = ['strength', 'dexterity', 'constitution', 'intelligence', 'wisdom', 'charisma'];
const STANDARD_ARRAY = [15, 14, 13, 12, 10, 8];
/** The Weapon Mastery properties the game models (SRD 5.2.1 p.89); items list each weapon's own. */
const MASTERIES = ['cleave', 'graze', 'nick', 'push', 'sap', 'slow', 'topple', 'vex'];
/**
 * Ends my fight, and what lasts only for one: Rage, Innate Sorcery, saved Advantage, conditions, my turn. For UPDATE
 * users. I keep my square: it's where I stand in the room.
 */
const END_FIGHT = "fighting = NULL, raging = FALSE, innate = FALSE, advantage = FALSE, conditions = '', "
    . 'bonus_used = FALSE, moved = NULL';
const BONUS_USED = "You've already used your Bonus Action this round.";
/** SQL: effect e (joined to its caster c) still lasts: not yet run out, or cast in a fight the caster is still in. */
const LIVE_EFFECT = '(e.until > NOW() OR (e.during IS NOT NULL AND c.fighting = e.during))';
const SIZES = ['tiny', 'small', 'medium', 'large', 'huge', 'gargantuan'];
/** Font of Magic (SRD 5.2.1 p.66): a spell slot's level => [its cost in Sorcery Points, the Sorcerer level needed]. */
const FONT_COSTS = [1 => [2, 2], 2 => [3, 3], 3 => [5, 5], 4 => [6, 7], 5 => [7, 9]];
/** Metamagic the game models, => its cost in Sorcery Points. */
const METAMAGIC = ['heightened' => 2, 'seeking' => 1];

/**
 * The classes a hero can choose, from SRD 5.2.1 (Barbarian p.28, Bard p.30, Cleric p.36, Druid p.41, Fighter p.47, Monk
 * p.49, Paladin p.52, Ranger p.56, Rogue p.61, Sorcerer p.64, Warlock p.70, Wizard p.77): Hit Die, level 1 HP and
 * fixed HP per level (both before the Con modifier), the Standard Array by Class (p.21) in Str Dex Con Int Wis Cha
 * order, saving throw proficiencies, Weapon Mastery, a caster's spellcasting ability and slot table (full, half or
 * pact), prepared spells by level, the ones a new hero starts with and the ones always prepared (name => from level),
 * armor training, and starting equipment A as [item id, worn]. Kits leave out ammunition, packs, tools and focuses.
 * Weapon training is trained()'s. The choices the game makes for you: the Fighter's and Paladin's Fighting Style is
 * Defense, the Ranger's Archery, the Cleric's Divine Order is Protector, the Druid's Primal Order is Warden, a
 * Warlock's Eldritch Invocations are Armor of Shadows from level 1 and Agonizing Blast and Eldritch Mind from 2, and
 * the subclasses are the SRD's: Lore, Land (Arid) with Primal Strike, Open Hand, Devotion, Hunter with Colossus Slayer
 * and Multiattack Defense, Draconic Sorcery with Fire for its Elemental Affinity, Fiend, Evoker. shortcut: casters
 * know every cantrip of their class, and a Wizard's spellbook holds every Wizard spell, until there are more to choose
 * from.
 */
const CLASSES = [
    'fighter' => [
        'name' => 'Fighter', 'die' => 10, 'hp' => 10, 'per_level' => 6, 'scores' => [15, 14, 13, 8, 10, 12],
        'saves' => ['strength', 'constitution'], 'mastery' => true,
        'armor' => ['light', 'medium', 'heavy', 'shield'], 'gold' => 4,
        'kit' => [ // Chain Mail, Greatsword, Flail, 8 Javelins
            [40, true], [15, true], [12, false], [5, false], [5, false], [5, false], [5, false], [5, false], [5, false],
            [5, false], [5, false],
        ],
        'about' => 'd10 HP, any armor. Second Wind heals you; more attacks as you level.',
    ],
    'barbarian' => [
        'name' => 'Barbarian', 'die' => 12, 'hp' => 12, 'per_level' => 7, 'scores' => [15, 13, 14, 10, 12, 8],
        'saves' => ['strength', 'constitution'], 'mastery' => true,
        'armor' => ['light', 'medium', 'shield'], 'gold' => 15,
        'kit' => [[14, true], [4, false], [4, false], [4, false], [4, false]], // Greataxe, 4 Handaxes
        'about' => 'd12 HP, tough even unarmored. Rage to hit harder and shrug off weapon blows.',
    ],
    'rogue' => [
        'name' => 'Rogue', 'die' => 8, 'hp' => 8, 'per_level' => 5, 'scores' => [12, 15, 13, 14, 10, 8],
        'saves' => ['dexterity', 'intelligence'], 'mastery' => true,
        'armor' => ['light'], 'gold' => 8,
        'kit' => [[32, true], [24, true], [2, false], [2, false], [72, false]], // Leather Armor, Shortsword, 2 Daggers,
        // Shortbow
        'about' => 'd8 HP, light armor. Sneak Attack with a finesse or ranged weapon when you have Advantage or help.',
    ],
    'cleric' => [
        'name' => 'Cleric', 'die' => 8, 'hp' => 8, 'per_level' => 5, 'scores' => [14, 8, 13, 10, 15, 12],
        'saves' => ['wisdom', 'charisma'], 'casting' => 'wisdom', 'slots' => 'slots',
        'prepared' => [4, 5, 6, 7, 9, 10, 11, 12, 14, 15, 16, 16, 17, 17, 18, 18, 19, 20, 21, 22], // by level
        'spells' => ['Bless', 'Cure Wounds', 'Guiding Bolt', 'Shield of Faith'], // the SRD's suggested first four
        'armor' => ['light', 'medium', 'heavy', 'shield'], 'gold' => 7,
        'kit' => [[35, true], [50, true], [7, true]], // Chain Shirt, Shield, Mace
        'about' => 'd8 HP, armor and shield. Spells that burn foes and heal you.',
    ],
    'monk' => [
        'name' => 'Monk', 'die' => 8, 'hp' => 8, 'per_level' => 5, 'scores' => [12, 15, 13, 10, 14, 8],
        'saves' => ['strength', 'dexterity'],
        'armor' => [], 'gold' => 11,
        'kit' => [[10, true], [2, false], [2, false], [2, false], [2, false], [2, false]], // Spear, 5 Daggers
        'about' => 'd8 HP, no armor. Fast fists and Focus: Flurry of Blows, Patient Defense, Stunning Strike.',
    ],
    'paladin' => [
        'name' => 'Paladin', 'die' => 10, 'hp' => 10, 'per_level' => 6, 'scores' => [15, 10, 13, 8, 12, 14],
        'saves' => ['wisdom', 'charisma'], 'mastery' => true, 'casting' => 'charisma', 'slots' => 'half_slots',
        'prepared' => [2, 3, 4, 5, 6, 6, 7, 7, 9, 9, 10, 10, 11, 11, 12, 12, 14, 14, 15, 15],
        'spells' => ['Divine Favor', 'Cure Wounds'], // the SRD suggests Heroism and Searing Smite, not modeled
        'always' => ['Divine Smite' => 2, 'Shield of Faith' => 3, 'Aid' => 5], // Paladin's Smite, Oath of Devotion
        'armor' => ['light', 'medium', 'heavy', 'shield'], 'gold' => 9,
        'kit' => [ // Chain Mail, Shield, Longsword, 6 Javelins
            [40, true], [50, true], [18, true], [5, false], [5, false], [5, false], [5, false], [5, false], [5, false],
        ],
        'about' => 'd10 HP, any armor. Lay On Hands, Divine Smite and holy spells.',
    ],
    'ranger' => [
        'name' => 'Ranger', 'die' => 10, 'hp' => 10, 'per_level' => 6, 'scores' => [12, 15, 13, 8, 14, 10],
        'saves' => ['strength', 'dexterity'], 'mastery' => true, 'casting' => 'wisdom', 'slots' => 'half_slots',
        'prepared' => [2, 3, 4, 5, 6, 6, 7, 7, 9, 9, 10, 10, 11, 11, 12, 12, 14, 14, 15, 15],
        'spells' => ['Cure Wounds', 'Ensnaring Strike'], // the SRD's suggested two
        'always' => ["Hunter's Mark" => 1], // Favored Enemy
        'armor' => ['light', 'medium', 'shield'], 'gold' => 7,
        'kit' => [[33, true], [23, true], [24, false], [77, false]], // Studded Leather, Scimitar, Shortsword, Longbow
        'about' => "d10 HP, light or medium armor. Bow or blades, Hunter's Mark and nature's spells.",
    ],
    'wizard' => [
        'name' => 'Wizard', 'die' => 6, 'hp' => 6, 'per_level' => 4, 'scores' => [8, 12, 13, 15, 14, 10],
        'saves' => ['intelligence', 'wisdom'], 'casting' => 'intelligence', 'slots' => 'slots',
        'prepared' => [4, 5, 6, 7, 9, 10, 11, 12, 14, 15, 16, 16, 17, 18, 19, 21, 22, 23, 24, 25],
        'spells' => ['Mage Armor', 'Magic Missile', 'Burning Hands', 'Thunderwave'],
        'armor' => [], 'gold' => 5,
        'kit' => [[8, true], [2, false], [2, false]], // Quarterstaff, 2 Daggers
        'about' => 'd6 HP, no armor. Fire Bolt, Magic Missile, Fireball: the most spells of all.',
    ],
    'bard' => [
        'name' => 'Bard', 'die' => 8, 'hp' => 8, 'per_level' => 5, 'scores' => [8, 14, 12, 13, 10, 15],
        'saves' => ['dexterity', 'charisma'], 'casting' => 'charisma', 'slots' => 'slots',
        'prepared' => [4, 5, 6, 7, 9, 10, 11, 12, 14, 15, 16, 16, 17, 17, 18, 18, 19, 20, 21, 22],
        'spells' => ['Bane', 'Dissonant Whispers', 'Faerie Fire', 'Healing Word'], // the SRD suggests Charm Person and
        // Color Spray, not modeled
        'armor' => ['light'], 'gold' => 19,
        'kit' => [[32, true], [2, true], [2, false]], // Leather Armor, 2 Daggers
        'about' => 'd8 HP, light armor. Inspire other heroes, cut foes down with words, heal and hex.',
    ],
    'druid' => [
        'name' => 'Druid', 'die' => 8, 'hp' => 8, 'per_level' => 5, 'scores' => [8, 12, 14, 13, 15, 10],
        'saves' => ['intelligence', 'wisdom'], 'casting' => 'wisdom', 'slots' => 'slots',
        'prepared' => [4, 5, 6, 7, 9, 10, 11, 12, 14, 15, 16, 16, 17, 17, 18, 18, 19, 20, 21, 22],
        'spells' => ['Cure Wounds', 'Entangle', 'Faerie Fire', 'Thunderwave'], // the SRD suggests Animal Friendship
        'always' => ['Burning Hands' => 3, 'Fire Bolt' => 3, 'Fireball' => 5], // Circle of the Land Spells (Arid)
        'armor' => ['light', 'medium', 'shield'], 'gold' => 9,
        'kit' => [[32, true], [50, true], [9, true], [8, false]], // Leather Armor, Shield, Sickle, Quarterstaff
        'about' => 'd8 HP, light armor and shield. Nature spells, and Wild Shape into beasts.',
    ],
    'warlock' => [
        'name' => 'Warlock', 'die' => 8, 'hp' => 8, 'per_level' => 5, 'scores' => [8, 14, 13, 12, 10, 15],
        'saves' => ['wisdom', 'charisma'], 'casting' => 'charisma', 'slots' => 'pact_slots',
        'prepared' => [2, 3, 4, 5, 6, 7, 8, 9, 10, 10, 11, 11, 12, 12, 13, 13, 14, 14, 15, 15],
        'spells' => ['Bane', 'Hex'], // the SRD suggests Charm Person, not modeled
        'always' => [ // Armor of Shadows, then Fiend Spells (Suggestion and Stinking Cloud aren't modeled)
            'Mage Armor' => 1, 'Burning Hands' => 3, 'Command' => 3, 'Scorching Ray' => 3, 'Fireball' => 5,
        ],
        'armor' => ['light'], 'gold' => 15,
        'kit' => [[32, true], [9, true], [2, false], [2, false]], // Leather Armor, Sickle, 2 Daggers
        'about' => 'd8 HP, light armor. Eldritch Blast and Hex; few spell slots, back on every short rest.',
    ],
    'sorcerer' => [
        'name' => 'Sorcerer', 'die' => 6, 'hp' => 6, 'per_level' => 4, 'scores' => [10, 13, 14, 8, 12, 15],
        'saves' => ['constitution', 'charisma'], 'casting' => 'charisma', 'slots' => 'slots',
        'prepared' => [2, 4, 6, 7, 9, 10, 11, 12, 14, 15, 16, 16, 17, 17, 18, 18, 19, 20, 21, 22],
        'spells' => ['Burning Hands', 'Magic Missile'], // the SRD suggests Detect Magic, not modeled
        'always' => ['Command' => 3], // Draconic Spells (shortcut: the others aren't modeled)
        'armor' => [], 'gold' => 28,
        'kit' => [[10, true], [2, false], [2, false]], // Spear, 2 Daggers
        'about' => 'd6 HP, no armor. Innate magic: arcane spells, bent with Sorcery Points and Metamagic.',
    ],
];

/**
 * The species a hero can be (SRD 5.2.1 p.84-86), all Humanoid: size, Speed, Darkvision, damage Resistance, and the
 * choice some make ('kinds', stored in users.lineage: Draconic Ancestry, Elven or Gnomish Lineage, Giant Ancestry,
 * Fiendish Legacy, or a Human's Versatile Origin feat). A kind adds to or replaces its species' values, and its
 * 'spells' (name => character level) are known from that level, cast with origin_ability(), a leveled one free once a
 * long rest (or with slots). What a fight uses is ruled in code: Breath Weapon (breathe), Dwarven Toughness, Gnomish
 * Cunning, Giant Ancestry, Halfling Luck, Heroic Inspiration (Resourceful), Relentless Endurance, a Small hero's
 * shoves and grapples. 'traits' are only shown. The game's choices: Humans and Tieflings are Medium, and the lineage
 * spells and cantrips it doesn't have (Dancing Lights, Misty Step...) are traits.
 */
const SPECIES = [
    'dragonborn' => [
        'name' => 'Dragonborn', 'size' => 'medium', 'speed' => 30, 'darkvision' => 60,
        'choose' => 'your dragon ancestor',
        'kinds' => [
            'black' => ['resist' => 'acid'], 'blue' => ['resist' => 'lightning'], 'brass' => ['resist' => 'fire'],
            'bronze' => ['resist' => 'lightning'], 'copper' => ['resist' => 'acid'], 'gold' => ['resist' => 'fire'],
            'green' => ['resist' => 'poison'], 'red' => ['resist' => 'fire'], 'silver' => ['resist' => 'cold'],
            'white' => ['resist' => 'cold'],
        ],
        'traits' => ['Draconic Flight (5)'],
        'about' => "Breath Weapon, and Resistance to your dragon ancestor's damage.",
    ],
    'dwarf' => [
        'name' => 'Dwarf', 'size' => 'medium', 'speed' => 30, 'darkvision' => 120, 'resist' => 'poison',
        'traits' => ['Dwarven Resilience', 'Stonecunning'],
        'about' => 'Poison Resistance, and +1 HP every level.',
    ],
    'elf' => [
        'name' => 'Elf', 'size' => 'medium', 'speed' => 30, 'darkvision' => 60,
        'choose' => 'your elven lineage',
        'kinds' => [
            'drow' => ['darkvision' => 120, 'spells' => ['Faerie Fire' => 3], 'traits' => ['Dancing Lights',
                'Darkness (5)'], 'about' => 'Faerie Fire from level 3'],
            'high' => ['traits' => ['Prestidigitation', 'Detect Magic (3)', 'Misty Step (5)'],
                'about' => 'arcane tricks'],
            'wood' => ['speed' => 35, 'traits' => ['Druidcraft', 'Longstrider (3)', 'Pass without Trace (5)'],
                'about' => 'Speed 35'],
        ],
        'traits' => ['Fey Ancestry', 'Keen Senses', 'Trance'],
        'about' => 'Darkvision and fey magic of your lineage.',
    ],
    'gnome' => [
        'name' => 'Gnome', 'size' => 'small', 'speed' => 30, 'darkvision' => 60,
        'choose' => 'your gnomish lineage',
        'kinds' => [
            'forest' => ['traits' => ['Minor Illusion', 'Speak with Animals'], 'about' => 'Minor Illusion'],
            'rock' => ['traits' => ['Mending', 'Prestidigitation', 'clockwork devices'], 'about' => 'tinkering'],
        ],
        'traits' => [],
        'about' => 'Small. Advantage on Int, Wis and Cha saves.',
    ],
    'goliath' => [
        'name' => 'Goliath', 'size' => 'medium', 'speed' => 35, 'darkvision' => 0,
        'choose' => 'your giant ancestor',
        'kinds' => [ // used at the first chance, Proficiency Bonus times a long rest
            'cloud' => ['about' => "Cloud's Jaunt: flee without fail"],
            'fire' => ['about' => "Fire's Burn: +1d10 Fire on a hit"],
            'frost' => ['about' => "Frost's Chill: +1d6 Cold on a hit"],
            'hill' => ['about' => "Hill's Tumble: a weapon hit knocks it Prone"],
            'stone' => ['about' => "Stone's Endurance: take 1d12 + Con less from a hit"],
            'storm' => ['about' => "Storm's Thunder: 1d8 Thunder back when hit"],
        ],
        'traits' => ['Large Form (5)', 'Powerful Build'],
        'about' => 'Speed 35, and a boon from your giant ancestor.',
    ],
    'halfling' => [
        'name' => 'Halfling', 'size' => 'small', 'speed' => 30, 'darkvision' => 0,
        'traits' => ['Brave', 'Halfling Nimbleness', 'Naturally Stealthy'],
        'about' => 'Small. Luck: reroll a 1 on any d20.',
    ],
    'human' => [
        'name' => 'Human', 'size' => 'medium', 'speed' => 30, 'darkvision' => 0,
        'choose' => 'an Origin feat (Versatile)',
        'kinds' => [ // Versatile: an Origin feat, besides your background's
            'alert' => ['feat' => 'alert'], 'savage' => ['feat' => 'savage'], 'skilled' => ['feat' => 'skilled'],
            'cleric' => ['feat' => 'cleric'], 'wizard' => ['feat' => 'wizard'],
        ],
        'traits' => ['Skillful'],
        'about' => 'A second Origin feat, and Heroic Inspiration after each long rest.',
    ],
    'orc' => [
        'name' => 'Orc', 'size' => 'medium', 'speed' => 30, 'darkvision' => 120,
        'traits' => ['Adrenaline Rush'],
        'about' => 'Relentless Endurance: once a long rest, drop to 1 HP instead of 0.',
    ],
    'tiefling' => [
        'name' => 'Tiefling', 'size' => 'medium', 'speed' => 30, 'darkvision' => 60,
        'choose' => 'your fiendish legacy',
        'kinds' => [
            'abyssal' => ['resist' => 'poison', 'spells' => ['Poison Spray' => 1, 'Hold Person' => 5],
                'traits' => ['Ray of Sickness (3)']],
            'chthonic' => ['resist' => 'necrotic', 'spells' => ['Chill Touch' => 1],
                'traits' => ['False Life (3)', 'Ray of Enfeeblement (5)']],
            'infernal' => ['resist' => 'fire', 'spells' => ['Fire Bolt' => 1],
                'traits' => ['Hellish Rebuke (3)', 'Darkness (5)']],
        ],
        'traits' => ['Thaumaturgy'],
        'about' => 'Resistance and spells from your fiendish legacy.',
    ],
];

/**
 * Origin feats (SRD 5.2.1 p.87). Alert adds PB to Initiative (its swap is left out: no allies share initiative).
 * Magic Initiate's spells are the game's choice, of the ones it has: its cantrips and level 1 spell (cast free once a
 * long rest, or with slots) use origin_ability(). Skilled is only shown.
 */
const FEATS = [
    'alert' => ['name' => 'Alert', 'about' => 'add your Proficiency Bonus to Initiative'],
    'savage' => ['name' => 'Savage Attacker',
        'about' => "once a turn, roll a weapon hit's damage twice, keep the best"],
    'skilled' => ['name' => 'Skilled', 'about' => 'three more skills (not chosen yet, so not used)'],
    'cleric' => ['name' => 'Magic Initiate (Cleric)', 'spells' => ['Sacred Flame', 'Cure Wounds'],
        'about' => 'Sacred Flame, and Cure Wounds free once a long rest'],
    'wizard' => ['name' => 'Magic Initiate (Wizard)', 'spells' => ['Fire Bolt', 'Ray of Frost', 'Magic Missile'],
        'about' => 'Fire Bolt and Ray of Frost, and Magic Missile free once a long rest'],
];

/**
 * Backgrounds (SRD 5.2.1 p.83): the three abilities it can raise (+2 and +1, or +1 each, none past 20), its Origin
 * feat and its skills (used in skill checks, see SKILLS). Its tool and equipment are left out: heroes start with their class's kit.
 */
const BACKGROUNDS = [
    'acolyte' => ['name' => 'Acolyte', 'abilities' => ['intelligence', 'wisdom', 'charisma'], 'feat' => 'cleric',
        'skills' => 'Insight, Religion'],
    'criminal' => ['name' => 'Criminal', 'abilities' => ['dexterity', 'constitution', 'intelligence'],
        'feat' => 'alert', 'skills' => 'Sleight of Hand, Stealth'],
    'sage' => ['name' => 'Sage', 'abilities' => ['constitution', 'intelligence', 'wisdom'], 'feat' => 'wizard',
        'skills' => 'Arcana, History'],
    'soldier' => ['name' => 'Soldier', 'abilities' => ['strength', 'dexterity', 'constitution'], 'feat' => 'savage',
        'skills' => 'Athletics, Intimidation'],
];

/** The skills (SRD 5.2.1 p.9), => the ability each check uses. A background's skills add my Proficiency Bonus. */
const SKILLS = [
    'acrobatics' => 'dexterity', 'animal handling' => 'wisdom', 'arcana' => 'intelligence', 'athletics' => 'strength',
    'deception' => 'charisma', 'history' => 'intelligence', 'insight' => 'wisdom', 'intimidation' => 'charisma',
    'investigation' => 'intelligence', 'medicine' => 'wisdom', 'nature' => 'intelligence', 'perception' => 'wisdom',
    'performance' => 'charisma', 'persuasion' => 'charisma', 'religion' => 'intelligence',
    'sleight of hand' => 'dexterity', 'stealth' => 'dexterity', 'survival' => 'wisdom',
];

/** Every command, as usage => what it does. Drives help and the play page's autocomplete. */
const COMMANDS = [
    'look' => 'describe where you are (or l)',
    'inspect <thing>' => 'look closer at something or someone here (or look <thing>); some reward a skill check',
    'ask <npc> about <topic>' => "talk to someone here; 'ask <npc>' (or speak) says what they'll talk about",
    'north' => 'move north (or n)',
    'south' => 'move south (or s)',
    'east' => 'move east (or e)',
    'west' => 'move west (or w)',
    'up' => 'move up (or u)',
    'down' => 'move down (or d)',
    'sneak' => 'move quietly, or stop: a Stealth check as you enter a room with monsters can keep them from seeing you',
    'kill <monster>' => 'attack, closing in first if you need to; one round per command (or k)',
    'shoot <monster>' => 'throw or shoot your weapon from where you stand (kill does this with a bow)',
    'move <square>' => 'in a fight, move to a square of the board, like c3 (or tap it); the rest of your turn waits',
    'kite <monster> [square]' => 'attack, then back off as far as you can, or to the square (next to it with a bow, '
        . 'move first, then shoot)',
    'dash [square]' => 'in a fight, run twice as far: away from your foe, or to a square; no attack',
    'disengage [square]' => 'in a fight, move away (or to a square) without drawing opportunity attacks; no attack',
    'dodge' => 'in a fight, attacks on you have Disadvantage until your next turn; no attack',
    'reckless <monster>' => 'Barbarian 2: attack with Advantage, but attacks on you get it too',
    'shove <monster>' => 'knock a foe Prone, in place of one attack: attacks on it get Advantage',
    'grapple <monster>' => 'hold a foe, in place of one attack: it has Disadvantage against other heroes',
    'breathe <monster>' => 'Dragonborn: Breath Weapon in place of one attack, a Dex save or 1d10 (more by level)',
    'flee [direction]' => 'run for an exit (the nearest, or the one you name) and out of the fight; leaving its reach '
        . 'draws an attack',
    'flurry <monster>' => 'Monk 2: attack, then two Unarmed Strikes as your Bonus Action (1 Focus)',
    'patient <monster>' => 'Monk 2: attack, and Dodge as your Bonus Action: attacks on you have Disadvantage (1 Focus)',
    'stun <monster>' => 'Monk 5: attack; your first hit tries a Stunning Strike (1 Focus)',
    'second' => 'Fighter: Second Wind, regain 1d10 + your level in HP',
    'rage' => 'Barbarian: Rage for the rest of a fight; costs no turn',
    'inspire <hero>' => 'Bard: give a hero here a Bardic Inspiration die for a failed attack or save; costs no turn',
    'wild [beast]' => 'Druid 2: Wild Shape into a beast (wild alone lists them, or leaves the form); costs no turn',
    'land <monster>' => "Druid 3: Land's Aid, a Con save or 2d6 Necrotic, and you regain 2d6 HP (1 Wild Shape)",
    'cunning' => 'Warlock 2: Magical Cunning, half your Pact Magic slots back, once a long rest (not in a fight)',
    'lay [hero]' => 'Paladin: Lay On Hands, heal yourself or a hero here from your pool; costs no turn',
    'wholeness' => 'Monk 6: Wholeness of Body, regain a Martial Arts die + Wis in HP; costs no turn',
    'innate' => 'Sorcerer: Innate Sorcery for the fight, +1 spell save DC and Advantage on spell attacks; no turn',
    'font <level>' => "Sorcerer 2: Sorcery Points into a spell slot; 'font points <level>' turns a slot into points",
    'cast <spell>' => "cast a spell, at a monster or a hero if it needs one (cast alone lists them); a Sorcerer may "
        . "put 'heightened' or 'seeking' first",
    'prepare <spells>' => 'after a long rest, choose your spells, with commas (prepare alone lists them)',
    'rest' => 'short rest in a safe place (1 min): spend Hit Dice to heal',
    'sleep' => 'long rest in a safe place (10 min): recover everything',
    'score' => 'your abilities, HP, AC and XP',
    'map' => 'rooms near you (also under score on wide screens)',
    'inventory' => 'what you carry and wear (or i)',
    'get <item>' => 'pick something up',
    'drop <item>' => 'put something down',
    'equip <item>' => "wield or wear something you carry; 'equip <item> offhand' for a second Light weapon",
    'drink <potion>' => 'drink a potion; like Second Wind, it costs no turn',
    'hire <guard|mastiff|acolyte>' => 'at the Swaying Lantern, 2 gold a day: it fights beside you (hire again for '
        . 'more days)',
    'dismiss' => 'send your hireling away',
    'order <stance|aid>' => 'your hireling: attack (close in and fight), guard (stay by you) or hold (stay put); an '
        . 'acolyte heals, blesses or wards you (heal, bless, sanctuary) once a long rest',
    'list' => 'in a shop or the temple: what you can buy',
    'buy <item>' => 'in a shop off Market Lane: buy something',
    'sell <item>' => 'in a shop: sell something for half its price',
    'pray <cure|heal|aid>' => "at Chauntea's temple, east of the Mill Bridge: a priest's spell, for a donation",
    'say <text>' => "speak to the room ('<text> works too)",
    'shout <text>' => 'in town, everyone in town hears it; elsewhere only the room',
    'who' => 'every hero, and when last seen',
    'help' => 'this list',
    'quit' => 'log out',
];

/**
 * The class (or species), and level, a feature's command needs. Its handler refuses anyone else; this only hides it
 * from them.
 */
const FEATURES = [
    'breathe' => ['dragonborn', 1],
    'reckless' => ['barbarian', 2], 'rage' => ['barbarian', 1], 'second' => ['fighter', 1],
    'flurry' => ['monk', 2], 'patient' => ['monk', 2], 'stun' => ['monk', 5], 'wholeness' => ['monk', 6],
    'inspire' => ['bard', 1], 'wild' => ['druid', 2], 'land' => ['druid', 3], 'cunning' => ['warlock', 2],
    'lay' => ['paladin', 1], 'innate' => ['sorcerer', 1], 'font' => ['sorcerer', 2],
];

/** COMMANDS without other classes' features, or spells for a class that has none: what help and Tab offer. */
function my_commands(array $me): array
{
    return array_filter(COMMANDS, function (string $usage) use ($me): bool {
        $verb = explode(' ', $usage)[0];
        [$class, $level] = FEATURES[$verb] ?? [$me['class'], 1];
        return in_array($class, [$me['class'], $me['species']], true) && $me['level'] >= $level
            && ($verb !== 'cast' || isset(CLASSES[$me['class']]['casting']) || origin_spells($me))
            && ($verb !== 'prepare' || isset(CLASSES[$me['class']]['prepared']));
    }, ARRAY_FILTER_USE_KEY);
}

/** Faces for the next dice rolled, used in order before any real rolls. Only tests fill it. */
$dice_queue = [];

/** Rolls dice like '1d20', '2d6+3' or '2d6+1d6-1'; a critical hit rolls every die twice. All game randomness. */
function roll(string $dice, bool $crit = false): int
{
    global $dice_queue;
    if (!preg_match('/^\d+(d\d+)?([+-]\d+(d\d+)?)*$/', $dice)) {
        throw new LogicException("Bad dice: $dice");
    }
    preg_match_all('/([+-]?)(\d+)(?:d(\d+))?/', $dice, $terms, PREG_SET_ORDER);
    $total = 0;
    foreach ($terms as $t) {
        $value = (int) $t[2];
        if (isset($t[3])) {
            $sides = (int) $t[3];
            $value = 0;
            for ($i = (int) $t[2] * ($crit ? 2 : 1); $i > 0; $i--) {
                $face = array_shift($dice_queue) ?? random_int(1, $sides);
                if ($face < 1 || $face > $sides) {
                    throw new LogicException("Queued a $face for a d$sides");
                }
                $value += $face;
            }
        }
        $total += $t[1] === '-' ? -$value : $value;
    }
    return $total;
}

/** An ability score's modifier: 8 is -1, 10 is +0, 15 is +2. */
function mod(int $score): int
{
    return (int) floor(($score - 10) / 2);
}

/** My species with my kind of it merged in (see SPECIES), traits from both; [] before I've chosen one. */
function origin(array $me): array
{
    if (($me['species'] ?? null) === null) {
        return [];
    }
    $s = SPECIES[$me['species']];
    $k = $s['kinds'][$me['lineage']] ?? [];
    return ['traits' => [...$s['traits'], ...($k['traits'] ?? [])]] + $k + $s;
}

/** True if my background or a Human's Versatile gave me Origin feat $feat (a FEATS key). */
function has_feat(array $me, string $feat): bool
{
    return (BACKGROUNDS[$me['background'] ?? '']['feat'] ?? null) === $feat || (origin($me)['feat'] ?? null) === $feat;
}

/** The ids of the spells my lineage (from its level) and Magic Initiate give me, whatever my class. */
function origin_spells(array $me): array
{
    $names = array_keys(array_filter(origin($me)['spells'] ?? [], fn ($lv) => $me['level'] >= $lv));
    foreach (['cleric', 'wizard'] as $feat) {
        $names = [...$names, ...(has_feat($me, $feat) ? FEATS[$feat]['spells'] : [])];
    }
    return spell_ids($names);
}

/** The ability my origin spells use: my class's if it's Int, Wis or Cha, else the best of those (the game's choice). */
function origin_ability(array $me): string
{
    $class = CLASSES[$me['class']]['casting'] ?? null;
    if ($class !== null) {
        return $class;
    }
    $mental = ['intelligence' => $me['intelligence'], 'wisdom' => $me['wisdom'], 'charisma' => $me['charisma']];
    return array_search(max($mental), $mental, true);
}

/**
 * Spends one use of my species' trait (Breath Weapon, Giant Ancestry, Heroic Inspiration, Relentless Endurance), or
 * returns false if none are left. The UPDATE checks too, as $me may be a copy behind an earlier spend this command.
 */
function spend_species(array &$me): bool
{
    if ($me['species_spent'] >= $me['species_uses'] || q(
        'UPDATE users SET species_spent = species_spent + 1 WHERE id = ? AND species_spent < ?',
        [$me['id'], $me['species_uses']]
    )->rowCount() !== 1) {
        return false;
    }
    $me['species_spent']++;
    return true;
}

/**
 * Adds the stats derived from class, level and gear to a `users` row. 'uses' is Second Wind, Rage, Focus Points, the
 * Lay On Hands pool, free Hunter's Marks, Sorcery Points or Bardic Inspiration, and 'slots' a caster's spell slots by
 * spell level, with a Wild Shape's form in place of my gear. A Draconic Sorcerer has Draconic Resilience (level 3: +1
 * HP a level) and Elemental Affinity (6: Fire Resistance), and a Dwarf Dwarven Toughness (+1 HP a level).
 * 'species_uses' is how many times my species' trait works a long rest.
 */
function with_stats(array $me): array
{
    $lv = q('SELECT * FROM levels WHERE level = ?', [$me['level']])->fetch();
    $class = $me['class'];
    $table = CLASSES[$class]['slots'] ?? null; // a caster's column of spell slots in `levels`
    $me += [
        'prof_bonus' => $lv['prof_bonus'],
        'attacks' => match ($class) {
            'fighter' => $lv['attacks'],
            'barbarian', 'monk', 'paladin', 'ranger' => $me['level'] >= 5 ? 2 : 1, // Extra Attack
            default => 1,
        },
        'uses' => match ($class) {
            'fighter' => $lv['second_wind'],
            'barbarian' => $lv['rages'],
            'monk' => $me['level'] >= 2 ? $me['level'] : 0, // Focus Points
            'paladin' => 5 * $me['level'], // Lay On Hands
            'ranger' => 2 + intdiv($me['level'] - 1, 4), // Favored Enemy: free Hunter's Marks
            'sorcerer' => $me['level'] >= 2 ? $me['level'] : 0, // Sorcery Points
            'bard' => max(1, mod($me['charisma'])), // Bardic Inspiration
            'druid' => $me['level'] >= 2 ? ($me['level'] >= 17 ? 4 : ($me['level'] >= 6 ? 3 : 2)) : 0, // Wild Shape
            'warlock' => $me['level'] >= 6 ? max(1, mod($me['charisma'])) : 0, // Dark One's Own Luck
            default => 0,
        },
        'bardic' => $me['level'] >= 15 ? 12 : ($me['level'] >= 10 ? 10 : ($me['level'] >= 5 ? 8 : 6)), // its die
        'rage_damage' => $lv['rage_damage'],
        'martial_arts' => $lv['martial_arts'],
        'slots' => $table === null ? [] : array_map('intval', explode(',', $lv[$table])),
        'resistances' => implode(',', array_filter([
            $me['raging'] ? 'bludgeoning,piercing,slashing' : '',
            // Elemental Affinity, Nature's Ward (Arid)
            ($class === 'sorcerer' && $me['level'] >= 6) || ($class === 'druid' && $me['level'] >= 10) ? 'fire' : '',
            origin($me)['resist'] ?? '',
        ])),
        'species_uses' => match ($me['species']) {
            'dragonborn', 'goliath' => $lv['prof_bonus'],
            'human', 'orc' => 1,
            default => 0,
        },
        'immunities' => '',
        'vulnerabilities' => '',
        'speed' => origin($me)['speed'] ?? 30,
    ];
    $aura = $class === 'paladin' && $me['level'] >= 6 ? max(1, mod($me['charisma'])) : 0; // Aura of Protection
    foreach (ABILITIES as $a) { // str_save to cha_save, as on a monster, so save() takes either
        $proficient = in_array($a, CLASSES[$class]['saves'], true) || ($class === 'monk' && $me['level'] >= 14);
        $me[substr($a, 0, 3) . '_save'] = mod($me[$a]) + ($proficient ? $lv['prof_bonus'] : 0) + $aura;
    }
    $me += gear($me) + ['effects' => effects_on('user_id', (int) $me['id'])];
    // Unarmored Movement (Monk 2: +10 ft, 5 more every 4 levels) and Fast Movement (Barbarian 5, not in Heavy armor)
    $me['speed'] += match (true) {
        $class === 'monk' && $me['level'] >= 2 && !$me['armored'] && $me['shield_ac'] === 0
            => 10 + 5 * intdiv($me['level'] - 2, 4),
        $class === 'barbarian' && $me['level'] >= 5 && !$me['heavy'] => 10,
        default => 0,
    };
    if ($me['shape'] !== null) {
        $me = beast_form($me);
    }
    $me['attacks'] = $me['loading'] ? 1 : $me['attacks']; // Loading: one shot a turn, Extra Attack or not
    $me['max_hp'] += $class === 'sorcerer' && $me['level'] >= 3 ? $me['level'] : 0; // Draconic Resilience
    $me['max_hp'] += $me['species'] === 'dwarf' ? $me['level'] : 0; // Dwarven Toughness
    foreach ($me['effects'] as $e) { // Mage Armor first: a base AC, if it beats mine
        if ($e['effect'] === 'base_ac' && !$me['armored']) {
            $me['ac'] = max($me['ac'], (int) $e['value'] + mod($me['dexterity']) + $me['shield_ac']);
        }
    }
    foreach ($me['effects'] as $e) {
        $me['ac'] += $e['effect'] === 'ac' ? (int) $e['value'] : 0; // Shield of Faith
        $me['max_hp'] += $e['effect'] === 'max_hp' ? (int) $e['value'] : 0; // Aid
    }
    $me['hp'] = min($me['hp'], $me['max_hp']); // an Aid that ran out
    $me['hireling'] = hireling($me);
    return $me;
}

/**
 * My hireling while its paid time lasts: its monsters row with its 'hp' now, 'max_hp' (the stat block's average, so
 * it isn't rolled), 'mins' left, its square in a fight ('x', 'y'), its 'stance' (see cmd_order()) and whether its
 * Divine Aid is spent ('aid'); null if I have none.
 */
function hireling(array $me): ?array
{
    $h = q(
        // The hireling's columns come last, so its hp wins over the template's (the HP dice).
        'SELECT m.*, m.hp AS dice, u.hireling_hp AS hp, TIMESTAMPDIFF(MINUTE, NOW(), u.hired_until) AS mins,
         u.hireling_x AS x, u.hireling_y AS y, u.hireling_order AS stance, u.hireling_aid AS aid
         FROM users u JOIN monsters m ON m.id = u.hireling_monster_id WHERE u.id = ? AND u.hired_until > NOW()',
        [$me['id']]
    )->fetch();
    if (!$h) {
        return null;
    }
    $h['stance'] ??= $h['id'] === HIRELINGS['acolyte'] ? 'guard' : 'attack'; // a caster stays back
    return ['max_hp' => average($h['dice'])] + $h;
}

/** The average of dice like '2d8+2' (11), rounded down, as a stat block gives it. */
function average(string $dice): int
{
    preg_match('/^(\d+)d(\d+)([+-]\d+)?$/', $dice, $d);
    return intdiv((int) $d[1] * ((int) $d[2] + 1), 2) + (int) ($d[3] ?? 0);
}

/** Runs one command for $me (a users row) and returns the output lines. */
function run_command(array $me, string $input): array
{
    q('UPDATE users SET last_seen = NOW() WHERE id = ?', [$me['id']]);
    $input = trim(mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $input) ?? '', 0, 300));
    if ($me['setup'] !== null) {
        return cmd_setup($me, $input);
    }
    $me = with_stats($me);
    $rested = $me['resting'] !== null ? finish_rest($me) : [];
    if ($me['hireling_monster_id'] !== null && $me['hireling'] === null) { // its paid time ran out
        $name = q('SELECT name FROM monsters WHERE id = ?', [$me['hireling_monster_id']])->fetchColumn();
        dismiss($me);
        $rested[] = "Your $name's paid time is up, and it heads back to the Swaying Lantern.";
    }
    if (str_starts_with($input, "'")) {
        $input = 'say ' . substr($input, 1);
    }
    [$verb, $arg] = array_pad(preg_split('/\s+/', $input, 2), 2, '');
    $verb = strtolower($verb);
    $verb = DIRS[$verb] ?? $verb;

    $heard = heard_since_last_command($me);
    $clock = [];
    if ($me['fighting'] !== null) { // the clock: turns of heroes who are away or out of time pass
        my_turn($me, $clock);
    }
    $was = $me['fighting'];
    $out = match (true) {
        $verb === '' => [],
        in_array($verb, DIRS, true) => cmd_move($me, $verb),
        $verb === 'sneak' => cmd_sneak($me),
        $verb === 'l' || $verb === 'look' || $verb === 'inspect' => trim($arg) === '' ? cmd_look($me)
            : cmd_inspect($me, trim($arg)),
        $verb === 'ask' || $verb === 'speak' || $verb === 'talk' => cmd_ask($me, trim($arg)),
        $verb === 'k' || $verb === 'kill' => cmd_kill($me, trim($arg)),
        $verb === 'shoot' => cmd_shoot($me, trim($arg)),
        $verb === 'move' => cmd_step($me, trim($arg)),
        $verb === 'kite' => cmd_kite($me, trim($arg)),
        in_array($verb, ['dash', 'disengage', 'dodge'], true) => cmd_action($me, $verb, trim($arg)),
        $verb === 'reckless' => cmd_reckless($me, trim($arg)),
        $verb === 'shove' || $verb === 'grapple' => cmd_maneuver($me, $verb, trim($arg)),
        $verb === 'breathe' => cmd_breathe($me, trim($arg)),
        in_array($verb, ['flurry', 'patient', 'stun'], true) => cmd_technique($me, $verb, trim($arg)),
        $verb === 'wholeness' => cmd_wholeness($me),
        $verb === 'innate' => cmd_innate($me),
        $verb === 'font' => cmd_font($me, trim($arg)),
        $verb === 'flee' => cmd_flee($me, trim($arg)),
        $verb === 'second' => cmd_second($me),
        $verb === 'rage' => cmd_rage($me),
        $verb === 'lay' => cmd_lay($me, trim($arg)),
        $verb === 'inspire' => cmd_inspire($me, trim($arg)),
        $verb === 'wild' => cmd_wild($me, trim($arg)),
        $verb === 'land' => cmd_land($me, trim($arg)),
        $verb === 'cunning' => cmd_cunning($me),
        $verb === 'cast' => cmd_cast($me, trim($arg)),
        $verb === 'prepare' => cmd_prepare($me, trim($arg)),
        $verb === 'rest' => cmd_rest($me, 'short'),
        $verb === 'sleep' => cmd_rest($me, 'long'),
        $verb === 'score' => cmd_score($me),
        $verb === 'map' => cmd_map($me),
        $verb === 'i' || $verb === 'inventory' => cmd_inventory($me),
        $verb === 'get' => cmd_get($me, trim($arg)),
        $verb === 'drop' => cmd_drop($me, trim($arg)),
        $verb === 'equip' => cmd_equip($me, trim($arg)),
        $verb === 'drink' => cmd_drink($me, trim($arg)),
        $verb === 'hire' => cmd_hire($me, trim($arg)),
        $verb === 'dismiss' => cmd_dismiss($me),
        $verb === 'order' => cmd_order($me, trim($arg)),
        $verb === 'list' => cmd_list($me),
        $verb === 'buy' => cmd_buy($me, trim($arg)),
        $verb === 'sell' => cmd_sell($me, trim($arg)),
        $verb === 'pray' => cmd_pray($me, trim($arg)),
        $verb === 'say' => cmd_say($me, trim($arg)),
        $verb === 'shout' => cmd_say($me, trim($arg), true),
        $verb === 'who' => cmd_who(),
        $verb === 'help' => cmd_help($me),
        default => ["Huh? Type 'help' for commands."],
    };
    // What I do in a fight outside my turn's action (a Bonus Action, a potion, an order), everyone here sees too.
    // shortcut: these don't wait for my turn.
    if (($was ?? $me['fighting']) !== null && in_array($verb, ['second', 'rage', 'wholeness', 'innate', 'font', 'lay',
        'inspire', 'wild', 'drink', 'order', 'cunning'], true)) {
        $mine = $out;
        $out = [];
        told($me, $mine, $out);
    }
    // look already lists what was said in the room, so don't repeat it.
    $shows_room = (in_array($verb, ['l', 'look', 'inspect'], true) && trim($arg) === '') || in_array($verb, DIRS, true);
    return array_merge($rested, $shows_room ? [] : $heard, tell($me, [...$clock, ...$out]),
        $me['fighting'] !== null ? heal_hint($me) : []);
}

/**
 * My output $lines with the fight lines told about a hero (see told()) logged as events, for the others in their
 * room, and made plain: as they are if they're about me, else in the third person (and not at all if just for them).
 */
function tell(array $me, array $lines): array
{
    $shown = [];
    $names = [];
    $heard = []; // room => whether anyone else is there to hear
    foreach ($lines as $line) {
        if (!preg_match('/^\x01(\d+),(\d+)\x01(.*)$/s', $line, $m)) {
            $shown[] = ltrim($line, "\x02");
            continue;
        }
        [$id, $room, $text] = [(int) $m[1], (int) $m[2], $m[3]];
        $heard[$room] ??= (bool) q('SELECT 1 FROM users WHERE room_id = ? AND id <> ? LIMIT 1', [$room, $me['id']])
            ->fetch();
        if ($id !== $me['id'] || $heard[$room]) {
            q('INSERT INTO events (room_id, user_id, text, about) VALUES (?, ?, ?, ?)',
                [$room, $me['id'], mb_substr($text, 0, 500), $id]);
        }
        if ($id === $me['id']) {
            $shown[] = ltrim($text, "\x02");
        } elseif (!str_starts_with($text, "\x02")) {
            $names[$id] ??= q('SELECT name FROM users WHERE id = ?', [$id])->fetchColumn();
            $shown[] = third_person($text, $names[$id], $me['name']);
        }
    }
    return $shown;
}

/** Once a fight is over (won, fled or died), says how to heal if it left me at half HP or less. */
function heal_hint(array $me): array
{
    $u = q('SELECT u.fighting, u.hp, u.max_hp, u.level - u.hit_dice_spent AS dice, r.safe
            FROM users u JOIN rooms r ON r.id = u.room_id WHERE u.id = ?', [$me['id']])->fetch();
    if ($u['fighting'] !== null || $u['hp'] > intdiv($u['max_hp'], 2)) {
        return [];
    }
    $where = $u['safe'] ? 'You can rest here'
        : 'Rest in a safe room, green on the map: the town square, the Lantern or the temple';
    return ["You're hurt (HP {$u['hp']}/{$u['max_hp']}). $where. 'rest' (1 min) spends Hit Dice ({$u['dice']} left); "
        . "'sleep' (10 min) heals you fully."];
}

/**
 * What others said in my room, or shouted anywhere in town while I'm in town, since my last command, and the fight
 * lines told in my room or about me (see told()): about me as they were, about another hero in the third person.
 * Advances my read pointer either way.
 */
function heard_since_last_command(array $me): array
{
    $max = (int) q('SELECT COALESCE(MAX(id), 0) FROM events')->fetchColumn();
    $rows = q(
        'SELECT u.name, e.text, e.shout, e.about, a.name AS about_name FROM events e JOIN users u ON u.id = e.user_id
         LEFT JOIN users a ON a.id = e.about
         WHERE (e.room_id = ? OR e.about = ? OR e.shout AND e.room_id <= ? AND ? <= ?)
         AND e.id > ? AND e.id <= ? AND e.user_id <> ? ORDER BY e.id',
        [$me['room_id'], $me['id'], TOWN_LAST_ROOM, $me['room_id'], TOWN_LAST_ROOM, $me['last_event_id'], $max,
            $me['id']]
    )->fetchAll();
    q('UPDATE users SET last_event_id = ? WHERE id = ?', [$max, $me['id']]);
    $heard = [];
    foreach ($rows as $r) {
        $heard[] = match (true) {
            $r['about'] === null => $r['name'] . ($r['shout'] ? ' shouts: ' : ' says: ') . $r['text'],
            $r['about'] === $me['id'] => ltrim($r['text'], "\x02"),
            str_starts_with($r['text'], "\x02") => null, // just for that hero, like the room they woke in
            default => third_person($r['text'], $r['about_name'], $me['name']),
        };
    }
    return array_values(array_filter($heard, fn ($l) => $l !== null));
}

/**
 * Fight lines $lines, told about hero $who (whose 'you' they are, in $who's room), added to $out: run_command() shows
 * them to $who as they are, logs them as events, and shows them to me in the third person if they're about another
 * hero. A line starting \x02 is for $who alone. Lines already told about someone stay theirs.
 */
function told(array $who, array $lines, array &$out): void
{
    foreach ($lines as $l) {
        $out[] = str_starts_with($l, "\x01") ? $l : "\x01{$who['id']},{$who['room_id']}\x01$l";
    }
}

/**
 * A line told about $name, said of them: "You hit the Kobold." becomes "Ana hits the Kobold." With $mine, my name: what
 * of mine it names is mine again ("Bo's Hold Person" is "your Hold Person").
 */
function third_person(string $text, string $name, string $mine = ''): string
{
    $text = preg_replace(["/\b[Yy]ou're\b/", "/\b[Yy]ou've\b/", "/\b[Yy]ou'll\b/"],
        ["$name is", "$name has", "$name will"], $text);
    // "You" as a subject (starting a sentence, or after a conjunction): its verb takes an s.
    $text = preg_replace_callback(
        "/(^|[.!?:]\s+|\b(?:so|and|as|while|until|if|when|then|but|or|because|before)\s+)[Yy]ou\s+([A-Za-z']+)/",
        function (array $m) use ($name): string {
            $v = $m[2];
            $s = match (true) {
                in_array(strtolower($v), ['can', 'could', 'will', 'would', 'may', 'might', 'must', 'should', "can't",
                    'cannot', "won't", "couldn't", "wouldn't", 'and', 'or', 'also', 'still', 'just', 'only', 'now',
                    'never', 'already', 'both', 'too'], true) => $v,
                $v === 'are' => 'is',
                $v === 'have' => 'has',
                $v === 'were' => 'was',
                $v === 'do' => 'does',
                $v === "don't" => "doesn't",
                $v === "aren't" => "isn't",
                $v === "haven't" => "hasn't",
                (bool) preg_match('/(s|sh|ch|x|z|o)$/', $v) => $v . 'es',
                (bool) preg_match('/[^aeiou]y$/', $v) => substr($v, 0, -1) . 'ies',
                default => $v . 's',
            };
            return "{$m[1]}$name $s";
        },
        $text
    );
    $text = preg_replace(['/\b[Yy]ourself\b/', '/\b[Yy]ours\b/', '/\b[Yy]our\b/', '/\b[Yy]ou\b/'],
        [$name, "$name's", "$name's", $name], $text);
    return $mine === '' ? $text : preg_replace_callback('/(^|.)\b' . preg_quote($mine, '/') . "'s\b/",
        fn ($m) => $m[1] . ($m[1] === '' ? 'Your' : 'your'), $text);
}

/**
 * Character creation, one step per command until the hero sets out: a name (new heroes only), a class, then assign the
 * Standard Array (which gives the class's kit and a fresh level 1 hero, even one who was played before), a species, a
 * background (whose ability increases set the hero's HP), then 'begin'. 'back' starts over at the class.
 */
function cmd_setup(array $me, string $input): array
{
    $word = strtolower($input);
    $word = $word === 'l' || $word === 'look' ? '' : $word; // the play page sends 'look' on load: show the step again
    if ($me['setup'] === 'name') {
        return setup_name($me, $word === '' ? '' : $input);
    }
    if ($word === 'back' || $me['setup'] === 'class') {
        return setup_class($me, $word === 'back' ? '' : $word);
    }
    if ($me['setup'] === 'abilities') {
        return setup_abilities($me, $word);
    }
    if ($me['setup'] === 'species') {
        return setup_species($me, $word);
    }
    if ($me['setup'] === 'background') {
        return setup_background($me, $word);
    }
    if ($word !== 'begin') {
        return setup_summary($me);
    }
    q('UPDATE users SET setup = NULL WHERE id = ?', [$me['id']]);
    return ["You set out, {$me['name']}. Type 'help' for commands.", '', ...cmd_look($me)];
}

function setup_name(array $me, string $name): array
{
    $ask = "What is your hero's name? (3-16 letters; others see it, unlike your account name)";
    if ($name === '') {
        return ['Welcome to Greenest!', $ask];
    }
    $name = ucfirst($name);
    if (!preg_match(NAME_RULE, $name)) {
        return ["A hero's name is 3-16 letters, no spaces.", $ask];
    }
    if (q('SELECT 1 FROM users WHERE name = ?', [$name])->fetch()) {
        return ["There's already a hero called $name.", $ask];
    }
    q('UPDATE users SET name = ? WHERE id = ?', [$name, $me['id']]);
    return setup_class(['name' => $name] + $me, '');
}

function setup_class(array $me, string $word): array
{
    foreach (CLASSES as $class => $c) {
        if ($word !== '' && str_starts_with($class, $word)) {
            q("UPDATE users SET class = ?, setup = 'abilities' WHERE id = ?", [$class, $me['id']]);
            return ["You will be a {$c['name']}.", ...ability_prompt($class)];
        }
    }
    q("UPDATE users SET setup = 'class' WHERE id = ?", [$me['id']]);
    $lines = $word === '' ? [] : ["There's no '$word' class here."];
    $lines[] = "Welcome to Greenest, {$me['name']}! Choose your class by typing its name:";
    foreach (CLASSES as $class => $c) {
        $lines[] = sprintf('  %-10s %s', $class, $c['about']);
    }
    return $lines;
}

function ability_prompt(string $class): array
{
    $c = CLASSES[$class];
    $suggested = array_map(fn ($a, $s) => ucfirst(substr($a, 0, 3)) . " $s", ABILITIES, $c['scores']);
    return [
        'Now give each ability one of the scores ' . implode(' ', STANDARD_ARRAY) . '.',
        "Suggested for a {$c['name']}: " . implode('  ', $suggested),
        "Type 'ok' for that, or six numbers in Str Dex Con Int Wis Cha order (like '" . implode(' ', $c['scores'])
            . "'). 'back' chooses another class.",
    ];
}

function setup_abilities(array $me, string $word): array
{
    $c = CLASSES[$me['class']];
    $words = preg_split('/[\s,]+/', $word, -1, PREG_SPLIT_NO_EMPTY);
    $scores = $word === 'ok' ? $c['scores'] : array_map('intval', array_filter($words, 'ctype_digit'));
    $sorted = $scores;
    rsort($sorted);
    if ($sorted !== STANDARD_ARRAY) {
        $error = $word === '' ? [] : ['Use each of ' . implode(' ', STANDARD_ARRAY) . ' exactly once.'];
        return [...$error, ...ability_prompt($me['class'])];
    }
    $hp = $c['hp'] + mod($scores[2]);
    q(
        "UPDATE users SET setup = 'species', species = NULL, lineage = NULL, background = NULL, species_spent = 0,
         origin_spent = '', strength = ?, dexterity = ?, constitution = ?, intelligence = ?,
         wisdom = ?, charisma = ?, level = 1, xp = 0, hp = ?, max_hp = ?, hit_dice_spent = 0, uses_spent = 0,
         slots_spent = '', prepared = '', may_prepare = TRUE, once_used = FALSE, feature_spent = 0, temp_hp = 0,
         shape = NULL, gold = ?, room_id = ?, x = NULL, y = NULL, resting = NULL, rest_until = NULL, " . END_FIGHT
            . ' WHERE id = ?',
        [...$scores, $hp, $hp, $c['gold'], START_ROOM, $me['id']]
    );
    q('DELETE FROM objects WHERE user_id = ?', [$me['id']]);
    q('DELETE FROM effects WHERE user_id = ? OR caster_id = ?', [$me['id'], $me['id']]); // Aid, Hex, Inspiration...
    foreach ($c['kit'] as [$item, $worn]) {
        q('INSERT INTO objects (item_id, user_id, worn) VALUES (?, ?, ?)', [$item, $me['id'], (int) $worn]);
    }
    return species_prompt();
}

/** species <name> [kind]: the kind is needed when the species has a choice, and then asked for. */
function setup_species(array $me, string $word): array
{
    [$name, $kind] = array_pad(preg_split('/\s+/', $word, 2), 2, '');
    foreach (SPECIES as $species => $s) {
        if ($name === '' || !str_starts_with($species, $name)) {
            continue;
        }
        $kinds = array_keys($s['kinds'] ?? []);
        $match = array_values(array_filter($kinds, fn ($k) => $kind !== '' && str_starts_with($k, $kind)));
        if ($kinds && !$match) {
            $lines = $kind === '' ? [] : ["There's no '$kind' to choose for a {$s['name']}."];
            $lines[] = "Choose {$s['choose']} by typing '$species' and one of these:";
            foreach ($s['kinds'] as $k => $about) {
                $lines[] = sprintf('  %-9s %s', $k, kind_about($about));
            }
            return $lines;
        }
        q("UPDATE users SET species = ?, lineage = ?, setup = 'background' WHERE id = ?",
            [$species, $match[0] ?? null, $me['id']]);
        $feat = $s['kinds'][$match[0] ?? '']['feat'] ?? null;
        $you = $feat === null ? lcfirst(a_an(trim(ucfirst($match[0] ?? '') . " {$s['name']}")))
            : "a {$s['name']}, with " . FEATS[$feat]['name'];
        return ["You are $you.", ...background_prompt()];
    }
    return [...($word === '' ? [] : ["There's no '$word' species here."]), ...species_prompt()];
}

/** What a species kind gives, for the prompt. */
function kind_about(array $k): string
{
    return match (true) {
        isset($k['feat']) => FEATS[$k['feat']]['name'] . ': ' . FEATS[$k['feat']]['about'],
        isset($k['about']) => $k['about'],
        default => ucfirst($k['resist']) . ' breath and Resistance',
    };
}

function species_prompt(): array
{
    $lines = ['Now choose your species by typing its name:'];
    foreach (SPECIES as $species => $s) {
        $lines[] = sprintf('  %-10s %s', $species, $s['about']);
    }
    return [...$lines, "'back' starts over."];
}

function background_prompt(): array
{
    $lines = ['Now choose your background. It raises three abilities and gives an Origin feat:'];
    foreach (BACKGROUNDS as $bg => $b) {
        $f = FEATS[$b['feat']];
        $lines[] = sprintf('  %-9s %s; %s: %s', $bg, implode(' ', array_map(fn ($a) => ucfirst(substr($a, 0, 3)),
            $b['abilities'])), $f['name'], $f['about']);
    }
    return [
        ...$lines,
        "Its name alone gives +2 and +1 to your best two of its three ('soldier'). Or name them: 'soldier con str'",
        "is Con +2, Str +1, and 'soldier all' is +1 to each. 'back' starts over.",
    ];
}

/**
 * background <name> [+2 ability] [+1 ability] | all. A Human's Versatile feat can't be the background's too, unless
 * it's the repeatable Skilled. The Standard Array tops out at 15, so no increase can pass 20.
 */
function setup_background(array $me, string $word): array
{
    $words = preg_split('/\s+/', $word, -1, PREG_SPLIT_NO_EMPTY);
    $bg = array_values(array_filter(array_keys(BACKGROUNDS), fn ($b) => str_starts_with($b, $words[0] ?? '-')))[0]
        ?? null;
    if ($bg === null) {
        return [...($word === '' ? [] : ["There's no '$word' background here."]), ...background_prompt()];
    }
    $b = BACKGROUNDS[$bg];
    $human = origin($me)['feat'] ?? null;
    if ($human === $b['feat'] && $human !== 'skilled') {
        return ['You already have ' . FEATS[$human]['name'] . " as a Human. Choose another background, or 'back'."];
    }
    $boost = array_fill_keys($b['abilities'], 0);
    if (($words[1] ?? '') === 'all') {
        $boost = array_fill_keys($b['abilities'], 1);
    } else {
        $named = [];
        foreach (array_slice($words, 1, 2) as $w) {
            $a = array_values(array_filter($b['abilities'], fn ($a) => str_starts_with($a, $w)))[0] ?? null;
            if ($a === null || in_array($a, $named, true)) {
                return ["A {$b['name']} raises two different ones of "
                    . implode(', ', array_map(fn ($a) => ucfirst(substr($a, 0, 3)), $b['abilities'])) . '.'];
            }
            $named[] = $a;
        }
        $rest = array_diff($b['abilities'], $named);
        usort($rest, fn ($x, $y) => $me[$y] <=> $me[$x]); // the best scores first; ties keep the listed order
        [$plus2, $plus1] = array_slice([...$named, ...$rest], 0, 2);
        [$boost[$plus2], $boost[$plus1]] = [2, 1];
    }
    foreach ($boost as $a => $n) {
        $me[$a] += $n;
    }
    $max_hp = CLASSES[$me['class']]['hp'] + mod($me['constitution']);
    $hp = $max_hp + ($me['species'] === 'dwarf' ? 1 : 0); // with_stats() adds Dwarven Toughness to the maximum
    q(
        "UPDATE users SET background = ?, setup = 'confirm', strength = ?, dexterity = ?, constitution = ?,
         intelligence = ?, wisdom = ?, charisma = ?, hp = ?, max_hp = ? WHERE id = ?",
        [$bg, ...array_map(fn ($a) => $me[$a], ABILITIES), $hp, $max_hp, $me['id']]
    );
    $raised = array_map(fn ($a, $n) => ucfirst(substr($a, 0, 3)) . " +$n", array_keys($boost), $boost);
    return [
        "You were a {$b['name']}: " . implode(', ', array_filter($raised, fn ($r) => !str_ends_with($r, '+0')))
            . ', and ' . FEATS[$b['feat']]['name'] . '.',
        ...setup_summary(q('SELECT * FROM users WHERE id = ?', [$me['id']])->fetch()),
    ];
}

function setup_summary(array $me): array
{
    return [
        ...cmd_score(with_stats($me)),
        ...cmd_inventory($me),
        "Type 'begin' to set out, or 'back' to start over.",
    ];
}

function cmd_look(array $me): array
{
    $room = q('SELECT name, description FROM rooms WHERE id = ?', [$me['room_id']])->fetch();
    $exits = q('SELECT dir FROM exits WHERE room_id = ? ORDER BY dir', [$me['room_id']])->fetchAll(PDO::FETCH_COLUMN);
    $lines = [$room['name'], $room['description'], 'Exits: ' . ($exits ? implode(' ', $exits) : 'none')];

    $others = q(
        'SELECT name FROM users WHERE room_id = ? AND id <> ? AND name IS NOT NULL
         AND last_seen > NOW() - INTERVAL ? MINUTE ORDER BY name',
        [$me['room_id'], $me['id'], ONLINE_MINUTES]
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($others as $name) {
        $lines[] = "$name is here.";
    }
    if (isset($me['hireling'])) { // a hero fresh from character creation has no stats yet, and no hireling
        $lines[] = "Your {$me['hireling']['name']} is here, with you.";
    }
    foreach (npcs_here((int) $me['room_id']) as $n) {
        $lines[] = $n['name'] . (str_contains($n['name'], ',') ? ', is here.' : ' is here.');
    }
    foreach (room_monsters((int) $me['room_id']) as $m) {
        $lines[] = a_an($m['name']) . ' is here' . ($m['id'] === $me['fighting'] ? ', fighting you.' : '.');
    }
    foreach (objects_here('room_id', (int) $me['room_id']) as $o) {
        $lines[] = a_an($o['name']) . ' lies here.';
    }

    $said = q(
        'SELECT u.name, e.text, TIMESTAMPDIFF(MINUTE, e.created, NOW()) AS mins FROM events e
         JOIN users u ON u.id = e.user_id
         WHERE e.room_id = ? AND NOT e.shout AND e.about IS NULL AND e.created > NOW() - INTERVAL ? MINUTE
         ORDER BY e.id DESC LIMIT 5',
        [$me['room_id'], ECHO_MINUTES]
    )->fetchAll();
    foreach (array_reverse($said) as $s) {
        $lines[] = "{$s['name']} said here " . ago((int) $s['mins']) . ": {$s['text']}";
    }
    $here = features_here((int) $me['room_id']);
    $features = array_map(fn ($f) => strtok($f['keywords'], ' '), $here);
    if ($features) {
        $lines[] = 'You could inspect: ' . implode(', ', $features) . '.';
    }
    $board = array_filter($here, fn ($f) => str_contains($f['text'], '{bulletin}'));
    if ($board && q('SELECT 1 FROM bulletins WHERE id > ? LIMIT 1', [$me['last_bulletin_id']])->fetch()) {
        $word = strtok(reset($board)['keywords'], ' ');
        $lines[] = "A new notice is pinned to the $word board. Try 'inspect $word'.";
    }
    return $lines;
}

function features_here(int $room_id): array
{
    return q('SELECT * FROM features WHERE room_id = ? ORDER BY id', [$room_id])->fetchAll();
}

function npcs_here(int $room_id): array
{
    return q('SELECT * FROM npcs WHERE room_id = ? ORDER BY id', [$room_id])->fetchAll();
}

/** What a player typed after look, inspect or ask, without a leading "at the" or "about a". */
function bare(string $target): string
{
    return preg_replace('/^((at|about|the|an?|to)\s+)+/i', '', $target) ?? $target;
}

/** The townsperson here whose name names($target) matches. */
function find_npc(array $me, string $target): ?array
{
    foreach (npcs_here((int) $me['room_id']) as $n) {
        if (names(bare($target), $n['name'])) {
            return $n;
        }
    }
    return null;
}

/** 'Marta Dunmore, the innkeeper' is Marta Dunmore. */
function short_name(array $npc): string
{
    return explode(',', $npc['name'])[0];
}

/** An NPC's topics, the greeting ('hello') first if they have one. */
function topics_of(array $npc): array
{
    return q("SELECT * FROM topics WHERE npc_id = ? ORDER BY keywords NOT LIKE 'hello%', id", [$npc['id']])->fetchAll();
}

/** What an NPC will talk about, for a line after their greeting or description. */
function topics_line(array $npc): string
{
    $words = array_map(fn ($t) => strtok($t['keywords'], ' '), topics_of($npc));
    $words = array_values(array_diff($words, ['hello']));
    if ($words === []) {
        return short_name($npc) . " has nothing much to say.";
    }
    return short_name($npc) . ' will talk about: ' . implode(', ', $words)
        . ". Try 'ask " . strtolower(strtok($npc['name'], ' ,')) . " about {$words[0]}'.";
}

/**
 * Looks closer at a feature of this room, or at a townsperson here (Search or Study, SRD 5.2.1 p.187, p.189): its text,
 * and its secret if my skill check finds it.
 */
function cmd_inspect(array $me, string $target): array
{
    $npc = find_npc($me, $target);
    if ($npc !== null) {
        return [$npc['about'], topics_line($npc)];
    }
    foreach (features_here((int) $me['room_id']) as $f) {
        if (names(bare($target), $f['keywords'])) {
            $text = str_replace('{roster}', implode("\n", roster()), $f['text']);
            if (str_contains($text, '{bulletin}')) { // only here, since reading the board marks it read
                $text = str_replace('{bulletin}', implode("\n", bulletins($me)), $text);
            }
            return [...explode("\n", $text), ...secret($me, $f, "feature {$f['id']}")];
        }
    }
    return ["You see no " . bare($target) . " here worth a closer look."];
}

/** Every hero in Greenest, a line each, as the Swaying Lantern's ledger lists them. */
function roster(): array
{
    $heroes = q('SELECT name, class, species, level, setup FROM users WHERE name IS NOT NULL ORDER BY name')
        ->fetchAll();
    return array_map(fn ($h) => sprintf('  %-16s %s', $h['name'], $h['setup'] !== null ? '(just arrived)'
        : ucwords(trim("{$h['species']} {$h['class']}")) . ", level {$h['level']}"), $heroes);
}

/**
 * The newest posts on the bulletin board, a line each (more if a post has line breaks), and marks them read: the
 * newest id becomes my last_bulletin_id, so cmd_look stops pointing me at the board.
 */
function bulletins(array $me): array
{
    $posts = q('SELECT id, text, TIMESTAMPDIFF(MINUTE, posted, NOW()) AS mins FROM bulletins ORDER BY id DESC LIMIT 3')
        ->fetchAll();
    if (!$posts) {
        return ['  Nothing is pinned there.'];
    }
    q('UPDATE users SET last_bulletin_id = ? WHERE id = ?', [$posts[0]['id'], $me['id']]);
    return array_map(fn ($p) => '  (' . ago((int) $p['mins']) . ') ' . str_replace("\n", "\n    ", $p['text']),
        $posts);
}

/** My bonus to a check with a SKILLS skill: its ability's modifier, plus my Proficiency Bonus if I'm proficient. */
function skill_bonus(array $me, string $skill): int
{
    $mine = array_map('trim', explode(',', strtolower(BACKGROUNDS[$me['background'] ?? '']['skills'] ?? '')));
    return mod((int) $me[SKILLS[$skill]]) + (in_array($skill, $mine, true) ? $me['prof_bonus'] : 0);
}

/**
 * A feature's or topic's secret, if my check with its skill beats its DC. A success stands; after a failure I wait 24
 * hours to try again (the Influence action's rule, SRD 5.2.1 p.184, used for Search and Study too). [] if it has none.
 */
function secret(array $me, array $row, string $what): array
{
    if ($row['skill'] === null) {
        return [];
    }
    $skill = str_replace(' Of ', ' of ', ucwords($row['skill']));
    $done = q(
        'SELECT passed, at > NOW() - INTERVAL 1 DAY AS recent FROM checks WHERE user_id = ? AND what = ?',
        [$me['id'], $what]
    )->fetch();
    if ($done && $done['passed']) {
        return [$row['secret']];
    }
    if ($done && $done['recent']) {
        return ["(Your $skill check fell short today. Try again tomorrow.)"];
    }
    $total = d20(0, lucky($me)) + skill_bonus($me, $row['skill']);
    $passed = $total >= (int) $row['dc'];
    q('REPLACE INTO checks (user_id, what, passed) VALUES (?, ?, ?)', [$me['id'], $what, (int) $passed]);
    return ["($skill check: " . vs($total, (int) $row['dc']) . ($passed ? ', success)' : ', fail)'),
        ...($passed ? [$row['secret']] : [])];
}

/**
 * Asks a townsperson here about a topic ('ask marta about the ledger', 'ask marta ledger', or 'ask about ledger' with
 * one of them here). With no topic, their greeting and what they'll talk about. A topic with a skill is an Influence
 * check (SRD 5.2.1 p.184), its secret the reward.
 */
function cmd_ask(array $me, string $arg): array
{
    $here = npcs_here((int) $me['room_id']);
    if ($here === []) {
        return ["There's no one here to talk to."];
    }
    [$who, $topic] = array_pad(preg_split('/(^|\s+)about\s+/i', $arg, 2) ?: [$arg], 2, '');
    $npc = $who === '' && count($here) === 1 ? $here[0] : find_npc($me, $who);
    if ($npc === null && $topic === '' && str_contains($who, ' ')) {
        [$who, $topic] = explode(' ', $who, 2);
        $npc = find_npc($me, $who);
    }
    if ($npc === null) {
        return [$who === '' ? 'Ask whom? ' . implode(' or ', array_map('short_name', $here)) . '.'
            : "There's nobody called $who here."];
    }
    $topics = topics_of($npc);
    $topic = bare($topic);
    if ($topic === '') {
        $hello = $topics && str_starts_with($topics[0]['keywords'], 'hello') ? [$topics[0]['reply']] : [];
        return [...$hello, topics_line($npc)];
    }
    foreach ($topics as $t) {
        if (names($topic, $t['keywords'])) {
            return [...explode("\n", $t['reply']), ...secret($me, $t, "topic {$t['id']}")];
        }
    }
    return [short_name($npc) . " has nothing to say about $topic.", topics_line($npc)];
}

function cmd_move(array $me, string $dir): array
{
    if ($me['fighting'] !== null) {
        return ["You're in a fight! Flee first."];
    }
    $to = q('SELECT to_room FROM exits WHERE room_id = ? AND dir = ?', [$me['room_id'], $dir])->fetchColumn();
    if ($to === false) {
        return ["You can't go $dir."];
    }
    if ((int) $to === DELVE_STAIR) {
        delve_refresh();
    }
    // I come in by the exit facing the way I went: going north, the new room's south one.
    $in = exit_square((int) $to, ['north' => 'south', 'south' => 'north', 'east' => 'west', 'west' => 'east',
        'up' => 'down', 'down' => 'up'][$dir]);
    q('UPDATE users SET room_id = ?, x = ?, y = ?, resting = NULL, rest_until = NULL WHERE id = ?',
        [$to, $in[0], $in[1], $me['id']]);
    q('INSERT IGNORE INTO visited (user_id, room_id) VALUES (?, ?)', [$me['id'], $to]); // for the map's fog of war
    $lines = $me['resting'] !== null ? ['You stop resting.'] : [];
    [$me['room_id'], $me['x'], $me['y']] = [(int) $to, $in[0], $in[1]];
    array_push($lines, ...cmd_look($me));
    arrive($me, $lines);
    return $lines;
}

/**
 * I've just come into my room. Sneaking, I make a Stealth check (Disadvantage in noisy armor): 15 or more hides me
 * (the Hide action, SRD 5.2.1 p.183; shortcut: every room has cover), from every monster whose passive Perception is
 * below it. The first aggressive monster that sees me attacks, unless I'm more than 5 levels above its CR (Riftshadow's
 * rule, so the Southern Fields' kobolds leave veterans be). Monsters that come back while I'm here haven't seen me.
 */
function arrive(array &$me, array &$lines): void
{
    $monsters = room_monsters((int) $me['room_id']);
    $fight = fight_monsters($me);
    if ($fight !== [] && fight_heroes((int) $me['room_id']) !== []) { // a fight under way here: I join it
        $mine = my_square($me);
        usort($fight, fn ($a, $b) => feet($mine, [$a['x'], $a['y']]) <=> feet($mine, [$b['x'], $b['y']]));
        [$me['stealth'], $joined] = [null, []];
        start_fight($me, $fight[0], $joined, '');
        $joined[] = fight_status($me, $fight[0]);
        told($me, $joined, $lines);
        return;
    }
    $me['stealth'] = null;
    if ($me['sneaking'] && $monsters) {
        $total = d20($me['noisy'] ? -1 : 0, lucky($me)) + skill_bonus($me, 'stealth');
        $me['stealth'] = $total >= 15 ? $total : null;
        $seen = array_unique(array_map(fn ($m) => "the {$m['name']}",
            array_filter($monsters, fn ($m) => !hidden_from($me, $m))));
        $lines[] = "(Stealth $total" . with($me['noisy'] ? -1 : 0) . ': ' . match (true) {
            $me['stealth'] === null => 'too loud to hide.)',
            $seen === [] => 'nothing here notices you.)',
            default => implode(', ', $seen) . (count($seen) > 1 ? ' notice' : ' notices') . ' you.)',
        };
    }
    q('UPDATE users SET stealth = ? WHERE id = ?', [$me['stealth'], $me['id']]);
    foreach ($monsters as $foe) {
        if ($foe['aggressive'] && !hidden_from($me, $foe) && $me['level'] <= (float) $foe['cr'] + 5) {
            [$fought, $here] = [[], $me['room_id']];
            start_fight($me, $foe, $fought, "The {$foe['name']} attacks you!");
            // The monsters that won Initiative act; my next command is my turn in this round.
            $foe = named($me, $foe);
            if (!begin_turn($me, $foe, $fought)) {
                $fought[] = fight_status($me, $foe);
            }
            told(['room_id' => $here] + $me, $fought, $lines); // where it happened, if I died
            return;
        }
    }
}

/** True if my Stealth total hides me from $foe: it's above its passive Perception. */
function hidden_from(array $me, array $foe): bool
{
    return $me['stealth'] !== null && $me['stealth'] > $foe['perception'];
}

/** Turns sneaking on or off. It takes effect as I enter the next room; a fight, speaking or dying ends it. */
function cmd_sneak(array $me): array
{
    if ($me['fighting'] !== null) {
        return ["You can't sneak in the middle of a fight."];
    }
    q('UPDATE users SET sneaking = ?, stealth = NULL WHERE id = ?', [(int) !$me['sneaking'], $me['id']]);
    if ($me['sneaking']) {
        return ['You stop sneaking.'];
    }
    return ['You move quietly. Entering a room with monsters, a Stealth check of 15 or more hides you from those '
        . "that don't notice it (their passive Perception). Attack one from hiding for Advantage on Initiative and "
        . 'your first attack, and it may be surprised.',
        ...($me['noisy'] ? ['Your armor is noisy: Disadvantage on Stealth.'] : [])];
}

/** Respawns monsters in the room whose timer ran out (rolling their HP), then returns the living ones, oldest first. */
function room_monsters(int $room_id): array
{
    $due = q(
        'SELECT s.id, m.hp AS dice FROM spawns s JOIN monsters m ON m.id = s.monster_id
         WHERE s.room_id = ? AND s.dead_since < NOW() - INTERVAL s.respawn_secs SECOND ORDER BY s.id',
        [$room_id]
    )->fetchAll();
    foreach ($due as $s) {
        q('DELETE FROM effects WHERE spawn_id = ?', [$s['id']]);
        // "dead_since IS NOT NULL" so two heroes arriving at once can't both respawn it.
        $hp = max(1, roll($s['dice']));
        q("UPDATE spawns SET dead_since = NULL, hp = ?, max_hp = ?, conditions = '', grappled_by = NULL, x = NULL,
           y = NULL, placed_at = NULL, init = NULL WHERE id = ? AND dead_since IS NOT NULL", [$hp, $hp, $s['id']]);
    }
    $monsters = q(
        // The spawn's columns come last, so its id and hp win over the template's (the HP dice). It has been where it
        // stands 10 minutes or more: with nobody fighting it, it's back at a fresh spot.
        'SELECT m.*, s.*, COALESCE(s.placed_at < NOW() - INTERVAL 10 MINUTE, TRUE) AS stale
         FROM spawns s JOIN monsters m ON m.id = s.monster_id
         WHERE s.room_id = ? AND s.dead_since IS NULL ORDER BY s.id',
        [$room_id]
    )->fetchAll();
    return array_map(fn ($m) => $m + ['effects' => effects_on('spawn_id', $m['id'])], $monsters);
}

/** The monster I'm fighting, or else the first one here with a word in its name starting with $target. */
function find_foe(array $me, string $target): ?array
{
    $all = room_monsters((int) $me['room_id']);
    if ($target === '' && $me['fighting'] !== null) {
        foreach ($all as $m) {
            if ($m['id'] === $me['fighting']) {
                return $m;
            }
        }
        // never expected (a kill moves everyone fighting it on), but don't strand the hero
        q('UPDATE users SET ' . END_FIGHT . ' WHERE id = ?', [$me['id']]);
        return null;
    }
    $sq = $me['fighting'] !== null && preg_match('/^[a-z]\d{1,2}$/i', $target) ? board_square($me, $target) : null;
    if ($sq !== null) { // a monster by its square on the board, as a tap names it
        return array_values(array_filter($all, fn ($m) => [$m['x'], $m['y']] === $sq))[0] ?? null;
    }
    [$name, $n] = preg_match('/^(.*\S)\s+(\d+)$/', $target, $nth) ? [$nth[1], (int) $nth[2]] : [$target, 1];
    $named = array_values(array_filter($all, fn ($m) => names($name, $m['name']))); // 'kobold 2': the second here
    return $named[$n - 1] ?? null;
}

/** True if some word of $name starts with $target, so "rat" matches "Giant Rat". */
function names(string $target, string $name): bool
{
    return $target !== '' && preg_match('/\\b' . preg_quote($target, '/') . '/i', $name) === 1;
}

function a_an(string $name): string
{
    return (preg_match('/^[aeiou]/i', $name) ? 'An ' : 'A ') . $name;
}

// Combat follows SRD 5.2.1: initiative when a fight starts, then one round per command in initiative order.
// Attack rolls are d20 + bonus against AC; a natural 20 is a critical hit (damage dice twice), a natural 1 misses.

function cmd_kill(array $me, string $target): array
{
    return fight($me, $target, 'hero_turn', 'Kill what?', weapon_reach($me));
}

/** Throws my weapon from where I stand, or shoots (as kill does with a bow); a thrown off-hand weapon goes too. */
function cmd_shoot(array $me, string $target): array
{
    if (!$me['ranged'] && !$me['thrown']) {
        return ["Your {$me['weapon']} can't be thrown or shot."];
    }
    $me['ranged'] = true; // a thrown weapon makes a ranged attack
    if ($me['offhand'] !== null && $me['offhand']['thrown']) {
        $me['offhand']['ranged'] = true;
    }
    return fight($me, $target, 'hero_turn', 'Shoot what?', weapon_reach($me));
}

/** How near my foe my weapon attack needs me, in feet: its long range for a shot, 10 with Reach, else 5. */
function weapon_reach(array $me): int
{
    return $me['ranged'] ? $me['long_range'] ?? 5 : ($me['reach'] ? 10 : 5);
}

/** A Rogue's Cunning Action or a Monk's Step of the Wind, from level 2: a free Disengage whenever I move away. */
function slips(array $me): bool
{
    return in_array($me['class'], ['rogue', 'monk'], true) && $me['level'] >= 2;
}

/**
 * Kites: my attack, then with what's left of my Speed I go to the square that $arg ends with (like e5), or back off
 * (see back_off()). With a ranged attack and my foe next to me I move first, so the shot has no Disadvantage.
 */
function cmd_kite(array $me, string $arg): array
{
    $words = preg_split('/\s+/', $arg);
    $sq = null;
    if (preg_match('/^[a-z]\d{1,2}$/i', end($words))) {
        $sq = board_square($me, array_pop($words));
        if ($sq === null) {
            return ['Kite to where? Name a square on the board, like b3 (a1 is the top left).'];
        }
    }
    return fight($me, implode(' ', $words), function (array &$me, array &$foe, array &$lines) use ($sq): bool {
        $go = function () use (&$me, &$foe, &$lines, $sq): bool { // by reference: it moves me
            $feet = $me['speed'] - $me['moved'];
            return $sq === null ? back_off($me, $foe, $feet, $lines, slips($me))
                : go_to($me, $foe, $sq, $feet, $lines, slips($me));
        };
        $first = $me['ranged'] && $me['pressed'];
        if ($first) {
            if ($go()) {
                return true;
            }
            sizing($me, $foe);
        }
        return hero_turn($me, $foe, $lines) || (!$first && $go());
    }, 'Kite what?', weapon_reach($me));
}

/**
 * I $how ('move', 'dash'...) toward square $sq, up to $feet; $free: no opportunity attack. Returns true if one ended
 * the fight.
 */
function go_to(array &$me, array &$foe, array $sq, int $feet, array &$lines, bool $free, string $how = 'move'): bool
{
    if (walk($me, $foe, $sq, $feet, $lines, 0, $free)) {
        return true;
    }
    $lines[] = "You $how to " . square(my_square($me)) . ', ' . feet(my_square($me), [$foe['x'], $foe['y']])
        . " ft from the {$foe['name']}.";
    return false;
}

/**
 * My action is a Dash (twice my Speed this turn), a Disengage (no opportunity attacks this turn) or a Dodge (attacks
 * on me have Disadvantage until my next turn). Dash and Disengage take me to square $to (like c3), or as far from my
 * foe as they can.
 */
function cmd_action(array $me, string $how, string $to): array
{
    $sq = $to === '' ? null : board_square($me, $to);
    if ($to !== '' && $sq === null) {
        return ['Where to? Name a square on the board, like b3 (a1 is the top left).'];
    }
    return fight($me, '', function (array &$me, array &$foe, array &$lines) use ($how, $sq): bool {
        if ($how === 'dodge') {
            $me['conditions'] = implode(',', array_filter([$me['conditions'], 'dodging']));
            q('UPDATE users SET conditions = ? WHERE id = ?', [$me['conditions'], $me['id']]);
            $lines[] = 'You Dodge: attacks on you have Disadvantage until your next turn.';
            return false;
        }
        $feet = $me['speed'] * ($how === 'dash' ? 2 : 1) - $me['moved'];
        $free = $how === 'disengage' || slips($me);
        $verb = $how === 'dash' ? 'dash' : 'disengage and move';
        return $sq === null ? back_off($me, $foe, $feet, $lines, $free, $verb)
            : go_to($me, $foe, $sq, $feet, $lines, $free, $verb);
    }, "You aren't fighting anything.", PHP_INT_MAX, false);
}

/**
 * I $how ('back off', 'dash'...) up to $feet to the square farthest from the nearest monster of my fight, the
 * nearest such to me, then the straightest, so along a wall I slide sideways. $free: no opportunity attack. Returns
 * true if one ended the fight.
 */
function back_off(array &$me, array &$foe, int $feet, array &$lines, bool $free = false, string $how = 'back off'): bool
{
    [$mine, $there] = [my_square($me), [$foe['x'], $foe['y']]];
    $foes = [$there, ...monster_squares($me, $foe['id'])];
    $ways = paths((int) $me['room_id'], $mine, [...$foes, ...crowd($me), ...array_filter([hire_square($me)])]);
    $near = fn ($p) => min(array_map(fn ($f) => feet($p, $f), $foes)); // from the nearest monster
    $key = fn ($p, $c) => [-$near($p), $c, abs($p[0] - $mine[0]) + abs($p[1] - $mine[1])];
    $best = $mine;
    foreach ($ways as $k => [$cost]) {
        $p = array_map('intval', explode(',', $k));
        if ($cost <= $feet && $key($p, $cost) < $key($best, $ways[implode(',', $best)][0])) {
            $best = $p;
        }
    }
    if ($best === $mine) {
        $lines[] = "You can't get any farther from the {$foe['name']}.";
        return false;
    }
    if (walk($me, $foe, $best, $feet, $lines, 0, $free)) {
        return true;
    }
    $lines[] = "You $how to " . square(my_square($me)) . ', ' . feet(my_square($me), $there)
        . " ft from the {$foe['name']}.";
    return false;
}

/** Square $to (like c3) of my room's board as [x, y], or null if it isn't one. */
function board_square(array $me, string $to): ?array
{
    [$w, $h] = board_size((int) $me['room_id']);
    if (!preg_match('/^([a-z])(\d{1,2})$/', strtolower($to), $m) || ord($m[1]) - 97 >= $w || $m[2] < 1
        || $m[2] > $h) {
        return null;
    }
    return [ord($m[1]) - 97, (int) $m[2] - 1];
}

/**
 * One round against $target (or the monster I'm fighting), starting the fight if need be, with $act as my action:
 * a function (array &$me, array &$foe, array &$lines): bool that returns true if the monster died. First I close in
 * until it's within $reach feet (5 for a melee attack, my weapon's long range for a shot, PHP_INT_MAX for anything
 * that works from where I stand); if what's left of my Speed can't get me there, I Dash, and that's my action. A
 * $hostile action (an attack or a spell) ends my Sanctuary.
 */
function fight(
    array $me,
    string $target,
    callable $act,
    string $what = 'Kill what?',
    int $reach = 5,
    bool $hostile = true
): array {
    $lines = [];
    $wait = $me['fighting'] === null ? null : my_turn($me, $lines);
    $room = $me['room_id'];
    $foe = $wait === null ? find_foe($me, $target) : null;
    if (!$foe) {
        return tell_lines($me, $room, $lines, $wait ?? ($target === '' && $me['fighting'] === null ? $what
            : ($target === '' ? $what : "You don't see a $target here.")));
    }
    if ($me['fighting'] !== null && $foe['id'] !== $me['fighting']) {
        retarget($me, $foe, $lines);
    }
    if ($me['fighting'] === null) {
        $unseen = hidden_from($me, $foe);
        start_fight($me, $foe, $lines, "You attack the {$foe['name']}" . ($unseen ? ' from hiding!' : '!'));
        if ($unseen && !in_reach((int) $me['room_id'], my_square($me), [$foe['x'], $foe['y']], $reach)) { // unnoticed
            $me['moved'] = 0;
            walk($me, $foe, [$foe['x'], $foe['y']], $me['speed'], $lines, $reach); // toward it: no opportunity attack
            $me['moved'] = null;
            q('UPDATE users SET moved = NULL WHERE id = ?', [$me['id']]);
            $lines[] = "You creep up on the {$foe['name']}, unseen.";
        }
        if (($wait = my_turn($me, $lines)) !== null) { // I joined others' fight, and it's someone else's turn
            return tell_lines($me, $room, $lines, $wait);
        }
    }
    $foe = named($me, $foe);
    $over = begin_turn($me, $foe, $lines);
    if (!$over) {
        if (close_in($me, $foe, $reach, $lines)) {
            sizing($me, $foe);
            if ($hostile) {
                unward($me, $lines);
            }
            $cover = $me['far'] ? sight((int) $me['room_id'], my_square($me), [$foe['x'], $foe['y']]) ?? 0 : 0;
            if ($cover) {
                $lines[] = "The {$foe['name']} has half cover (+2 AC).";
            }
            $foe['ac'] += $cover; // shortcut: not on its Dex saves, which half cover also helps
            $act($me, $foe, $lines);
            $foe['ac'] -= $cover;
        }
        $over = refocus($me, $foe);
        if (!$over && $me['hireling'] !== null) {
            hireling_turn($me, $foe, $lines);
            $over = refocus($me, $foe);
        }
        $over = $over || end_turn($me, $foe, $lines);
    }
    if (!$over) {
        $lines[] = fight_status($me, $foe);
    }
    return tell_lines($me, $room, $lines);
}

/** Fight lines $lines told about me in room $room (where they happened, if I've since died), then $after for me. */
function tell_lines(array $me, int $room, array $lines, ?string $after = null): array
{
    $out = [];
    told(['room_id' => $room] + $me, $lines, $out);
    return $after === null ? $out : [...$out, $after];
}

/**
 * My turn starts, unless it's under way (I've moved this turn): the monsters in my fight that beat me on Initiative
 * act first (see monsters_act()). Returns true if that ended the fight for me.
 */
function begin_turn(array &$me, array &$foe, array &$lines): bool
{
    if ($me['moved'] !== null) {
        return false;
    }
    if (monsters_act($me, $foe, $lines, true)) {
        return true;
    }
    start_turn($me);
    // shortcut: these end at the start of my turn, though another hero may have caused them
    foreach (fight_monsters($me) as $m) {
        foreach (['sapped', 'stunned', 'reeling', 'slowed'] as $c) {
            if ($m['id'] === $foe['id'] && has($foe, $c)) {
                set_condition($foe, $c, false);
            } elseif ($m['id'] !== $foe['id'] && has($m, $c)) {
                set_condition($m, $c, false);
            }
        }
    }
    $me['moved'] = 0;
    q('UPDATE users SET moved = 0 WHERE id = ?', [$me['id']]);
    return false;
}

/**
 * My turn ends: a Bonus Action before my next one counts for that one, the monsters between me and the next hero in
 * Initiative act, and the turn is that hero's (mine again if I'm alone). Returns true if the fight is over for me.
 */
function end_turn(array &$me, array &$foe, array &$lines): bool
{
    [$me['moved'], $me['bonus_used']] = [null, false];
    q('UPDATE users SET moved = NULL, bonus_used = FALSE WHERE id = ?', [$me['id']]);
    $room = (int) $me['room_id'];
    $heroes = fight_heroes($room);
    $i = array_search($me['id'], array_column($heroes, 'id'), true);
    $next = $heroes[$i === false ? 0 : ($i + 1) % count($heroes)]['id'] ?? $me['id'];
    $over = monsters_act($me, $foe, $lines, false);
    q('UPDATE rooms SET turn = ?, turn_at = NOW() WHERE id = ?', [$next, $room]);
    return $over;
}

/**
 * The monsters of the fight in my room act in Initiative order. With $before, if I'm the first hero in the order,
 * those that beat me (ties go to heroes); else those after me and before the next hero, or all the rest if I'm the
 * last. Each goes for the nearest hero, me on a tie (see monster_turn()); what it does to another is told about them.
 * $foe follows my target as it changes. Returns true if the fight is over for me: I dropped, or none is left.
 */
function monsters_act(array &$me, array &$foe, array &$lines, bool $before): bool
{
    $room = (int) $me['room_id'];
    $heroes = fight_heroes($room);
    $i = array_search($me['id'], array_column($heroes, 'id'), true);
    if ($before && $i > 0) {
        return false;
    }
    $mine = $me['init'] ?? 0;
    $next = $before || $i === false || !isset($heroes[$i + 1]) ? null : (int) $heroes[$i + 1]['init'];
    foreach (fight_monsters($me) as $m) {
        if ($before ? $m['init'] <= $mine : $m['init'] > $mine || ($next !== null && $m['init'] <= $next)) {
            continue;
        }
        if (!q('SELECT 1 FROM spawns WHERE id = ? AND dead_since IS NULL', [$m['id']])->fetch()) {
            continue; // it died this round
        }
        $at = [$m['x'], $m['y']];
        $near = fight_heroes($room);
        // the nearest; of those, me (whose command this is), then by id
        $key = fn ($h) => [feet($at, [$h['x'], $h['y']]), $h['id'] !== $me['id'], $h['id']];
        usort($near, fn ($a, $b) => $key($a) <=> $key($b));
        $who = $near[0]['id'] ?? $me['id'];
        $it = $m['id'] === $foe['id'] ? $foe : $m;
        if ($who === $me['id']) {
            monster_turn($me, $it, $lines);
        } else {
            $them = with_stats(q('SELECT * FROM users WHERE id = ?', [$who])->fetch());
            $theirs = [];
            monster_turn($them, $it, $theirs);
            told(['room_id' => $room] + $them, $theirs, $lines);
        }
        if ($m['id'] === $foe['id']) {
            $foe = $it;
        }
        if (refocus($me, $foe)) {
            return true;
        }
    }
    return false;
}

/**
 * The heroes fighting in room $room in Initiative order (highest first, ties by id), each with 'here': their page has
 * asked for news in the last 15 seconds (play.js does every few seconds while it's open).
 */
function fight_heroes(int $room): array
{
    return q(
        'SELECT id, name, COALESCE(init, 0) AS init, x, y,
         COALESCE(last_seen > NOW() - INTERVAL 15 SECOND, FALSE) AS here
         FROM users WHERE room_id = ? AND fighting IS NOT NULL ORDER BY init DESC, id',
        [$room]
    )->fetchAll();
}

/** The squares of the other heroes fighting in my room, and their hirelings', where I can't step. */
function crowd(array $me): array
{
    $at = [];
    foreach (q('SELECT x, y, hireling_x, hireling_y, hireling_monster_id FROM users WHERE room_id = ?
        AND fighting IS NOT NULL AND id <> ? AND x IS NOT NULL', [$me['room_id'], $me['id']]) as $u) {
        $at[] = [$u['x'], $u['y']];
        if ($u['hireling_monster_id'] !== null && $u['hireling_x'] !== null) {
            $at[] = [$u['hireling_x'], $u['hireling_y']];
        }
    }
    return $at;
}

/**
 * Whether I can act in the fight now: null if it's my turn (it becomes mine if nobody's it is, or theirs has left the
 * fight), else why not. The turn of a hero who's away (their page closed), or who's taken 2 minutes, passes first:
 * they take the Dodge action (see pass_turn()), and what happens is told in $lines. $me is reloaded if it may have
 * changed.
 */
function my_turn(array &$me, array &$lines): ?string
{
    $room = (int) $me['room_id'];
    for ($tries = 0; $tries < 20; $tries++) { // each pass moves the turn on; 20 is past any party
        $r = q('SELECT turn, TIMESTAMPDIFF(SECOND, turn_at, NOW()) AS secs FROM rooms WHERE id = ?', [$room])->fetch();
        $them = array_values(array_filter(fight_heroes($room), fn ($h) => $h['id'] === $r['turn']))[0] ?? null;
        if ($them === null || $them['id'] === $me['id']) {
            if ($r['turn'] !== $me['id']) {
                q('UPDATE rooms SET turn = ?, turn_at = NOW() WHERE id = ?', [$me['id'], $room]);
            }
            return null;
        }
        if ($them['here'] && $r['secs'] < TURN_SECS) {
            $left = TURN_SECS - (int) $r['secs'];
            return "It's {$them['name']}'s turn (" . intdiv($left, 60) . ':' . sprintf('%02d', $left % 60)
                . ' left).';
        }
        pass_turn($them['id'], $lines, !$them['here']);
        $me = with_stats(q('SELECT * FROM users WHERE id = ?', [$me['id']])->fetch());
        if ($me['fighting'] === null) {
            return null;
        }
    }
    return null;
}

/** Hero $id is $away, or out of time: their turn passes as the Dodge action, told about them in $lines. */
function pass_turn(int $id, array &$lines, bool $away): void
{
    $them = with_stats(q('SELECT * FROM users WHERE id = ?', [$id])->fetch());
    $foe = array_values(array_filter(fight_monsters($them), fn ($m) => $m['id'] === $them['fighting']))[0]
        ?? fight_monsters($them)[0] ?? null;
    $theirs = [];
    if ($foe === null) { // never expected: a fight with no monsters left
        q('UPDATE users SET ' . END_FIGHT . ' WHERE id = ?', [$id]);
        return;
    }
    if (!begin_turn($them, $foe, $theirs)) {
        $them['conditions'] = implode(',', array_filter([$them['conditions'], 'dodging']));
        q('UPDATE users SET conditions = ? WHERE id = ?', [$them['conditions'], $id]);
        $theirs[] = ($away ? 'You are away' : 'Your ' . intdiv(TURN_SECS, 60) . ' minutes are up')
            . ', so you take the Dodge action.';
        end_turn($them, $foe, $theirs);
    }
    told(['room_id' => $foe['room_id']] + $them, $theirs, $lines);
}

/**
 * The monsters in my fight: alive in my room with an Initiative (see start_fight()), highest Initiative first, named
 * apart from others of their kind ('Kobold', 'Kobold 2', by id). Spends no dice (no respawns), so the board can use it.
 */
function fight_monsters(array $me): array
{
    $in = array_map(fn ($m) => $m + ['effects' => effects_on('spawn_id', $m['id'])], q(
        'SELECT m.*, s.*, COALESCE(s.placed_at < NOW() - INTERVAL 10 MINUTE, TRUE) AS stale
         FROM spawns s JOIN monsters m ON m.id = s.monster_id
         WHERE s.room_id = ? AND s.dead_since IS NULL AND s.init IS NOT NULL ORDER BY s.id',
        [$me['room_id']]
    )->fetchAll());
    $seen = [];
    foreach ($in as &$m) {
        $n = $seen[$m['name']] = ($seen[$m['name']] ?? 0) + 1;
        $m['name'] .= $n > 1 ? " $n" : '';
    }
    unset($m);
    usort($in, fn ($a, $b) => [$b['init'], $a['id']] <=> [$a['init'], $b['id']]);
    return $in;
}

/** $foe with its name in my fight ('Kobold 2'), if it's in it. */
function named(array $me, array $foe): array
{
    foreach (fight_monsters($me) as $m) {
        if ($m['id'] === $foe['id']) {
            $foe['name'] = $m['name'];
        }
    }
    return $foe;
}

/** The squares of the monsters in my fight, but $except's (an id). */
function monster_squares(array $me, ?int $except = null): array
{
    return array_values(array_map(fn ($m) => [$m['x'], $m['y']],
        array_filter(fight_monsters($me), fn ($m) => $m['id'] !== $except && $m['x'] !== null)));
}

/**
 * After something that may have killed my target: true if my fight is over, else $foe is my target, a new one if
 * win() turned me to it.
 */
function refocus(array &$me, array &$foe): bool
{
    // Another hero's kill may have turned me (see win()), so ask the database.
    $me['fighting'] = q('SELECT fighting FROM users WHERE id = ?', [$me['id']])->fetchColumn();
    if ($me['fighting'] === null) {
        return true;
    }
    if ($me['fighting'] !== $foe['id']) {
        foreach (fight_monsters($me) as $m) {
            if ($m['id'] === $me['fighting']) {
                $foe = $m;
                return false;
            }
        }
        return true;
    }
    return false;
}

/** I fight $foe now, in place of my target; one not yet in the fight joins it, rolling Initiative. */
function retarget(array &$me, array &$foe, array &$lines): void
{
    if ($foe['init'] === null) {
        $foe['init'] = d20(0) + $foe['initiative'];
        $at = $foe['x'] === null || $foe['stale'] ? open_near((int) $me['room_id'], my_square($me),
            [my_square($me), ...monster_squares($me), ...crowd($me)]) : [$foe['x'], $foe['y']];
        [$foe['x'], $foe['y']] = $at;
        q('UPDATE spawns SET init = ?, x = ?, y = ?, placed_at = NOW() WHERE id = ?',
            [$foe['init'], ...$at, $foe['id']]);
        $lines[] = 'The ' . named($me, $foe)['name'] . " joins the fight (Initiative {$foe['init']}).";
    }
    [$me['fighting'], $me['foe_first']] = [$foe['id'], $foe['init'] > ($me['init'] ?? 0)];
    q('UPDATE users SET fighting = ?, foe_first = ? WHERE id = ?', [$foe['id'], (int) $me['foe_first'], $me['id']]);
}

/**
 * How things stand before my action: 'dist', feet to my target; 'far', more than 5 ft (no melee); 'pressed', a monster
 * of my fight within 5 ft (a shot has Disadvantage).
 */
function sizing(array &$me, array $foe): void
{
    $me['dist'] = feet(my_square($me), [$foe['x'], $foe['y']]);
    $me['far'] = $me['dist'] > 5;
    $me['pressed'] = !$me['far'] || (bool) array_filter(monster_squares($me), fn ($p) => feet(my_square($me), $p) <= 5);
}

/**
 * Before my action: if a $reach-ft attack can't reach my foe from here (too far, or no line to it), I walk to where
 * one can with what's left of my Speed. If that can't get me there I Dash, as far again, and return false: the Dash
 * was my action.
 */
function close_in(array &$me, array &$foe, int $reach, array &$lines): bool
{
    $there = [$foe['x'], $foe['y']];
    $room = (int) $me['room_id'];
    if (in_reach($room, my_square($me), $there, $reach)) {
        return true;
    }
    walk($me, $foe, $there, $me['speed'] - $me['moved'], $lines, $reach);
    if (in_reach($room, my_square($me), $there, $reach)) {
        $lines[] = "You close in on the {$foe['name']}.";
        return true;
    }
    walk($me, $foe, $there, $me['speed'], $lines, $reach);
    $off = feet(my_square($me), $there);
    $lines[] = "You run at the {$foe['name']}" . ($off > 5 ? ", $off ft off." : '.');
    return false;
}

/**
 * Walks me square by square toward $to, up to $feet (difficult terrain costs double), stopping once I'm there, or
 * when $stop isn't 0, where a $stop-ft attack reaches my foe (see in_reach()). A step out of a monster's reach draws
 * its opportunity attack (once until its turn: its Reaction), unless $free (Disengage). Saves my square
 * and the feet of Speed I've used. Returns true if that attack ended the fight.
 */
function walk(array &$me, array &$foe, array $to, int $feet, array &$lines, int $stop = 0, bool $free = false): bool
{
    $room = (int) $me['room_id'];
    $rows = board($room);
    $at = my_square($me);
    $there = [$foe['x'], $foe['y']];
    $others = array_values(array_filter(fight_monsters($me), fn ($m) => $m['id'] !== $foe['id'] && $m['x'] !== null));
    $blocked = [$there, ...array_map(fn ($m) => [$m['x'], $m['y']], $others), ...array_filter([hire_square($me)]),
        ...crowd($me)];
    for ($left = $feet; $at !== $to && ($stop === 0 || !in_reach($room, $at, $there, $stop));) {
        $next = toward($room, $at, $to, $blocked, $stop);
        if ($next === $at || step_cost($rows, $next) > $left) {
            break;
        }
        $left -= step_cost($rows, $next);
        $leaving = array_filter([$foe, ...$others], fn ($m) => feet($at, [$m['x'], $m['y']]) <= $m['reach']
            && feet($next, [$m['x'], $m['y']]) > $m['reach']);
        [$at, $me['x'], $me['y'], $me['moved']] = [$next, $next[0], $next[1], $me['moved'] + step_cost($rows, $next)];
        if ($foe['grappled_by'] === $me['id'] && feet($at, $there) > 5) { // out of my reach, my hold breaks
            $foe['grappled_by'] = null;
            q('UPDATE spawns SET grappled_by = NULL WHERE id = ?', [$foe['id']]);
            $lines[] = "You let go of the {$foe['name']}.";
        }
        foreach ($free ? [] : array_keys($leaving) as $i) { // each one's Reaction, once until its turn
            $m = $i === 0 ? $foe : $others[$i - 1];
            if (has($m, 'reacted')) {
                continue;
            }
            $lines[] = "The {$m['name']} strikes as you pull away!";
            if ($i === 0) {
                set_condition($foe, 'reacted');
                monster_turn($me, $foe, $lines, 1);
            } else {
                set_condition($others[$i - 1], 'reacted');
                monster_turn($me, $others[$i - 1], $lines, 1);
            }
            if ($me['fighting'] === null) {
                return true;
            }
        }
    }
    q('UPDATE users SET x = ?, y = ?, moved = ? WHERE id = ?', [$at[0], $at[1], $me['moved'], $me['id']]);
    return false;
}

/** In a fight, walks me to square $to (like c3) with what's left of my Speed this turn; my action waits. */
function cmd_step(array $me, string $to): array
{
    $foe = $me['fighting'] === null ? null : find_foe($me, '');
    if (!$foe) {
        return ["You're not in a fight. To walk elsewhere: north, south, east, west, up or down."];
    }
    $sq = board_square($me, $to);
    if ($sq === null) {
        return ['Move where? Name a square on the board, like b3 (a1 is the top left).'];
    }
    if (!open_square(board((int) $me['room_id']), $sq)) {
        return [square($sq) . ' is blocked: rock or a tree.'];
    }
    foreach (fight_monsters($me) as $m) {
        if ($sq === [$m['x'], $m['y']]) {
            return ["The {$m['name']} is there."];
        }
    }
    if ($sq === hire_square($me) || in_array($sq, crowd($me), true)) {
        return ['Someone is there.'];
    }
    $lines = [];
    $room = (int) $me['room_id'];
    if (($wait = my_turn($me, $lines)) !== null || $me['fighting'] === null) {
        return tell_lines($me, $room, $lines, $wait);
    }
    $foe = find_foe($me, '');
    if (!$foe || begin_turn($me, $foe, $lines)) {
        return tell_lines($me, $room, $lines);
    }
    $left = $me['speed'] - $me['moved'];
    $cost = paths($room, my_square($me), [...monster_squares($me), ...crowd($me),
        ...array_filter([hire_square($me)])])[implode(',', $sq)][0] ?? null;
    if ($cost === null || $cost > $left) {
        return tell_lines($me, $room, $lines, $cost === null ? "You can't get to " . square($sq) . ' from here.'
            : "You have $left ft of movement left this turn, and " . square($sq) . " is $cost ft away.");
    }
    if (!walk($me, $foe, $sq, $left, $lines)) {
        $lines[] = 'You move to ' . square(my_square($me)) . ', ' . feet(my_square($me), [$foe['x'], $foe['y']])
            . " ft from the {$foe['name']}.";
        $lines[] = fight_status($me, $foe);
    }
    return tell_lines($me, $room, $lines);
}

/** The line closing a round: everyone's HP. */
function fight_status(array $me, array $foe): string
{
    $h = $me['hireling'];
    $all = fight_monsters($me) ?: [$foe];
    usort($all, fn ($a, $b) => $a['id'] <=> $b['id']);
    return "You: {$me['hp']}/{$me['max_hp']} HP.  " . ($h ? "{$h['name']}: {$h['hp']}/{$h['max_hp']} HP.  " : '')
        . implode('  ', array_map(fn ($m) => "{$m['name']}: {$m['hp']} HP.", $all));
}

/**
 * Rolls Initiative against $foe, opening with the line $opening, and clears what's left of earlier fights. Hidden from
 * it, I have Advantage on Initiative and my first attack roll (Invisible, SRD 5.2.1 p.184), and it's surprised unless
 * already fighting: Disadvantage on its Initiative (p.13). Either way I'm seen, and stop sneaking. shortcut: the
 * Advantage waits for my next attack roll even if I open with a spell that has none.
 */
function start_fight(array &$me, array &$foe, array &$lines, string $opening): void
{
    $others = (bool) q('SELECT 1 FROM users WHERE fighting = ? AND id <> ?', [$foe['id'], $me['id']])->fetch();
    $room = (int) $me['room_id'];
    // With another hero fighting here, I join their fight, in its order: its monsters keep their Initiative.
    $crowd = (bool) q('SELECT 1 FROM users WHERE room_id = ? AND fighting IS NOT NULL AND id <> ?',
        [$room, $me['id']])->fetch();
    $joining = $crowd && $foe['init'] !== null;
    $unseen = hidden_from($me, $foe);
    $surprised = $unseen && !$others && !$joining;
    $mine = d20((int) $unseen, lucky($me)) + mod($me['dexterity']) + (has_feat($me, 'alert') ? $me['prof_bonus'] : 0);
    $theirs = $joining ? $foe['init'] : d20($surprised ? -1 : 0) + $foe['initiative'];
    $me['foe_first'] = $theirs > $mine; // ties go to the hero
    $me['fighting'] = $foe['id'];
    $me['advantage'] = $me['advantage'] || $unseen;
    // Where we stand: I'm where I came in or last moved, and it where it last stood, or if that's stale (or it never
    // fought) at its board's letter, if the board was drawn by hand, else across the board from me (at the far end of
    // my row if I'm mid-board), or the open square nearest that. I stand clear of the other heroes and monsters.
    [$w, $h] = board_size($room);
    $spot = my_square($me);
    $busy = [...crowd($me), ...array_values(array_map(fn ($m) => [$m['x'], $m['y']], array_filter(fight_monsters($me),
        fn ($m) => $m['id'] !== $foe['id'] && $m['x'] !== null)))];
    if ($joining && (in_array($spot, $busy, true) || $spot === [$foe['x'], $foe['y']])) {
        $spot = open_near($room, $spot, [...$busy, [$foe['x'], $foe['y']]]);
    }
    if ($foe['x'] === null || ($foe['stale'] && !$others)) {
        $across = [$w - 1 - $spot[0], $h - 1 - $spot[1]];
        [$foe['x'], $foe['y']] = mark($room) ?? (feet($across, $spot) >= 10 ? $across
            : [$spot[0] < $w / 2 ? $w - 1 : 0, $spot[1]]);
    }
    if (!$joining && ([$foe['x'], $foe['y']] === $spot || !open_square(board($room), [$foe['x'], $foe['y']]))) {
        [$foe['x'], $foe['y']] = open_near($room, [$foe['x'], $foe['y']], [$spot, ...$busy]);
    }
    [$me['x'], $me['y'], $me['init'], $foe['init'], $me['moved']] = [...$spot, $mine, $theirs, null];
    // With no other hero fighting here, the room's last fight is over: its monsters are out of it, and the first
    // turn is mine.
    if (!$crowd) {
        q('UPDATE spawns SET init = NULL WHERE room_id = ?', [$room]);
        q('UPDATE rooms SET turn = ?, turn_at = NOW() WHERE id = ?', [$me['id'], $room]);
    }
    q('UPDATE spawns SET x = ?, y = ?, placed_at = NOW(), init = ? WHERE id = ?',
        [$foe['x'], $foe['y'], $theirs, $foe['id']]);
    // Every other aggressive monster here that sees me joins in (not one more than 5 levels below me, as arrive()),
    // where it stood, or by the first.
    $taken = [$spot, [$foe['x'], $foe['y']], ...$busy];
    $joined = [];
    foreach (room_monsters($room) as $m) {
        if ($m['id'] === $foe['id'] || ($m['init'] !== null && $crowd) || !$m['aggressive'] || hidden_from($me, $m)
            || $me['level'] > (float) $m['cr'] + 5) {
            continue;
        }
        $init = d20(0) + $m['initiative'];
        $at = $m['x'] !== null && !$m['stale'] && !in_array([$m['x'], $m['y']], $taken, true) ? [$m['x'], $m['y']]
            : open_near($room, [$foe['x'], $foe['y']], $taken);
        $taken[] = $at;
        q("UPDATE spawns SET init = ?, x = ?, y = ?, placed_at = NOW(), conditions = IF(?, conditions, ''),
           grappled_by = IF(?, grappled_by, NULL) WHERE id = ?", [$init, ...$at, (int) $crowd, (int) $crowd, $m['id']]);
        $joined[$m['id']] = $init;
    }
    if ($me['hireling'] !== null) { // my hireling starts beside me, nearest the foe
        $there = beside($room, $spot, [$foe['x'], $foe['y']], $taken);
        [$me['hireling']['x'], $me['hireling']['y']] = $there;
        q('UPDATE users SET hireling_x = ?, hireling_y = ? WHERE id = ?', [$there[0], $there[1], $me['id']]);
    }
    q(
        'UPDATE users SET fighting = ?, foe_first = ?, advantage = ?, sneaking = FALSE, stealth = NULL, resting = NULL,
         rest_until = NULL, x = ?, y = ?, init = ?, moved = NULL WHERE id = ?',
        [$foe['id'], (int) $me['foe_first'], (int) $me['advantage'], $spot[0], $spot[1], $mine, $me['id']]
    );
    $lines[] = $joining ? "You join the fight (Initiative $mine" . with($unseen ? 1 : 0) . ').'
        . ($me['sneaking'] ? ' You stop sneaking.' : '')
        : $opening . ($surprised ? " It's surprised." : '') . ' Initiative: you ' . $mine . with($unseen ? 1 : 0)
        . ", it $theirs" . with($surprised ? -1 : 0) . '.' . ($me['sneaking'] ? ' You stop sneaking.' : '');
    foreach (fight_monsters($me) as $m) {
        if (isset($joined[$m['id']])) {
            $lines[] = "The {$m['name']} joins the fight (Initiative {$joined[$m['id']]}).";
        }
    }
    [$me['sneaking'], $me['stealth']] = [false, null];
    if ($me['class'] === 'monk' && $me['level'] >= 2) {
        focus_on_initiative($me, $lines);
    }
    if ($me['class'] === 'bard' && $me['level'] >= 18 && $me['uses_spent'] > max(0, $me['uses'] - 2)) {
        $me['uses_spent'] = max(0, $me['uses'] - 2); // Superior Inspiration
        q('UPDATE users SET uses_spent = ? WHERE id = ?', [$me['uses_spent'], $me['id']]);
        $lines[] = 'Superior Inspiration: you have ' . ($me['uses'] - $me['uses_spent']) . ' Bardic Inspiration.';
    }
    // My effects from earlier fights now last only their own time, even if I'm back against the same spawn.
    q('UPDATE effects SET during = NULL WHERE caster_id = ? AND during IS NOT NULL', [$me['id']]);
    $foe['effects'] = effects_on('spawn_id', $foe['id']);
    // What's left on it from fights nobody is still in has worn off, and my own old hold is gone.
    if (!$others && !$joining) {
        [$foe['conditions'], $foe['grappled_by']] = ['', null];
    } elseif ($foe['grappled_by'] === $me['id']) {
        $foe['grappled_by'] = null;
    }
    q('UPDATE spawns SET conditions = ?, grappled_by = ? WHERE id = ?',
        [$foe['conditions'], $foe['grappled_by'], $foe['id']]);
}

/** My turn starts: every condition modeled so far ends here. */
function start_turn(array &$me): void
{
    if ($me['conditions'] !== '') {
        $me['conditions'] = '';
        q("UPDATE users SET conditions = '' WHERE id = ?", [$me['id']]);
    }
}

/**
 * Flees: I Dash for an exit ($dir, or the nearest) and out of the room, the fight over, if what's left of my Speed and
 * the Dash get me there; else my turn ends short of it. A step out of my foe's reach draws its opportunity attack
 * unless I Disengage: a Rogue's Cunning Action or a Monk's Step of the Wind from level 2, or a Cloud Goliath's Cloud's
 * Jaunt, which also carries me 30 ft (a use is spent only when it's needed). A foe that can't act can't stop me. It
 * stays where it is (see start_fight()).
 */
function cmd_flee(array $me, string $dir = ''): array
{
    $foe = $me['fighting'] === null ? null : find_foe($me, '');
    if (!$foe) {
        return ["You aren't fighting anything."];
    }
    $room = (int) $me['room_id'];
    $dir = DIRS[strtolower($dir)] ?? strtolower($dir);
    $exits = q('SELECT dir FROM exits WHERE room_id = ?', [$room])->fetchAll(PDO::FETCH_COLUMN);
    if ($exits === [] || ($dir !== '' && !in_array($dir, $exits, true))) {
        return [$exits === [] ? "There's no way out of here." : "You can't go $dir."];
    }
    $lines = [];
    if (($wait = my_turn($me, $lines)) !== null || $me['fighting'] === null) {
        return tell_lines($me, $room, $lines, $wait);
    }
    $foe = find_foe($me, '');
    if (!$foe || begin_turn($me, $foe, $lines)) {
        return tell_lines($me, $room, $lines);
    }
    $away = fn ($d) => feet(my_square($me), exit_square($room, $d));
    if ($dir === '') {
        usort($exits, fn ($a, $b) => $away($a) <=> $away($b));
        $dir = $exits[0];
    }
    $held = has($foe, 'paralyzed') || has($foe, 'stunned') || has($foe, 'incapacitated');
    $free = slips($me) || $held;
    $near = feet(my_square($me), [$foe['x'], $foe['y']]) <= $foe['reach'];
    $jaunt = !$free && $near && $me['species'] === 'goliath' && $me['lineage'] === 'cloud' && spend_species($me);
    if ($jaunt) {
        $lines[] = "Cloud's Jaunt: you vanish and step out of the air 30 feet away.";
    }
    $to = exit_square($room, $dir);
    if (walk($me, $foe, $to, 2 * $me['speed'] - $me['moved'] + ($jaunt ? 30 : 0), $lines, 0, $free || $jaunt)) {
        return tell_lines($me, $room, $lines);
    }
    if (my_square($me) === $to) {
        // My turn ends with me gone: it's the next hero's. shortcut: monsters between us skip this round.
        $heroes = array_column(fight_heroes($room), 'id');
        $next = $heroes[(array_search($me['id'], $heroes, true) + 1) % count($heroes)];
        q('UPDATE rooms SET turn = ?, turn_at = NOW() WHERE id = ?', [$next === $me['id'] ? null : $next, $room]);
        q('UPDATE users SET ' . END_FIGHT . ' WHERE id = ?', [$me['id']]);
        $lines[] = "You flee $dir from the {$foe['name']}!";
        return [...tell_lines($me, $room, $lines), ...cmd_move(['fighting' => null] + $me, $dir)];
    }
    $lines[] = "You run for the $dir exit, " . feet(my_square($me), $to) . ' ft short.';
    if (!end_turn($me, $foe, $lines)) {
        $lines[] = fight_status($me, $foe);
    }
    return tell_lines($me, $room, $lines);
}

/**
 * My weapon attacks on $foe this round (more than one with Extra Attack), then any off-hand attack and a Monk's
 * Unarmed Strikes (see cmd_technique() for what Focus adds). Returns true if it died. Rage adds damage to
 * Strength attacks. A Rogue's Sneak Attack, once a turn with a Finesse or Ranged weapon, needs Advantage or another
 * hero on the same foe; Steady Aim gives them Advantage from level 3 (in a fight you never move). A Vex weapon's hit
 * gives Advantage on the next attack, and a Topple weapon's hit can knock it Prone (shortcut: MASTERIES are modeled for
 * any weapon you wield, not the few your class picks); Slow takes 10 ft off its Speed, and Push shoves it 10 ft. A
 * ranged attack has Disadvantage within 5 ft of my foe or past its normal range; from afar ('far', set by fight())
 * only thrown, shot or Reach weapons attack.
 */
function hero_turn(array &$me, array &$foe, array &$lines): bool
{
    $swings = array_fill(0, $me['attacks'], $me);
    if ($me['offhand'] !== null && $me['attacks'] > 0 && (!$me['far'] || $me['offhand']['ranged'])) {
        $swings[] = ['off' => true] + $me['offhand'];
    }
    // A Monk's Bonus Action Unarmed Strike (Martial Arts), or Flurry of Blows' two or three, already paid for.
    $flurry = $me['flurry'] ?? 0;
    if (($me['arts'] || $flurry) && !$me['far']) {
        for ($i = 0; $i < max(1, $flurry); $i++) {
            $swings[] = ['strike' => $flurry ? 'flurry' : 'bonus'] + $me['unarmed'];
        }
    }
    $sneak = $me['class'] === 'rogue';
    $colossus = $me['class'] === 'ranger' && $me['level'] >= 3; // Colossus Slayer, once a turn
    $primal = $me['class'] === 'druid' && $me['level'] >= 7; // Primal Strike, once a turn
    $cleaved = false;
    $savage = has_feat($me, 'savage') && $me['shape'] === null; // Savage Attacker, once a turn, with a weapon
    $helped = $sneak && q('SELECT 1 FROM users WHERE fighting = ? AND id <> ?', [$foe['id'], $me['id']])->fetch();
    foreach ($swings as $i => $w) {
        $off = isset($w['off']);
        // Light: the extra attack takes my Bonus Action, unless its weapon's Nick makes it part of the Attack action.
        if ((($off && $w['mastery'] !== 'nick') || ($w['strike'] ?? '') === 'bonus') && !spend_bonus($me)) {
            break;
        }
        $aim = ($me['class'] === 'rogue' && $me['level'] >= 3) || (has($me, 'reckless') && $w['str_based']);
        $dis = $w['ranged'] && ($me['pressed'] || $me['dist'] > ($w['normal_range'] ?? PHP_INT_MAX));
        [$d20, $edge, $bonus, $with] = attack_d20($me, $foe, $aim, $dis);
        $total = $d20 + $w['to_hit'] + $bonus;
        [$you, $s] = match (true) {
            $off => ["Your off-hand {$w['weapon']}", 'es'],
            isset($w['strike']) => ['Your Unarmed Strike', 'es'],
            default => ['You', ''],
        };
        if (misses($me, $d20, $total, $foe['ac'], $with)) {
            $why = $d20 === 1 ? 'natural 1' : "$total vs AC {$foe['ac']}";
            $lines[] = "$you miss$s the {$foe['name']} ($why$with).";
            if ($w['mastery'] === 'graze' && $w['attack_mod'] > 0) { // the modifier only: no Rage, no dice
                [$dmg, $note] = typed($w['attack_mod'], $w['damage_type'], $foe);
                $lines[] = "The blow grazes it for $dmg damage$note.";
                if (hurt_foe($me, $foe, $dmg, $lines)) {
                    return true;
                }
            }
            continue;
        }
        // Paralyzed: a hit from within 5 feet is a Critical Hit.
        $crit = $d20 === 20 || (has($foe, 'paralyzed') && !$me['far']);
        $rage = $me['raging'] && $w['str_based'] ? $me['rage_damage'] : 0;
        // The extra attack adds no ability modifier unless it's negative (shortcut: no Two-Weapon Fighting style yet).
        $dice = roll($w['damage'], $crit);
        if ($savage && !isset($w['strike'])) {
            [$dice, $savage] = [max($dice, roll($w['damage'], $crit)), false];
        }
        $dmg = $dice + ($off ? min(0, $w['attack_mod']) : $w['attack_mod']) + $rage;
        $sneaking = $sneak && ($w['finesse'] || $w['ranged']) && ($edge > 0 || $helped);
        if ($sneaking) {
            $dmg += roll(intdiv($me['level'] + 1, 2) . 'd6', $crit);
            $sneak = false;
        }
        $typed = typed(max(0, $dmg), $w['damage_type'], $foe);
        if ($w['weapon'] === 'Unarmed Strike' && $me['class'] === 'monk' && $me['level'] >= 6) { // Empowered Strikes
            $force = typed(max(0, $dmg), 'force', $foe);
            $typed = $force[0] > $typed[0] ? [$force[0], $force[1] . ' (force)'] : $typed;
        }
        [$dmg, $note] = $typed;
        $melee = !$w['ranged'];
        $slayer = $colossus && $foe['hp'] < $foe['max_hp'];
        $adds = $slayer ? [['1d8', $w['damage_type'], 'Colossus Slayer']] : [];
        if ($primal) {
            $type = primal_type($foe);
            $adds[] = [$me['level'] >= 15 ? '2d8' : '1d8', $type, "$type Primal Strike"];
        }
        if (($me['beast_extra'] ?? null) !== null) { // a Giant Spider's venom
            $adds[] = [...$me['beast_extra'], $me['beast_extra'][1]];
        }
        foreach (extra_damage($me, $foe, $melee, $crit, $adds) as [$more, $more_note]) {
            $dmg += $more;
            $note .= $more_note;
        }
        [$colossus, $primal] = [$colossus && !$slayer, false];
        $lines[] = ($crit ? 'Critical hit! ' : '') . "$you hit" . ($s === '' ? '' : 's') . " the {$foe['name']} "
            . "($total vs AC {$foe['ac']}$with) for $dmg damage$note" . ($sneaking ? ', with Sneak Attack.' : '.');
        if (($me['smite'] ?? null) !== null && ($melee || $me['smite']['ranged'])) {
            $dmg += smite($me, $foe, $crit, $lines);
        }
        if (hurt_foe($me, $foe, $dmg, $lines)) {
            return true;
        }
        if ($me['species'] === 'goliath' && $me['lineage'] === 'hill' && !has($foe, 'prone')
            && array_search($foe['size'], SIZES, true) <= array_search('large', SIZES, true) && spend_species($me)) {
            $lines[] = "Hill's Tumble: the {$foe['name']} goes down, Prone.";
            set_condition($foe, 'prone');
        }
        if (($me['stun'] ?? false) && $w['monk'] && spend_uses($me)) { // Stunning Strike, on my first such hit
            $me['stun'] = false;
            $dc = focus_dc($me);
            [$save, $saved] = save($foe, 'con', $dc);
            $roll = 'Con save ' . vs($save, $dc);
            $lines[] = $saved
                ? "The {$foe['name']} reels but isn't Stunned ($roll); the next attack on it has Advantage."
                : "The {$foe['name']} is Stunned until your next turn ($roll)!";
            set_condition($foe, $saved ? 'reeling' : 'stunned');
        }
        if (($w['strike'] ?? '') === 'flurry' && $me['level'] >= 3 && !has($foe, 'prone')) { // Open Hand Technique
            $dc = focus_dc($me);
            [$save, $saved] = save($foe, 'dex', $dc);
            $lines[] = $saved ? "The {$foe['name']} keeps its feet (Dex save " . vs($save, $dc) . ').'
                : "Your open hand topples the {$foe['name']}, Prone (Dex save " . vs($save, $dc) . ').';
            if (!$saved) {
                set_condition($foe, 'prone');
            }
        }
        if ($w['mastery'] === 'topple' && !has($foe, 'prone')) {
            $dc = 8 + $w['attack_mod'] + $me['prof_bonus'];
            [$save, $saved] = save($foe, 'con', $dc);
            $lines[] = $saved ? "The {$foe['name']} keeps its feet (Con save " . vs($save, $dc) . ').'
                : "The {$foe['name']} topples over, Prone (Con save " . vs($save, $dc) . ').';
            if (!$saved) {
                set_condition($foe, 'prone');
            }
        }
        if ($w['mastery'] === 'sap' && !has($foe, 'sapped')) {
            set_condition($foe, 'sapped');
        }
        if ($w['mastery'] === 'cleave' && !$w['ranged'] && !$cleaved) {
            $cleaved = true;
            cleave($me, $w, $foe, $lines);
        }
        if ($w['mastery'] === 'vex' && !$me['advantage']) {
            $me['advantage'] = true;
            q('UPDATE users SET advantage = TRUE WHERE id = ?', [$me['id']]);
        }
        if ($w['mastery'] === 'slow' && $dmg > 0 && !has($foe, 'slowed')) {
            set_condition($foe, 'slowed');
            $lines[] = "Slow: the {$foe['name']}'s Speed drops 10 ft until your next turn.";
        }
        // Push on my last attack of the turn, so it never carries the foe out of reach of my next one
        if ($w['mastery'] === 'push' && $i === array_key_last($swings)
            && array_search($foe['size'], SIZES, true) <= array_search('large', SIZES, true)) {
            push($me, $foe, $lines);
        }
    }
    return false;
}

/** Push: my foe goes up to 10 ft straight away from me, as far as the board allows. */
function push(array $me, array &$foe, array &$lines): void
{
    $rows = board((int) $me['room_id']);
    $mine = my_square($me);
    $from = [$foe['x'], $foe['y']];
    $at = $from;
    for ($n = 0; $n < 2; $n++) {
        $next = [$at[0] + ($at[0] <=> $mine[0]), $at[1] + ($at[1] <=> $mine[1])];
        $crowd = [hire_square($me), ...monster_squares($me, $foe['id']), ...crowd($me)];
        if (!open_square($rows, $next) || in_array($next, $crowd, true)) {
            break;
        }
        $at = $next;
    }
    if ($at !== $from) {
        [$foe['x'], $foe['y']] = $at;
        q('UPDATE spawns SET x = ?, y = ?, placed_at = NOW() WHERE id = ?', [$at[0], $at[1], $foe['id']]);
        $lines[] = "Push: the {$foe['name']} is pushed " . feet($at, $from) . ' ft back.';
    }
}

/**
 * Cleave: after a melee hit, one attack a turn with the same weapon at a second monster here (shortcut: no
 * positions, so every monster in the room is within 5 feet of the first). Its damage adds no ability modifier unless
 * negative. The second monster doesn't join the fight.
 */
function cleave(array &$me, array $w, array $foe, array &$lines): void
{
    $other = array_values(array_filter(room_monsters((int) $me['room_id']), fn ($m) => $m['id'] !== $foe['id']))[0]
        ?? null;
    if ($other === null) {
        return;
    }
    $aim = has($me, 'reckless') && $w['str_based'];
    [$d20, $edge, $bonus, $with] = attack_d20($me, $other, $aim);
    $total = $d20 + $w['to_hit'] + $bonus;
    if (misses($me, $d20, $total, $other['ac'], $with)) {
        $why = $d20 === 1 ? 'natural 1' : "$total vs AC {$other['ac']}";
        $lines[] = "You cleave at the {$other['name']} and miss ($why$with).";
        return;
    }
    $rage = $me['raging'] && $w['str_based'] ? $me['rage_damage'] : 0;
    $dmg = roll($w['damage'], $d20 === 20) + min(0, $w['attack_mod']) + $rage;
    [$dmg, $note] = typed(max(0, $dmg), $w['damage_type'], $other);
    $lines[] = ($d20 === 20 ? 'Critical hit! ' : '') . "You cleave into the {$other['name']} ($total vs AC "
        . "{$other['ac']}$with) for $dmg damage$note.";
    hurt_foe($me, $other, $dmg, $lines);
}

/** Spends my Bonus Action for this round of a fight, or returns false if it's spent. Outside a fight it's free. */
function spend_bonus(array &$me): bool
{
    if ($me['fighting'] === null) {
        return true;
    }
    if ($me['bonus_used']) {
        return false;
    }
    $me['bonus_used'] = true;
    q('UPDATE users SET bonus_used = TRUE WHERE id = ?', [$me['id']]);
    return true;
}

/**
 * The d20 for my attack roll at $foe, its edge, any dice my effects add (Bless), and how a combat line says those:
 * [d20, edge, bonus, note]. The edge is 1 with Advantage ($adv, my saved one, a Paralyzed, Stunned, Blinded,
 * Restrained or outlined (Shining Smite) foe, a reeling one (used up), a Prone foe next to me, or my quarry once I'm a
 * level 17 Ranger), -1 with Disadvantage ($dis, or a Prone foe shot at from afar), 0 with neither or both.
 */
function attack_d20(array &$me, array &$foe, bool $adv = false, bool $dis = false): array
{
    $prone = has($foe, 'prone');
    $far = $me['far'] ?? false;
    $adv = $adv || ($prone && !$far) || has($foe, 'paralyzed') || has($foe, 'stunned')
        || has($foe, 'blinded') || has($foe, 'reeling') || has($foe, 'outlined') || has($foe, 'restrained')
        || ($me['class'] === 'ranger' && $me['level'] >= 17 && marking($me)); // Precise Hunter
    if (has($foe, 'reeling')) { // a Stunning Strike it saved against: only the next attack roll
        set_condition($foe, 'reeling', false);
    }
    $dis = $dis || ($prone && $far);
    if ($me['advantage']) { // used up by this roll
        $adv = true;
        $me['advantage'] = false;
        q('UPDATE users SET advantage = FALSE WHERE id = ?', [$me['id']]);
    }
    $edge = $adv <=> $dis;
    $d20 = d20($edge, lucky($me));
    [$bonus, $note] = d20_bonus($me);
    return [$d20, $edge, $bonus, with($edge) . $note];
}

/**
 * True if my attack roll misses AC $ac: a natural 1, or a total under it that isn't a natural 20, once a Human's
 * Heroic Inspiration has rerolled $d20 and inspired() has had its chance to add to $total and the line's note $with.
 */
function misses(array &$me, int &$d20, int &$total, int $ac, string &$with): bool
{
    if ($d20 !== 20 && ($d20 === 1 || $total < $ac) && $me['species'] === 'human' && spend_species($me)) {
        $new = roll('1d20');
        [$total, $with, $d20] = [$total - $d20 + $new, "$with, Heroic Inspiration rerolls a $d20", $new];
    }
    if ($d20 === 1 || $d20 === 20 || $total >= $ac) {
        return $d20 === 1;
    }
    [$add, $note] = inspired($me, $ac - $total);
    [$total, $with] = [$total + $add, $with . $note];
    return $total < $ac;
}

/**
 * $who (a hero) failed a D20 Test by $short: a Bardic Inspiration die they hold rolls and adds, when it could make up
 * the difference (it's spent once rolled). Failing that, an attack roll may use a Lore Bard's Peerless Skill (14): one
 * of their own uses, spent only if its die makes up the difference. Returns [what it adds, a note for the line].
 */
function inspired(array &$who, int $short, bool $attack = true): array
{
    foreach ($who['effects'] ?? [] as $i => $e) {
        // Deleting first means a copy of $who that still lists the die (save() takes one) can't spend it twice.
        if ($e['effect'] === 'inspiration' && $short <= (int) substr($e['value'], 2)
            && q('DELETE FROM effects WHERE id = ?', [$e['id']])->rowCount() === 1) {
            unset($who['effects'][$i]);
            $n = roll($e['value']);
            return [$n, ", +$n Bardic Inspiration"];
        }
    }
    $peerless = $attack && ($who['class'] ?? '') === 'bard' && $who['level'] >= 14;
    if ($peerless && $who['uses_spent'] < $who['uses'] && $short <= $who['bardic']) {
        $n = roll('1d' . $who['bardic']);
        if ($n >= $short && spend_uses($who)) {
            return [$n, ", +$n Peerless Skill"];
        }
    }
    return [0, ''];
}

/** The dice $who's effects add to a d20 test, rolled (Bless +1d4, Bane -1d4), and a note like ', +3 Bless'. */
function d20_bonus(array $who): array
{
    [$sum, $note] = [0, ''];
    foreach ($who['effects'] ?? [] as $e) {
        if ($e['effect'] === 'd20') {
            $n = str_starts_with($e['value'], '-') ? -roll(substr($e['value'], 1)) : roll($e['value']);
            $sum += $n;
            $note .= sprintf(', %+d %s', $n, $e['spell']);
        }
    }
    return [$sum, $note];
}

/**
 * A d20 for a D20 Test: with Advantage (edge 1) the higher of two, with Disadvantage (-1) the lower. A $lucky one
 * rerolls each 1 and keeps the new roll (Halfling Luck).
 */
function d20(int $edge, bool $lucky = false): int
{
    $die = fn (): int => ($n = roll('1d20')) === 1 && $lucky ? roll('1d20') : $n;
    return match ($edge) {
        1 => max($die(), $die()),
        -1 => min($die(), $die()),
        default => $die(),
    };
}

/** True if $who (a hero or a monster) has Halfling Luck. */
function lucky(array $who): bool
{
    return ($who['species'] ?? null) === 'halfling';
}

/** How a combat line says a roll's edge. */
function with(int $edge): string
{
    return [-1 => ', with Disadvantage', 0 => '', 1 => ', with Advantage'][$edge];
}

/**
 * A saving throw by $who, a hero or a monster, with its 'str' to 'cha' save against $dc: [the total (null when it
 * fails automatically), whether it saved, its edge]. No natural 1 or 20 (SRD 5.2.1 p.6). Paralyzed and Stunned fail
 * Str and Dex saves. Rage gives Advantage on Str saves, and a Barbarian's Danger Sense (level 2) on Dex saves unless
 * Incapacitated; Restrained gives Disadvantage on Dex saves. $force adds Advantage (1: Eldritch Mind) or Disadvantage
 * (-1: Heightened Spell), and Gnomish Cunning on Int, Wis and Cha saves. Effects like Bless add their dice, and a hero
 * who fails may reroll the d20 with Heroic Inspiration, be inspired(), then use a Fiend Warlock's Dark One's Own Luck
 * (6): 1d10 more, when it could make the difference.
 */
function save(array $who, string $ability, int $dc, int $force = 0): array
{
    if (in_array($ability, ['str', 'dex'], true) && (has($who, 'paralyzed') || has($who, 'stunned'))) {
        return [null, false, 0];
    }
    $barbarian = ($who['class'] ?? null) === 'barbarian';
    $edge = ($force > 0 || ($ability === 'str' && ($who['raging'] ?? false))
        || ($ability === 'dex' && $barbarian && $who['level'] >= 2 && !has($who, 'paralyzed'))
        || (in_array($ability, ['int', 'wis', 'cha'], true) && ($who['species'] ?? null) === 'gnome'))
        <=> ($force < 0 || ($ability === 'dex' && has($who, 'restrained')));
    $d20 = d20($edge, lucky($who));
    $total = $d20 + $who[$ability . '_save'] + d20_bonus($who)[0];
    if ($total < $dc && ($who['species'] ?? null) === 'human' && spend_species($who)) {
        $total += roll('1d20') - $d20;
    }
    if ($total < $dc && isset($who['class'])) {
        $total += inspired($who, $dc - $total, false)[0];
    }
    // The UPDATE spends a use only if one is left: $who is a copy, maybe behind an earlier save this command.
    if ($total < $dc && $dc - $total <= 10 && ($who['class'] ?? '') === 'warlock' && $who['level'] >= 6 && q(
        'UPDATE users SET uses_spent = uses_spent + 1 WHERE id = ? AND uses_spent < ?',
        [$who['id'], $who['uses']]
    )->rowCount() === 1) {
        $total += roll('1d10');
    }
    return [$total, $total >= $dc, $edge];
}

/** A save's roll for a combat line: '11 vs DC 12', or that it failed automatically. */
function vs(?int $total, int $dc): string
{
    return $total === null ? "automatic fail, DC $dc" : "$total vs DC $dc";
}

/** True if $who (a hero or a monster) has $condition, its own or from a spell's effect. */
function has(array $who, string $condition): bool
{
    foreach ($who['effects'] ?? [] as $e) {
        if ($e['effect'] === 'condition' && $e['value'] === $condition) {
            return true;
        }
    }
    return in_array($condition, explode(',', $who['conditions']), true);
}

/** The live effects on a hero ($col 'user_id') or a monster ('spawn_id'), each with its spell's name. */
function effects_on(string $col, int $id): array
{
    $col = match ($col) { 'user_id' => 'user_id', 'spawn_id' => 'spawn_id' }; // the only two allowed in SQL
    return q(
        "SELECT e.*, s.name AS spell, s.damage_type, s.half, c.name AS caster FROM effects e
         JOIN users c ON c.id = e.caster_id
         JOIN spells s ON s.id = e.spell_id WHERE e.$col = ? AND " . LIVE_EFFECT . ' ORDER BY e.id',
        [$id]
    )->fetchAll();
}

/** The spell I'm concentrating on, or null. */
function concentrating(array $me): ?string
{
    $name = q(
        'SELECT s.name FROM effects e JOIN users c ON c.id = e.caster_id JOIN spells s ON s.id = e.spell_id
         WHERE e.caster_id = ? AND e.concentration AND ' . LIVE_EFFECT . ' LIMIT 1',
        [$me['id']]
    )->fetchColumn();
    return $name === false ? null : $name;
}

/** Ends my Concentration: every effect I hold with it, live or not, is gone. */
function end_concentration(array $me): void
{
    q('DELETE FROM effects WHERE caster_id = ? AND concentration', [$me['id']]);
}

/** Gives $foe (a spawn) $condition, or takes it away. */
function set_condition(array &$foe, string $condition, bool $on = true): void
{
    $set = array_diff(array_filter(explode(',', $foe['conditions'])), [$condition]);
    $foe['conditions'] = implode(',', $on ? [...$set, $condition] : $set);
    q('UPDATE spawns SET conditions = ? WHERE id = ?', [$foe['conditions'], $foe['id']]);
}

/**
 * shove or grapple (SRD 5.2.1 Unarmed Strike, p.190) in place of one attack of my turn; any others follow with my
 * weapon. The foe makes a Str or Dex save, the better of its two, against 8 + my Str (or a Monk's Dex) + PB. Failing a
 * shove, it's Prone; failing a grapple, it's Grappled by me, so it has Disadvantage attacking any other hero (Grappled
 * p.182).
 * The foe can be at most one size larger than me (shortcut: free hands aren't checked).
 * Shove's push and the foe escaping a grapple are left out: there are no distances, and monsters never try.
 */
function cmd_maneuver(array $me, string $how, string $target): array
{
    return fight($me, $target, function (array &$me, array &$foe, array &$lines) use ($how): bool {
        $done = $how === 'shove' ? has($foe, 'prone') : $foe['grappled_by'] === $me['id'];
        if ($done) {
            $lines[] = "The {$foe['name']} is already " . ($how === 'shove' ? 'Prone.' : 'in your grip.');
            return hero_turn($me, $foe, $lines);
        }
        if (array_search($foe['size'], SIZES, true) > array_search(origin($me)['size'] ?? 'medium', SIZES, true) + 1) {
            $lines[] = "The {$foe['name']} is too big to " . ($how === 'shove' ? 'shove.' : 'grab.');
            return hero_turn($me, $foe, $lines);
        }
        $dc = 8 + max(mod($me['strength']), $me['arts'] ? mod($me['dexterity']) : -5) + $me['prof_bonus'];
        $ability = $foe['str_save'] >= $foe['dex_save'] ? 'str' : 'dex';
        [$save, $saved] = save($foe, $ability, $dc);
        $roll = ucfirst($ability) . ' save ' . vs($save, $dc);
        if ($saved) {
            $lines[] = "The {$foe['name']} " . ($how === 'shove' ? 'stands firm' : 'slips your grip') . " ($roll).";
        } elseif ($how === 'shove') {
            $lines[] = "You shove the {$foe['name']} to the ground, Prone ($roll).";
            set_condition($foe, 'prone');
        } else {
            $lines[] = "You grab hold of the {$foe['name']}, Grappled ($roll).";
            $foe['grappled_by'] = $me['id'];
            q('UPDATE spawns SET grappled_by = ? WHERE id = ?', [$me['id'], $foe['id']]);
        }
        $me['attacks']--;
        return hero_turn($me, $foe, $lines);
    }, ucfirst($how) . ' what?');
}

/**
 * Breath Weapon (Dragonborn), in place of one attack of my round: a Dex save against 8 + Con + PB or 1d10 of my
 * ancestor's damage type (2d10 from level 5, 3d10 from 11, 4d10 from 17), half on a success; then my other attacks.
 * Proficiency Bonus uses a long rest. shortcut: it reaches only the foe I fight, as there are no positions for its
 * Cone or Line.
 */
function cmd_breathe(array $me, string $target): array
{
    if ($me['species'] !== 'dragonborn') {
        return ['Only Dragonborn have a Breath Weapon.'];
    }
    if ($me['species_spent'] >= $me['species_uses']) {
        return ['Your Breath Weapon is spent until you finish a long rest.'];
    }
    return fight($me, $target, function (array &$me, array &$foe, array &$lines): bool {
        if (spend_species($me)) {
            $dc = 8 + mod($me['constitution']) + $me['prof_bonus'];
            [$save, $saved] = save($foe, 'dex', $dc);
            $type = origin($me)['resist'];
            $dmg = roll((1 + intdiv($me['level'] + 1, 6)) . 'd10');
            [$dmg, $note] = typed($saved ? intdiv($dmg, 2) : $dmg, $type, $foe);
            $lines[] = "You breathe $type at the {$foe['name']} (Dex save " . vs($save, $dc)
                . ") for $dmg damage$note.";
            if (hurt_foe($me, $foe, $dmg, $lines)) {
                return true;
            }
            $me['attacks']--;
        }
        return hero_turn($me, $foe, $lines);
    }, 'Breathe on what?', 15); // a 15-ft Cone
}

/**
 * Reckless Attack (Barbarian 2): a round of attacks with Advantage on Strength attack rolls, but attack rolls against
 * me have Advantage until the start of my next turn.
 */
function cmd_reckless(array $me, string $target): array
{
    if ($me['class'] !== 'barbarian' || $me['level'] < 2) {
        return ['Only Barbarians of level 2 or more attack recklessly.'];
    }
    return fight($me, $target, function (array &$me, array &$foe, array &$lines): bool {
        $me['conditions'] = 'reckless'; // fight() just cleared the others
        q("UPDATE users SET conditions = 'reckless' WHERE id = ?", [$me['id']]);
        $lines[] = 'You throw aside all concern for defense!';
        return hero_turn($me, $foe, $lines);
    }, 'Kill what?', weapon_reach($me));
}

/**
 * $dmg of $type dealt to $who (a hero or monster with resistances, immunities and vulnerabilities as SET strings),
 * after SRD 5.2.1 p.17: Immunity takes it all, Resistance halves it (rounded down), then Vulnerability doubles it.
 * Returns [the damage, a note for the combat line].
 */
function typed(int $dmg, string $type, array $who): array
{
    $is = fn (string $key): bool => in_array($type, explode(',', $who[$key]), true);
    if ($is('immunities')) {
        return [0, ' (immune)'];
    }
    $note = '';
    if ($is('resistances')) {
        [$dmg, $note] = [intdiv($dmg, 2), ' (resisted)'];
    }
    if ($is('vulnerabilities')) {
        [$dmg, $note] = [$dmg * 2, $note . ' (vulnerable)'];
    }
    return [$dmg, $note];
}

/** Deals $dmg to $foe. Returns true if that killed it. */
function hurt_foe(array &$me, array &$foe, int $dmg, array &$lines): bool
{
    q('UPDATE spawns SET hp = hp - ? WHERE id = ?', [$dmg, $foe['id']]);
    $foe['hp'] -= $dmg;
    if ($foe['hp'] > 0) {
        return false;
    }
    // The kill goes to whoever marks it dead first, in case two heroes land the last blow at once.
    $won = q(
        'UPDATE spawns SET dead_since = NOW() WHERE id = ? AND dead_since IS NULL AND hp <= 0',
        [$foe['id']]
    )->rowCount() === 1;
    if ($won) {
        win($me, $foe, $lines);
    }
    return true;
}

/**
 * A kill: the stat block's XP and a little gold by CR, split evenly among the heroes fighting in the room (the gold
 * left over to the killer), levels, and maybe loot. Each hero whose target it was turns to the nearest monster left;
 * with none left, the fight is over.
 */
function win(array &$me, array $foe, array &$lines): void
{
    $room = (int) $me['room_id'];
    $party = q('SELECT id FROM users WHERE room_id = ? AND fighting IS NOT NULL AND id <> ?', [$room, $me['id']])
        ->fetchAll(PDO::FETCH_COLUMN);
    $cr = max(1, (int) ceil((float) $foe['cr']));
    $gold = roll($cr . 'd6');
    [$xp, $share] = [intdiv($foe['xp'], count($party) + 1), intdiv($gold, count($party) + 1)];
    award($me, $xp, $gold - $share * count($party), $lines, "The {$foe['name']} dies! ");
    foreach ($party as $id) {
        $them = with_stats(q('SELECT * FROM users WHERE id = ?', [$id])->fetch());
        $theirs = [];
        award($them, $xp, $share, $theirs);
        told($them, $theirs, $lines);
    }
    if ($me['class'] === 'warlock' && $me['level'] >= 3) { // Dark One's Blessing
        $me['temp_hp'] = max($me['temp_hp'], max(1, mod($me['charisma']) + $me['level']));
        q('UPDATE users SET temp_hp = ? WHERE id = ?', [$me['temp_hp'], $me['id']]);
        $lines[] = "Dark One's Blessing: you have {$me['temp_hp']} temporary HP.";
    }
    $item = roll('1d' . LOOT_CHANCE) === 1 ? loot((float) $foe['cr'], $room, $foe['gear']) : null;
    if ($item !== null) {
        $lines[] = "The {$foe['name']} drops " . lcfirst(a_an($item)) . '.';
    }
    $left = fight_monsters($me);
    if ($left === []) {
        q('UPDATE users SET ' . END_FIGHT . ' WHERE room_id = ? AND fighting IS NOT NULL', [$room]);
        q('UPDATE rooms SET turn = NULL WHERE id = ?', [$room]);
        $me['fighting'] = null;
        return;
    }
    foreach (q('SELECT id, room_id, x, y, init FROM users WHERE room_id = ? AND fighting = ?', [$room, $foe['id']])
        ->fetchAll() as $u) {
        $at = [$u['x'], $u['y']];
        usort($left, fn ($a, $b) => feet($at, [$a['x'], $a['y']]) <=> feet($at, [$b['x'], $b['y']]));
        $first = $left[0]['init'] > ($u['init'] ?? 0);
        q('UPDATE users SET fighting = ?, foe_first = ? WHERE id = ?', [$left[0]['id'], (int) $first, $u['id']]);
        $turn = ["You turn to the {$left[0]['name']}."];
        if ($u['id'] === $me['id']) {
            [$me['fighting'], $me['foe_first']] = [$left[0]['id'], $first];
            $lines[] = $turn[0];
        } else {
            told($u, $turn, $lines);
        }
    }
}

/**
 * $who gains $xp XP and $gold gold, and maybe a level (fixed HP). $who's XP and level are read fresh, as another
 * hero's kill may have just added to them.
 */
function award(array &$who, int $xp, int $gold, array &$lines, string $before = ''): void
{
    $was = q('SELECT xp, level FROM users WHERE id = ?', [$who['id']])->fetch();
    $total = $was['xp'] + $xp;
    // Never lower: a level set by hand (bin scripts, tests) can be ahead of the XP.
    $level = max($was['level'], (int) q('SELECT MAX(level) FROM levels WHERE xp <= ?', [$total])->fetchColumn());
    $gain = ($level - $was['level']) * (CLASSES[$who['class']]['per_level'] + mod($who['constitution'])); // fixed HP
    $tough = $who['species'] === 'dwarf' ? $level - $was['level'] : 0; // Dwarven Toughness: with_stats() adds the max
    q(
        'UPDATE users SET xp = ?, gold = gold + ?, level = ?, max_hp = max_hp + ?, hp = hp + ? WHERE id = ?',
        [$total, $gold, $level, $gain, $gain + $tough, $who['id']]
    );
    $gain += $tough;
    $lines[] = "{$before}You gain $xp XP and $gold gold.";
    if ($level > $was['level']) {
        $lines[] = "You reach level $level! +$gain max HP.";
    }
    // A Cleave can kill a second foe in the same turn, so later writes must start from these.
    [$who['xp'], $who['level'], $who['max_hp'], $who['hp']] = [$total, $level, $who['max_hp'] + $gain,
        $who['hp'] + $gain];
}

/**
 * $foe's turn: it moves (see advance()), then attacks me (in melee, on a 2 on a d2, my hireling instead) with its
 * Multiattack; or, with $n, its Reaction: $n melee attacks and no move. Returns true if the fight
 * is over: I died (and woke in town), or my Reaction killed it. A Stone or Storm Goliath's Reaction (Giant Ancestry)
 * goes on the first hit that hurts (shortcut: it doesn't count against Deflect Attacks' or Cutting Words'), and an
 * Orc's Relentless Endurance keeps them on 1 HP.
 */
function monster_turn(array &$me, array &$foe, array &$lines, ?int $n = null): bool
{
    // Grappled by a hero still fighting it, it can't move; Slow takes 10 ft off.
    $grappled = $foe['grappled_by'] !== null
        && q('SELECT 1 FROM users WHERE id = ? AND fighting = ?', [$foe['grappled_by'], $foe['id']])->fetch();
    $speed = $grappled ? 0 : max(0, $foe['speed'] - (has($foe, 'slowed') ? 10 : 0));
    if ($n === null && has($foe, 'reacted')) { // its turn: its Reaction is back
        set_condition($foe, 'reacted', false);
    }
    if ($n === null && has($foe, 'prone')) { // its turn starts (an opportunity attack is a Reaction, not a turn)
        set_condition($foe, 'prone', false);
        $lines[] = "The {$foe['name']} gets back up.";
        $speed = intdiv($speed, 2); // standing up costs half its Speed
    }
    if (has($foe, 'paralyzed') || has($foe, 'stunned') || has($foe, 'incapacitated')) { // no turn, no Reaction
        if ($n !== null) {
            return false;
        }
        $why = has($foe, 'paralyzed') ? 'Paralyzed' : (has($foe, 'stunned') ? 'Stunned' : 'halted');
        $lines[] = "The {$foe['name']} is $why and can't act.";
        foreach ($foe['effects'] as $i => $e) { // Command lasts that one turn
            if ($e['value'] === 'incapacitated') {
                q('DELETE FROM effects WHERE id = ?', [$e['id']]);
                unset($foe['effects'][$i]);
            }
        }
        return end_of_turn($me, $foe, $lines);
    }
    $how = $n === null ? advance($me, $foe, $speed, $lines) : 'melee';
    if ($how === 'dead' || $how === null) { // my hireling's opportunity attack killed it, or it Dashed
        return $how === 'dead' || end_of_turn($me, $foe, $lines);
    }
    $shot = $how === 'shoot';
    $a = $shot ? ['attack' => $foe['shot'], 'to_hit' => $foe['shot_hit'], 'damage' => $foe['shot_damage'],
        'damage_type' => $foe['shot_type'], 'extra_damage' => null, 'extra_type' => null] : $foe;
    $long = $shot && feet([$foe['x'], $foe['y']], my_square($me)) > $foe['shot_range']; // Disadvantage
    $cover = $shot ? sight((int) $me['room_id'], [$foe['x'], $foe['y']], my_square($me)) ?? 0 : 0;
    if ($cover) {
        $lines[] = 'You have half cover (+2 AC).';
    }
    $ac = $me['ac'] + $cover;
    // In melee it fights my hireling if that's all it can reach, or on a d2 if it can reach us both (an opportunity
    // attack is always at me).
    $hire = hire_square($me);
    $by_hire = $hire !== null && feet([$foe['x'], $foe['y']], $hire) <= 5;
    if ($n === null && !$shot && ($how === 'hireling'
        || ($by_hire && feet([$foe['x'], $foe['y']], my_square($me)) <= $foe['reach'] && roll('1d2') === 2))) {
        hit_hireling($me, $foe, $lines);
        return end_of_turn($me, $foe, $lines);
    }
    // Sanctuary (an acolyte's Divine Aid): a Wis save to attack me, or it turns on my hireling beside it, if any.
    $ward = array_values(array_filter($me['effects'], fn ($e) => $e['effect'] === 'condition'
        && $e['value'] === 'sanctuary'))[0] ?? null;
    if ($ward !== null) {
        [$save, $saved] = save($foe, 'wis', (int) $ward['dc']);
        if (!$saved) {
            $lines[] = "The {$foe['name']} can't bring itself to attack you (Sanctuary, Wis save "
                . vs($save, (int) $ward['dc']) . ').';
            if ($n === null && $by_hire && !$shot) {
                hit_hireling($me, $foe, $lines);
            }
            return $n === null && end_of_turn($me, $foe, $lines);
        }
    }
    // Held by another hero still fighting it, it has Disadvantage against me.
    $held = $grappled && $foe['grappled_by'] !== $me['id'];
    $deflect = $me['class'] === 'monk' && $me['level'] >= 3; // my Reaction, once a turn of theirs
    $cutting = $me['class'] === 'bard' && $me['level'] >= 3; // Cutting Words, my Reaction too
    $giant = $me['species'] === 'goliath' && in_array($me['lineage'], ['stone', 'storm'], true);
    $hit_me = false; // Multiattack Defense (Ranger 7): after one hit, its other attacks this turn have Disadvantage
    for ($i = 0; $i < ($n ?? $foe['attacks']); $i++) {
        $edge = has($me, 'reckless') <=> (has($foe, 'prone') || $held || has($foe, 'sapped') || has($foe, 'blinded')
            || has($foe, 'restrained') || has($me, 'dodging') || $hit_me || $long);
        if (has($foe, 'sapped')) { // Sap: only its next attack roll
            set_condition($foe, 'sapped', false);
        }
        $d20 = d20($edge);
        [$bonus, $note] = d20_bonus($foe); // Bane
        $total = $d20 + $a['to_hit'] + $bonus;
        $its = "The {$foe['name']}'s {$a['attack']}";
        $with = with($edge) . $note;
        if ($d20 === 1 || ($d20 < 20 && $total < $ac)) {
            $lines[] = "$its misses you (" . ($d20 === 1 ? 'natural 1' : "$total vs AC {$ac}") . "$with).";
            continue;
        }
        // Cutting Words: when my die could turn the hit, spend a Bardic Inspiration and take it off the roll.
        if ($cutting && $d20 < 20 && $total - $ac < $me['bardic'] && spend_uses($me)) {
            $cutting = false;
            $cut = roll('1d' . $me['bardic']);
            [$total, $with] = [$total - $cut, "$with, -$cut Cutting Words"];
            if ($total < $ac) {
                $lines[] = "$its misses you ($total vs AC {$ac}$with).";
                continue;
            }
        }
        [$dmg, $note] = typed(max(0, roll($a['damage'], $d20 === 20)), $a['damage_type'], $me);
        if ($a['extra_damage'] !== null) {
            [$extra, $extra_note] = typed(roll($a['extra_damage'], $d20 === 20), $a['extra_type'], $me);
            $dmg += $extra;
            $note = implode('', array_unique([$note, $extra_note]));
        }
        // Deflect Attacks (Monk 3): my Reaction cuts a Bludgeoning, Piercing or Slashing hit (any, from level 13).
        $deflected = 0;
        $bps = array_intersect([$a['damage_type'], $a['extra_type']], ['bludgeoning', 'piercing', 'slashing']);
        if ($deflect && $dmg > 0 && ($bps || $me['level'] >= 13)) {
            $deflect = false;
            $deflected = min($dmg, roll('1d10') + mod($me['dexterity']) + $me['level']);
            $dmg -= $deflected;
        }
        $endured = 0; // Stone's Endurance
        if ($giant && $dmg > 0 && $me['lineage'] === 'stone' && spend_species($me)) {
            [$giant, $endured] = [false, min($dmg, max(0, roll('1d12') + mod($me['constitution'])))];
            $dmg -= $endured;
        }
        $soaked = min($me['temp_hp'], $dmg); // Temporary Hit Points go first
        if ($soaked > 0) {
            $me['temp_hp'] -= $soaked;
            q('UPDATE users SET temp_hp = ? WHERE id = ?', [$me['temp_hp'], $me['id']]);
        }
        $me['hp'] -= $dmg - $soaked;
        $hit_me = $me['class'] === 'ranger' && $me['level'] >= 7;
        $lines[] = ($d20 === 20 ? 'Critical hit! ' : '') . "$its hits you ($total vs AC {$ac}$with) for $dmg "
            . "damage$note" . ($soaked ? " ($soaked to temporary HP)" : '')
            . ($deflected ? ", $deflected deflected" : '')
            . ($endured ? ", $endured shrugged off (Stone's Endurance)" : '') . '.';
        if ($deflected && $dmg === 0 && spend_uses($me) && redirect($me, $foe, $lines)) {
            q('UPDATE users SET hp = ? WHERE id = ?', [$me['hp'], $me['id']]);
            return true;
        }
        if ($me['hp'] <= 0 && $me['species'] === 'orc' && spend_species($me)) {
            $me['hp'] = 1;
            $lines[] = 'Relentless Endurance: you stay on your feet at 1 HP!';
        }
        if ($me['hp'] <= 0) {
            // Dragon Knight's death, kept for async play: back to town with a quarter of your HP and half your gold.
            $me['hp'] = (int) ceil($me['max_hp'] / 4);
            q(
                'UPDATE users SET hp = ?, temp_hp = 0, shape = NULL, gold = gold - gold DIV 2, ' . END_FIGHT
                    . ', sneaking = FALSE, stealth = NULL, room_id = ?, x = NULL, y = NULL WHERE id = ?',
                [$me['hp'], START_ROOM, $me['id']]
            );
            end_concentration($me);
            [$me['fighting'], $me['room_id'], $me['x'], $me['y']] = [null, START_ROOM, null, null];
            array_push($lines, 'You have died!', 'You wake in the town square, bruised and lighter of purse.');
            array_push($lines, ...array_map(fn ($l) => "\x02$l", // for me alone (see told())
                cmd_look(['room_id' => START_ROOM, 'fighting' => null] + $me)));
            return true;
        }
        if ($giant && $dmg > 0 && $me['lineage'] === 'storm' && spend_species($me)) { // Storm's Thunder
            $giant = false;
            [$thunder, $note] = typed(roll('1d8'), 'thunder', $foe);
            $lines[] = "Storm's Thunder: thunder cracks back at the {$foe['name']} for $thunder damage$note.";
            if (hurt_foe($me, $foe, $thunder, $lines)) {
                q('UPDATE users SET hp = ? WHERE id = ?', [$me['hp'], $me['id']]);
                return true;
            }
        }
        $relentless = $me['class'] === 'ranger' && $me['level'] >= 13; // Hunter's Mark holds
        if ($dmg > 0 && ($spell = concentrating($me)) !== null && !($relentless && $spell === "Hunter's Mark")) {
            $dc = min(30, max(10, intdiv($dmg, 2)));
            [$save, $saved] = save($me, 'con', $dc, $me['class'] === 'warlock' && $me['level'] >= 2 ? 1 : 0);
            $lines[] = $saved ? "You keep your Concentration on $spell (Con save " . vs($save, $dc) . ').'
                : "You lose your Concentration on $spell (Con save " . vs($save, $dc) . ').';
            if (!$saved) {
                end_concentration($me);
                $foe['effects'] = effects_on('spawn_id', $foe['id']);
                $me['effects'] = effects_on('user_id', $me['id']);
            }
        }
    }
    q('UPDATE users SET hp = ? WHERE id = ?', [$me['hp'], $me['id']]);
    return $n === null && end_of_turn($me, $foe, $lines);
}

/**
 * $foe's move on its turn, with $speed feet. With a ranged attack and nobody within 5 ft of it, it walks up to its
 * normal range and shoots me (with Disadvantage if it can't get that close). Else it goes for me in melee, unless my
 * hireling is next to it and it can't reach me without leaving the hireling's reach: then it fights the hireling. If
 * it can't get within its long range or reach this turn it Dashes, as far again, and can't attack. Stepping out of my
 * hireling's reach draws its opportunity attack. Returns 'shoot', 'melee', 'hireling', null when it Dashed or
 * couldn't move, or 'dead' when that attack killed it.
 */
function advance(array &$me, array &$foe, int $speed, array &$lines): ?string
{
    $room = (int) $me['room_id'];
    [$mine, $hire] = [my_square($me), hire_square($me)];
    $at = [$foe['x'], $foe['y']];
    $crowd = [$mine, ...array_filter([$hire]), ...monster_squares($me, $foe['id']), ...crowd($me)]; // can't step on
    $held = $hire !== null && feet($at, $hire) <= 5;
    $shoot = $foe['shot'] !== null && feet($at, $mine) > 5 && !$held;
    [$want, $need] = $shoot ? [$foe['shot_range'], $foe['shot_long']] : [$foe['reach'], $foe['reach']];
    if ($held && !in_reach($room, $at, $mine, $need)) {
        // A square in its move that reaches me and stays beside the hireling, else it fights the hireling.
        $spots = [];
        foreach (paths($room, $at, $crowd) as $k => [$cost]) {
            $p = array_map('intval', explode(',', $k));
            if ($cost <= $speed && in_reach($room, $p, $mine, $need) && feet($p, $hire) <= 5) {
                $spots[] = [$cost, $p];
            }
        }
        sort($spots);
        $spots = array_column($spots, 1);
        if ($spots === []) {
            return 'hireling';
        }
        [$foe['x'], $foe['y']] = $spots[0];
        q('UPDATE spawns SET x = ?, y = ?, placed_at = NOW() WHERE id = ?', [...$spots[0], $foe['id']]);
        $lines[] = "The {$foe['name']} closes in.";
        return 'melee';
    }
    if ($speed === 0 && !in_reach($room, $at, $mine, $need)) {
        $lines[] = "The {$foe['name']} can't move.";
        return null;
    }
    $rows = board($room);
    $walked = 0;
    $reacted = false; // my hireling's Reaction
    foreach ([[$want, $speed], [$need, 2 * $speed]] as [$stop, $limit]) { // its move, then a Dash if need be
        while (!in_reach($room, $at, $mine, $stop)) {
            $next = toward($room, $at, $mine, $crowd, $stop);
            if ($next === $at || $walked + step_cost($rows, $next) > $limit) {
                break;
            }
            $passing = $hire !== null && !$reacted && feet($at, $hire) <= 5 && feet($next, $hire) > 5;
            [$at, $walked, $foe['x'], $foe['y']] = [$next, $walked + step_cost($rows, $next), $next[0], $next[1]];
            if ($passing) {
                $reacted = true;
                $lines[] = "Your {$me['hireling']['name']} strikes as the {$foe['name']} goes past!";
                if (hireling_attacks($me, $foe, $lines, 1)) {
                    return 'dead';
                }
            }
        }
    }
    q('UPDATE spawns SET x = ?, y = ?, placed_at = NOW() WHERE id = ?', [$at[0], $at[1], $foe['id']]);
    if (!in_reach($room, $at, $mine, $need) || $walked > $speed) {
        $lines[] = "The {$foe['name']} runs at you.";
        return null;
    }
    if ($walked > 0) {
        $lines[] = "The {$foe['name']} " . ($shoot ? 'steps in' : 'closes in') . '.';
    }
    return $shoot ? 'shoot' : 'melee';
}

/**
 * The end of $foe's turn: my auras hurt it (Spirit Guardians: its save for half), then it repeats each save its
 * effects allow, and one it makes ends that effect. Returns true if the aura killed it. shortcut: only the auras of
 * the hero it fights this turn, as each hero's command is a turn of its own.
 */
function end_of_turn(array &$me, array &$foe, array &$lines): bool
{
    foreach ($me['effects'] as $e) {
        if ($e['effect'] !== 'aura') {
            continue;
        }
        [$save, $saved] = save($foe, $e['save'], $e['dc']);
        $dmg = roll($e['value']);
        [$dmg, $note] = typed($saved && $e['half'] ? intdiv($dmg, 2) : $dmg, $e['damage_type'], $foe);
        $lines[] = "Your {$e['spell']} sear" . ($e['spell'] === 'Spirit Guardians' ? '' : 's') . " the {$foe['name']} ("
            . ucfirst($e['save']) . ' save ' . vs($save, $e['dc']) . ") for $dmg damage$note.";
        if (hurt_foe($me, $foe, $dmg, $lines)) {
            return true;
        }
    }
    repeat_saves($me, $foe, $lines);
    return false;
}

/** $foe repeats each save its effects allow, and one it makes ends that effect. Lines are told to $me. */
function repeat_saves(array $me, array &$foe, array &$lines): void
{
    foreach ($foe['effects'] as $i => $e) {
        if ($e['save'] === null || $e['effect'] === 'aura') {
            continue;
        }
        [$save, $saved] = save($foe, $e['save'], $e['dc']);
        $roll = ucfirst($e['save']) . ' save ' . vs($save, $e['dc']);
        $whose = $e['caster_id'] === $me['id'] ? 'your' : "{$e['caster']}'s";
        if ($saved) {
            q('DELETE FROM effects WHERE id = ?', [$e['id']]);
            unset($foe['effects'][$i]);
            $lines[] = "The {$foe['name']} shakes off $whose {$e['spell']} ($roll).";
        } else {
            $lines[] = "The {$foe['name']} is still held by $whose {$e['spell']} ($roll).";
        }
    }
}

/** Second Wind: a bonus action, so it costs no turn and the monster doesn't swing. */
function cmd_second(array $me): array
{
    if ($me['class'] !== 'fighter') {
        return ['Only Fighters have Second Wind.'];
    }
    $left = $me['uses'] - $me['uses_spent'];
    if ($left <= 0) {
        return ['You have no Second Wind left. Rest to recover it.'];
    }
    if ($me['hp'] >= $me['max_hp']) {
        return ["You're already at full health."];
    }
    if (!spend_bonus($me)) {
        return [BONUS_USED];
    }
    $hp = min($me['max_hp'], $me['hp'] + roll('1d10') + $me['level']);
    q('UPDATE users SET hp = ?, uses_spent = uses_spent + 1 WHERE id = ?', [$hp, $me['id']]);
    return [
        'You catch your second wind and regain ' . ($hp - $me['hp']) . " HP. HP $hp/{$me['max_hp']}, "
            . ($left - 1) . ' Second Wind left.',
    ];
}

/** Rage: a Bonus Action, so it costs no turn. It lasts until the fight ends (the SRD's limit is 10 minutes). */
function cmd_rage(array $me): array
{
    if ($me['class'] !== 'barbarian') {
        return ['Only Barbarians can Rage.'];
    }
    if ($me['fighting'] === null) {
        return ['There is nothing here to rage at. Start a fight first.'];
    }
    if ($me['raging']) {
        return ['You are already raging!'];
    }
    $left = $me['uses'] - $me['uses_spent'];
    if ($left <= 0) {
        return ['You have no Rage left. Rest to recover it.'];
    }
    if (!spend_bonus($me)) {
        return [BONUS_USED];
    }
    q('UPDATE users SET raging = TRUE, uses_spent = uses_spent + 1 WHERE id = ?', [$me['id']]);
    return [
        "You fly into a Rage! +{$me['rage_damage']} damage with Strength attacks and Resistance to Bludgeoning, "
            . 'Piercing and Slashing damage until the fight ends. ' . ($left - 1) . ' Rage left.',
    ];
}

/** Spends $n of my uses (Focus Points, Sorcery Points), or returns false if too few are left. */
function spend_uses(array &$me, int $n = 1): bool
{
    if ($me['uses_spent'] + $n > $me['uses']) {
        return false;
    }
    $me['uses_spent'] += $n;
    q('UPDATE users SET uses_spent = ? WHERE id = ?', [$me['uses_spent'], $me['id']]);
    return true;
}

/** The save DC of a Monk's Focus features: 8 + Wis + PB. */
function focus_dc(array $me): int
{
    return 8 + mod($me['wisdom']) + $me['prof_bonus'];
}

/**
 * A Monk rolling Initiative: Uncanny Metabolism (level 2, once a long rest) gets back every Focus Point and a Martial
 * Arts die + level in HP, used once any point is spent; failing that, Perfect Focus (15) tops points up to 4.
 */
function focus_on_initiative(array &$me, array &$lines): void
{
    if (!$me['once_used'] && $me['uses_spent'] > 0) {
        $me['hp'] = min($me['max_hp'], $me['hp'] + roll('1d' . $me['martial_arts']) + $me['level']);
        [$me['uses_spent'], $me['once_used']] = [0, 1];
        q('UPDATE users SET hp = ?, uses_spent = 0, once_used = TRUE WHERE id = ?', [$me['hp'], $me['id']]);
        $lines[] = "Uncanny Metabolism: your Focus Points return, and HP {$me['hp']}/{$me['max_hp']}.";
    } elseif ($me['level'] >= 15 && $me['uses'] - $me['uses_spent'] < 4) {
        $me['uses_spent'] = $me['uses'] - 4;
        q('UPDATE users SET uses_spent = ? WHERE id = ?', [$me['uses_spent'], $me['id']]);
        $lines[] = 'Perfect Focus: you have 4 Focus Points.';
    }
}

/**
 * Deflect Attacks took a hit to 0 and I spent a Focus Point: $foe makes a Dex save or takes two Martial Arts dice + my
 * Dex of the attack's type. Returns true if that killed it.
 */
function redirect(array &$me, array &$foe, array &$lines): bool
{
    $dc = focus_dc($me);
    [$save, $saved] = save($foe, 'dex', $dc);
    $roll = 'Dex save ' . vs($save, $dc);
    if ($saved) {
        $lines[] = "You turn the blow back, but the {$foe['name']} dodges it ($roll).";
        return false;
    }
    $dice = '2d' . $me['martial_arts'] . sprintf('%+d', mod($me['dexterity']));
    [$dmg, $note] = typed(max(0, roll($dice)), $foe['damage_type'], $foe);
    $lines[] = "You turn the blow back on the {$foe['name']} ($roll) for $dmg damage$note.";
    return hurt_foe($me, $foe, $dmg, $lines);
}

/**
 * A round of attacks with a Monk technique, each 1 Focus Point (SRD 5.2.1 p.49-52): flurry (Flurry of Blows, the
 * Bonus Action is two Unarmed Strikes, three from level 10; from level 3 Open Hand Technique lets each hit topple),
 * patient (Patient Defense, the Bonus Action is a Dodge: attacks on me have Disadvantage until my next turn) and stun
 * (Stunning Strike, level 5: my first hit with a Monk weapon or Unarmed Strike spends the point, and the foe makes a
 * Con save or is Stunned until my next turn; if it saves, the next attack on it has Advantage). Patient Defense's
 * Temporary Hit Points (level 10) are left out: there are none yet.
 */
function cmd_technique(array $me, string $how, string $target): array
{
    $level = $how === 'stun' ? 5 : 2;
    if ($me['class'] !== 'monk' || $me['level'] < $level) {
        return ["Only Monks of level $level or more know that technique."];
    }
    if ($me['uses_spent'] >= $me['uses']) {
        return ['You have no Focus Points left. Rest to recover them.'];
    }
    if ($how !== 'stun' && $me['bonus_used']) {
        return [BONUS_USED];
    }
    return fight($me, $target, function (array &$me, array &$foe, array &$lines) use ($how): bool {
        if ($how === 'stun') {
            $me['stun'] = true;
            return hero_turn($me, $foe, $lines);
        }
        spend_bonus($me);
        spend_uses($me);
        if ($how === 'flurry') {
            $me['flurry'] = $me['level'] >= 10 ? 3 : 2;
            $lines[] = 'You unleash a Flurry of Blows!';
        } else {
            $me['conditions'] = implode(',', array_filter([$me['conditions'], 'dodging']));
            q('UPDATE users SET conditions = ? WHERE id = ?', [$me['conditions'], $me['id']]);
            $lines[] = 'You settle into Patient Defense, Dodging.';
        }
        return hero_turn($me, $foe, $lines);
    }, ucfirst($how) . ' what?');
}

/**
 * Wholeness of Body (Open Hand 6): a Bonus Action, so it costs no turn. Heals a Martial Arts die + Wis (at least 1),
 * Wis modifier times (at least once) a long rest.
 */
function cmd_wholeness(array $me): array
{
    if ($me['class'] !== 'monk' || $me['level'] < 6) {
        return ['Only Monks of level 6 or more know Wholeness of Body.'];
    }
    $left = max(1, mod($me['wisdom'])) - $me['feature_spent'];
    if ($left <= 0) {
        return ['You have no Wholeness of Body left. A long rest restores it.'];
    }
    if ($me['hp'] >= $me['max_hp']) {
        return ["You're already at full health."];
    }
    if (!spend_bonus($me)) {
        return [BONUS_USED];
    }
    $hp = min($me['max_hp'], $me['hp'] + max(1, roll('1d' . $me['martial_arts']) + mod($me['wisdom'])));
    q('UPDATE users SET hp = ?, feature_spent = feature_spent + 1 WHERE id = ?', [$hp, $me['id']]);
    return ['Your breath steadies and you regain ' . ($hp - $me['hp']) . " HP. HP $hp/{$me['max_hp']}, "
        . ($left - 1) . ' Wholeness of Body left.'];
}

/**
 * Bardic Inspiration (Bard 1): a Bonus Action, so it costs no turn. Gives a hero here (not me) my Bardic Inspiration
 * die for the next hour, to add to an attack roll or save they fail when it could make the difference (inspired()). One
 * die at a time per hero; Charisma modifier (at least 1) uses a long rest, and from level 5 a short rest too.
 */
function cmd_inspire(array $me, string $target): array
{
    if ($me['class'] !== 'bard') {
        return ['Only Bards give Bardic Inspiration.'];
    }
    $others = q('SELECT * FROM users WHERE room_id = ? AND id <> ? AND setup IS NULL ORDER BY name',
        [$me['room_id'], $me['id']])->fetchAll();
    $who = array_values(array_filter($others, fn ($o) => names($target, $o['name'])))[0] ?? null;
    if ($who === null) {
        return [$target === '' ? 'Inspire whom? Another hero here.' : "There's no hero called '$target' here."];
    }
    if (in_array('inspiration', array_column(effects_on('user_id', (int) $who['id']), 'effect'), true)) {
        return ["{$who['name']} already holds a Bardic Inspiration die."];
    }
    if ($me['uses_spent'] >= $me['uses']) {
        return ['You have no Bardic Inspiration left. Rest to recover it.'];
    }
    if (!spend_bonus($me)) {
        return [BONUS_USED];
    }
    spend_uses($me);
    $spell = q("SELECT * FROM spells WHERE name = 'Bardic Inspiration'")->fetch();
    start_effect($me, $spell, 0, 'user_id', [$who], null, "1d{$me['bardic']}");
    return ["You inspire {$who['name']} with a d{$me['bardic']} of Bardic Inspiration. "
        . ($me['uses'] - $me['uses_spent']) . ' left.'];
}

/**
 * Wild Shape (Druid 2): wild <beast> shifts me into a Beast form, a Bonus Action that costs no turn (beast_form()). I
 * keep my HP and gain Temporary Hit Points equal to my level, and can't cast spells until level 18. Forms are the SRD
 * Beasts in `monsters` of CR 1/4 or less (1/2 from level 4, 1 from 8; none of them fly). wild alone leaves the form
 * (a Bonus Action too) or lists the forms. Two uses (three at 6, four at 17), one back on a short rest. shortcut: I
 * know every eligible form, and a form lasts until I leave it, drop to 0 HP or finish a long rest.
 */
function cmd_wild(array $me, string $arg): array
{
    if ($me['class'] !== 'druid' || $me['level'] < 2) {
        return ['Only Druids of level 2 or more have Wild Shape.'];
    }
    if ($arg === '' && $me['shape'] !== null) {
        if (!spend_bonus($me)) {
            return [BONUS_USED];
        }
        q('UPDATE users SET shape = NULL WHERE id = ?', [$me['id']]);
        return ["You shed the {$me['shape_name']}'s form and stand as yourself again."];
    }
    $forms = wild_forms($me);
    $form = array_values(array_filter($forms, fn ($f) => names($arg, $f['name'])))[0] ?? null;
    $left = $me['uses'] - $me['uses_spent'];
    if ($form === null) {
        $lines = $arg === '' ? [] : ["You know no '$arg' form."];
        $lines[] = "Your Wild Shape forms ($left/{$me['uses']} uses left):";
        foreach ($forms as $f) {
            $lines[] = sprintf('  %-14s AC %d, %s%s +%d, %s %s', strtolower($f['name']), $f['ac'],
                $f['attacks'] > 1 ? "{$f['attacks']} x " : '', $f['attack'], $f['to_hit'], $f['damage'],
                ucfirst($f['damage_type']));
        }
        return $lines;
    }
    if ($left <= 0) {
        return ['You have no Wild Shape left. Rest to recover it.'];
    }
    if (!spend_bonus($me)) {
        return [BONUS_USED];
    }
    spend_uses($me);
    $temp = max($me['temp_hp'], $me['level']);
    q('UPDATE users SET shape = ?, temp_hp = ? WHERE id = ?', [$form['id'], $temp, $me['id']]);
    return ["You shift into " . lcfirst(a_an($form['name'])) . "! AC {$form['ac']}, $temp temporary HP, "
        . ($left - 1) . ' Wild Shape left.'];
}

/**
 * Land's Aid (Circle of the Land 3): my turn's action and a use of Wild Shape. The foe makes a Con save against my
 * spell save DC or takes 2d6 Necrotic (half on a success), and I regain 2d6 HP; 3d6 from level 10, 4d6 from 14.
 * shortcut: the Sphere holds only the foe I fight and me.
 */
/** The Beasts my Wild Shape can take at my level. */
function wild_forms(array $me): array
{
    $cr = $me['level'] >= 8 ? 1 : ($me['level'] >= 4 ? 0.5 : 0.25);
    // No Fly Speed before level 8. shortcut: monsters have no speeds, so the ones that fly are named here.
    $flyers = $me['level'] >= 8 ? '' : 'Bat,Swarm of Insects';
    return q(
        "SELECT * FROM monsters WHERE type = 'beast' AND id < 1001 AND cr <= ? AND NOT FIND_IN_SET(name, ?)
         ORDER BY cr, name",
        [$cr, $flyers]
    )->fetchAll();
}

function cmd_land(array $me, string $target): array
{
    if ($me['class'] !== 'druid' || $me['level'] < 3) {
        return ["Only Druids of level 3 or more know Land's Aid."];
    }
    if ($me['uses_spent'] >= $me['uses']) {
        return ['You have no Wild Shape left. Rest to recover it.'];
    }
    return fight($me, $target, function (array &$me, array &$foe, array &$lines): bool {
        spend_uses($me);
        $dice = (2 + ($me['level'] >= 10 ? 1 : 0) + ($me['level'] >= 14 ? 1 : 0)) . 'd6';
        $dc = spell_dc($me);
        [$save, $saved] = save($foe, 'con', $dc);
        $dmg = roll($dice);
        [$dmg, $note] = typed($saved ? intdiv($dmg, 2) : $dmg, 'necrotic', $foe);
        $hp = min($me['max_hp'], $me['hp'] + roll($dice));
        $lines[] = "Flowers and thorns bloom: the {$foe['name']} " . ($saved ? 'makes' : 'fails') . ' its Con save ('
            . vs($save, $dc) . ") and takes $dmg damage$note, and you regain " . ($hp - $me['hp']) . ' HP.';
        $me['hp'] = $hp;
        q('UPDATE users SET hp = ? WHERE id = ?', [$hp, $me['id']]);
        return hurt_foe($me, $foe, $dmg, $lines);
    }, "Land's Aid at what?", 60);
}

/**
 * Innate Sorcery (Sorcerer 1): a Bonus Action, so it costs no turn. For the rest of the fight (the SRD's 1 minute) my
 * spell save DC is 1 higher and my spell attack rolls have Advantage. Twice a long rest, and from level 7 (Sorcery
 * Incarnate) for 2 Sorcery Points once those are gone.
 */
function cmd_innate(array $me): array
{
    if ($me['class'] !== 'sorcerer') {
        return ['Only Sorcerers have Innate Sorcery.'];
    }
    if ($me['fighting'] === null) {
        return ['Innate Sorcery needs a fight to burn in. Start one first.'];
    }
    if ($me['innate']) {
        return ['Your Innate Sorcery already burns.'];
    }
    $left = 2 - $me['feature_spent'];
    $points = $left <= 0 && $me['level'] >= 7;
    if ($left <= 0 && (!$points || $me['uses'] - $me['uses_spent'] < 2)) {
        return [$me['level'] >= 7 ? 'You have no Innate Sorcery left, nor 2 Sorcery Points. A long rest restores them.'
            : 'You have no Innate Sorcery left. A long rest restores it.'];
    }
    if (!spend_bonus($me)) {
        return [BONUS_USED];
    }
    if ($points) {
        spend_uses($me, 2);
    } else {
        q('UPDATE users SET feature_spent = feature_spent + 1 WHERE id = ?', [$me['id']]);
    }
    q('UPDATE users SET innate = TRUE WHERE id = ?', [$me['id']]);
    return ['Magic surges up within you: +1 to your spell save DC and Advantage on your spell attacks until the fight '
        . 'ends. ' . ($points ? '2 Sorcery Points spent.' : ($left - 1) . ' Innate Sorcery left.')];
}

/**
 * Font of Magic (Sorcerer 2): font <level> shapes Sorcery Points into a spell slot of that level (FONT_COSTS), a Bonus
 * Action; font points <level> turns a slot of that level back into as many points, no action. A slot made beyond my
 * table shows as fewer than none spent, and vanishes with the long rest that clears slots_spent.
 */
function cmd_font(array $me, string $arg): array
{
    if ($me['class'] !== 'sorcerer' || $me['level'] < 2) {
        return ['Only Sorcerers of level 2 or more have Font of Magic.'];
    }
    $back = (bool) preg_match('/^points?\s+/i', $arg, $m);
    $level = (int) ($back ? substr($arg, strlen($m[0])) : $arg);
    $left = $me['uses'] - $me['uses_spent'];
    if ($back) {
        if ($level < 1 || $level > count($me['slots']) || slots_spent($me)[$level - 1] >= $me['slots'][$level - 1]) {
            return ["You have no level $level spell slot left to turn into points."];
        }
        $gain = min($level, $me['uses_spent']); // never more than my table's points
        if ($gain === 0) {
            return ['Your Sorcery Points are already full.'];
        }
        spend_slot($me, $level);
        q('UPDATE users SET uses_spent = uses_spent - ? WHERE id = ?', [$gain, $me['id']]);
        return ["You turn a level $level spell slot into $gain Sorcery " . ($gain === 1 ? 'Point' : 'Points')
            . '. Sorcery Points: ' . ($left + $gain) . "/{$me['uses']}. Slots " . slots_left($me) . '.'];
    }
    [$cost, $needs] = FONT_COSTS[$level] ?? [0, 99];
    if ($me['level'] < $needs) {
        return ["Usage: font <level> (a slot of level 1 to 5, as your level allows), or font points <level>."];
    }
    if ($left < $cost) {
        return ["A level $level slot takes $cost Sorcery Points, and you have $left."];
    }
    if (!spend_bonus($me)) {
        return [BONUS_USED];
    }
    spend_uses($me, $cost);
    $spent = slots_spent($me);
    $spent[$level - 1]--;
    $me['slots_spent'] = implode(',', $spent);
    q('UPDATE users SET slots_spent = ? WHERE id = ?', [$me['slots_spent'], $me['id']]);
    return ["You shape $cost Sorcery Points into a level $level spell slot. Slots " . slots_left($me) . '.'];
}

/**
 * Lay On Hands (Paladin 1): a Bonus Action, so it costs no turn. Heals me or a hero here I name from a pool of 5 HP a
 * level, as much as they're missing; a long rest refills it.
 */
function cmd_lay(array $me, string $target): array
{
    if ($me['class'] !== 'paladin') {
        return ['Only Paladins Lay On Hands.'];
    }
    $who = $me;
    if ($target !== '') {
        $others = q('SELECT * FROM users WHERE room_id = ? AND id <> ? AND setup IS NULL ORDER BY name',
            [$me['room_id'], $me['id']])->fetchAll();
        $who = array_values(array_filter($others, fn ($o) => names($target, $o['name'])))[0] ?? null;
        if ($who === null) {
            return ["There's no hero called '$target' here."];
        }
        $who = with_stats($who);
    }
    $pool = $me['uses'] - $me['uses_spent'];
    $heal = min($pool, $who['max_hp'] - $who['hp']);
    if ($heal <= 0) {
        return [$pool <= 0 ? 'Your Lay On Hands pool is empty. A long rest refills it.'
            : ($who['id'] === $me['id'] ? "You're" : "{$who['name']} is") . ' already at full health.'];
    }
    if (!spend_bonus($me)) {
        return [BONUS_USED];
    }
    q('UPDATE users SET hp = hp + ? WHERE id = ?', [$heal, $who['id']]);
    q('UPDATE users SET uses_spent = uses_spent + ? WHERE id = ?', [$heal, $me['id']]);
    $hp = $who['hp'] + $heal;
    $line = ($who['id'] === $me['id'] ? 'You regain' : "{$who['name']} regains") . " $heal HP. HP $hp/{$who['max_hp']}";
    return ["$line, " . ($pool - $heal) . ' left in your Lay On Hands pool.'];
}

/** Spell slots I've spent, one count per spell level I have slots for. */
function slots_spent(array $me): array
{
    $spent = array_map('intval', array_filter(explode(',', $me['slots_spent']), 'strlen'));
    return array_pad($spent, count($me['slots']), 0);
}

/** "2/2 1/3": spell slots left of each level I have any of (a Warlock's are all of one level). */
function slots_left(array $me): string
{
    $left = array_map(fn ($max, $used) => $max ? ($max - $used) . "/$max" : null, $me['slots'], slots_spent($me));
    return implode(' ', array_filter($left));
}

/** My spell save DC: 8 + PB + my spellcasting ability's modifier (or origin_ability()), + 1 with Innate Sorcery. */
function spell_dc(array $me): int
{
    $ability = CLASSES[$me['class']]['casting'] ?? origin_ability($me);
    return 8 + $me['prof_bonus'] + mod($me[$ability]) + ($me['innate'] ? 1 : 0);
}

/** True while my Hunter's Mark lasts. */
function marking(array $me): bool
{
    return in_array("Hunter's Mark", array_column($me['effects'], 'spell'), true);
}

/** The lowest spell slot I have left of $level or higher, or 0 (none, or a cantrip). */
function lowest_slot(array $me, int $level): int
{
    foreach (slots_spent($me) as $i => $used) {
        if ($level > 0 && $i + 1 >= $level && $used < $me['slots'][$i]) {
            return $i + 1;
        }
    }
    return 0;
}

/** Spends one of my spell slots of level $slot (0: none). */
function spend_slot(array &$me, int $slot): void
{
    if ($slot > 0) {
        $spent = slots_spent($me);
        $spent[$slot - 1]++;
        $me['slots_spent'] = implode(',', $spent);
        q('UPDATE users SET slots_spent = ? WHERE id = ?', [$me['slots_spent'], $me['id']]);
    }
}

/**
 * The smite I readied ($me['smite'], a spell of kind smite) lands with my melee hit on $foe: a Bonus Action that
 * spends the lowest slot left of its level or higher (a Paladin's first Divine Smite after a long rest is free), for
 * its dice and its upcast dice per level above (twice on a critical hit), and leaves its effect, after a save if it
 * has one (Ensnaring Strike). Divine Smite adds 1d8 against a Fiend or Undead. Returns the damage, for the hit to deal.
 */
function smite(array &$me, array &$foe, bool $crit, array &$lines): int
{
    [$spell, $me['smite']] = [$me['smite'], null];
    $free = $spell['name'] === 'Divine Smite' && !$me['once_used'];
    $slot = $free ? $spell['level'] : lowest_slot($me, $spell['level']);
    if ($slot === 0 || !spend_bonus($me)) { // an off-hand attack took the Bonus Action
        return 0;
    }
    if ($free) {
        $me['once_used'] = 1;
        q('UPDATE users SET once_used = TRUE WHERE id = ?', [$me['id']]);
    } else {
        spend_slot($me, $slot);
    }
    $name = $spell['name'] . ($slot > $spell['level'] ? " (level $slot)" : '') . ($free ? ' (free)' : '');
    $slots = ' Slots ' . slots_left($me) . '.';
    [$dmg, $saved] = [0, false];
    if ($spell['dice'] !== null) {
        $dice = $spell['dice'] . str_repeat('+' . $spell['upcast'], $slot - $spell['level'])
            . ($spell['name'] === 'Divine Smite' && in_array($foe['type'], ['fiend', 'undead'], true) ? '+1d8' : '');
        [$dmg, $note] = typed(roll($dice, $crit), $spell['damage_type'], $foe);
        $lines[] = "Your $name sears the {$foe['name']} for $dmg more damage$note.$slots";
    } else {
        $dc = spell_dc($me);
        [$save, $saved] = save($foe, $spell['save'], $dc);
        $roll = ucfirst($spell['save']) . ' save ' . vs($save, $dc);
        $lines[] = "Your $name: the {$foe['name']} " . ($saved ? "breaks free ($roll)."
            : 'is ' . ucfirst($spell['value']) . " ($roll).") . $slots;
    }
    if ($spell['effect'] !== null && !$saved) { // Shining Smite: attacks on it have Advantage
        start_effect($me, $spell, spell_dc($me), 'spawn_id', [$foe], $foe['id']);
        $foe['effects'] = effects_on('spawn_id', $foe['id']);
    }
    return $dmg;
}

/** Primal Strike's damage type against $foe: Cold, Fire, Lightning or Thunder, whichever hurts it most. */
function primal_type(array $foe): string
{
    $hurts = [];
    foreach (['cold', 'fire', 'lightning', 'thunder'] as $type) {
        $hurts[$type] = typed(8, $type, $foe)[0];
    }
    return array_search(max($hurts), $hurts, true);
}

/**
 * Damage my effects and features add to a weapon hit on $foe, each [damage, note]: Divine Favor's and Hunter's Mark's
 * dice (a d10 for a level 20 Ranger's Foe Slayer), from Paladin level 11 Radiant Strikes' 1d8 Radiant on a $melee
 * hit, and $more as [dice, damage type, name] (Colossus Slayer, Primal Strike, a Beast form's second damage). Twice
 * the dice on a $crit. A spell attack (not a $weapon) gets Hunter's Mark and Hex but not Divine Favor. Either gets a
 * Fire or Frost Goliath's Giant Ancestry, a use spent. shortcut: Hunter's Mark and Hex mark whatever I fight, and
 * moving them costs no Bonus Action.
 */
function extra_damage(array &$me, array $foe, bool $melee, bool $crit, array $more = [], bool $weapon = true): array
{
    $giant = ['fire' => ['1d10', 'fire', "Fire's Burn"], 'frost' => ['1d6', 'cold', "Frost's Chill"]];
    if ($me['species'] === 'goliath' && isset($giant[$me['lineage']]) && spend_species($me)) {
        $more[] = $giant[$me['lineage']];
    }
    $extra = array_map(fn ($e) => [$e['value'], $e['damage_type'], $e['spell']], array_filter($me['effects'],
        fn ($e) => $e['effect'] === 'damage' && ($weapon || $e['spell'] !== 'Divine Favor')));
    $extra = [...$extra, ...$more];
    foreach ($extra as &$x) {
        $x[0] = $x[2] === "Hunter's Mark" && $me['class'] === 'ranger' && $me['level'] >= 20 ? '1d10' : $x[0];
    }
    unset($x);
    if ($me['class'] === 'paladin' && $me['level'] >= 11 && $melee) {
        $extra[] = ['1d8', 'radiant', 'Radiant Strikes'];
    }
    return array_map(function ($x) use ($foe, $crit) {
        [$dmg, $note] = typed(roll($x[0], $crit), $x[1], $foe);
        return [$dmg, " (+$dmg {$x[2]}" . ($note === '' ? '' : ',' . substr($note, 2, -1)) . ')'];
    }, $extra);
}

/**
 * cast <spell> [monster]: the spell's words can be shortened ("cast sac rat"). Spells are rows of `spells`, resolved
 * by kind, the way Luminari's spell routines are: an attack rolls against AC (once per ray), a save against 8 + PB +
 * the spellcasting modifier, healing adds that modifier, an auto spell just hits, a smite waits for my weapon hit. I
 * know cantrips, my prepared spells and the ones always prepared (which may come from another class's list), and my
 * origin_spells(), whatever my class: a leveled one is free once a long rest. A spell uses the lowest slot left of its
 * level or higher, and a higher slot adds its upcast dice; a cantrip's dice grow with
 * character level. A Sorcerer (level 2) may put a Metamagic word first: heightened (2 Sorcery Points, Disadvantage on
 * the spell's save) or seeking (1 point, spent only on a miss: reroll the attack's d20). With Innate Sorcery its spell
 * attacks have Advantage; Elemental Affinity (Draconic 6) adds Cha to one damage roll of a Fire spell.
 */
function cmd_cast(array $me, string $arg): array
{
    $ability = CLASSES[$me['class']]['casting'] ?? null;
    $origin = origin_spells($me);
    if ($ability === null && !$origin) {
        return ["You don't know any spells."];
    }
    $ability ??= origin_ability($me);
    if ($me['shape'] !== null && $me['level'] < 18) { // Beast Spells (Druid 18)
        return ["You can't cast spells in Wild Shape. 'wild' returns you to your own form."];
    }
    $meta = null;
    if (preg_match('/^(' . implode('|', array_keys(METAMAGIC)) . ')\s+(.*)$/i', $arg, $m)) {
        [$meta, $arg] = [strtolower($m[1]), $m[2]];
        if ($me['class'] !== 'sorcerer' || $me['level'] < 2) {
            return ['Only Sorcerers of level 2 or more know Metamagic.'];
        }
    }
    $known = known_spells($me);
    [$spell, $target] = find_spell($known, $arg);
    $mod = mod($me[$ability]);
    $dc = spell_dc($me);
    if ($spell === null) {
        $lines = $arg === '' ? [] : ["You don't know a spell called '$arg'."];
        $lines[] = "Your spells (save DC $dc, spell attack +" . ($me['prof_bonus'] + $mod)
            . ($me['slots'] ? '; slots ' . slots_left($me) : '') . '):';
        foreach ($known as $k) {
            $usage = strtolower($k['name']) . (['foe' => ' <monster>', 'ally' => ' [hero]'][$k['targets']] ?? '');
            $lines[] = sprintf('  %-28s %s', $usage, ($k['level'] === 0 ? 'cantrip: ' : '') . $k['about']);
        }
        return [...$lines, ...(isset(CLASSES[$me['class']]['prepared']) ? ["'prepare' shows the others."] : [])];
    }

    if ($meta !== null) {
        $kind = $meta === 'heightened' ? 'save' : 'attack';
        if ($spell['kind'] !== $kind || $spell['targets'] !== 'foe') {
            return [ucfirst($meta) . ' Spell works only on a spell with ' . ($kind === 'save' ? 'a saving throw.'
                : 'an attack roll.')];
        }
        $cost = METAMAGIC[$meta];
        if ($me['uses'] - $me['uses_spent'] < $cost) {
            return ["You need $cost Sorcery " . ($cost === 1 ? 'Point' : 'Points') . ' for that, and you have '
                . ($me['uses'] - $me['uses_spent']) . '.'];
        }
    }
    // Favored Enemy: a Ranger casts Hunter's Mark free a few times a long rest. Armor of Shadows: a Warlock casts Mage
    // Armor on themself with no slot.
    $favored = $spell['name'] === "Hunter's Mark" && $me['class'] === 'ranger' && $me['uses_spent'] < $me['uses'];
    $free = $favored || ($spell['name'] === 'Mage Armor' && $me['class'] === 'warlock' && $target === '');
    $innate = !$free && $spell['level'] > 0 && in_array($spell['id'], $origin, true)
        && !in_array((string) $spell['id'], explode(',', $me['origin_spent']), true); // a lineage or feat spell
    $free = $free || $innate;
    $slot = $free ? 0 : lowest_slot($me, $spell['level']); // cantrips need none
    if ($spell['kind'] === 'smite') { // its slot is spent on the hit
        $free = $spell['name'] === 'Divine Smite' && !$me['once_used']; // Paladin's Smite
        if (!$free && $slot === 0) {
            return ['You have no spell slots left for that. A long rest restores them.'];
        }
        if ($me['bonus_used']) {
            return [BONUS_USED];
        }
        return fight($me, $target, function (array &$me, array &$foe, array &$lines) use ($spell): bool {
            $me['smite'] = $spell;
            return hero_turn($me, $foe, $lines);
        }, 'Cast it at what?', weapon_reach($me));
    }
    if ($spell['level'] > 0 && $slot === 0 && !$free) {
        return ['You have no spell slots left for that. A long rest restores them.'];
    }
    $tier = 1 + intdiv($me['level'] + 1, 6); // cantrips: twice the dice at 5, three times at 11, four at 17
    $beams = $spell['name'] === 'Eldritch Blast'; // whose upgrade is more beams, each its own attack, not more dice
    $dice = match (true) {
        $spell['dice'] === null => null,
        $spell['level'] === 0 && !$beams => preg_replace_callback('/^\d+/', fn ($n) => (string) ($n[0] * $tier),
            $spell['dice']),
        $spell['level'] === 0 => $spell['dice'],
        default => $spell['dice'] . str_repeat('+' . $spell['upcast'], $spell['upcast'] === null ? 0
            : max(0, $slot - $spell['level'])),
    };
    $name = $spell['name'] . ($slot > $spell['level'] ? " (level $slot)" : '') . ($free ? ' (free)' : '');
    $up = max(0, $slot - $spell['level']); // slot levels above the spell's
    $spend = function (array &$me) use ($slot, $favored, $innate, $spell): void {
        spend_slot($me, $slot);
        if ($innate) {
            $me['origin_spent'] = ltrim("{$me['origin_spent']},{$spell['id']}", ',');
            q('UPDATE users SET origin_spent = ? WHERE id = ?', [$me['origin_spent'], $me['id']]);
        }
        if ($favored) {
            $me['uses_spent']++;
            q('UPDATE users SET uses_spent = ? WHERE id = ?', [$me['uses_spent'], $me['id']]);
        }
    };

    if ($spell['targets'] !== 'foe') { // healing or a buff, on myself or heroes here
        $others = q(
            'SELECT * FROM users WHERE room_id = ? AND id <> ? AND setup IS NULL ORDER BY name',
            [$me['room_id'], $me['id']]
        )->fetchAll();
        $named = array_values(array_filter($others, fn ($o) => names($target, $o['name'])));
        if ($spell['targets'] === 'ally' && $target !== '' && !$named) {
            return ["There's no hero called '$target' here."];
        }
        $self = $spell['targets'] !== 'ally' || $target === '';
        $who = match ($spell['targets']) {
            'self' => [],
            'ally' => array_slice($named, 0, 1),
            'allies' => array_slice($others, 0, $spell['count'] - 1 + $spell['upcast_targets'] * $up),
        };
        if ($spell['kind'] === 'heal' && $self && !$who && $me['hp'] >= $me['max_hp']) {
            return ["You're already at full health."];
        }
        $cast = function (array &$me) use ($spell, $spend, $dice, $mod, $name, $slot, $dc, $up, $self, $who): string {
            $spend($me);
            $slots = $slot > 0 ? ' Slots ' . slots_left($me) . '.' : '';
            $targets = [...($self ? [$me] : []), ...$who];
            if ($spell['kind'] === 'buff') {
                $value = match ($spell['effect']) {
                    'max_hp' => (string) ((int) $spell['value'] + (int) $spell['upcast'] * $up),
                    'aura' => $spell['value'] . str_repeat('+' . $spell['upcast'], $spell['upcast'] === null ? 0 : $up),
                    default => $spell['value'],
                };
                start_effect($me, $spell, $dc, 'user_id', $targets, $me['fighting'], $value);
                if ($spell['effect'] === 'max_hp') { // Aid raises current HP as well
                    q('UPDATE users SET hp = hp + ? WHERE FIND_IN_SET(id, ?)', [
                        (int) $value, implode(',', array_column($targets, 'id')),
                    ]);
                    [$me['hp'], $me['max_hp']] = $self ? [$me['hp'] + $value, $me['max_hp'] + $value]
                        : [$me['hp'], $me['max_hp']];
                }
                $me['effects'] = effects_on('user_id', $me['id']);
                $names = array_map(fn ($t) => $t['id'] === $me['id'] ? 'you' : $t['name'], $targets);
                return "You cast $name on " . implode(', ', $names) . ".$slots";
            }
            $amount = max(0, roll($dice) + $mod); // one roll for every target
            $parts = [];
            foreach ($targets as $t) {
                $t = $t['id'] === $me['id'] ? $me : with_stats($t);
                $hp = min($t['max_hp'], $t['hp'] + $amount);
                q('UPDATE users SET hp = ? WHERE id = ?', [$hp, $t['id']]);
                $parts[] = $t['id'] === $me['id'] ? 'you regain ' . ($hp - $t['hp']) . ' HP'
                    : "{$t['name']} regains " . ($hp - $t['hp']) . ' HP';
                if ($t['id'] === $me['id']) {
                    $line = "You cast $name and regain " . ($hp - $me['hp']) . " HP. HP $hp/{$me['max_hp']}.$slots";
                    $me['hp'] = $hp;
                }
            }
            return count($targets) === 1 && $self ? $line : "You cast $name: " . implode(', ', $parts) . ".$slots";
        };
        if ($spell['casting'] === 'bonus' && !spend_bonus($me)) {
            return [BONUS_USED];
        }
        if ($spell['casting'] === 'bonus' || $me['fighting'] === null) { // a Bonus Action costs no turn
            $lines = [];
            unward($me, $lines);
            return [...$lines, $cast($me)];
        }
        return fight($me, '', function (array &$me, array &$foe, array &$lines) use ($cast): bool {
            $lines[] = $cast($me);
            $foe['effects'] = effects_on('spawn_id', $foe['id']); // a new Concentration may have ended one on it
            return false;
        }, 'Cast it at what?', PHP_INT_MAX);
    }
    $foe = $spell['creature'] === null ? null : find_foe($me, $target);
    if ($foe !== null && $foe['type'] !== $spell['creature']) {
        return ["$spell[name] works only on a " . ucfirst($spell['creature']) . ", and the $foe[name] is "
            . lcfirst(a_an(ucfirst($foe['type']))) . '.'];
    }
    $act = function (array &$me, array &$foe, array &$lines) use (
        $spell,
        $spend,
        $dice,
        $mod,
        $dc,
        $name,
        $up,
        $meta,
        $beams,
        $tier
    ): bool {
        $spend($me);
        $type = $spell['damage_type'];
        // Potent Cantrip (Evoker 3): a cantrip that misses or is saved against still deals half damage.
        $potent = $spell['level'] === 0 && $me['class'] === 'wizard' && $me['level'] >= 3;
        // Elemental Affinity (Draconic 6): + Cha to one damage roll of a Fire spell.
        $affinity = $me['class'] === 'sorcerer' && $me['level'] >= 6 && $type === 'fire' ? mod($me['charisma']) : 0;
        $agonizing = $beams && $me['class'] === 'warlock' && $me['level'] >= 2 ? mod($me['charisma']) : 0;
        if ($spell['kind'] === 'auto') {
            [$dmg, $note] = typed(roll($dice) + $affinity, $type, $foe);
            $lines[] = "Your $name strikes the {$foe['name']} for $dmg damage$note.";
            return hurt_foe($me, $foe, $dmg, $lines);
        }
        if ($spell['kind'] === 'save') {
            $ability = $spell['save'];
            if ($meta === 'heightened') {
                spend_uses($me, METAMAGIC[$meta]);
            }
            [$save, $saved, $edge] = save($foe, $ability, $dc, $meta === 'heightened' ? -1 : 0);
            $roll = ucfirst($ability) . ' save (' . vs($save, $dc) . with($edge) . ") against your $name";
            if ($spell['effect'] === 'condition' && $spell['minutes'] === null && !$saved) {
                set_condition($foe, $spell['value']); // Vicious Mockery: until its next attack roll, like Sap
            } elseif ($spell['effect'] !== null && !$saved) {
                start_effect($me, $spell, $dc, 'spawn_id', [$foe], $foe['id']);
                $foe['effects'] = effects_on('spawn_id', $foe['id']);
            }
            if ($dice === null) {
                $lines[] = "The {$foe['name']} " . ($saved ? "makes its $roll." : "fails its $roll and is "
                    . ($spell['effect'] === 'condition' ? ucfirst($spell['value']) : "under your $name") . '.');
                return false;
            }
            if ($saved && !$spell['half'] && !$potent) {
                $lines[] = "The {$foe['name']} makes its $roll and takes no damage.";
                return false;
            }
            $dmg = roll($dice) + $affinity;
            [$dmg, $note] = typed($saved ? intdiv($dmg, 2) : $dmg, $type, $foe);
            $lines[] = "The {$foe['name']} " . ($saved ? 'makes' : 'fails')
                . " its $roll and takes $dmg damage$note.";
            return hurt_foe($me, $foe, $dmg, $lines);
        }
        $rays = $beams ? $tier : $spell['count'] + $spell['upcast_targets'] * $up; // Scorching Ray: three
        for ($ray = 0; $ray < $rays; $ray++) {
            [$d20, $edge, $bonus, $with] = attack_d20($me, $foe, (bool) $me['innate'],
                $spell['ranged'] && $me['pressed']);
            $total = $d20 + $me['prof_bonus'] + $mod + $bonus;
            if ($meta === 'seeking' && ($d20 === 1 || ($d20 < 20 && $total < $foe['ac']))) { // one reroll, one d20
                $meta = null;
                spend_uses($me, METAMAGIC['seeking']);
                $why = $d20 === 1 ? 'natural 1' : "$total vs AC {$foe['ac']}";
                $lines[] = "Your $name misses the {$foe['name']} ($why$with), but Seeking Spell steers it back.";
                $with = substr($with, strlen(with($edge))); // keep Bless's note
                $d20 = d20(0, lucky($me));
                $total = $d20 + $me['prof_bonus'] + $mod + $bonus;
            }
            if ($missed = misses($me, $d20, $total, $foe['ac'], $with)) {
                $why = $d20 === 1 ? 'natural 1' : "$total vs AC {$foe['ac']}";
                if (!$potent) {
                    $lines[] = "Your $name misses the {$foe['name']} ($why$with).";
                    continue;
                }
                [$dmg, $note] = typed(intdiv(roll($dice), 2), $type, $foe);
                $lines[] = "Your $name misses the {$foe['name']} ($why$with), but still deals $dmg damage$note.";
            } else {
                [$dmg, $note] = typed(roll($dice, $d20 === 20) + $affinity + $agonizing, $type, $foe);
                $affinity = 0;
                foreach (extra_damage($me, $foe, false, $d20 === 20, [], false) as [$more, $more_note]) { // Hex
                    $dmg += $more;
                    $note .= $more_note;
                }
                $lines[] = ($d20 === 20 ? 'Critical hit! ' : '') . "Your $name hits the {$foe['name']} ($total vs AC "
                    . "{$foe['ac']}$with) for $dmg damage$note.";
            }
            if (hurt_foe($me, $foe, $dmg, $lines)) {
                return true;
            }
        }
        if ($missed) {
            return false;
        }
        if ($spell['name'] === 'Guiding Bolt') { // its rider: Advantage on the next attack roll against it
            $me['advantage'] = true; // shortcut: only on mine
            q('UPDATE users SET advantage = TRUE WHERE id = ?', [$me['id']]);
        }
        return false;
    };
    return fight($me, $target, $act, 'Cast it at what?', $spell['reach'] ?? PHP_INT_MAX);
}

/**
 * Starts $spell's effect on $targets (heroes for 'user_id', monsters for 'spawn_id'), cast by me, with $value in place
 * of the spell's (an upcast). It lasts the spell's minutes, and while I'm still in the fight it was cast in ($during:
 * the spawn I fight). A Concentration spell first ends the one I was concentrating on.
 */
function start_effect(
    array $me,
    array $spell,
    int $dc,
    string $col,
    array $targets,
    ?int $during,
    ?string $value = null
): void {
    $col = match ($col) { 'user_id' => 'user_id', 'spawn_id' => 'spawn_id' }; // the only two allowed in SQL
    if ($spell['concentration']) {
        end_concentration($me);
    }
    $keep_save = $spell['repeat_save'] || $spell['effect'] === 'aura';
    foreach ($targets as $t) {
        q(
            "INSERT INTO effects ($col, caster_id, spell_id, effect, value, save, dc, concentration, during, until)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW() + INTERVAL ? MINUTE)",
            [$t['id'], $me['id'], $spell['id'], $spell['effect'], $value ?? $spell['value'],
                $keep_save ? $spell['save'] : null, $keep_save ? $dc : null,
                (int) $spell['concentration'], $during, (int) $spell['minutes']]
        );
    }
}

/** My class's cantrips, my prepared spells I have slots for, the ones always prepared, and my origin_spells(). */
function known_spells(array $me): array
{
    return q(
        'SELECT * FROM spells WHERE (level <= ? AND ((level = 0 AND FIND_IN_SET(?, classes)) OR FIND_IN_SET(id, ?)))
         OR FIND_IN_SET(id, ?) ORDER BY level, name',
        [
            count($me['slots']), $me['class'], implode(',', [...prepared_ids($me), ...always_ids($me)]),
            implode(',', origin_spells($me)),
        ]
    )->fetchAll();
}

/** The ids of my prepared spells: my own list, or the class's suggested ones until I make one. */
function prepared_ids(array $me): array
{
    $ids = array_map('intval', array_filter(explode(',', $me['prepared'])));
    return $ids ?: spell_ids(CLASSES[$me['class']]['spells'] ?? []);
}

/** The ids of the spells my class always has prepared at my level; they don't count against the others. */
function always_ids(array $me): array
{
    return spell_ids(array_keys(array_filter(CLASSES[$me['class']]['always'] ?? [], fn ($lv) => $me['level'] >= $lv)));
}

/** The ids of the spells named. */
function spell_ids(array $names): array
{
    if (!$names) {
        return [];
    }
    $in = implode(', ', array_fill(0, count($names), '?'));
    return array_map('intval', q("SELECT id FROM spells WHERE name IN ($in)", $names)->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * prepare <spell>, <spell>...: my list of prepared spells of level 1+ (cantrips are always ready), up to my class's
 * count for my level, of levels I have slots for (SRD 5.2.1 Cleric p.36). It can change after a long rest; between
 * rests I can only add to it, as when a new level raises the count. prepare alone lists what I can choose. A Lore
 * Bard may also choose Cleric, Druid and Wizard spells: two that don't count against the others from level 6
 * (Magical Discoveries; shortcut: they're prepared like the others, not fixed), and any number from 10 (Magical
 * Secrets).
 */
function cmd_prepare(array $me, string $arg): array
{
    $max = CLASSES[$me['class']]['prepared'][$me['level'] - 1] ?? null;
    if ($max === null) {
        return ["You don't prepare spells."];
    }
    $secrets = $me['class'] === 'bard' && $me['level'] >= 6;
    $lists = [$me['class'], ...($secrets ? ['cleric', 'druid', 'wizard'] : [])];
    $all = q(
        'SELECT * FROM spells WHERE (' . implode(' OR ', array_fill(0, count($lists), 'FIND_IN_SET(?, classes)'))
            . ') AND level BETWEEN 1 AND ? AND NOT FIND_IN_SET(id, ?) ORDER BY level, name',
        [...$lists, count($me['slots']), implode(',', always_ids($me))]
    )->fetchAll();
    $now = prepared_ids($me);
    if ($arg === '') {
        $lines = ['Prepared spells (' . count($now) . "/$max), * marks one; change them after a long rest:"];
        foreach ($all as $k) {
            $mark = in_array($k['id'], $now, true) ? '*' : ' ';
            $lines[] = sprintf(' %s %-22s level %d: %s', $mark, strtolower($k['name']), $k['level'], $k['about']);
        }
        return $lines;
    }
    [$chosen, $foreign] = [[], []];
    foreach (preg_split('/\s*,\s*/', $arg, -1, PREG_SPLIT_NO_EMPTY) as $part) {
        [$spell, $rest] = find_spell($all, $part);
        if ($spell === null || $rest !== '') {
            return ["You can't prepare '$part'. Type 'prepare' for the list."];
        }
        $chosen[$spell['id']] = $spell['name'];
        $foreign[$spell['id']] = !in_array($me['class'], explode(',', $spell['classes']), true);
    }
    $foreign = count(array_filter($foreign));
    if ($foreign > 2 && $me['level'] < 10) {
        return ['Until level 10, only two of your spells can come from other lists.'];
    }
    $extra = min(2, $foreign); // Magical Discoveries
    if (count($chosen) - $extra > $max) {
        return ["You can prepare $max spells at your level" . ($secrets ? ' (and two from other lists)' : '')
            . ', not ' . count($chosen) . '.'];
    }
    if (!$me['may_prepare'] && array_diff($now, array_keys($chosen))) {
        return ['You can only add spells until your next long rest.'];
    }
    q('UPDATE users SET prepared = ?, may_prepare = FALSE WHERE id = ?', [
        implode(',', array_keys($chosen)), $me['id'],
    ]);
    return ['You prepare ' . implode(', ', $chosen) . '.'];
}

/**
 * The spell among $known whose name the words of $arg start with, each word a prefix of the name's next word ("sac
 * fl"), and the words left over, the target: [spell or null, target]. The spell matching the most words wins.
 */
function find_spell(array $known, string $arg): array
{
    $words = preg_split('/\s+/', strtolower($arg), -1, PREG_SPLIT_NO_EMPTY);
    [$best, $used] = [null, 0];
    foreach ($known as $k) {
        $n = 0;
        foreach (explode(' ', strtolower($k['name'])) as $i => $word) {
            if (!isset($words[$i]) || !str_starts_with($word, $words[$i])) {
                break;
            }
            $n++;
        }
        if ($n > $used) {
            [$best, $used] = [$k, $n];
        }
    }
    return [$best, implode(' ', array_slice($words, $used))];
}

/** Starts a short or long rest. It finishes on the first command after it's done, unless I leave the room. */
function cmd_rest(array $me, string $kind): array
{
    if ($me['resting'] !== null) {
        $mins = q('SELECT CEIL(TIMESTAMPDIFF(SECOND, NOW(), rest_until) / 60) FROM users WHERE id = ?', [$me['id']])
            ->fetchColumn();
        return ["You are already taking a {$me['resting']} rest ($mins min to go)."];
    }
    if ($me['fighting'] !== null) {
        return ["You can't rest in the middle of a fight!"];
    }
    if (!q('SELECT safe FROM rooms WHERE id = ?', [$me['room_id']])->fetchColumn()) {
        return ["It isn't safe to rest here. Try the town square or the Swaying Lantern."];
    }
    $secs = $kind === 'long' ? LONG_REST_SECS : SHORT_REST_SECS;
    q('UPDATE users SET resting = ?, rest_until = NOW() + INTERVAL ? SECOND WHERE id = ?', [$kind, $secs, $me['id']]);
    return ["You settle in for a $kind rest. It takes " . intdiv($secs, 60) . ' min; leaving cancels it.'];
}

/** If my rest's time is up, applies it and returns what it did. */
function finish_rest(array &$me): array
{
    // Claiming the rest in one UPDATE means two requests at once can't both apply it.
    $done = q(
        'UPDATE users SET resting = NULL, rest_until = NULL
         WHERE id = ? AND resting IS NOT NULL AND rest_until <= NOW()',
        [$me['id']]
    )->rowCount() === 1;
    if (!$done) {
        return [];
    }
    $use = $me['uses'] ? ['fighter' => 'Second Wind', 'barbarian' => 'Rage', 'monk' => 'Focus Points',
        'paladin' => 'Lay On Hands', 'ranger' => "free Hunter's Marks", 'sorcerer' => 'Sorcery Points',
        'bard' => 'Bardic Inspiration', 'druid' => 'Wild Shape', 'warlock' => "Dark One's Own Luck"][$me['class']]
        : null;
    if ($me['resting'] === 'long') {
        [$me['hp'], $me['hit_dice_spent'], $me['uses_spent'], $me['slots_spent']] = [$me['max_hp'], 0, 0, ''];
        $line = 'You finish a long rest: full HP, all Hit Dice' . ($use ? " and $use" : '')
            . ($me['slots'] ? ' and spell slots back, and you may change your prepared spells' : ' back') . '.';
        // shortcut: a Wild Shape lasts until now, not half my level in hours
        q("UPDATE users SET may_prepare = TRUE, once_used = FALSE, feature_spent = 0, temp_hp = 0, shape = NULL,
           species_spent = 0, origin_spent = '', hireling_aid = FALSE WHERE id = ?", [$me['id']]);
    } else {
        $spent = 0;
        while ($me['hp'] < $me['max_hp'] && $me['hit_dice_spent'] < $me['level']) {
            $die = '1d' . CLASSES[$me['class']]['die'];
            $me['hp'] = min($me['max_hp'], $me['hp'] + max(0, roll($die) + mod($me['constitution'])));
            $me['hit_dice_spent']++;
            $spent++;
        }
        $recovered = match (true) {
            $me['class'] === 'warlock' => pact_magic($me),
            (bool) $me['once_used'] => '',
            $me['class'] === 'wizard' => arcane_recovery($me, 'Arcane Recovery'),
            $me['class'] === 'druid' && $me['level'] >= 6 => arcane_recovery($me, 'Natural Recovery'),
            $me['class'] === 'sorcerer' && $me['level'] >= 5 => sorcerous_restoration($me),
            default => '',
        };
        // One Second Wind, Rage or Wild Shape comes back, every Focus Point, from Bard 5 every Bardic Inspiration (Font
        // of Inspiration), and nothing else.
        $all = $me['class'] === 'monk' || ($me['class'] === 'bard' && $me['level'] >= 5);
        $one = in_array($me['class'], ['fighter', 'barbarian', 'druid'], true);
        $me['uses_spent'] = $all ? 0 : max(0, $me['uses_spent'] - ($one ? 1 : 0));
        $line = "You finish a short rest, spending $spent Hit " . ($spent === 1 ? 'Die' : 'Dice') . $recovered
            . match (true) {
                $use && $all => " and regaining your $use.",
                $use && $one => " and regaining a $use.",
                default => '.',
            };
    }
    $me['resting'] = null;
    q(
        'UPDATE users SET hp = ?, hit_dice_spent = ?, uses_spent = ?, slots_spent = ? WHERE id = ?',
        [$me['hp'], $me['hit_dice_spent'], $me['uses_spent'], $me['slots_spent'], $me['id']]
    );
    $h = $me['hireling'];
    $healed = $h !== null && $h['hp'] < $h['max_hp'];
    if ($healed) { // either rest heals a hireling fully
        $me['hireling']['hp'] = $h['max_hp'];
        q('UPDATE users SET hireling_hp = ? WHERE id = ?', [$h['max_hp'], $me['id']]);
    }
    return [$line . " HP {$me['hp']}/{$me['max_hp']}.", ...($healed ? ["Your {$h['name']} rests too: full HP."] : [])];
}

/**
 * Arcane Recovery (Wizard 1) or Natural Recovery (Circle of the Land 6), named $feature, on a short rest once a long
 * rest: spent slots back, highest first, up to a combined level of half my level (rounded up), none of level 6 or
 * more. Returns how the rest line says it.
 */
function arcane_recovery(array &$me, string $feature): string
{
    $spent = slots_spent($me);
    $budget = intdiv($me['level'] + 1, 2);
    $back = [];
    for ($lv = min(5, count($spent)); $lv >= 1; $lv--) {
        while ($spent[$lv - 1] > 0 && $lv <= $budget) {
            $spent[$lv - 1]--;
            $budget -= $lv;
            $back[] = $lv;
        }
    }
    if (!$back) {
        return '';
    }
    $me['slots_spent'] = implode(',', $spent);
    $me['once_used'] = 1;
    q('UPDATE users SET once_used = TRUE WHERE id = ?', [$me['id']]); // slots_spent is saved with the rest
    return ", with $feature bringing back slots of level " . implode(', ', $back);
}

/** Pact Magic (Warlock 1): every slot back on a short rest. Returns how the rest line says it. */
function pact_magic(array &$me): string
{
    if (!array_filter(slots_spent($me))) {
        return '';
    }
    $me['slots_spent'] = ''; // saved with the rest
    return ', with your Pact Magic slots back';
}

/**
 * Magical Cunning (Warlock 2): a 1-minute rite, once a long rest, outside a fight. Spent Pact Magic slots come back,
 * up to half my slots (rounded up).
 */
function cmd_cunning(array $me): array
{
    if ($me['class'] !== 'warlock' || $me['level'] < 2) {
        return ['Only Warlocks of level 2 or more know Magical Cunning.'];
    }
    if ($me['fighting'] !== null) {
        return ["The rite takes a minute. You can't perform it in a fight."];
    }
    if ($me['once_used']) {
        return ['You have performed Magical Cunning since your last long rest.'];
    }
    $spent = slots_spent($me);
    $level = count($spent);
    $back = min($spent[$level - 1], intdiv($me['slots'][$level - 1] + 1, 2));
    if ($back <= 0) {
        return ['Your Pact Magic slots are all there.'];
    }
    $spent[$level - 1] -= $back;
    $me['slots_spent'] = implode(',', $spent);
    q('UPDATE users SET slots_spent = ?, once_used = TRUE WHERE id = ?', [$me['slots_spent'], $me['id']]);
    return ["You perform an esoteric rite, and $back Pact Magic " . ($back === 1 ? 'slot returns' : 'slots return')
        . '. Slots ' . slots_left($me) . '.'];
}

/**
 * Sorcerous Restoration (Sorcerer 5), on a short rest once a long rest: spent Sorcery Points back, up to half my level.
 * Returns how the rest line says it.
 */
function sorcerous_restoration(array &$me): string
{
    $back = min($me['uses_spent'], intdiv($me['level'], 2));
    if ($back === 0) {
        return '';
    }
    $me['uses_spent'] -= $back;
    $me['once_used'] = 1;
    q('UPDATE users SET once_used = TRUE WHERE id = ?', [$me['id']]); // uses_spent is saved with the rest
    return ", with Sorcerous Restoration bringing back $back Sorcery " . ($back === 1 ? 'Point' : 'Points');
}

/**
 * Drops on $room_id's floor one of the monster's $gear (item ids, '23,50') or, carrying none, a random item a monster of
 * $cr could have. Returns its name (null if none can).
 */
function loot(float $cr, int $room_id, string $gear = ''): ?string
{
    $item = $gear !== ''
        ? q('SELECT id, name FROM items WHERE FIND_IN_SET(id, ?) ORDER BY RAND() LIMIT 1', [$gear])->fetch()
        : q('SELECT id, name FROM items WHERE cost <= ? ORDER BY RAND() LIMIT 1', [$cr * LOOT_GP_PER_CR])->fetch();
    if (!$item) {
        return null;
    }
    q('INSERT INTO objects (item_id, room_id) VALUES (?, ?)', [$item['id'], $room_id]);
    return $item['name'];
}

/**
 * My AC and attack from what I wear, by the SRD: armor's AC plus Dex (all for light, up to +2 for medium, none for
 * heavy) and +1 for a Fighter's or Paladin's (level 2) Defense fighting style; unarmored, 10 + Dex (+ Con for a
 * Barbarian's Unarmored Defense, + Wis for a Monk's without a shield, + Cha for a Draconic Sorcerer's from level 3);
 * +2 for a shield. The weapon keys are
 * wielding()'s for my main hand, 'offhand' is a second Light weapon's, or null, and 'unarmed' my Unarmed Strike's.
 * 'arts' is true while a Monk's Martial Arts work: no armor or shield, and only Monk weapons in hand.
 */
function gear(array $me): array
{
    $worn = [];
    foreach (objects_here('user_id', (int) $me['id']) as $o) {
        if ($o['worn']) {
            $worn[$o['offhand'] ? 'offhand' : $o['slot']] = $o;
        }
    }
    $dex = mod($me['dexterity']);
    $armor = $worn['armor'] ?? null;
    $bare = $armor === null && !isset($worn['shield']);
    $defense = $me['class'] === 'fighter' || ($me['class'] === 'paladin' && $me['level'] >= 2) ? 1 : 0;
    $ac = match (true) {
        $armor !== null => $armor['ac'] + $defense
            + match ($armor['armor']) { 'light' => $dex, 'medium' => min($dex, 2), 'heavy' => 0 },
        $me['class'] === 'barbarian' => 10 + $dex + mod($me['constitution']),
        $me['class'] === 'monk' && $bare => 10 + $dex + mod($me['wisdom']),
        $me['class'] === 'sorcerer' && $me['level'] >= 3 => 10 + $dex + mod($me['charisma']), // Draconic Resilience
        default => 10 + $dex,
    };
    $arts = $me['class'] === 'monk' && $bare && monk_weapon($worn['weapon'] ?? null)
        && monk_weapon($worn['offhand'] ?? null);
    return [
        'ac' => $ac + ($worn['shield']['ac'] ?? 0),
        'armored' => $armor !== null,
        'heavy' => $armor !== null && $armor['armor'] === 'heavy',
        // Disadvantage on Stealth (SRD 5.2.1 p.92): heavy armor, and Padded, Scale Mail and Half Plate (and their +1s)
        'noisy' => $armor !== null && ($armor['armor'] === 'heavy'
            || preg_match('/^(Padded Armor|Scale Mail|Half Plate Armor)\b/', $armor['name']) === 1),
        'shield_ac' => $worn['shield']['ac'] ?? 0,
        ...wielding($me, $worn['weapon'] ?? null, isset($worn['shield']) || isset($worn['offhand']), $arts),
        'offhand' => isset($worn['offhand']) ? wielding($me, $worn['offhand'], true, $arts) : null,
        'unarmed' => wielding($me, null, true, $arts),
        'arts' => $arts,
    ];
}

/**
 * $me in a Wild Shape form: the Beast's AC, attack and Str, Dex and Con saves replace mine, as if wielding nothing
 * (its damage dice, which include its modifier, are split back into dice and modifier). shortcut: Initiative stays
 * mine, and my save proficiencies and Proficiency Bonus aren't weighed against the stat block's.
 */
function beast_form(array $me): array
{
    $b = q('SELECT * FROM monsters WHERE id = ?', [$me['shape']])->fetch();
    preg_match('/^(\d+d\d+)([+-]\d+)?$/', $b['damage'], $dmg);
    return [
        'shape_name' => $b['name'],
        'ac' => $b['ac'], 'armored' => false, 'shield_ac' => 0,
        'weapon' => $b['attack'], 'damage' => $dmg[1], 'attack_mod' => (int) ($dmg[2] ?? 0), 'to_hit' => $b['to_hit'],
        'damage_type' => $b['damage_type'], 'attacks' => $b['attacks'],
        'beast_extra' => $b['extra_damage'] === null ? null : [$b['extra_damage'], $b['extra_type']],
        'finesse' => false, 'str_based' => true, 'ranged' => false, 'thrown' => false, 'loading' => false,
        'reach' => $b['reach'] > 5, 'normal_range' => null, 'long_range' => null, 'speed' => $b['speed'],
        'mastery' => null, 'monk' => false, 'offhand' => null, 'arts' => false,
        'str_save' => $b['str_save'], 'dex_save' => $b['dex_save'], 'con_save' => $b['con_save'],
    ] + $me;
}

/** A Monk weapon (or none): a Simple melee weapon, or a Martial melee one that's Light. */
function monk_weapon(?array $weapon): bool
{
    return $weapon === null || (!$weapon['ranged'] && (!$weapon['martial'] || $weapon['light']));
}

/**
 * How I attack with $weapon (null: an Unarmed Strike, 1 + Str). A Versatile weapon does its bigger die unless the
 * other hand is busy, Finesse uses the better of Str and Dex, a Ranged weapon Dex. The Proficiency Bonus is added only
 * for a trained weapon. With a Monk's Martial Arts ($arts), a Monk weapon or Unarmed Strike may use Dex and the
 * Martial Arts die.
 */
function wielding(array $me, ?array $weapon, bool $one_hand, bool $arts = false): array
{
    [$dex, $str] = [mod($me['dexterity']), mod($me['strength'])];
    $finesse = $weapon !== null && $weapon['finesse'];
    $ranged = $weapon !== null && $weapon['ranged'];
    $nimble = $finesse || $arts;
    $attack_mod = $nimble ? max($str, $dex) : ($ranged ? $dex : $str);
    $damage = $weapon === null ? '1' : ($one_hand ? $weapon['dice'] : $weapon['versatile'] ?? $weapon['dice']);
    // The Martial Arts die if it's bigger (Monk weapons all do one die).
    if ($arts && ($weapon === null || (int) substr($damage, 2) < $me['martial_arts'])) {
        $damage = '1d' . $me['martial_arts'];
    }
    $bonus = $weapon['bonus'] ?? 0; // a +1 Weapon's, to attack and damage rolls
    return [
        'weapon' => $weapon['name'] ?? 'Unarmed Strike',
        'damage' => $damage . ($bonus ? "+$bonus" : ''),
        'damage_type' => $weapon['damage_type'] ?? 'bludgeoning', // an Unarmed Strike's
        'attack_mod' => $attack_mod,
        'to_hit' => $attack_mod + $bonus + ($weapon === null || trained($me, $weapon) ? $me['prof_bonus'] : 0)
            + ($ranged && $me['class'] === 'ranger' && $me['level'] >= 2 ? 2 : 0), // Archery
        'finesse' => $finesse,
        'str_based' => $nimble ? $str >= $dex : !$ranged,
        'ranged' => $ranged,
        'thrown' => $weapon !== null && $weapon['thrown'],
        'loading' => $weapon !== null && $weapon['loading'],
        'reach' => $weapon !== null && $weapon['reach'],
        'normal_range' => $weapon['normal_range'] ?? null,
        'long_range' => $weapon['long_range'] ?? null,
        'mastery' => (CLASSES[$me['class']]['mastery'] ?? false) ? ($weapon['mastery'] ?? null) : null,
        'monk' => monk_weapon($weapon),
    ];
}

/**
 * If my class is trained with an item: armor and shields per CLASSES; Rogues use Simple, Finesse or Light weapons,
 * Monks Simple or Light ones, Wizards, Sorcerers, Bards and Warlocks Simple ones, the rest (a Druid as a Warden) every
 * weapon.
 */
function trained(array $me, array $o): bool
{
    return match ($o['slot']) {
        'armor' => in_array($o['armor'], CLASSES[$me['class']]['armor'], true),
        'shield' => in_array('shield', CLASSES[$me['class']]['armor'], true),
        'weapon' => !$o['martial'] || match ($me['class']) {
            'rogue' => $o['finesse'] || $o['light'],
            'monk' => $o['light'],
            'wizard', 'sorcerer', 'bard', 'warlock' => false,
            default => true,
        },
        'potion' => true,
    };
}

/** An item's stats in a few words, for the inventory. */
function describe(array $o): string
{
    return match ($o['slot']) {
        'weapon' => "weapon, {$o['dice']}" . ($o['versatile'] ? " ({$o['versatile']} two-handed)" : '')
            . ($o['two_handed'] ? ', two-handed' : '') . ($o['finesse'] ? ', finesse' : '')
            . ($o['light'] ? ', light' : '') . (in_array($o['mastery'], MASTERIES, true) ? ", {$o['mastery']}" : '')
            . ($o['reach'] ? ', reach' : '') . ($o['ranged'] ? ', ranged' : '') . ($o['thrown'] ? ', thrown' : '')
            . ($o['loading'] ? ', loading' : '')
            . ($o['bonus'] ? ", +{$o['bonus']} to hit and damage" : ''),
        'armor' => "{$o['armor']} armor, AC {$o['ac']}"
            . ['light' => ' + Dex', 'medium' => ' + Dex (max 2)', 'heavy' => ''][$o['armor']],
        'shield' => "shield, +{$o['ac']} AC",
        'potion' => "potion, heals {$o['dice']}",
    };
}

/** Objects on a room's floor ('room_id') or carried by a hero ('user_id'), with their item's columns. */
function objects_here(string $where, int $id): array
{
    $col = match ($where) { 'room_id' => 'room_id', 'user_id' => 'user_id' }; // the only two allowed in SQL
    return q(
        // o.id comes last, so it wins over i.id.
        "SELECT i.*, o.id, o.worn, o.offhand FROM objects o JOIN items i ON i.id = o.item_id WHERE o.$col = ?
         ORDER BY i.name, o.id",
        [$id]
    )->fetchAll();
}

/** The first object in $objects that names($target) matches. */
function find_object(array $objects, string $target): ?array
{
    foreach ($objects as $o) {
        if (names($target, $o['name'])) {
            return $o;
        }
    }
    return null;
}

function cmd_inventory(array $me): array
{
    $carried = objects_here('user_id', (int) $me['id']);
    if (!$carried) {
        return ['You are carrying nothing.'];
    }
    $lines = ['You are carrying:'];
    foreach ($carried as $o) {
        $worn = $o['worn'] ? ($o['offhand'] ? '  (off hand)' : '  (equipped)') : '';
        $lines[] = sprintf('  %-22s %s', $o['name'], describe($o)) . $worn;
    }
    return $lines;
}

function cmd_get(array $me, string $target): array
{
    if ($target === '') {
        return ['Get what?'];
    }
    $o = find_object(objects_here('room_id', (int) $me['room_id']), $target);
    // The room_id condition makes sure only one hero gets it if two try at once.
    if (!$o || q(
        'UPDATE objects SET room_id = NULL, user_id = ? WHERE id = ? AND room_id = ?',
        [$me['id'], $o['id'], $me['room_id']]
    )->rowCount() === 0) {
        return ["You don't see a $target here."];
    }
    return ["You pick up the {$o['name']}."];
}

function cmd_drop(array $me, string $target): array
{
    if ($target === '') {
        return ['Drop what?'];
    }
    $o = find_object(objects_here('user_id', (int) $me['id']), $target);
    if (!$o) {
        return ["You aren't carrying a $target."];
    }
    q('UPDATE objects SET user_id = NULL, worn = FALSE, room_id = ? WHERE id = ?', [$me['room_id'], $o['id']]);
    return ["You drop the {$o['name']}."];
}

function cmd_equip(array $me, string $target): array
{
    $off = (bool) preg_match('/\s+off-?\s?hand$/i', $target, $m);
    $target = $off ? substr($target, 0, -strlen($m[0])) : $target;
    if ($target === '') {
        return ['Equip what?'];
    }
    $carried = objects_here('user_id', (int) $me['id']);
    // Prefer one not already worn, so "equip dagger" works with two daggers.
    $o = find_object(array_filter($carried, fn ($c) => !$c['worn']), $target) ?? find_object($carried, $target);
    if (!$o) {
        return ["You aren't carrying a $target."];
    }
    if ($o['slot'] === 'potion') {
        return ["You can't equip the {$o['name']}. Drink it instead."];
    }
    if ($off && ($o['slot'] !== 'weapon' || !$o['light'])) {
        return ["Only a Light weapon goes in your off hand, and the {$o['name']} isn't one."];
    }
    if ($o['worn']) {
        return ["You already have the {$o['name']} equipped."];
    }
    $trained = trained($me, $o);
    if (!$trained && $o['slot'] !== 'weapon') {
        return ["A " . CLASSES[$me['class']]['name'] . " isn't trained to use the {$o['name']}."];
    }
    if ($o['slot'] === 'armor' && $me['fighting'] !== null) { // the SRD's quickest armor takes a minute to don
        return ["You can't change armor in the middle of a fight!"];
    }
    // Light (SRD 5.2.1 p.89): the off hand takes a second Light weapon, with a Light one in the main hand.
    $main = fn ($c) => $c['worn'] && $c['slot'] === 'weapon' && !$c['offhand'];
    if ($off && !array_filter($carried, fn ($c) => $main($c) && $c['light'])) {
        return ['Wield a Light weapon in your main hand first.'];
    }
    // One of each slot, a Two-Handed weapon leaves no hand for a shield, and an off-hand weapon none for either.
    $two_hands = fn ($c) => $c['slot'] === 'weapon' && $c['two_handed'];
    $in_off = fn ($c) => $c['worn'] && $c['offhand'];
    $clashes = fn ($c) => $c['worn'] && match (true) {
        $off => $in_off($c) || $c['slot'] === 'shield',
        $o['slot'] === 'weapon' => $main($c) || ($in_off($c) && !$o['light'])
            || ($c['slot'] === 'shield' && $two_hands($o)),
        $o['slot'] === 'shield' => $c['slot'] === 'shield' || $two_hands($c) || $in_off($c),
        default => $c['slot'] === $o['slot'],
    };
    $lines = [];
    foreach (array_filter($carried, $clashes) as $old) {
        q('UPDATE objects SET worn = FALSE, offhand = FALSE WHERE id = ?', [$old['id']]);
        $lines[] = "You put away the {$old['name']}.";
    }
    q('UPDATE objects SET worn = TRUE, offhand = ? WHERE id = ?', [(int) $off, $o['id']]);
    $lines[] = "You equip the {$o['name']}" . ($off ? ' in your off hand.' : '.')
        . ($trained ? '' : " You aren't trained with it: no Proficiency Bonus.");
    return $lines;
}

/** Drinking a potion is a Bonus Action, so like Second Wind it costs no turn. */
function cmd_drink(array $me, string $target): array
{
    if ($target === '') {
        return ['Drink what?'];
    }
    $potions = array_filter(objects_here('user_id', (int) $me['id']), fn ($o) => $o['slot'] === 'potion');
    $o = find_object($potions, $target);
    if (!$o) {
        return ["You aren't carrying a $target to drink."];
    }
    if ($me['hp'] >= $me['max_hp']) {
        return ["You're already at full health."];
    }
    if (!spend_bonus($me)) {
        return [BONUS_USED];
    }
    // Deleting by owner first means two requests at once can't both drink it.
    if (q('DELETE FROM objects WHERE id = ? AND user_id = ?', [$o['id'], $me['id']])->rowCount() === 0) {
        return ["You aren't carrying a $target to drink."];
    }
    $hp = min($me['max_hp'], $me['hp'] + roll($o['dice']));
    q('UPDATE users SET hp = ? WHERE id = ?', [$hp, $me['id']]);
    return ["You drink the {$o['name']} and regain " . ($hp - $me['hp']) . " HP. HP $hp/{$me['max_hp']}."];
}

/** The items for sale in $room_id's shop, cheapest first; none if it isn't a shop. */
function stock(int $room_id): array
{
    return q('SELECT * FROM items WHERE shop = ? ORDER BY cost, name', [$room_id])->fetchAll();
}

/** What an item costs in a shop. shortcut: gold is whole GP, so under 1 GP costs 1; add SP and CP if they matter. */
function price(array $item): int
{
    return max(1, (int) ceil((float) $item['cost']));
}

function cmd_list(array $me): array
{
    if ($me['room_id'] === TEMPLE) {
        $lines = ["The priests of Chauntea cast for a donation ('pray <service>'):"];
        foreach (PRAYERS as $word => [, $gp, $what]) {
            $lines[] = sprintf('  %-6s %4d GP  %s', $word, $gp, $what);
        }
        return [...$lines, "You have {$me['gold']} gold."];
    }
    $stock = stock((int) $me['room_id']);
    if (!$stock) {
        return ["Nothing is for sale here. Greenest's shops are off Market Lane, its temple east of the Mill Bridge."];
    }
    $lines = ["For sale ('buy <item>'; the shop pays half price for anything you 'sell'):"];
    $width = max(array_map('strlen', array_column($stock, 'name')));
    foreach ($stock as $i) {
        $lines[] = sprintf("  %-{$width}s %4d GP  %s", $i['name'], price($i), describe($i));
    }
    return [...$lines, "You have {$me['gold']} gold."];
}

function cmd_buy(array $me, string $target): array
{
    $stock = stock((int) $me['room_id']);
    if (!$stock) {
        return ["Nothing is for sale here. Greenest's shops are off Market Lane."];
    }
    $i = find_object($stock, $target);
    if ($i === null) {
        return [($target === '' ? 'Buy what?' : "Nobody here sells a $target.") . " 'list' shows what's for sale."];
    }
    $gp = price($i);
    if (q('UPDATE users SET gold = gold - ? WHERE id = ? AND gold >= ?', [$gp, $me['id'], $gp])->rowCount() !== 1) {
        return ["The {$i['name']} costs $gp gold, and you have {$me['gold']}."];
    }
    q('INSERT INTO objects (item_id, user_id) VALUES (?, ?)', [$i['id'], $me['id']]);
    return ["You buy the {$i['name']} for $gp gold."];
}

/** Any shop buys anything for half its cost (SRD 5.2.1 p.89), preferring one I don't have equipped. */
function cmd_sell(array $me, string $target): array
{
    if (!stock((int) $me['room_id'])) {
        return ["Nobody here is buying. Greenest's shops are off Market Lane."];
    }
    if ($target === '') {
        return ['Sell what?'];
    }
    $carried = objects_here('user_id', (int) $me['id']);
    $o = find_object(array_filter($carried, fn ($c) => !$c['worn']), $target) ?? find_object($carried, $target);
    if ($o === null) {
        return ["You aren't carrying a $target."];
    }
    $gp = (int) floor((float) $o['cost'] / 2);
    if ($gp === 0) {
        return ["Nobody will pay anything for the {$o['name']}."];
    }
    // Deleting by owner first means two requests at once can't both sell it.
    if (q('DELETE FROM objects WHERE id = ? AND user_id = ?', [$o['id'], $me['id']])->rowCount() === 0) {
        return ["You aren't carrying a $target."];
    }
    q('UPDATE users SET gold = gold + ? WHERE id = ?', [$gp, $me['id']]);
    return ["You sell the {$o['name']} for $gp gold."];
}

/** A priest at the temple casts PRAYERS[$what] on me, once I've paid for it. */
function cmd_pray(array $me, string $what): array
{
    if ($me['room_id'] !== TEMPLE) {
        return ["Chauntea's priests take donations at her temple, east of the Mill Bridge."];
    }
    $what = strtolower($what);
    if (!isset(PRAYERS[$what])) {
        return cmd_list($me);
    }
    [$spell_id, $gp] = PRAYERS[$what];
    $spell = q('SELECT * FROM spells WHERE id = ?', [$spell_id])->fetch();
    $missing = $me['max_hp'] - $me['hp'];
    if ($what !== 'aid' && $missing <= 0) {
        return ["You're already at full health."];
    }
    if ($what === 'aid' && array_filter($me['effects'], fn ($e) => $e['spell_id'] === $spell_id)) {
        return ['Aid is already on you; another casting would do nothing more.'];
    }
    $casts = $what === 'heal' ? (int) ceil($missing / (average($spell['dice']) + PRIEST_WIS)) : 1;
    $gp *= $casts;
    if (q('UPDATE users SET gold = gold - ? WHERE id = ? AND gold >= ?', [$gp, $me['id'], $gp])->rowCount() !== 1) {
        return ["That's a donation of $gp gold, and you have {$me['gold']}."];
    }
    if ($what === 'aid') {
        start_effect($me, $spell, 0, 'user_id', [$me], null);
        [$hp, $max] = [$me['hp'] + (int) $spell['value'], $me['max_hp'] + (int) $spell['value']];
    } else {
        $max = $me['max_hp'];
        $hp = $what === 'heal' ? $max : min($max, $me['hp'] + roll($spell['dice']) + PRIEST_WIS);
    }
    q('UPDATE users SET hp = ? WHERE id = ?', [$hp, $me['id']]);
    $cast = $casts > 1 ? "casts {$spell['name']} $casts times" : "casts {$spell['name']}";
    return ["You give $gp gold, and a priest of Chauntea $cast on you. HP $hp/$max."];
}

/** The hero sheet as data: the score panel in play.js draws it, `score` prints it. Takes a with_stats() row. */
/**
 * Hires a skilled hireling at the Swaying Lantern for a day of real time, or pays the one I have for another day. It
 * starts at its stat block's average HP and goes wherever I go (see hireling_turn() and hit_hireling()).
 */
function cmd_hire(array $me, string $kind): array
{
    $id = HIRELINGS[strtolower($kind)] ?? null;
    if ($id === null) {
        return ['Hire whom? A guard, a mastiff or an acolyte, for ' . HIRE_GP . ' gold a day.'];
    }
    if ($me['room_id'] !== LANTERN) {
        return ['Hirelings wait for work at the Swaying Lantern.'];
    }
    $h = $me['hireling'];
    if ($h !== null && $h['id'] !== $id) {
        return ["You already have a {$h['name']}. Dismiss it first."];
    }
    if (q('UPDATE users SET gold = gold - ? WHERE id = ? AND gold >= ?', [HIRE_GP, $me['id'], HIRE_GP])
        ->rowCount() !== 1) {
        return ['A hireling costs ' . HIRE_GP . " gold a day, and you have {$me['gold']}."];
    }
    if ($h !== null) {
        q('UPDATE users SET hired_until = hired_until + INTERVAL 1 DAY WHERE id = ?', [$me['id']]);
        return ["You pay your {$h['name']} for another day."];
    }
    $m = q('SELECT name, hp FROM monsters WHERE id = ?', [$id])->fetch();
    q(
        'UPDATE users SET hireling_monster_id = ?, hireling_hp = ?, hired_until = NOW() + INTERVAL 1 DAY,
         hireling_x = NULL, hireling_y = NULL, hireling_order = NULL, hireling_aid = FALSE WHERE id = ?',
        [$id, average($m['hp']), $me['id']]
    );
    return [a_an($m['name']) . ' takes your ' . HIRE_GP . " gold and will fight beside you for a day. 'dismiss' sends "
        . 'it away.'];
}

function cmd_dismiss(array $me): array
{
    if ($me['hireling'] === null) {
        return ["You haven't hired anyone."];
    }
    $name = $me['hireling']['name'];
    dismiss($me);
    return ["Your $name heads back to the Swaying Lantern."];
}

/** My hireling leaves: dismissed, killed or its time up. */
function dismiss(array &$me): void
{
    q('UPDATE users SET hireling_monster_id = NULL, hireling_hp = NULL, hired_until = NULL WHERE id = ?', [$me['id']]);
    $me['hireling'] = null;
}

/**
 * My hireling's turn, after my action, by its stance (see cmd_order()): on 'attack' it closes in on my foe, on 'guard'
 * it comes back beside me, on 'hold' it stays put; stepping out of my foe's reach draws its opportunity attack (once
 * a round, my foe's Reaction). Then it attacks: in melee if my foe is within 5 ft, else with its ranged attack if it
 * has one in range (Disadvantage past normal range). An acolyte first spends its Divine Aid on Healing Word if I'm at
 * half HP or less. Returns true if it killed my foe; the kill (XP, gold) is mine.
 */
function hireling_turn(array &$me, array &$foe, array &$lines): bool
{
    $h = $me['hireling'];
    if ($h['id'] === HIRELINGS['acolyte'] && !$h['aid'] && $me['hp'] <= intdiv($me['max_hp'], 2)) {
        divine_aid($me, 'heal', $lines);
    }
    $room = (int) $me['room_id'];
    [$mine, $there] = [my_square($me), [$foe['x'], $foe['y']]];
    $at = hire_square($me) ?? beside($room, $mine, $there, [$there]); // a fight from before it had a square
    [$to, $how] = match (true) {
        $h['stance'] === 'attack' && feet($at, $there) > 5 => [$there, 'closes in'],
        $h['stance'] === 'guard' && feet($at, $mine) > 5
            => [beside($room, $mine, $there, [$there, ...monster_squares($me), ...crowd($me)]),
                'falls back beside you'],
        default => [$at, ''],
    };
    $rows = board($room);
    $reach = $how === 'closes in' ? 5 : 0;
    for ($walked = 0; $at !== $to && ($reach === 0 || feet($at, $there) > 5);) {
        $next = toward($room, $at, $to, [$there, $mine, ...monster_squares($me, $foe['id']), ...crowd($me)], $reach);
        if ($next === $at || $walked + step_cost($rows, $next) > $h['speed']) {
            break;
        }
        $walked += step_cost($rows, $next);
        $leaving = feet($at, $there) <= $foe['reach'] && feet($next, $there) > $foe['reach'];
        $at = $next;
        // shortcut: only my target's opportunity attack on it, not the other monsters' of my fight
        if ($leaving && !has($foe, 'reacted')) {
            $lines[] = "The {$foe['name']} strikes at your {$h['name']} as it pulls away!";
            set_condition($foe, 'reacted');
            hit_hireling($me, $foe, $lines, 1);
            if ($me['hireling'] === null) {
                return false;
            }
        }
    }
    [$me['hireling']['x'], $me['hireling']['y']] = $at;
    q('UPDATE users SET hireling_x = ?, hireling_y = ? WHERE id = ?', [$at[0], $at[1], $me['id']]);
    if ($walked > 0) {
        $lines[] = "Your {$h['name']} $how.";
    }
    return hireling_attacks($me, $foe, $lines);
}

/**
 * My hireling's attacks on my foe: $n of them (an opportunity attack), else its Multiattack; in melee from within 5 ft,
 * else its ranged attack if it has one and my foe is in range. A Mastiff's Bite knocks a Medium or smaller foe Prone.
 * Returns true if it killed my foe. shortcut: no Advantage or Disadvantage from conditions, and no other traits.
 */
function hireling_attacks(array &$me, array &$foe, array &$lines, ?int $n = null): bool
{
    $h = $me['hireling'];
    $d = feet(hire_square($me), [$foe['x'], $foe['y']]);
    $shot = $d > 5 && $n === null;
    $cover = $shot ? sight((int) $me['room_id'], hire_square($me), [$foe['x'], $foe['y']]) : 0;
    if ($shot && ($h['shot'] === null || $d > $h['shot_long'] || $cover === null)) {
        return false;
    }
    $ac = $foe['ac'] + $cover;
    [$attack, $to_hit, $damage, $type, $extra] = $shot
        ? [$h['shot'], $h['shot_hit'], $h['shot_damage'], $h['shot_type'], null]
        : [$h['attack'], $h['to_hit'], $h['damage'], $h['damage_type'], $h['extra_damage']];
    $edge = $shot && $d > $h['shot_range'] ? -1 : 0;
    for ($i = 0; $i < ($n ?? $h['attacks']); $i++) {
        $d20 = d20($edge);
        $total = $d20 + $to_hit;
        $its = "Your {$h['name']}'s $attack";
        if ($d20 === 1 || ($d20 < 20 && $total < $ac)) {
            $lines[] = "$its misses the {$foe['name']} (" . ($d20 === 1 ? 'natural 1' : "$total vs AC {$ac}")
                . with($edge) . ').';
            continue;
        }
        [$dmg, $note] = typed(max(0, roll($damage, $d20 === 20)), $type, $foe);
        if ($extra !== null) {
            [$more, $more_note] = typed(roll($extra, $d20 === 20), $h['extra_type'], $foe);
            $dmg += $more;
            $note = implode('', array_unique([$note, $more_note]));
        }
        $lines[] = ($d20 === 20 ? 'Critical hit! ' : '') . "$its hits the {$foe['name']} ($total vs AC {$ac}"
            . with($edge) . ") for $dmg damage$note.";
        if (hurt_foe($me, $foe, $dmg, $lines)) {
            return true;
        }
        if ($h['id'] === HIRELINGS['mastiff'] && !has($foe, 'prone')
            && array_search($foe['size'], SIZES, true) <= array_search('medium', SIZES, true)) {
            set_condition($foe, 'prone');
            $lines[] = "The {$foe['name']} goes down, Prone.";
        }
    }
    return false;
}

/**
 * An acolyte hireling's Divine Aid (the SRD Priest Acolyte's, once a long rest), cast on me: 'heal' is Healing Word
 * (2d4 + its Wis +2), 'bless' Bless (a d4 on my attack rolls and saves for a minute; shortcut: it holds no
 * Concentration), 'sanctuary' Sanctuary (a foe makes a Wis save against its DC 12 to attack me, for a minute or until I
 * attack or cast a spell).
 */
function divine_aid(array &$me, string $what, array &$lines): void
{
    $name = $me['hireling']['name'];
    q('UPDATE users SET hireling_aid = TRUE WHERE id = ?', [$me['id']]);
    $me['hireling']['aid'] = true;
    if ($what === 'heal') {
        $hp = min($me['max_hp'], $me['hp'] + roll('2d4') + 2);
        $lines[] = "Your $name casts Healing Word on you: +" . ($hp - $me['hp']) . ' HP.';
        $me['hp'] = $hp;
        q('UPDATE users SET hp = ? WHERE id = ?', [$hp, $me['id']]);
        return;
    }
    [$spell, $effect, $value, $line] = $what === 'bless'
        ? ['Bless', 'd20', '1d4', "Your $name blesses you: a d4 on your attack rolls and saves for a minute."]
        : ['Sanctuary', 'condition', 'sanctuary', "Your $name wards you with Sanctuary: for a minute, a foe must make "
            . 'a Wis save (DC 12) to attack you, until you attack or cast a spell.'];
    q(
        "INSERT INTO effects (user_id, caster_id, spell_id, effect, value, save, dc, concentration, until)
         VALUES (?, ?, (SELECT id FROM spells WHERE name = ?), ?, ?, 'wis', 12, FALSE, NOW() + INTERVAL 1 MINUTE)",
        [$me['id'], $me['id'], $spell, $effect, $value]
    );
    $me['effects'] = effects_on('user_id', (int) $me['id']);
    $lines[] = $line;
}

/** My Sanctuary ends, if I have one: I attacked or cast a spell. */
function unward(array &$me, array &$lines): void
{
    if (!has($me, 'sanctuary')) {
        return;
    }
    q("DELETE FROM effects WHERE user_id = ? AND effect = 'condition' AND value = 'sanctuary'", [$me['id']]);
    $me['effects'] = effects_on('user_id', (int) $me['id']);
    $lines[] = 'Your Sanctuary ends.';
}

/**
 * Orders my hireling, at no cost: a stance ('attack': it closes in and fights my foe; 'guard': it stays beside me;
 * 'hold': it stays where it is), or an acolyte's Divine Aid now ('heal', 'bless', 'sanctuary', see divine_aid()).
 */
function cmd_order(array $me, string $what): array
{
    $h = $me['hireling'];
    $what = strtolower($what);
    if ($h === null) {
        return ['You have no hireling.'];
    }
    $stances = ['attack' => 'will close in and fight your foe', 'guard' => 'will stay by your side',
        'hold' => 'will hold its ground'];
    if (isset($stances[$what])) {
        q('UPDATE users SET hireling_order = ? WHERE id = ?', [$what, $me['id']]);
        return ["Your {$h['name']} {$stances[$what]}."];
    }
    if (!in_array($what, ['heal', 'bless', 'sanctuary'], true)) {
        return ['Order what? attack, guard or hold' . ($h['id'] === HIRELINGS['acolyte'] ? '; heal, bless or sanctuary'
            : '') . '.'];
    }
    if ($h['id'] !== HIRELINGS['acolyte']) {
        return ["Only an acolyte has Divine Aid, and your {$h['name']} isn't one."];
    }
    if ($h['aid']) {
        return ["Your {$h['name']} has used its Divine Aid until your next long rest."];
    }
    $lines = [];
    divine_aid($me, $what, $lines);
    return $lines;
}

/**
 * $foe's turn goes at my hireling instead of me ($n attacks, else its Multiattack). At 0 HP the hireling is gone.
 * shortcut: plain d20s, no Advantage or Disadvantage from conditions on either side, and the whole turn picks one
 * target.
 */
function hit_hireling(array &$me, array $foe, array &$lines, ?int $n = null): void
{
    $h = $me['hireling'];
    for ($i = 0; $i < ($n ?? $foe['attacks']) && $h['hp'] > 0; $i++) {
        $d20 = roll('1d20');
        $total = $d20 + $foe['to_hit'];
        $its = "The {$foe['name']}'s {$foe['attack']}";
        if ($d20 === 1 || ($d20 < 20 && $total < $h['ac'])) {
            $lines[] = "$its misses your {$h['name']} (" . ($d20 === 1 ? 'natural 1' : "$total vs AC {$h['ac']}")
                . ').';
            continue;
        }
        [$dmg, $note] = typed(max(0, roll($foe['damage'], $d20 === 20)), $foe['damage_type'], $h);
        if ($foe['extra_damage'] !== null) {
            [$extra, $extra_note] = typed(roll($foe['extra_damage'], $d20 === 20), $foe['extra_type'], $h);
            $dmg += $extra;
            $note = implode('', array_unique([$note, $extra_note]));
        }
        $h['hp'] -= $dmg;
        $lines[] = ($d20 === 20 ? 'Critical hit! ' : '') . "$its hits your {$h['name']} ($total vs AC {$h['ac']}) "
            . "for $dmg damage$note.";
    }
    if ($h['hp'] > 0) {
        $me['hireling'] = $h;
        q('UPDATE users SET hireling_hp = ? WHERE id = ?', [$h['hp'], $me['id']]);
        return;
    }
    $lines[] = "Your {$h['name']} falls, and is carried back to the Swaying Lantern.";
    dismiss($me);
}

function hero_sheet(array $me): array
{
    $xp = q('SELECT level, xp FROM levels WHERE level IN (?, ?)', [$me['level'], $me['level'] + 1])
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    $c = CLASSES[$me['class']];
    $uses = max(0, $me['uses'] - $me['uses_spent']) . "/{$me['uses']}";
    $species = max(0, $me['species_uses'] - $me['species_spent']) . "/{$me['species_uses']}";
    $o = origin($me);
    $b = BACKGROUNDS[$me['background'] ?? ''] ?? null;
    $free = q('SELECT id, name FROM spells WHERE level > 0 AND FIND_IN_SET(id, ?) ORDER BY level, name',
        [implode(',', origin_spells($me))])->fetchAll(PDO::FETCH_KEY_PAIR);
    $spent = explode(',', $me['origin_spent']);
    $rest_secs = $me['resting'] === null ? 0 : max(0, (int) q(
        'SELECT TIMESTAMPDIFF(SECOND, NOW(), rest_until) FROM users WHERE id = ?',
        [$me['id']]
    )->fetchColumn());
    return [
        'name' => $me['name'],
        'level' => $me['level'],
        'class' => trim(($o['name'] ?? '') . ' ' . $c['name']),
        'hp' => $me['hp'],
        'max_hp' => $me['max_hp'],
        'temp_hp' => $me['temp_hp'],
        'ac' => $me['ac'],
        'prof' => $me['prof_bonus'],
        'abilities' => array_map(
            fn ($a) => [ucfirst(substr($a, 0, 3)), $me[$a], mod($me[$a]), $me[substr($a, 0, 3) . '_save']],
            ABILITIES
        ),
        'weapon' => $me['weapon'],
        'to_hit' => $me['to_hit'],
        'damage' => sprintf('%s%+d', $me['damage'], $me['attack_mod']),
        'attacks' => $me['attacks'],
        'resources' => [
            ['Hit Dice', ($me['level'] - $me['hit_dice_spent']) . "/{$me['level']} d{$c['die']}"],
            ...match ($me['class']) {
                'fighter' => [['Second Wind', $uses]],
                'barbarian' => [['Rage', $uses]],
                'rogue' => [['Sneak Attack', intdiv($me['level'] + 1, 2) . 'd6']],
                'cleric' => [['Spell slots', slots_left($me)]],
                'monk' => [['Martial Arts', "d{$me['martial_arts']}"], ...($me['uses'] ? [['Focus', $uses]] : [])],
                'paladin' => [['Lay On Hands', $uses], ['Spell slots', slots_left($me)]],
                'ranger' => [['Favored Enemy', $uses], ['Spell slots', slots_left($me)]],
                'wizard' => [['Spell slots', slots_left($me)]],
                'bard' => [['Bardic Inspiration', "$uses d{$me['bardic']}"], ['Spell slots', slots_left($me)]],
                'druid' => [...($me['uses'] ? [['Wild Shape', $uses]] : []), ['Spell slots', slots_left($me)]],
                'warlock' => [
                    ...($me['uses'] ? [["Dark One's Own Luck", $uses]] : []),
                    ['Pact slots', slots_left($me) . ' (level ' . count($me['slots']) . ')'],
                ],
                'sorcerer' => [
                    ['Innate Sorcery', max(0, 2 - $me['feature_spent']) . '/2'],
                    ...($me['uses'] ? [['Sorcery Points', $uses]] : []),
                    ['Spell slots', slots_left($me)],
                ],
            },
            ...match ($me['species']) {
                'dragonborn' => [['Breath Weapon', "$species " . (1 + intdiv($me['level'] + 1, 6)) . 'd10 '
                    . ucfirst($o['resist'])]],
                'goliath' => [[explode(':', $o['about'])[0], $species]],
                'human' => [['Heroic Inspiration', $species]],
                'orc' => [['Relentless Endurance', $species]],
                default => [],
            },
            ...array_map(fn ($id, $name) => [$name, (in_array((string) $id, $spent, true) ? '0' : '1') . '/1 free'],
                array_keys($free), $free),
            ...($me['offhand'] === null ? [] : [['Off hand', sprintf('%s %+d to hit, %s damage%s',
                $me['offhand']['weapon'], $me['offhand']['to_hit'], $me['offhand']['damage'],
                $me['offhand']['mastery'] === 'nick' ? ' (Nick)' : ', a Bonus Action')]]),
            ...(($h = $me['hireling']) === null ? [] : [[$h['name'], "{$h['hp']}/{$h['max_hp']} HP, AC {$h['ac']}, "
                . ($h['mins'] >= 60 ? intdiv($h['mins'], 60) . ' h' : "{$h['mins']} min") . ' paid']]),
        ],
        'status' => array_values(array_filter([
            $me['raging'] ? 'raging' : null,
            $me['innate'] ? 'Innate Sorcery' : null,
            $me['sneaking'] ? ($me['stealth'] !== null ? "hidden (Stealth {$me['stealth']})" : 'sneaking') : null,
            isset($me['shape_name']) ? "Wild Shape: {$me['shape_name']}" : null,
            $me['class'] === 'monk' && !$me['arts'] ? 'no Martial Arts' : null,
            ...explode(',', $me['conditions']),
            ...array_column($me['effects'], 'spell'),
            ($spell = concentrating($me)) === null ? null : "concentrating on $spell",
            $rest_secs > 0 ? "{$me['resting']} rest, " . (int) ceil($rest_secs / 60) . ' min left' : null,
        ])),
        'rest_secs' => $rest_secs, // play.js sends a command when it's up, so the rest finishes on its own
        // Shown, not ruled where SPECIES says so. A hero from before species has none of this.
        'origin' => $o === [] ? [] : [
            ['Species', trim(ucfirst(isset($o['feat']) ? '' : (string) $me['lineage']) . " {$o['name']}") . ': '
                . implode(', ', array_filter([
                    "Speed {$o['speed']} ft",
                    $o['darkvision'] ? "Darkvision {$o['darkvision']} ft" : null,
                    isset($o['resist']) ? ucfirst($o['resist']) . ' Resistance' : null,
                    isset($o['feat']) ? FEATS[$o['feat']]['name'] : null,
                    ...$o['traits'],
                ]))],
            ...($b === null ? [] : [['Background', "{$b['name']}: " . FEATS[$b['feat']]['name'] . "; {$b['skills']}"]]),
        ],
        'xp' => $me['xp'],
        'xp_from' => $xp[$me['level']],
        'xp_next' => $xp[$me['level'] + 1] ?? null,
        'gold' => $me['gold'],
    ];
}

function cmd_score(array $me): array
{
    $s = hero_sheet($me);
    return [
        "{$s['name']}, level {$s['level']} {$s['class']}",
        implode('  ', array_map(fn ($a) => sprintf('%s %d (%+d)', ...$a), $s['abilities'])),
        'Saves  ' . implode('  ', array_map(fn ($a) => sprintf('%s %+d', $a[0], $a[3]), $s['abilities'])),
        "HP {$s['hp']}/{$s['max_hp']}" . ($s['temp_hp'] ? " +{$s['temp_hp']} temp" : '')
            . "   AC {$s['ac']}   Proficiency Bonus +{$s['prof']}",
        sprintf('%s %+d to hit, %s damage', $s['weapon'], $s['to_hit'], $s['damage'])
            . ($s['attacks'] > 1 ? ", {$s['attacks']} attacks a round" : ''),
        implode('   ', [...array_map(fn ($r) => implode(' ', $r), $s['resources']), ...$s['status']]),
        ...array_map(fn ($r) => implode(' ', $r), $s['origin']),
        "XP {$s['xp']}"
            . ($s['xp_next'] === null ? ' (max level)' : " ({$s['xp_next']} for level " . ($s['level'] + 1) . ')')
            . "   Gold {$s['gold']}",
    ];
}

/**
 * Room $id's ZONES row: [first, last, name, board width, board depth, terrain]. A room in none gets a nameless 8x6
 * board.
 */
function zone(int $id): array
{
    foreach (ZONES as $z) {
        if ($id >= $z[0] && $id <= $z[1]) {
            return $z;
        }
    }
    return [$id, $id, '', 8, 6, null];
}

/** Room $id's hand-drawn board, rows of TERRAIN's glyphs and . with a letter where a monster starts, or null. */
function layout(int $id): ?array
{
    $layout = q('SELECT layout FROM rooms WHERE id = ?', [$id])->fetchColumn();
    return is_string($layout) && $layout !== '' ? explode("\n", str_replace("\r", '', trim($layout))) : null;
}

/** The square of room $id's hand-drawn board marked with a letter, where its monster starts; null if none. */
function mark(int $id): ?array
{
    foreach (layout($id) ?? [] as $y => $row) {
        if (preg_match('/[a-z]/', $row, $m, PREG_OFFSET_CAPTURE)) {
            return [$m[0][1], $y];
        }
    }
    return null;
}

/** Room $id's board, [width, depth] in squares: its layout's, else its zone's. */
function board_size(int $id): array
{
    $layout = layout($id);
    return $layout === null ? array_slice(zone($id), 3, 2) : [strlen($layout[0]), count($layout)];
}

/**
 * Room $id's battle board, rows of . and TERRAIN's glyphs: its layout (letters as floor), else generated from its
 * terrain, the same every time (mt_rand seeded by the room, as the Delve is by the week). A generated board keeps the
 * squares by its exits clear and every open square reachable.
 */
function board(int $id): array
{
    $layout = layout($id);
    if ($layout !== null) {
        return array_map(fn ($row) => preg_replace('/[a-z]/', '.', $row), $layout);
    }
    [$w, $h] = board_size($id);
    $terrain = zone($id)[5] ?? q('SELECT terrain FROM rooms WHERE id = ?', [$id])->fetchColumn();
    $rows = array_fill(0, $h, str_repeat('.', $w));
    $keep = [[intdiv($w, 2), intdiv($h, 2)]];
    foreach (q('SELECT dir FROM exits WHERE room_id = ?', [$id])->fetchAll(PDO::FETCH_COLUMN) as $dir) {
        $at = exit_square($id, $dir);
        $keep = [...$keep, $at, ...array_map(fn ($d) => [$at[0] + $d[0], $at[1] + $d[1]], STEPS)];
    }
    mt_srand($id);
    foreach (TERRAIN[$terrain] ?? TERRAIN['field'] as $glyph => $percent) {
        for ($n = intdiv($w * $h * $percent, 100); $n > 0; $n--) {
            [$x, $y] = [mt_rand(0, $w - 1), mt_rand(0, $h - 1)];
            if ($rows[$y][$x] !== '.' || in_array([$x, $y], $keep, true)) {
                continue;
            }
            $rows[$y][$x] = $glyph;
            if (in_array($glyph, ['#', '%'], true) && !connected($rows)) {
                $rows[$y][$x] = '.';
            }
        }
    }
    mt_srand();
    return $rows;
}

/** True if every square of board $rows that can be walked on can be reached from every other. */
function connected(array $rows): bool
{
    $open = [];
    foreach ($rows as $y => $row) {
        for ($x = 0; $x < strlen($row); $x++) {
            if (!in_array($row[$x], ['#', '%'], true)) {
                $open["$x,$y"] = [$x, $y];
            }
        }
    }
    $first = array_key_first($open);
    $seen = [$first => true];
    for ($todo = [$open[$first]]; $todo;) {
        [$x, $y] = array_pop($todo);
        foreach (STEPS as [$dx, $dy]) {
            $k = ($x + $dx) . ',' . ($y + $dy);
            if (isset($open[$k]) && !isset($seen[$k])) {
                [$seen[$k], $todo[]] = [true, $open[$k]];
            }
        }
    }
    return count($seen) === count($open);
}

/** True if square $at of $rows can be stood on: on the board, and not rock or a tree. */
function open_square(array $rows, array $at): bool
{
    return isset($rows[$at[1]][$at[0]]) && $at[0] >= 0 && !in_array($rows[$at[1]][$at[0]], ['#', '%'], true);
}

/**
 * Line of sight on room $room's board from square $a to $b (Bresenham, the squares between): null if rock blocks it,
 * else the cover a tree, boulder or table on the way gives (2: half cover).
 */
function sight(int $room, array $a, array $b): ?int
{
    $rows = board($room);
    [$x, $y] = $a;
    [$dx, $dy] = [abs($b[0] - $x), -abs($b[1] - $y)];
    [$sx, $sy] = [$x < $b[0] ? 1 : -1, $y < $b[1] ? 1 : -1];
    $err = $dx + $dy;
    $cover = 0;
    while (true) {
        $e2 = 2 * $err;
        if ($e2 >= $dy) {
            [$err, $x] = [$err + $dy, $x + $sx];
        }
        if ($e2 <= $dx) {
            [$err, $y] = [$err + $dx, $y + $sy];
        }
        if ([$x, $y] === $b || ($dx === 0 && $dy === 0)) {
            return $cover;
        }
        $cover = $rows[$y][$x] === '%' ? 2 : $cover;
        if ($rows[$y][$x] === '#') {
            return null;
        }
    }
}

/** True if from square $a a $reach-ft attack reaches $b on room $room's board: near enough, and in sight past 5 ft. */
function in_reach(int $room, array $a, array $b, int $reach): bool
{
    return feet($a, $b) <= $reach && ($reach <= 5 || $reach === PHP_INT_MAX || sight($room, $a, $b) !== null);
}

/** The feet it costs to step onto square $at of $rows: 10 on difficult terrain, else 5. */
function step_cost(array $rows, array $at): int
{
    return in_array($rows[$at[1]][$at[0]], [',', '~'], true) ? 10 : 5;
}

/**
 * The cheapest ways across room $room's board from $from, avoiding the $blocked squares (other creatures): 'x,y' =>
 * [feet, the square before it]. A diagonal step can't cut the corner of rock or a tree (SRD 5.2.1 p.15).
 */
function paths(int $room, array $from, array $blocked): array
{
    $rows = board($room);
    $best = [implode(',', $from) => [0, null]];
    $queue = new SplPriorityQueue();
    $queue->insert($from, 0);
    while (!$queue->isEmpty()) {
        $at = $queue->extract();
        $cost = $best[implode(',', $at)][0];
        foreach (STEPS as [$dx, $dy]) {
            $next = [$at[0] + $dx, $at[1] + $dy];
            $k = implode(',', $next);
            if (!open_square($rows, $next) || in_array($next, $blocked, true)
                || ($dx && $dy && (!open_square($rows, [$at[0] + $dx, $at[1]])
                    || !open_square($rows, [$at[0], $at[1] + $dy])))) {
                continue;
            }
            $c = $cost + step_cost($rows, $next);
            if (!isset($best[$k]) || $c < $best[$k][0]) {
                $best[$k] = [$c, $at];
                $queue->insert($next, -$c);
            }
        }
    }
    return $best;
}

/** The distance in feet between squares $a and $b ([x, y]): a diagonal step is 5 ft like any other (SRD 5.2.1 p.15). */
function feet(array $a, array $b): int
{
    return 5 * max(abs($a[0] - $b[0]), abs($a[1] - $b[1]));
}

/** A square's name, as `move` takes it: column letter, row number (a1 is the top left). */
function square(array $at): string
{
    return chr(97 + $at[0]) . ($at[1] + 1);
}

/** The square by exit $dir of room $room's board: mid-wall, or the right-hand corners for stairs (as on the map). */
function exit_square(int $room, string $dir): array
{
    [$w, $h] = board_size($room);
    return match ($dir) {
        'north' => [intdiv($w, 2), 0],
        'south' => [intdiv($w, 2), $h - 1],
        'west' => [0, intdiv($h, 2)],
        'east' => [$w - 1, intdiv($h, 2)],
        'up' => [$w - 1, 0],
        'down' => [$w - 1, $h - 1],
    };
}

/** My square on my room's board: where I came in or last moved, else the middle. */
function my_square(array $me): array
{
    [$w, $h] = board_size((int) $me['room_id']);
    return [$me['x'] ?? intdiv($w, 2), $me['y'] ?? intdiv($h, 2)];
}

/**
 * The next step from $from on the cheapest way (see paths()) to a square where a $reach-ft attack reaches $to (see
 * in_reach(); 0: to $to itself), or if there's none, to the square nearest $to; on a tie, the straightest, as a
 * creature walks. $from itself if that's where it is.
 */
function toward(int $room, array $from, array $to, array $blocked, int $reach = 0): array
{
    $ways = paths($room, $from, $blocked);
    $line = fn ($p, $q) => [feet($p, $q), abs($p[0] - $q[0]) + abs($p[1] - $q[1])];
    $key = function ($k) use ($ways, $room, $to, $reach, $line) {
        $p = array_map('intval', explode(',', $k));
        $ok = $reach === 0 ? $p === $to : in_reach($room, $p, $to, $reach);
        return [!$ok, $ok ? $ways[$k][0] : feet($p, $to), $ways[$k][0], ...$line($p, $to)];
    };
    $goal = array_map('intval', explode(',', array_reduce(array_keys($ways),
        fn ($best, $k) => $best === null || $key($k) < $key($best) ? $k : $best)));
    if ($goal === $from) {
        return $from;
    }
    // Of the first steps on a cheapest way there (a way back from it costs the same), the straightest toward it.
    $rows = board($room);
    $back = paths($room, $goal, $blocked);
    $total = $ways[implode(',', $goal)][0];
    $best = null;
    foreach (STEPS as [$dx, $dy]) {
        $n = [$from[0] + $dx, $from[1] + $dy];
        $k = implode(',', $n);
        if (isset($ways[$k], $back[$k]) && $ways[$k][0] === step_cost($rows, $n) // a step straight there
            && $back[$k][0] - step_cost($rows, $n) + step_cost($rows, $goal) + $ways[$k][0] === $total
            && ($best === null || $line($n, $goal) < $line($best, $goal))) {
            $best = $n;
        }
    }
    return $best ?? $goal;
}

/** The open squares next to $at on room $room's board, other than the $blocked ones. */
function neighbours(int $room, array $at, array $blocked): array
{
    $rows = board($room);
    $all = array_map(fn ($d) => [$at[0] + $d[0], $at[1] + $d[1]], STEPS);
    return array_values(array_filter($all, fn ($p) => open_square($rows, $p) && !in_array($p, $blocked, true)));
}

/** The square next to $at nearest $near (straightest on a tie), not a $blocked one; $at if there's none. */
function beside(int $room, array $at, array $near, array $blocked): array
{
    $key = fn ($p) => [feet($p, $near), abs($p[0] - $near[0]) + abs($p[1] - $near[1])];
    $best = null;
    foreach (neighbours($room, $at, $blocked) as $p) {
        $best = $best === null || $key($p) < $key($best) ? $p : $best;
    }
    return $best ?? $at;
}

/** The open square of room $room's board nearest $at (itself if it's open), other than the $taken ones. */
function open_near(int $room, array $at, array $taken): array
{
    $rows = board($room);
    $best = null;
    foreach ($rows as $y => $row) {
        for ($x = 0; $x < strlen($row); $x++) {
            if (open_square($rows, [$x, $y]) && !in_array([$x, $y], $taken, true)
                && ($best === null || feet([$x, $y], $at) < feet($best, $at))) {
                $best = [$x, $y];
            }
        }
    }
    return $best ?? $at;
}

/** My hireling's square in this fight, or null if I have none (or it has none yet). */
function hire_square(array $me): ?array
{
    $h = $me['hireling'] ?? null;
    return $h === null || $h['x'] === null ? null : [$h['x'], $h['y']];
}

/**
 * In a fight, my room's board in place of the map: its squares walled by #, its exits as gaps (stairs < > in the
 * right-hand corners, as on the map), @ me and my foe by initial, then my foe's distance and HP, and the legend. Plain
 * text, as cmd_map(): play.js letters the columns and numbers the rows, and makes the squares tappable.
 */
function board_map(array $me): array
{
    // Not find_foe(): its respawn rolls HP, and drawing the board must not spend dice.
    $all = fight_monsters($me);
    usort($all, fn ($a, $b) => $a['id'] <=> $b['id']);
    $room = (int) $me['room_id'];
    $rows = board($room);
    [$w, $h] = [strlen($rows[0]), count($rows)];
    $grid = [str_repeat('#', $w + 2), ...array_map(fn ($row) => "#$row#", $rows), str_repeat('#', $w + 2)];
    foreach (q('SELECT dir FROM exits WHERE room_id = ?', [$room])->fetchAll(PDO::FETCH_COLUMN) as $dir) {
        [$x, $y] = exit_square($room, $dir);
        match ($dir) {
            'north' => $grid[0][$x + 1] = '.',
            'south' => $grid[$h + 1][$x + 1] = '.',
            'west' => $grid[$y + 1][0] = '.',
            'east' => $grid[$y + 1][$w + 1] = '.',
            'up' => $grid[1][$w] = '<',
            'down' => $grid[$h][$w] = '>',
        };
    }
    $mine = my_square($me);
    foreach ($all as $m) {
        $grid[$m['y'] + 1][$m['x'] + 1] = strtolower($m['name'][0]);
    }
    if (($hire = hire_square($me)) !== null) {
        $grid[$hire[1] + 1][$hire[0] + 1] = strtoupper($me['hireling']['name'][0]);
    }
    foreach (q('SELECT u.name, u.x, u.y, u.hireling_x, u.hireling_y, m.name AS hireling FROM users u
        LEFT JOIN monsters m ON m.id = u.hireling_monster_id WHERE u.room_id = ? AND u.fighting IS NOT NULL
        AND u.id <> ? AND u.x IS NOT NULL', [$room, $me['id']]) as $u) { // the other heroes, and their hirelings
        $grid[$u['y'] + 1][$u['x'] + 1] = strtoupper($u['name'][0]);
        if ($u['hireling'] !== null && $u['hireling_x'] !== null) {
            $grid[$u['hireling_y'] + 1][$u['hireling_x'] + 1] = strtoupper($u['hireling'][0]);
        }
    }
    $grid[$mine[1] + 1][$mine[0] + 1] = '@';
    return [...$grid, implode('  ', array_map(fn ($m) => "{$m['name']}: " . feet($mine, [$m['x'], $m['y']])
        . " ft away, {$m['hp']} HP.", $all)), '@ you  a-z foe  A-Z ally  tap: move, attack, flee'];
}

/**
 * The explored part of my zone, placed on a grid by walking exits out from me; an exit into another zone is a gap
 * onto rock. Fog of war: a room I've walked into (visited, or the one I'm in; the whole town always) shows its floor,
 * exits, stairs and whether it's safe; a room next to one of those shows only its walls; the rest is rock. What's
 * inside shows only for the room I'm in. play.js scrolls the map to keep @ in view.
 */
function cmd_map(array $me): array
{
    if ($me['fighting'] !== null) {
        return board_map($me);
    }
    $here = (int) $me['room_id'];
    [$first, $last, $zone] = zone($here);
    $exits = [];
    foreach (q('SELECT room_id, dir, to_room FROM exits WHERE room_id BETWEEN ? AND ?', [$first, $last])->fetchAll()
        as $e) {
        $exits[$e['room_id']][$e['dir']] = $e['to_room'];
    }
    $at = [$here => [0, 0]]; // room id => [x, y]; north is -y
    $taken = ['0,0' => true];
    for ($todo = [$here]; $todo;) {
        $id = array_shift($todo);
        foreach ($exits[$id] ?? [] as $dir => $to) {
            if (!isset(MAP_STEPS[$dir]) || isset($at[$to]) || $to < $first || $to > $last) {
                continue;
            }
            [$x, $y] = [$at[$id][0] + MAP_STEPS[$dir][0], $at[$id][1] + MAP_STEPS[$dir][1]];
            // shortcut: a room whose cell is already taken is left off (a few in koboldmines), fine while rare.
            if (!isset($taken["$x,$y"])) {
                [$at[$to], $taken["$x,$y"], $todo[]] = [[$x, $y], true, $to];
            }
        }
    }
    $known = $here <= TOWN_LAST_ROOM ? array_keys($at) : [$here, ...array_intersect(array_keys($at),
        q('SELECT room_id FROM visited WHERE user_id = ? AND room_id BETWEEN ? AND ?', [$me['id'], $first, $last])
            ->fetchAll(PDO::FETCH_COLUMN))];
    $shown = array_fill_keys($known, true);
    foreach ($known as $id) {
        foreach ($exits[$id] ?? [] as $to) {
            $shown[$to] ??= false; // an outline
        }
    }
    $at = array_intersect_key($at, $shown);
    $ids = array_keys($at);
    $safe = q(
        'SELECT id FROM rooms WHERE safe AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
        $ids
    )->fetchAll(PDO::FETCH_COLUMN);
    // Not room_monsters(): its respawn rolls HP, and drawing the map must not spend dice.
    $foes = q(
        'SELECT m.name FROM spawns s JOIN monsters m ON m.id = s.monster_id WHERE s.room_id = ?
         AND (s.dead_since IS NULL OR s.dead_since < NOW() - INTERVAL s.respawn_secs SECOND) ORDER BY s.id',
        [$here]
    )->fetchAll(PDO::FETCH_COLUMN);
    // Each room is a 5x3 floor (: when safe) walled by #, sharing walls with its neighbours; an exit is a gap in the
    // wall. Stairs sit in the right-hand corners (< up, > down); monsters in your room stand around you by initial.
    // play.js colours these glyphs, so keep them in step with MAP_CELLS there.
    $xs = array_column($at, 0);
    $ys = array_column($at, 1);
    [$x0, $y0] = [min($xs), min($ys)];
    $grid = array_fill(0, 4 * (max($ys) - $y0) + 5, array_fill(0, 6 * (max($xs) - $x0) + 7, ' '));
    foreach ($at as $id => [$x, $y]) {
        $top = 4 * ($y - $y0) + 1;
        $left = 6 * ($x - $x0) + 1;
        for ($r = $top - 1; $r <= $top + 3; $r++) {
            for ($c = $left - 1; $c <= $left + 5; $c++) {
                $inside = $r >= $top && $r < $top + 3 && $c >= $left && $c < $left + 5;
                if ($inside && $shown[$id]) {
                    $grid[$r][$c] = in_array($id, $safe, true) ? ':' : '.';
                } elseif (!$inside && $grid[$r][$c] === ' ') {
                    $grid[$r][$c] = '#';
                }
            }
        }
        if (!$shown[$id]) {
            continue;
        }
        foreach (array_keys($exits[$id] ?? []) as $dir) {
            match ($dir) {
                'north' => $grid[$top - 1][$left + 2] = '.',
                'south' => $grid[$top + 3][$left + 2] = '.',
                'west' => $grid[$top + 1][$left - 1] = '.',
                'east' => $grid[$top + 1][$left + 5] = '.',
                'up' => $grid[$top][$left + 4] = '<',
                'down' => $grid[$top + 2][$left + 4] = '>',
            };
        }
        if ($id === $here) {
            $grid[$top + 1][$left + 2] = '@';
            $spots = [[1, 0], [1, 4], [0, 1], [0, 3], [2, 1], [2, 3]];
            foreach (array_slice($foes, 0, count($spots)) as $i => $name) {
                $grid[$top + $spots[$i][0]][$left + $spots[$i][1]] = strtolower($name[0]);
            }
        }
    }
    $lines = array_map(fn ($chars) => rtrim(implode('', $chars)), $grid);
    $lines[] = ($zone === '' ? '' : "$zone. ") . ($foes ? 'Here: ' . implode(', ', $foes) : 'Here: no monsters');
    $lines[] = '@ you  < > stairs  : safe  a-z monsters';
    return $lines;
}

/** Says something to the room, or shouts it: in town everyone in town hears a shout, elsewhere only the room. */
function cmd_say(array $me, string $text, bool $shout = false): array
{
    if ($text === '') {
        return [$shout ? 'Shout what?' : 'Say what?'];
    }
    q('INSERT INTO events (room_id, user_id, text, shout) VALUES (?, ?, ?, ?)',
        [$me['room_id'], $me['id'], $text, (int) $shout]);
    $lines = [($shout ? 'You shout: ' : 'You say: ') . $text];
    if ($me['sneaking']) {
        // Louder than a whisper ends hiding (the Hide action, SRD 5.2.1 p.183), so speaking ends sneaking too.
        q('UPDATE users SET sneaking = FALSE, stealth = NULL WHERE id = ?', [$me['id']]);
        $lines[] = 'You stop sneaking.';
    }
    return $lines;
}

function cmd_who(): array
{
    $rows = q(
        'SELECT u.name, r.name AS room, TIMESTAMPDIFF(MINUTE, u.last_seen, NOW()) AS mins
         FROM users u JOIN rooms r ON r.id = u.room_id WHERE u.name IS NOT NULL
         ORDER BY u.last_seen IS NULL, u.last_seen DESC'
    )->fetchAll();
    $lines = ['Heroes of Greenest:'];
    foreach ($rows as $r) {
        $seen = $r['mins'] === null ? 'never' : ago((int) $r['mins']);
        $lines[] = sprintf('  %-16s %-24s %s', $r['name'], $r['room'], $seen);
    }
    return $lines;
}

function cmd_help(array $me): array
{
    $lines = ['Commands (start typing for suggestions, Tab to complete):'];
    foreach (my_commands($me) as $usage => $what) {
        $lines[] = sprintf('  %-14s %s', $usage, $what);
    }
    return $lines;
}

/**
 * The buttons above the prompt: commands that fit right now, sent as if typed. One ending in a space needs more
 * typed after it, so play.js puts it in the prompt instead. A shortcut only: the handlers still check everything.
 */
function actions(array $me): array
{
    if ($me['setup'] !== null) {
        return match ($me['setup']) {
            'name' => [],
            'class' => array_keys(CLASSES),
            'abilities' => ['ok', 'back'],
            // A species with a choice goes in the prompt, for choices() to offer its kinds.
            'species' => [...array_map(fn ($sp) => isset(SPECIES[$sp]['kinds']) ? "$sp " : $sp, array_keys(SPECIES)),
                'back'],
            'background' => [...array_keys(BACKGROUNDS), 'back'],
            'confirm' => ['begin', 'back'],
        };
    }
    $mine = array_map(fn ($usage) => explode(' ', $usage)[0], array_keys(my_commands($me)));
    $mine = array_intersect(
        ['breathe', 'reckless', 'shove', 'grapple', 'flurry', 'patient', 'stun', 'land', 'second', 'rage', 'innate',
            'wholeness', 'lay', 'cast'],
        $mine
    );
    $cast = in_array('cast', $mine, true) ? ['cast '] : [];
    if ($me['fighting'] !== null) {
        // Every attack goes at the monster I'm fighting, so none of these needs a name.
        $potions = array_filter(objects_here('user_id', (int) $me['id']), fn ($o) => $o['slot'] === 'potion');
        return ['kill', ...array_diff($mine, ['cast']), ...$cast, ...($potions ? ['drink '] : []), 'flee'];
    }
    $exits = q('SELECT dir FROM exits WHERE room_id = ?', [$me['room_id']])->fetchAll(PDO::FETCH_COLUMN);
    $safe = q('SELECT safe FROM rooms WHERE id = ?', [$me['room_id']])->fetchColumn();
    return [
        ...(monsters_here($me) ? ['kill '] : []),
        ...(objects_here('room_id', (int) $me['room_id']) ? ['get '] : []),
        ...array_values(array_intersect(DIRS, $exits)),
        ...$cast,
        ...($safe ? ['rest', 'sleep'] : ['sneak']),
        ...($me['room_id'] === LANTERN ? ['hire '] : []),
        ...(stock((int) $me['room_id']) ? ['list', 'buy ', 'sell '] : []),
        ...($me['room_id'] === TEMPLE ? ['list', 'pray '] : []),
        ...(npcs_here((int) $me['room_id']) ? ['ask '] : []),
        ...(npcs_here((int) $me['room_id']) || features_here((int) $me['room_id']) ? ['inspect '] : []),
        'look',
        'inventory',
    ];
}

/**
 * What can follow a command, for the buttons once it's typed: [prefix => [[label, command], ...]]. As in actions(), a
 * command ending in a space needs more, and is then a prefix itself (a spell at a monster outside a fight, say).
 */
function choices(array $me): array
{
    if ($me['setup'] !== null) { // a species' kinds, once its name is in the prompt
        $c = [];
        foreach ($me['setup'] === 'species' ? SPECIES : [] as $species => $s) {
            $c[$species] = array_map(fn ($k) => [$k, "$species $k"], array_keys($s['kinds'] ?? []));
        }
        return array_filter($c);
    }
    $mine = array_map(fn ($usage) => explode(' ', $usage)[0], array_keys(my_commands($me)));
    $has = fn (string $verb): bool => in_array($verb, $mine, true);
    $pick = fn (string $verb, array $names): array => array_map(fn ($n) => [$n, "$verb $n"], array_values($names));
    $names = fn (array $objects): array => array_unique(array_map('strtolower', array_column($objects, 'name')));
    $foes = monsters_here($me);
    $heroes = $names(q('SELECT name FROM users WHERE room_id = ? AND id <> ? AND setup IS NULL ORDER BY name',
        [$me['room_id'], $me['id']])->fetchAll());
    $carried = objects_here('user_id', (int) $me['id']);
    $c = [
        'get' => $pick('get', $names(objects_here('room_id', (int) $me['room_id']))),
        'drop' => $pick('drop', $names($carried)),
        'equip' => $pick('equip', $names(array_filter($carried, fn ($o) => !$o['worn'] && $o['slot'] !== 'potion'))),
        'drink' => $pick('drink', $names(array_filter($carried, fn ($o) => $o['slot'] === 'potion'))),
        'hire' => $me['room_id'] === LANTERN ? $pick('hire', array_keys(HIRELINGS)) : [],
        'buy' => $pick('buy', $names(stock((int) $me['room_id']))),
        'sell' => stock((int) $me['room_id']) ? $pick('sell', $names($carried)) : [],
        'pray' => $me['room_id'] === TEMPLE ? $pick('pray', array_keys(PRAYERS)) : [],
        'inspire' => $has('inspire') ? $pick('inspire', $heroes) : [],
        'lay' => $has('lay') ? [['yourself', 'lay'], ...$pick('lay', $heroes)] : [],
        'wild' => $has('wild') ? [...($me['shape'] !== null ? [['your own form', 'wild']] : []),
            ...$pick('wild', $names(wild_forms($me)))] : [],
    ];
    $npcs = npcs_here((int) $me['room_id']);
    $c['inspect'] = $pick('inspect', [...array_map(fn ($f) => strtok($f['keywords'], ' '),
        features_here((int) $me['room_id'])), ...array_map(fn ($n) => strtolower(short_name($n)), $npcs)]);
    foreach ($npcs as $n) {
        $ask = 'ask ' . strtolower(short_name($n));
        $c['ask'][] = [strtolower(short_name($n)), "$ask "];
        $c[$ask] = array_map(fn ($t) => [strtok($t['keywords'], ' '), "$ask about " . strtok($t['keywords'], ' ')],
            array_values(array_filter(topics_of($n), fn ($t) => !str_starts_with($t['keywords'], 'hello'))));
    }
    foreach (['kill', 'breathe', 'reckless', 'shove', 'grapple', 'flurry', 'patient', 'stun', 'land'] as $verb) {
        $c[$verb] = $verb === 'kill' || $has($verb) ? $pick($verb, $foes) : [];
    }
    foreach ($has('cast') ? known_spells($me) : [] as $k) {
        $cast = 'cast ' . strtolower($k['name']);
        // In a fight a spell goes at the monster I'm fighting; an ally spell with no name is on myself.
        $more = match (true) {
            $k['targets'] === 'foe' && $me['fighting'] === null => $pick($cast, $foes),
            $k['targets'] === 'ally' && $heroes !== [] => [['yourself', $cast], ...$pick($cast, $heroes)],
            default => [],
        };
        $c['cast'][] = [strtolower($k['name']), $cast . ($more ? ' ' : '')];
        $c[$cast] = $more;
    }
    return array_filter($c);
}

/** The names of the living monsters here, lowercase, each once. */
function monsters_here(array $me): array
{
    // shortcut: not room_monsters(), which respawns (and rolls); a monster due back shows after the next look
    return q(
        'SELECT DISTINCT LOWER(m.name) FROM spawns s JOIN monsters m ON m.id = s.monster_id
         WHERE s.room_id = ? AND s.dead_since IS NULL ORDER BY 1',
        [$me['room_id']]
    )->fetchAll(PDO::FETCH_COLUMN);
}

function ago(int $mins): string
{
    return match (true) {
        $mins < 1 => 'just now',
        $mins < 60 => "$mins min ago",
        $mins < 1440 => intdiv($mins, 60) . ' h ago',
        default => intdiv($mins, 1440) . ' d ago',
    };
}
