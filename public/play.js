// @ts-check
// play.js :: terminal UI. Sends each command to api.php and appends the reply lines as plain text.

const log = /** @type {HTMLElement} */ (document.getElementById('log'));
const form = /** @type {HTMLFormElement} */ (document.getElementById('prompt'));
const input = /** @type {HTMLInputElement} */ (document.getElementById('cmd'));
const hints = /** @type {HTMLUListElement} */ (document.getElementById('hints'));
const score = /** @type {HTMLElement} */ (document.getElementById('score'));
/** @type {Record<string, string>} usage => what it does, from COMMANDS in game.php */
const commands = JSON.parse(input.dataset.commands ?? '{}');

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
        /** @type {{ lines?: string[], score?: string[], logout?: boolean }} */
        const data = await res.json();
        for (const line of data.lines ?? []) print(line);
        if (data.score) score.textContent = data.score.join('\n');
        if (data.logout) setTimeout(() => location.reload(), 800);
    } catch {
        print('The connection falters. Try again.', 'error');
    }
}

form.addEventListener('submit', (e) => {
    e.preventDefault();
    const cmd = input.value.trim();
    input.value = '';
    showHints();
    if (!cmd) return;
    print('> ' + cmd, 'echo');
    history.push(cmd);
    historyPos = history.length;
    queue = queue.then(() => send(cmd));
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
