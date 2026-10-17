# Choices and skills (roadmap task 8): design

Agreed with Aaron on 2026-10-16. Replaces task 8's text in `ROADMAP.md` where they differ.

## Goal

Heroes make the picks their class, level and feats grant, starting with skills, on a readable panel instead of
columns of text. Character creation moves onto the same panel. Later picks (task 9's Ability Score Improvements,
task 12's own picks) reuse it with no new UI.

Done means: a player sees each option with what it does, picks the right number and confirms; typing the same
choice still works; the selftest covers it; a new kind of choice is one PHP description, no JS.

## Decisions

- **Nothing blocks play outside character creation.** This overrides the roadmap's "an owed choice blocks play".
  Backfilled choices (heroes from before) and level-up choices follow the same rule.
- **Levels and choices come with a rest (house rule).** SRD 5.2.1 p.23 levels you as soon as your XP gets there;
  here the level waits for any rest to finish (a short rest is 1 minute), and the choices it owes are made then.
- **Layout A:** the panel takes the place of the log and the buttons; the sheet stays where it is. A left rail
  (ArchaicQuest-style) is a later decision, once there's more to keep on screen.
- **Expertise at Rogue 1 and 6, Bard 2 and 9** (the roadmap named only 1 and 2).

## 1. Data and rules

**`picks`** table: `user_id`, `choice`, `value`, primary key on all three, `ON DELETE CASCADE` with the hero. One row
per pick, e.g. `('class skills', 'stealth')`. Class, species and background stay `users` columns.

**`users.may_choose`** `BOOL NOT NULL DEFAULT FALSE`: set when a rest finishes and a choice is owed; cleared when
nothing is owed. Wandering off before choosing keeps it (the same idea as `may_prepare`).

**`owed_choices($me)`**: the hero's choices, in this order, each owed while it has fewer `picks` rows than it needs.

| Choice id | Who | Pick | Options |
|---|---|---|---|
| `class skills` | every class: a `skills` entry in `CLASSES`, from the SRD class tables | Barbarian 2, Bard 3 (any skill), Cleric 2, Druid 2, Fighter 2, Monk 2, Paladin 2, Ranger 3, Rogue 4, Sorcerer 2, Warlock 2, Wizard 2 | the class list |
| `skilled` | a Human whose Versatile feat is Skilled | 3 | any skill (tools aren't modelled) |
| `expertise 1`, `expertise 2` | Rogue 1 and 6; Bard 2 and 9 | 2 each | skills I'm proficient in that don't have Expertise |

Skills I already have (a background's, or an earlier pick) show ticked and locked and can't be picked again. Jack
of All Trades (Bard 2) is not a choice.

**`skill_bonus($me, $skill)`**: ability modifier, plus the Proficiency Bonus if proficient (background skills or a
`class skills` / `skilled` pick), twice it with Expertise, or half of it rounded down with Jack of All Trades when
not proficient. `inspect`, `ask` and `sneak` already call it.

**Levelling.** `award()` adds XP and gold only. `level_up(&$me, &$lines)` takes `award()`'s level-up code (fixed HP
per level, Dwarven Toughness, "You reach level N! +X max HP.") and `finish_rest()` calls it when any rest finishes,
applying every level the XP has reached. A level set by hand (bin scripts, tests) never goes down. After it,
`may_choose` is set if anything is owed.

**Routing in `run_command()`.** Creation steps from name to background go to `cmd_setup()` as today. At the confirm
step, `choose` works without a rest and `begin` refuses while anything is owed ("Choose your skills first."). `back`
also deletes the hero's picks (`setup_abilities()` resets the hero). Outside creation `choose` works only with
`may_choose`; every other command runs as usual.

**Existing heroes.** Their levels already match their XP. They have no picks, so their sheet says "Skills to
choose: short rest", and their checks use background skills alone until they choose.

## 2. What the server sends the page

`api.php` adds `panel` to its reply, plain data that `play.js` renders with `textContent` only:

```
panel: {
  id,        // 'class', 'species', 'background', 'abilities', 'class skills', 'expertise 1', ...
  title,     // "Rogue skills"
  about,     // one line under the title
  command,   // what Confirm prefixes: '' in creation, 'choose ' after
  groups: [{ title?, for?, pick, options: [{ key, label, about, tag?, locked?, picked? }] }],
  assign?: { rows, values, picked },   // the Standard Array: six selects, each score once
  back?: true                          // creation: a Back button
}
```

- Confirm sends `command` plus the selected keys in group order: the text a player could type, checked by the
  same code. No new endpoint.
- `pick` 1 is radios, more is checkboxes with an "n of N" counter.
- A group with `for` shows only while that option of the group above is selected, so the existing parsers stay:
  species then kind sends `dragonborn red`; background then boosts sends `soldier con str` or `soldier all`, the
  best-two boost pre-selected.
- Name stays the plain prompt; the confirm step stays the summary with Begin and Back buttons.

**When it's sent.** In creation, with every step that has options. Outside creation, with every reply while
`may_choose` is set and a choice is owed; `play.js` opens it by itself the first time it sees an `id`. Any other
command closes it and marks that `id` dismissed; `choose`, or tapping the sheet's badge, reopens it. The 5-second
listen leaves it alone.

**`choose`.** Bare, it prints the owed choice as text lines. With skills (`choose acrobatics, sleight of hand,
perception, insight`; commas optional, multi-word names matched longest first, prefixes allowed) it saves them,
refusing a wrong count, a skill not on the list or a locked one. Without `may_choose` outside creation: "Rest first
(a short rest takes 1 minute)."

**Sheet.** `hero_sheet()` adds `level_ready` (the XP line reads "Level up ready: short rest") and `owed` (a badge
"Skills to choose: short rest", or a tappable "Choose skills" once `may_choose` is set). `score` gains a Skills line:
proficient skills with their bonuses, Expertise marked.

## 3. The panel

- `<section id="panel" hidden>` right after `#log` in `index.php`. Open, it hides `#log` and `#actions` and takes
  the log's grid row; the prompt stays below, the sheet beside it (or in its drawer on phones). Closed, the log is
  back with Confirm's echo line in it.
- Header: title, the counter (`aria-live="polite"`), the about line.
- Each group a `<fieldset>` with a `<legend>`; each option a full-width `form-check` row: the box or radio, the name,
  its one line in `text-body-secondary`, a tag on the right (`Dex +3`). Locked: ticked, disabled, "From your
  background (Criminal)". At the limit the unticked boxes disable until one is unticked.
- Standard Array: six labelled selects (Str to Cha) and a "Suggested" button for the class default; a score used
  twice is flagged inline on both.
- Footer: a monospace preview of the command (`> choose acrobatics stealth …`), Back (creation) or Later, and
  Confirm. A wrong count shows "Pick 2 more." (`role="alert"`) and sends nothing.
- Phones: the panel fills the column and scrolls inside itself; the footer is sticky.
- Keyboard: native inputs; focus to the panel's heading on open and back to the prompt on close; Enter confirms;
  Escape is Later (not in creation).
- Bootstrap classes and the `hidden` attribute only, no inline styles (CSP). About 120 lines of `play.js`, 25 of
  `style.css`, 5 of `index.php`.

## 4. Migration, tests, docs

**Migration** `db/migrations/2026-10-17-picks.sql`, the same change in `schema.sql`: the `picks` table and
`users.may_choose`. No setup ENUM change; fights under way aren't touched.

**`tests/selftest.php`:**
- `create()` finishes creation by sending real `choose` commands with each owed choice's first options, so its 22
  callers don't change. Hand-set levels no longer stall anything.
- The "You reach level 2" check (about line 559) becomes XP only from the kill, then a short rest gives the level.
- New checks: `skill_bonus()` without proficiency, proficient, with Expertise and with Jack of All Trades, and a
  picked skill counting in `inspect` and `sneak`; the order of owed choices and Expertise offering only proficient
  skills; `choose` refusing a wrong count, an unlisted skill, a locked one, and anything before a rest, and parsing
  `sleight` and `animal handling`; `begin` refusing while owed and `back` clearing picks; creation for all 12
  classes and a Skilled Human; a short rest applying one level and two, `may_choose` set and then cleared, and a
  hand-set level never lowered; the `panel` for each creation step and an owed choice.
- In a browser (`php -S`, and localhost:8080): a whole creation on the panel at desktop and phone widths, an
  existing hero resting then choosing, Later and `choose`, and keyboard only.

**Docs**, in the commit that ships it: delete task 8 from `ROADMAP.md` and add a Decided entry with the decisions
above; refresh the claude.ai Project copies of `ROADMAP.md` and `BACKLOG.md`.

## Not in this task

A left rail; tools from Skilled; changing class, species or background after creation; task 9's and task 12's
choices (they plug into `owed_choices()` and the panel later).
