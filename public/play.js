// @ts-check
// play.js :: terminal UI. Sends each command to api.php and appends the reply lines as plain text.

const log = /** @type {HTMLElement} */ (document.getElementById('log'));
const form = /** @type {HTMLFormElement} */ (document.getElementById('prompt'));
const input = /** @type {HTMLInputElement} */ (document.getElementById('cmd'));

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
        /** @type {{ lines?: string[], logout?: boolean }} */
        const data = await res.json();
        for (const line of data.lines ?? []) print(line);
        if (data.logout) setTimeout(() => location.reload(), 800);
    } catch {
        print('The connection falters. Try again.', 'error');
    }
}

form.addEventListener('submit', (e) => {
    e.preventDefault();
    const cmd = input.value.trim();
    input.value = '';
    if (!cmd) return;
    print('> ' + cmd, 'echo');
    history.push(cmd);
    historyPos = history.length;
    queue = queue.then(() => send(cmd));
});

input.addEventListener('keydown', (e) => {
    if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
    e.preventDefault();
    historyPos = Math.max(0, Math.min(history.length, historyPos + (e.key === 'ArrowUp' ? -1 : 1)));
    input.value = history[historyPos] ?? '';
});

queue = queue.then(() => send('look'));
