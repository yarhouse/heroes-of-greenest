# Choices and Skills Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Heroes pick their skill proficiencies and Expertise on a readable panel, level up when a rest ends, and
make character creation's picks on the same panel; nothing blocks play outside creation.

**Architecture:** A `picks` table and `owed_choices($me)` in `src/game.php` decide what a hero still owes; a
`choose` command records picks, gated by `users.may_choose` (set when a rest ends). `panel($me)` describes the
current choice as data, `api.php` sends it, and `play.js` renders it in place of the log, sending the same text a
player could type.

**Tech Stack:** PHP 8.1 (no framework, no Composer), MySQL 8, plain JS with `// @ts-check`, Bootstrap 5.3 (vendored).

**Spec:** `docs/superpowers/specs/2026-10-17-choices-and-skills-design.md`

## Global Constraints

- PHP: PSR-12, 4 spaces, `declare(strict_types=1)`; all SQL through `q()` with bound parameters.
- MySQL 8 SQL (not MariaDB). Every table change is a `db/migrations/*.sql` file and also made in `db/schema.sql`.
- `api.php` never sends HTML; `play.js` builds DOM with `textContent` only, no `innerHTML`, no inline styles (CSP
  `'self'`); show and hide with the `hidden` attribute and classes.
- Every random number goes through `roll()`; tests queue faces in `$dice_queue`. A rest that heals rolls Hit Dice,
  so tests that finish a rest set `hp = max_hp` first.
- Nothing blocks play outside character creation. A short rest is 1 minute (`SHORT_REST_SECS`).
- Copy fixed by the spec: "Level up ready: short rest", "Skills to choose: short rest", "Choose skills",
  "Rest first (a short rest takes 1 minute).", "Choose your skills first.", "Pick N more.",
  "You reach level N! +X max HP."
- Validate with `php tests/selftest.php` (every line `ok`, exit 0) and in a browser at http://localhost:8080.
- Commit messages end with:
  `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` and
  `Claude-Session: https://claude.ai/code/session_01RR19CWNvvHnm8bGyPeVFGw`.
  Never stage Aaron's uncommitted `ROADMAP.md` / `BACKLOG.md` edits except in Task 7.

## Review Focus

1. A class list that background skills shrink below its pick count would be an impossible choice: `pick` is
   `min(count, options not locked)` (test: Cleric + Acolyte locks Insight and Religion and still picks 2). Task 1.
2. Confirm tapped twice queues the same `choose` twice: the second says "You have nothing to choose." and the picks
   stay exactly as chosen. Task 2.
3. Typed skills with odd case, commas and spacing ("Animal Handling,ATHLETICS", "sleight") parse; an ambiguous
   prefix ("in": insight, intimidation, investigation) is refused naming the word. Task 2.
4. Levels: XP past two levels gives both on one rest; leaving mid-rest (which cancels it) gives none; a hand-set
   level above the XP never drops. Task 3.
5. A hero with `may_choose` who walks into a fight gets no panel over the board (`panel()` is null while `fighting`
   is set) and `choose` there says "Not in the middle of a fight." Tasks 2 and 5.

---

### Task 1: Picks, skill lists, owed choices, skill bonuses

**Files:**
- Create: `db/migrations/2026-10-17-picks.sql`
- Modify: `db/schema.sql` (DROP list line 4; `users` ~line 163; new `picks` table after `checks`)
- Modify: `src/game.php` (`CLASSES` 95-221 and its doc comment, `FEATS['skilled']` ~323, `skill_bonus()` 1241)
- Test: `tests/selftest.php` (new section `// Choices and skills.` just before the Delve section)

**Interfaces:**
- Produces:
  - `picks (user_id INT NOT NULL, choice VARCHAR(20) NOT NULL, value VARCHAR(20) NOT NULL, PRIMARY KEY (user_id,
    choice, value), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)`;
    `users.may_choose BOOL NOT NULL DEFAULT FALSE`. (Match `users.id`'s type in `schema.sql`.)
  - `CLASSES[$class]['skills'] => [int $count, string[] $list]` — lowercase `SKILLS` keys; Bard's list is all 18.
  - `const EXPERTISE = ['rogue' => [1, 6], 'bard' => [2, 9]];` — the levels granting `expertise 1`, `expertise 2`.
  - `picks(array $me): array` — `[choice id => string[] values]`.
  - `proficient_skills(array $me): array` — lowercase keys: background skills + `class skills` + `skilled` picks.
  - `owed_choices(array $me): array` — the choices still owed, in the order `class skills`, `skilled`,
    `expertise 1`, `expertise 2`, each `['id' => string, 'title' => string, 'pick' => int, 'options' => string[],
    'locked' => string[]]`: `options` all listed skills in `SKILLS` order, `locked` the listed ones already mine
    (for Expertise: already Expertise), `pick` how many more to choose, capped at the unlocked options. Titles
    "{Class name} skills", "Skilled", "Expertise". `skilled` is owed when `has_feat($me, 'skilled')`. Empty with no
    class yet.
  - `skill_bonus(array $me, string $skill): int` — unchanged signature: modifier + PB if proficient, twice PB with an
    `expertise %` pick, else `intdiv(PB, 2)` for a Bard of level 2+ (Jack of All Trades).

- [ ] **Step 1: Write the failing tests**

```php
    // Choices and skills.
    create('Pick', 'rogue', 'ok', 'human skilled', 'criminal');  // Criminal: Sleight of Hand, Stealth
    $pid = hero('Pick')['id'];
    q('DELETE FROM picks WHERE user_id = ?', [$pid]);  // from Task 2 on, create() has picked already
    $o = owed_choices(hero('Pick'));
    check(array_column($o, 'id') === ['class skills', 'skilled', 'expertise 1'] && $o[0]['pick'] === 4
        && $o[0]['locked'] === ['sleight of hand', 'stealth'] && count($o[0]['options']) === 10,
        'a Rogue owes 4 class skills, Skilled and Expertise; background skills locked');
    foreach (['acrobatics', 'insight', 'perception', 'investigation'] as $s) {
        q("INSERT INTO picks VALUES (?, 'class skills', ?)", [$pid, $s]);
    }
    $o = owed_choices(hero('Pick'));
    check($o[0]['id'] === 'skilled' && in_array('perception', $o[0]['locked'], true), 'Skilled locks what I have');
    foreach (['athletics', 'history', 'nature'] as $s) {
        q("INSERT INTO picks VALUES (?, 'skilled', ?)", [$pid, $s]);
    }
    $o = owed_choices(hero('Pick'));
    check($o[0]['id'] === 'expertise 1' && count($o[0]['options']) === 9 && !in_array('arcana', $o[0]['options'], true),
        'Expertise offers only skills I am proficient in');
    $p = with_stats(hero('Pick'));
    $dex = mod($p['dexterity']);
    check(skill_bonus($p, 'stealth') === $dex + 2 && skill_bonus($p, 'acrobatics') === $dex + 2,
        'proficient: + Proficiency Bonus');
    check(skill_bonus($p, 'arcana') === mod($p['intelligence']), 'not proficient: the modifier alone');
    q("INSERT INTO picks VALUES (?, 'expertise 1', 'stealth'), (?, 'expertise 1', 'perception')", [$pid, $pid]);
    check(skill_bonus(with_stats(hero('Pick')), 'stealth') === $dex + 4 && owed_choices(hero('Pick')) === [],
        'Expertise doubles it, and nothing is owed');
    q('UPDATE users SET level = 6 WHERE id = ?', [$pid]);
    check(array_column(owed_choices(hero('Pick')), 'id') === ['expertise 2'], 'Rogue 6 owes a second Expertise');
    create('Lute', 'bard');
    q("UPDATE users SET level = 2 WHERE name = 'Lute'");
    $b = with_stats(hero('Lute'));
    check(skill_bonus($b, 'survival') === mod($b['wisdom']) + 1, 'Jack of All Trades: half the bonus, rounded down');
    create('Vow', 'cleric', 'ok', 'human alert', 'acolyte');  // Acolyte: Insight, Religion
    q('DELETE FROM picks WHERE user_id = ?', [hero('Vow')['id']]);
    $o = owed_choices(hero('Vow'));
    check($o[0]['pick'] === 2 && $o[0]['locked'] === ['insight', 'religion'], 'Cleric + Acolyte still picks 2');
```

- [ ] **Step 2: Run to verify it fails**

  Run: `php tests/selftest.php | grep -v '^ok'`
  Expected: the migrations check fails and a fatal "Call to undefined function owed_choices()".

- [ ] **Step 3: Migration and schema.** Write the migration (the table and the `ALTER TABLE users ADD may_choose`),
  make the same change in `schema.sql`, run `php bin/migrate.php`.

- [ ] **Step 4: Data.** Add `'skills'` to each `CLASSES` entry from the SRD class tables (`ref/srd521-flow.txt`,
  "Skill Proficiencies / Choose N"): Barbarian 2, Bard 3 (any), Cleric 2, Druid 2, Fighter 2, Monk 2, Paladin 2,
  Ranger 3, Rogue 4, Sorcerer 2, Warlock 2, Wizard 2; one clause in the `CLASSES` doc comment. Add `EXPERTISE`.
  `FEATS['skilled']['about']` becomes `'three more skills of your choice'`; drop "Skilled is only shown." from the
  `FEATS` comment.

- [ ] **Step 5: Implement** `picks()`, `proficient_skills()`, `owed_choices()` beside `skill_bonus()`, and the new
  `skill_bonus()` (update its doc line and the `SKILLS` comment).

- [ ] **Step 6: Run** `php tests/selftest.php` — Expected: all `ok`, exit 0.

- [ ] **Step 7: Commit**

```bash
git add db/migrations/2026-10-17-picks.sql db/schema.sql src/game.php tests/selftest.php
git commit -m "Choices: picks, class skill lists, owed choices, skill bonuses"
```

---

### Task 2: The `choose` command and creation

**Files:**
- Modify: `src/game.php` (`COMMANDS` ~355, `run_command()` 647, `cmd_setup()` 868, `setup_abilities()` 940,
  `setup_background()` 1035)
- Test: `tests/selftest.php` (`create()` ~31 and its doc comment; the Choices section)

**Interfaces:**
- Consumes: `owed_choices()`, `picks()` (Task 1).
- Produces:
  - `parse_skills(string $text): array|string` — the `SKILLS` keys named, in order, or an error line. Commas
    optional, case-insensitive; at each word take the longest run of 3, 2 or 1 words that equals a key or is a prefix
    of exactly one key. No match: "There's no skill '<word>'."; several: "'in' could be insight, intimidation or
    investigation." (the matches joined like the rest of the game's lists).
  - `choice_lines(array $choice): array` — the title, "Choose N:", one `sprintf('  %-16s %s', …)` line per option
    with its ability abbreviation and "(yours)" when locked, and "Type 'choose' and the skills, with commas."
  - `cmd_choose(array $me, string $arg): array` — bare: `choice_lines()` of the first owed choice; with skills:
    validate, insert, reply "You choose Acrobatics, Insight, Perception and Investigation." then the next choice's
    lines, if any; clear `may_choose` when nothing is left. Refusals, checked in this order: "Not in the middle of a
    fight." (`fighting` set), "You have nothing to choose.", "Rest first (a short rest takes 1 minute)." (outside
    creation without `may_choose`), a `parse_skills()` error, "Choose exactly N.", "You already have Stealth.",
    "Arcana isn't one of them."
  - `best_two(array $me, array $abilities): array` — `[+2 ability, +1 ability]`, the `usort` now inside
    `setup_background()` moved out unchanged; Task 5 reuses it.
  - `COMMANDS['choose <skills>'] = 'after a rest, make the choices your class and level give you (choose alone lists them)'`.
  - Creation: when the background step ends with something owed, reply with `choice_lines()` instead of the
    summary; at the confirm step `choose …` goes to `cmd_choose()` (no `may_choose` needed) and `begin` while owed
    replies "Choose your skills first." plus the lines; `setup_abilities()` also deletes the hero's picks, so `back`
    clears them.

- [ ] **Step 1: Write the failing tests** (append to the Choices section)

```php
    create('Chs', 'fighter');
    check(hero('Chs')['setup'] === null && count(picks(hero('Chs'))['class skills'] ?? []) === 2,
        'create() makes every owed choice');
    q('DELETE FROM picks WHERE user_id = ?', [hero('Chs')['id']]);
    check(says(run_command(hero('Chs'), 'choose athletics, perception'), 'Rest first (a short rest takes 1 minute).'),
        'outside creation, choosing waits for a rest');
    check(!says(run_command(hero('Chs'), 'look'), 'Choose'), 'and nothing blocks play meanwhile');
    q("UPDATE users SET may_choose = TRUE WHERE name = 'Chs'");
    check(says(run_command(hero('Chs'), 'choose athletics'), 'Choose exactly 2.'), 'a wrong count is refused');
    check(says(run_command(hero('Chs'), 'choose athletics, arcana'), "Arcana isn't one of them."),
        'a skill off the list is refused');
    check(says(run_command(hero('Chs'), 'choose in, athletics'),
        "'in' could be insight, intimidation or investigation."), 'an ambiguous prefix is refused');
    $out = run_command(hero('Chs'), 'choose Animal Handling,ATHLETICS');
    check(says($out, 'You choose Animal Handling and Athletics.') && hero('Chs')['may_choose'] === 0,
        'case, commas and two-word names parse; may_choose clears when nothing is left');
    check(says(run_command(hero('Chs'), 'choose animal handling, athletics'), 'You have nothing to choose.')
        && count(picks(hero('Chs'))['class skills']) === 2, 'a second Confirm changes nothing');
    check(parse_skills('sleight stealth') === ['sleight of hand', 'stealth'], 'prefixes parse');
    q("UPDATE users SET may_choose = TRUE, fighting = 9999 WHERE name = 'Chs'");  // the Training Dummy's spawn
    check(says(run_command(hero('Chs'), 'choose'), 'Not in the middle of a fight.'), 'no choosing mid-fight');
    q("UPDATE users SET fighting = NULL, may_choose = FALSE WHERE name = 'Chs'");
    save_account('Newb', 'correct horse');
    foreach (['Newb', 'rogue', 'ok', 'dwarf', 'criminal'] as $step) {
        $out = run_command(hero('Newb'), $step);
    }
    check(says($out, 'Rogue skills') && says($out, '(yours)'), 'creation asks for skills, background ones marked');
    check(says(run_command(hero('Newb'), 'begin'), 'Choose your skills first.'), 'begin waits for them');
    check(says(run_command(hero('Newb'), 'choose stealth, acrobatics, insight, perception'),
        'You already have Stealth.'), 'a locked skill is refused');
    run_command(hero('Newb'), 'choose acrobatics, insight, perception, investigation');
    foreach (['back', 'rogue', 'ok'] as $step) {
        run_command(hero('Newb'), $step);
    }
    check(picks(hero('Newb')) === [], 'back clears picks');
```

  If `fighting = 9999` breaks a foreign key or the spawn is gone by then, use any live spawn id the section can
  find (`SELECT id FROM spawns WHERE dead_since IS NULL LIMIT 1`).

- [ ] **Step 2: Run to verify it fails** — `php tests/selftest.php | grep -v '^ok'`: the new checks fail.

- [ ] **Step 3: Implement** the functions, the `COMMANDS` line, `$verb === 'choose' => cmd_choose($me, trim($arg))`
  in `run_command()`, and the creation changes. Update `create()` in the selftest: after the background (or the
  skipped-background `UPDATE`), `while ($o = owed_choices(hero($name)))` send `'choose ' . implode(', ',
  array_slice(array_values(array_diff($o[0]['options'], $o[0]['locked'])), 0, $o[0]['pick']))`, then `begin`.

- [ ] **Step 4: Run the whole selftest** — Expected: exit 0. Because `create()` now gives every test hero its
  class's first skills, a skill check in the inspect/ask/sneak sections may gain the Proficiency Bonus: fix such a
  FAIL in the test (its queued d20 face), never in the game, and say so in the commit message.

- [ ] **Step 5: Commit** — `git add src/game.php tests/selftest.php && git commit -m "choose: pick skills after a rest or in creation"`

---

### Task 3: Levels arrive with a rest

**Files:**
- Modify: `src/game.php` (`award()` 2881, `finish_rest()` 4175)
- Test: `tests/selftest.php` (the level check ~559; the Choices section)

**Interfaces:**
- Consumes: `owed_choices()` (Task 1).
- Produces: `level_up(array &$me, array &$lines): void` — reads XP and level fresh, raises the level to the highest
  `levels` row at or below the XP (never lower), adds fixed HP per level and Dwarven Toughness exactly as `award()`
  does today, appends "You reach level N! +X max HP.", and updates `$me`. `finish_rest()` applies the rest first,
  then calls `level_up()` (either kind of rest), then sets `may_choose = TRUE` and adds "You have choices to make:
  type 'choose'." when `owed_choices()` is non-empty. `award()` keeps its signature and only adds XP and gold.

- [ ] **Step 1: Write the failing tests.** Replace the level check at ~559 with:

```php
    check(!says($out, 'You reach level') && $t['level'] === 1, 'XP alone does not level you');
    q("UPDATE users SET hp = max_hp, resting = 'short', rest_until = NOW() WHERE name = 'Testa'");
    $out = run_command(hero('Testa'), '');
    $t = hero('Testa');
    check(says($out, 'You reach level 2! +7 max HP.') && $t['level'] === 2 && $t['max_hp'] === 18,
        'a rest brings the level: 6 + Con modifier HP');
```

  and append to the Choices section:

```php
    q("UPDATE users SET xp = 950, level = 1, hp = max_hp, room_id = 1, resting = 'short',
       rest_until = NOW() + INTERVAL 1 MINUTE WHERE name = 'Chs'");
    run_command(hero('Chs'), 'north');  // leaving cancels the rest (check room 1 has a north exit)
    check(hero('Chs')['level'] === 1, 'a cancelled rest brings no level');
    q("UPDATE users SET room_id = 1, resting = 'short', rest_until = NOW() WHERE name = 'Chs'");
    $out = run_command(hero('Chs'), '');
    check(hero('Chs')['level'] === 3 && says($out, 'You reach level 3!'), 'two levels on one rest');
    q("UPDATE users SET level = 7, hp = max_hp, resting = 'short', rest_until = NOW() WHERE name = 'Chs'");
    run_command(hero('Chs'), '');
    check(hero('Chs')['level'] === 7, 'a level set by hand never drops');
    q("UPDATE users SET level = 5, xp = 14000, hp = max_hp, resting = 'short', rest_until = NOW() WHERE name = 'Pick'");
    $out = run_command(hero('Pick'), '');
    check(hero('Pick')['level'] === 6 && hero('Pick')['may_choose'] === 1 && says($out, "type 'choose'"),
        'a level that owes a choice sets may_choose');
```

- [ ] **Step 2: Run to verify it fails.**
- [ ] **Step 3: Implement** `level_up()` from `award()`'s level code; slim `award()` and update its doc comment;
  call `level_up()` and set `may_choose` in `finish_rest()`.
- [ ] **Step 4: Run the whole selftest** — exit 0. A test that relied on `award()` levelling: add a rest or a
  hand-set level to that test and note it in the commit.
- [ ] **Step 5: Commit** — `git commit -am "Levels arrive when a rest ends"`

---

### Task 4: The sheet and `score`

**Files:**
- Modify: `src/game.php` (`hero_sheet()` 5006, `cmd_score()` 5109)
- Test: `tests/selftest.php` (the Choices section)

**Interfaces:**
- Produces: `hero_sheet()` gains `'level_ready' => bool` (the XP reaches a level above `level`), `'owed' => bool`
  (`owed_choices()` non-empty), `'may_choose' => bool`. `cmd_score()` adds after the Saves line
  `'Skills  ' . <each proficient skill as "Stealth +7", with "*" after it for Expertise, comma-separated, or "none">`,
  and its XP clause becomes "Level up ready: short rest" when `level_ready`.

- [ ] **Step 1: Write the failing tests**

```php
    $s = hero_sheet(with_stats(hero('Pick')));
    check($s['owed'] && $s['may_choose'] && !$s['level_ready'], 'the sheet knows a choice is owed and may be made');
    q("UPDATE users SET xp = 23000 WHERE name = 'Pick'");
    $score = cmd_score(with_stats(hero('Pick')));
    check(hero_sheet(with_stats(hero('Pick')))['level_ready'] && says($score, 'Level up ready: short rest'),
        'a level waiting shows on the sheet and in score');
    check(says($score, 'Skills  ') && says($score, 'Stealth +') && says($score, '*'),
        'score lists skills, Expertise marked');
```

- [ ] **Step 2: Run to verify it fails.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the whole selftest** — exit 0.
- [ ] **Step 5: Commit** — `git commit -am "Sheet and score: a level waiting, choices owed, skills"`

---

### Task 5: `panel()` and the API

**Files:**
- Modify: `src/game.php` (new `SKILL_ABOUT` beside `SKILLS`; new `panel()` beside `actions()` ~5609),
  `public/api.php`
- Test: `tests/selftest.php` (the Choices section)

**Interfaces:**
- Consumes: `owed_choices()`, `best_two()`, `kind_about()`, `skill_bonus()`.
- Produces:
  - `SKILL_ABOUT` — skill key => one line on what it does in this game (e.g. 'stealth' => 'Move unseen; sneak
    past monsters.', 'investigation' => 'Study a clue when you inspect.'), all 18.
  - `panel(array $me): ?array` in the spec's shape. `api.php` adds `'panel' => panel($me)` to both replies when not
    null (it reads `$me` as already refetched) and its header comment lists `panel?`.
  - `class`: one group, `pick` 1, an option per class (`key` the class, `label` its name, `about` its `about`,
    `tag` "d{die}"), `command` ''.
  - `abilities`: `assign` with `rows` ['Str', 'Dex', 'Con', 'Int', 'Wis', 'Cha'], `values` `STANDARD_ARRAY`,
    `picked` the class's `scores`; `command` ''; `back` true.
  - `species`: group 1 the species; then for each species with `kinds` a group with `for` that species, title its
    `choose`, options its kinds (`about` from `kind_about()`); `back` true.
  - `background`: group 1 the backgrounds (`about`: its abilities and feat); per background a group `for` it, title
    "Raise", options `all` ("+1 to each") and the six ordered pairs keyed like "con str" (label "Con +2, Str +1"),
    the `best_two()` pair `picked`; `back` true.
  - an owed choice (at the confirm step, or outside creation with `may_choose`): `id` the choice id, `title` and
    `about` from it, `command` 'choose ', one group with its `pick`, an option per listed skill (`about` from
    `SKILL_ABOUT`, or "Yours already" when locked; `tag` like "Dex +3" from `skill_bonus()`; `locked`).
  - null otherwise: the name step, confirm with nothing owed, no `may_choose`, or `fighting` set.

- [ ] **Step 1: Write the failing tests**

```php
    save_account('Pan', 'correct horse');
    run_command(hero('Pan'), 'Pan');
    check(panel(hero('Pan'))['id'] === 'class' && count(panel(hero('Pan'))['groups'][0]['options']) === 12,
        'the class panel lists all 12');
    run_command(hero('Pan'), 'rogue');
    check(panel(hero('Pan'))['assign']['picked'] === CLASSES['rogue']['scores'], 'the Standard Array panel');
    run_command(hero('Pan'), 'ok');
    $p = panel(hero('Pan'));
    check($p['id'] === 'species' && in_array('dragonborn', array_column($p['groups'], 'for'), true),
        "species' kinds hang off their species");
    run_command(hero('Pan'), 'dwarf');
    $soldier = array_values(array_filter(panel(hero('Pan'))['groups'], fn ($g) => ($g['for'] ?? '') === 'soldier'))[0];
    check(count($soldier['options']) === 7 && count(array_filter(array_column($soldier['options'], 'picked'))) === 1,
        'background boosts: all or a pair, the best two picked');
    run_command(hero('Pan'), 'criminal');
    $p = panel(hero('Pan'));
    check($p['id'] === 'class skills' && $p['command'] === 'choose ' && $p['groups'][0]['pick'] === 4
        && count(array_filter(array_column($p['groups'][0]['options'], 'locked'))) === 2,
        'the skills panel, background skills locked');
    q('DELETE FROM picks WHERE user_id = ?', [hero('Chs')['id']]);
    q("UPDATE users SET may_choose = TRUE, fighting = 9999 WHERE name = 'Chs'");
    check(panel(hero('Chs')) === null, 'no panel over a fight');
    q("UPDATE users SET fighting = NULL WHERE name = 'Chs'");
    check(panel(hero('Chs'))['id'] === 'class skills', 'a rested hero with a choice gets the panel');
```

- [ ] **Step 2: Run to verify it fails.**
- [ ] **Step 3: Implement** `SKILL_ABOUT`, `panel()` and the `api.php` lines.
- [ ] **Step 4: Run the whole selftest** — exit 0; and `php -l public/api.php`.
- [ ] **Step 5: Commit** — `git commit -am "panel(): the current choice as data for the play page"`

---

### Task 6: The panel on the play page

**Files:**
- Modify: `public/index.php` (after `#log`, ~line 42), `public/play.js`, `public/style.css`

**Interfaces:**
- Consumes: the reply's `panel` (Task 5); `hero.level_ready`, `hero.owed`, `hero.may_choose` (Task 4).
- Produces (in `play.js`): typedef `Panel`; the three `Hero` fields in its typedef; `drawPanel(p: Panel): void`;
  `closePanel(dismiss: boolean): void`; `dismissed: Set<string>`.
- Markup: `<section id="panel" class="card" hidden aria-labelledby="panel-title">` with `h2#panel-title`
  (`tabindex="-1"`), `#panel-count` (`aria-live="polite"`), `p#panel-about`, `#panel-body`, `#panel-error`
  (`role="alert"`), and a footer with `code#panel-cmd` and buttons `#panel-back`, `#panel-later`, `#panel-ok`
  (labels Back, Later, Confirm).
- Behaviour:
  - Open hides `#log` and `#actions`, shows `#panel`, focuses `#panel-title`; close reverses it and focuses `#cmd`.
  - A reply with `panel` opens it when it's a creation panel (`command === ''` or `back`), or its `id` isn't in
    `dismissed`, or the command just sent started with `choose` (which also deletes the id from `dismissed`); a reply
    without `panel` closes it. `run(cmd)` with the panel open and `cmd` not starting `choose` or `back` adds the id to
    `dismissed`. The 5-second listen never opens or closes it.
  - Groups: a `<fieldset>`, with a `<legend>` when the group has a title; `pick` 1 is radios named per group, else
    checkboxes; each option a `label.form-check` row holding the input, the name, a `small.text-body-secondary`
    line and a `span.badge` tag; locked is checked and disabled; `picked` starts checked; at the limit the unchecked
    boxes disable. A group with `for` is `hidden` unless that key is checked in the first group.
  - `assign`: six labelled `<select>`s of `values`, preset to `picked`; a "Suggested" button resets them; a value
    used twice gets `is-invalid` on both.
  - `#panel-count` "n of N" for a multi-pick group; `#panel-cmd` shows `'> ' + command`, where command is
    `p.command` + the visible groups' checked unlocked keys (joined ', ' within a multi-pick group, groups joined
    ' '), or the six select values joined ' ' for `assign`.
  - Confirm with too few: `#panel-error` "Pick N more." (or "Use each score once.") and nothing sent; else
    `run(command)`. Back sends `back`. Later is `closePanel(true)`. Enter inside the panel is Confirm; Escape is
    Later outside creation. Back shows only with `back`, Later only without it.
  - Sheet: `#xp-text` reads "Level up ready: short rest" when `level_ready`; when `owed`, `#status` gets a badge
    "Skills to choose: short rest", or with `may_choose` a `button.btn.btn-sm` "Choose skills" whose click runs
    `choose`.
  - CSS: `#panel` sits in the log's grid row (`min-height: 0; overflow: auto`), option rows full width with the tag
    pushed right, the footer `position: sticky; bottom: 0` on the card's background.

- [ ] **Step 1: Implement** the markup, CSS and JS; `// @ts-check` stays clean.
- [ ] **Step 2: Browser, wide screen** (http://localhost:8080): make an account with `bin/adduser.php` (see its usage
  line), log in, and create on the panel: Rogue → Suggested → Dragonborn (its kind group appears) → Criminal (boost
  group, best two pre-selected) → skills with Sleight of Hand and Stealth locked; the counter reaches 4 and the rest
  disable; Confirm; Begin. Expected: each step echoed in the log afterwards; the sheet as before.
- [ ] **Step 3: Browser, an existing hero:** delete their picks
  (`DELETE FROM picks WHERE user_id = <id>`); the sheet shows "Skills to choose: short rest"; `rest`, wait a
  minute: the panel opens by itself; Later closes it; "Choose skills" reopens it; typing `look` closes it; too few
  shows "Pick N more."; Confirm saves and the badge goes.
- [ ] **Step 4: Browser, 390px wide and keyboard only:** the panel scrolls with Confirm in view; Tab reaches every
  input, Space toggles, Enter confirms, Escape is Later.
- [ ] **Step 5: Run** `php tests/selftest.php` — exit 0.
- [ ] **Step 6: Commit** — `git add public && git commit -m "The choice panel: creation and skills on a form"`

---

### Task 7: Docs and ship

**Files:**
- Modify: `ROADMAP.md`; `BACKLOG.md` and `README.md` only if they need it

- [ ] **Step 1:** In `ROADMAP.md` delete task 8; in tasks 9 and 12 replace "A choice from task 8" with "A choice
  (`owed_choices()` and the panel)"; add a Decided entry dated the ship day with the spec's Decisions (no blocking
  outside creation; levels and choices when any rest ends, a house rule against SRD p.23; the panel in place of the
  log; Expertise at Rogue 1/6 and Bard 2/9; `picks` and `may_choose`). Keep Aaron's task-11 edits as they are.
- [ ] **Step 2:** `php tests/selftest.php` exit 0; `php -l` on each changed PHP file.
- [ ] **Step 3: Commit** — `git add ROADMAP.md BACKLOG.md README.md && git commit -m "Roadmap: choices and skills shipped"`
- [ ] **Step 4:** Refresh the claude.ai Project copies `claude/ROADMAP.md` and `claude/BACKLOG.md` from the repo.
- [ ] **Step 5:** Give Aaron the deploy command (`bin/deploy.sh`, which runs `bin/migrate.php`) in a code block.
