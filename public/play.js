// @ts-check
// play.js :: terminal UI. Sends each command to api.php and appends the reply lines as plain text.

const log = /** @type {HTMLElement} */ (document.getElementById('log'));
const form = /** @type {HTMLFormElement} */ (document.getElementById('prompt'));
const input = /** @type {HTMLInputElement} */ (document.getElementById('cmd'));
const hints = /** @type {HTMLUListElement} */ (document.getElementById('hints'));
const sheet = /** @type {HTMLElement} */ (document.getElementById('sheet'));
const heroToggle = /** @type {HTMLButtonElement} */ (document.getElementById('hero-toggle'));
const actions = /** @type {HTMLElement} */ (document.getElementById('actions'));
/** @type {Record<string, string>} usage => what it does, from my_commands() in game.php */
let commands = JSON.parse(input.dataset.commands ?? '{}');
/** @type {string[]} actions() in game.php: the buttons while nothing that has choices() is typed */
let base = [];
/** @type {Record<string, [string, string][]>} choices() in game.php: prefix => [label, command] */
let choices = {};

/** @type {string[]} */
const history = [];
let historyPos = 0;
let queue = Promise.resolve(); // keeps replies in the order commands were typed
let restTimer = 0;

/** @param {string} text @param {string} [cls] @returns {HTMLDivElement} */
function print(text, cls) {
    const line = document.createElement('div');
    if (cls) line.className = cls;
    line.textContent = text; // never innerHTML: player text comes back in these lines
    log.append(line);
    log.scrollTop = log.scrollHeight;
    return line;
}

/** @param {string} cmd */
async function send(cmd) {
    try {
        const res = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ cmd }),
        });
        /** @type {{ lines?: string[], actions?: string[], commands?: Record<string, string>,
         *   choices?: Record<string, [string, string][]>, hero?: Hero, map?: string[], logout?: boolean }} */
        const data = await res.json();
        const lines = data.lines ?? [];
        const map = data.map ?? [];
        // The map command's reply holds the map itself: draw it in colour like the sheet's, the rest as text.
        const drawn = map.join('\n');
        const at = map.length ? lines.findIndex((_, i) => lines.slice(i, i + map.length).join('\n') === drawn) : -1;
        for (const line of at < 0 ? lines : lines.slice(0, at)) print(line);
        if (at >= 0) {
            log.append(drawMap(el('div', 'map'), map));
            log.scrollTop = log.scrollHeight;
            for (const line of lines.slice(at + map.length)) print(line);
        }
        if (data.commands) commands = data.commands;
        base = data.actions ?? [];
        choices = data.choices ?? {};
        drawActions();
        sheet.hidden = heroToggle.hidden = !data.hero; // no sheet while creating a hero
        if (data.hero) drawHero(data.hero, data.map ?? []);
        if (data.logout) setTimeout(() => location.reload(), 800);
    } catch {
        print('The connection falters. Try again.', 'error');
    }
}

/**
 * hero_sheet() in game.php.
 * @typedef {{ name: string, level: number, class: string, hp: number, max_hp: number, temp_hp: number, ac: number,
 *   prof: number, abilities: [string, number, number, number][], weapon: string, to_hit: number, damage: string,
 *   attacks: number, resources: [string, string][], origin: [string, string][], status: string[], rest_secs: number,
 *   xp: number, xp_from: number,
 *   xp_next: number | null, gold: number }} Hero
 */

/** @param {string} id */
const byId = (id) => /** @type {HTMLElement} */ (document.getElementById(id));

/** @param {string} tag @param {string} cls @param {string | number} [text] */
function el(tag, cls, text) {
    const node = document.createElement(tag);
    node.className = cls;
    if (text !== undefined) node.textContent = String(text);
    return node;
}

/** @param {number} n */
const signed = (n) => (n < 0 ? '' : '+') + n;

/**
 * One labelled box; every number on the sheet that isn't a bar or a row is one of these.
 * @param {string} label @param {string | number} value @param {(string | number)[]} subs smaller lines below
 */
function tile(label, value, ...subs) {
    const box = el('div', 'stat border rounded-1 text-center h-100 py-1');
    box.append(el('small', 'd-block text-body-secondary text-uppercase', label), el('span', 'd-block fs-5', value));
    box.append(...subs.map((sub) => el('small', 'd-block text-body-secondary', sub)));
    const col = el('div', 'col');
    col.append(box);
    return col;
}

/** @param {string} id @param {number} now @param {number} max @param {string} [color] */
function bar(id, now, max, color = '') {
    const outer = byId(id);
    const fill = /** @type {HTMLElement} */ (outer.firstElementChild);
    outer.setAttribute('aria-valuenow', String(now));
    outer.setAttribute('aria-valuemax', String(max));
    fill.style.width = (max > 0 ? Math.min(100, (100 * now) / max) : 100) + '%'; // CSSOM, so the CSP allows it
    fill.className = 'progress-bar ' + color;
}

/** @param {Hero} h @param {string[]} map */
function drawHero(h, map) {
    byId('hero-name').textContent = h.name;
    byId('hero-class').textContent = `Level ${h.level} ${h.class}`;
    byId('hp-text').textContent = `${h.hp}/${h.max_hp}` + (h.temp_hp ? ` +${h.temp_hp} temp` : '');
    const hp = h.hp / h.max_hp;
    bar('hp-bar', h.hp, h.max_hp, hp > 0.5 ? 'bg-success' : hp > 0.25 ? 'bg-warning' : 'bg-danger');
    byId('tiles').replaceChildren(...[['AC', h.ac], ['Prof', signed(h.prof)], ['Gold', h.gold]].map(([k, v]) => {
        const stat = el('span', 'text-body-secondary', k + ' ');
        stat.append(el('span', 'text-body', v));
        return stat;
    }));
    byId('abilities').replaceChildren(
        ...h.abilities.map(([name, score, mod]) => tile(name, signed(mod), score)),
    );
    const attack = `${signed(h.to_hit)} · ${h.damage}` + (h.attacks > 1 ? ` ×${h.attacks}` : '');
    // Not h.origin (species, background): 'score' shows those, and the map needs the room.
    byId('rows').replaceChildren(...[[h.weapon, attack], ...h.resources].map(([k, v]) => {
        const row = el('div', 'd-flex justify-content-between gap-3');
        row.append(el('dt', 'fw-normal text-body-secondary', k), el('dd', 'm-0 text-end', v));
        return row;
    }));
    const status = byId('status');
    status.replaceChildren(...h.status.map((s) => el('span', 'badge text-bg-warning fw-normal', s)));
    status.hidden = h.status.length === 0;
    // A rest finishes on the next command, so send an empty one when it's up (the server applies it then).
    clearTimeout(restTimer);
    if (h.rest_secs > 0) restTimer = setTimeout(() => (queue = queue.then(() => send(''))), h.rest_secs * 1000 + 1000);
    byId('xp-text').textContent = h.xp_next === null ? `${h.xp} (max level)` : `${h.xp}/${h.xp_next}`;
    bar('xp-bar', h.xp - h.xp_from, (h.xp_next ?? h.xp) - h.xp_from, 'bg-warning');
    drawMap(byId('map'), map);
}

/**
 * cmd_map() in game.php: glyph => [CSS class, what to show]. `#` is a wall (see MAP_WALLS); any other glyph is a
 * monster's initial. A space is solid rock.
 * @type {Record<string, [string, string]>}
 */
const MAP_CELLS = { ' ': ['rock', '⋰'], '.': ['f', '∙'], ':': ['s', '∙'], '@': ['me', '@'], '<': ['st', '<'], '>': ['st', '>'] };

/** A wall's glyph by which neighbours are walls too (north 1, east 2, south 4, west 8), so walls join into lines. */
const MAP_WALLS = '■║═╚║║╔╠═╝═╩╗╣╦╬';

/**
 * One span per cell, in the Dungeon Mode font (style.css). The last two lines (who's here, the legend) stay text.
 * @param {HTMLElement} box @param {string[]} lines
 */
function drawMap(box, lines) {
    // Empty rows at the top and bottom are directions with no rooms; skip them to save height.
    const first = lines.findIndex((line) => line !== '');
    const grid = lines.slice(first, lines.length - 2).join('\n').trimEnd().split('\n');
    const width = Math.max(...grid.map((line) => line.length));
    const wall = (/** @type {number} */ y, /** @type {number} */ x) => (grid[y]?.[x] === '#' ? 1 : 0);
    box.replaceChildren(...grid.map((line, y) => {
        const row = el('div', 'map-row');
        for (let x = 0; x < width; x++) {
            const ch = line[x] ?? ' ';
            const [cls, show] = ch === '#'
                ? ['w', MAP_WALLS[wall(y - 1, x) | wall(y, x + 1) << 1 | wall(y + 1, x) << 2 | wall(y, x - 1) << 3]]
                : MAP_CELLS[ch] ?? ['foe', ch];
            row.append(el('span', cls, show));
        }
        return row;
    }), ...lines.slice(-2).map((line) => el('div', 'map-text', line)));
    return box;
}

/** Echoes a command and queues it, whether typed or a button. @param {string} cmd */
function run(cmd) {
    // shown on hover, or on tap via .show (see style.css)
    print('> ' + cmd, 'echo').dataset.time = new Date().toLocaleString([], { dateStyle: 'short', timeStyle: 'short' });
    history.push(cmd);
    historyPos = history.length;
    queue = queue.then(() => send(cmd));
}

log.addEventListener('click', (e) => /** @type {HTMLElement} */ (e.target).closest('.echo')?.classList.toggle('show'));

form.addEventListener('submit', (e) => {
    e.preventDefault();
    const cmd = input.value.trim();
    input.value = '';
    showHints();
    if (cmd) run(cmd);
});

/** @param {string} label @param {string} cmd sent, or put in the prompt if it ends in a space (it needs more) */
function button(label, cmd) {
    const b = el('button', 'btn btn-sm', cmd.endsWith(' ') ? label + '…' : label);
    b.setAttribute('type', 'button');
    b.dataset.cmd = cmd;
    return b;
}

/**
 * The buttons follow the prompt: once it starts with a prefix from choices() ("cast ", "cast fire bolt "), they are
 * what can follow it, narrowed by anything typed after it, with ✕ to clear it. Otherwise actions().
 */
function drawActions() {
    const typed = input.value.trimStart().toLowerCase().replace(/\s+/g, ' ');
    const prefix = Object.keys(choices).filter((k) => typed.startsWith(k + ' ')).sort((a, b) => b.length - a.length)[0];
    if (prefix === undefined) {
        actions.replaceChildren(...base.map((cmd) => button(cmd.trim(), cmd)));
        return;
    }
    const clear = button('✕', '');
    clear.setAttribute('aria-label', 'Clear the command');
    const fit = choices[prefix].filter(([, cmd]) => cmd.startsWith(typed) || typed.startsWith(cmd));
    actions.replaceChildren(clear, ...fit.map(([label, cmd]) => button(label, cmd)));
}

actions.addEventListener('click', (e) => {
    const cmd = /** @type {HTMLElement} */ (e.target).closest('button')?.dataset.cmd;
    if (cmd === undefined) return;
    input.value = cmd.endsWith(' ') ? cmd : '';
    showHints();
    if (cmd.endsWith(' ')) input.focus();
    else if (cmd) run(cmd); // no focus, so a phone keyboard stays down
});

/** Usages whose command word starts with what's typed. Only while typing the first word. */
function matches() {
    const typed = input.value.trimStart().toLowerCase();
    if (!typed || typed.includes(' ')) return [];
    return Object.keys(commands).filter((usage) => usage.startsWith(typed));
}

function showHints() {
    const found = matches();
    hints.replaceChildren(...found.map((usage) => {
        const li = document.createElement('li');
        li.setAttribute('role', 'option');
        li.dataset.usage = usage;
        const name = document.createElement('b');
        name.textContent = usage;
        li.append(name, ' ' + commands[usage]);
        return li;
    }));
    hints.hidden = found.length === 0;
    input.setAttribute('aria-expanded', String(!hints.hidden));
    drawActions(); // the buttons follow the prompt too
}

/** Fills in a command word; commands that take text get a trailing space to keep typing. */
function complete(usage) {
    const [word, arg] = usage.split(' ');
    input.value = word + (arg ? ' ' : '');
    showHints();
}

input.addEventListener('input', showHints);

// Tapping a suggestion fills it in. mousedown is cancelled so the input keeps focus (and the phone keyboard stays up).
hints.addEventListener('mousedown', (e) => e.preventDefault());
hints.addEventListener('click', (e) => {
    const li = /** @type {HTMLElement} */ (e.target).closest('li');
    if (li?.dataset.usage) complete(li.dataset.usage);
    input.focus();
});

input.addEventListener('keydown', (e) => {
    if (e.key === 'Tab' && !hints.hidden) {
        // Like a shell: one match completes, several extend to their shared prefix.
        e.preventDefault();
        const found = matches();
        if (found.length === 1) return complete(found[0]);
        const words = found.map((usage) => usage.split(' ')[0]);
        let common = words[0];
        for (const w of words) while (!w.startsWith(common)) common = common.slice(0, -1);
        if (common.length > input.value.trim().length) complete(common);
        return;
    }
    if (e.key === 'Escape') {
        hints.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        return;
    }
    if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
    e.preventDefault();
    historyPos = Math.max(0, Math.min(history.length, historyPos + (e.key === 'ArrowUp' ? -1 : 1)));
    input.value = history[historyPos] ?? '';
    showHints();
});

queue = queue.then(() => send('look'));
