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

/** @type {string[]} */
const history = [];
let historyPos = 0;
let queue = Promise.resolve(); // keeps replies in the order commands were typed

/** @param {string} text @param {string} [cls] */
function print(text, cls) {
    const line = document.createElement('div');
    if (cls) line.className = cls;
    line.textContent = text; // never innerHTML: player text comes back in these lines
    log.append(line);
    log.scrollTop = log.scrollHeight;
}

/** @param {string} cmd */
async function send(cmd) {
    try {
        const res = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ cmd }),
        });
        /** @type {{ lines?: string[], actions?: string[], commands?: Record<string, string>, hero?: Hero,
         *   map?: string[], logout?: boolean }} */
        const data = await res.json();
        for (const line of data.lines ?? []) print(line);
        if (data.commands) commands = data.commands;
        drawActions(data.actions ?? []);
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
 *   attacks: number, resources: [string, string][], status: string[], xp: number, xp_from: number,
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
    byId('tiles').replaceChildren(tile('AC', h.ac), tile('Prof', signed(h.prof)), tile('Gold', h.gold));
    byId('abilities').replaceChildren(
        ...h.abilities.map(([name, score, mod, save]) => tile(name, signed(mod), score, `save ${signed(save)}`)),
    );
    const attack = `${signed(h.to_hit)} · ${h.damage}` + (h.attacks > 1 ? ` ×${h.attacks}` : '');
    byId('rows').replaceChildren(...[[h.weapon, attack], ...h.resources].map(([k, v]) => {
        const row = el('div', 'd-flex justify-content-between gap-3');
        row.append(el('dt', 'fw-normal text-body-secondary', k), el('dd', 'm-0 text-end', v));
        return row;
    }));
    const status = byId('status');
    status.replaceChildren(...h.status.map((s) => el('span', 'badge text-bg-warning fw-normal', s)));
    status.hidden = h.status.length === 0;
    byId('xp-text').textContent = h.xp_next === null ? `${h.xp} (max level)` : `${h.xp}/${h.xp_next}`;
    bar('xp-bar', h.xp - h.xp_from, (h.xp_next ?? h.xp) - h.xp_from, 'bg-warning');
    byId('map').textContent = map.join('\n');
}

/** Echoes a command and queues it, whether typed or a button. @param {string} cmd */
function run(cmd) {
    print('> ' + cmd, 'echo');
    history.push(cmd);
    historyPos = history.length;
    queue = queue.then(() => send(cmd));
}

form.addEventListener('submit', (e) => {
    e.preventDefault();
    const cmd = input.value.trim();
    input.value = '';
    showHints();
    if (cmd) run(cmd);
});

/** actions() in game.php. One ending in a space needs more typed, so it goes in the prompt. @param {string[]} list */
function drawActions(list) {
    actions.replaceChildren(...list.map((cmd) => {
        const button = el('button', 'btn btn-sm', cmd.endsWith(' ') ? cmd.trim() + '…' : cmd);
        button.setAttribute('type', 'button');
        button.dataset.cmd = cmd;
        return button;
    }));
}

actions.addEventListener('click', (e) => {
    const cmd = /** @type {HTMLElement} */ (e.target).closest('button')?.dataset.cmd;
    if (!cmd) return;
    if (!cmd.endsWith(' ')) return run(cmd); // no focus, so a phone keyboard stays down
    input.value = cmd;
    showHints();
    input.focus();
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
