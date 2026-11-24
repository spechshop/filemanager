// Shared terminal controller. Keep classic-script globals for existing editor integrations.
let terminals = {};
const terminalStatusLabels = {connecting: 'Conectando', connected: 'Conectado', reconnecting: 'Reconectando', disconnected: 'Desconectado', unauthorized: 'Acesso expirado ou negado'};
function setTerminalStatus(id, status) {
    const info = terminals[id];
    if (!info) return;
    info.status = status;
    info.terminal.options.disableStdin = status !== 'connected';
    document.dispatchEvent(new CustomEvent('fm:terminal-change'));
}

// ---- Token helper ----
function getToken() {
    // The active credential (#fp) is what FMAuth validates. A persisted
    // localStorage token may belong to an older login and must not override it.
    return document.getElementById('fp')?.textContent?.trim() || localStorage.tokenBrowser || '';
}

async function waitForToken() {
    return new Promise((resolve) => {
        const check = setInterval(() => {
            const t = getToken();
            if (t && t.length > 3) {
                clearInterval(check);
                resolve(t);
            }
        }, 100);
    });
}

// ---- API helpers ----
async function apiSaveTerminal(session) {
    const token = getToken();
    return fetch(`/saveTerminal?tokenBrowser=${encodeURIComponent(token)}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            id: session.id,
            name: session.name,
        }),
    }).then(async r => {
        const result = await r.json();
        return r.ok === false ? {success: false, error: 'HTTP ' + r.status} : result;
    }).catch(() => ({success: false, error: 'Falha de comunicação com o servidor.'}));
}

async function apiDeleteTerminal(terminalId) {
    const token = getToken();
    return fetch(`/deleteTerminal?tokenBrowser=${token}&id=${terminalId}`)
        .then(r => r.json()).catch(() => ({}));
}

async function apiGetTerminals() {
    const token = getToken();
    return fetch(`/getTerminals?tokenBrowser=${token}`)
        .then(r => {
            if (!r.ok) { const error = new Error('Não foi possível carregar as sessões.'); error.status = r.status; throw error; }
            return r.json();
        });
}

async function loadTerminalSessions() {
    const revision = terminalSessionsRevision;
    const sessions = await apiGetTerminals();
    if (!Array.isArray(sessions)) throw new Error('Lista de sessões inválida.');
    // An in-flight GET may have started before a new session was saved.
    // Never replace the newer local state with that stale response.
    if (revision !== terminalSessionsRevision) {
        document.dispatchEvent(new CustomEvent('fm:terminal-loaded'));
        return getStoredTerminals();
    }
    // A GET issued just after saving a shell can still reach a replica or an
    // earlier request and return the old list. Never erase an open local PTY.
    const merged = [...sessions];
    const known = new Set(merged.map(session => session.id));
    for (const [id, info] of Object.entries(terminals)) {
        if (!known.has(id)) {
            merged.push({id, name: info.options.name});
            known.add(id);
        }
    }
    setStoredTerminals(merged);
    for (const session of merged) if (!terminals[session.id]) createTerminalTab({...session});
    document.dispatchEvent(new CustomEvent('fm:terminal-loaded'));
    return merged;
}

// A slower initial GET must not overwrite a session created while it ran.
let terminalSessionsRevision = 0;

// ---- LocalStorage helpers ----
function getStoredTerminals() {
    try {
        const raw = localStorage.getItem('storageTerminals');
        return raw ? JSON.parse(raw) : [];
    } catch { return []; }
}

function setStoredTerminals(sessions) {
    localStorage.setItem('storageTerminals', JSON.stringify(sessions));
}

function getXtermDimensions() {
    try {
        const raw = localStorage.getItem('xtermDimensions');
        return raw ? JSON.parse(raw) : null;
    } catch { return null; }
}

// ---- Tab & terminal creation ----
function createTerminalTab(options) {
    const { id: terminalId, name } = options;
    if (terminals[terminalId]) { selectTerminalTab(terminalId); return; }

    let container = document.getElementById(terminalId);
    if (!container) {
        container = document.createElement('div');
        container.id = terminalId;
        container.className = 'xterm-container';
        container.style.display = 'none';
        document.getElementById('terms').appendChild(container);
    }

    const li = document.createElement('li');
    li.classList.add('nav-item');
    li.dataset.terminalId = terminalId;

    const link = document.createElement('a');
    link.classList.add('nav-link');
    link.href = '#';

    const icon = document.createElement('i');
    icon.className = 'fa-duotone fa-solid fa-terminal me-1';

    const label = document.createElement('span');
    label.className = 'terminal-tab-label';
    label.textContent = name;
    label.title = 'Clique duplo para renomear';

    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'close-tab';
    closeBtn.textContent = '×';
    closeBtn.setAttribute('aria-label', 'Encerrar sessão ' + name);

    link.appendChild(icon);
    link.appendChild(label);
    li.appendChild(link);
    li.appendChild(closeBtn);
    document.getElementById('new-terminal').before(li);

    label.addEventListener('dblclick', (e) => {
        e.preventDefault();
        e.stopPropagation();
        startRename(label, options);
    });

    link.addEventListener('click', (e) => {
        e.preventDefault();
        if (e.target === closeBtn || e.target.closest('.close-tab')) return;
        selectTerminalTab(terminalId);
    });

    closeBtn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        closeTerminalTab(terminalId);
    });

    link.addEventListener('contextmenu', (e) => {
        e.preventDefault();
        e.stopPropagation();
        showTerminalCtxMenu(e.clientX, e.clientY, terminalId, label, options, link);
    });

    const dims = getXtermDimensions();
    const term = new window.Terminal({
        fontSize: 14,
        fontFamily: "'JetBrains Mono', ui-monospace, monospace",
        lineHeight: 1.2,
        cursorBlink: true,
        cols: dims?.cols ?? 100,
        rows: dims?.rows ?? 30,
        scrollback: 5000,
        allowProposedApi: true,
        theme: {
            background: '#141627', foreground: '#e8e9f0', cursor: '#47c99b',
            selectionBackground: '#394464', black: '#242638', red: '#f07b86',
            green: '#47c99b', yellow: '#f2c572', blue: '#89b4ff',
            magenta: '#c9a0ed', cyan: '#79d4db', white: '#d5d7e3',
            brightBlack: '#777e98', brightRed: '#ffa0a8', brightGreen: '#7ce3b7',
            brightYellow: '#ffdfa1', brightBlue: '#adcaff', brightMagenta: '#dfbcff',
            brightCyan: '#a2eef0', brightWhite: '#ffffff',
        },
    });
    const fitAddon = new FitAddon.FitAddon();
    term.loadAddon(fitAddon);
    term.open(container);
    terminals[terminalId] = { terminal: term, socket: null, options, fitAddon, retryTimer: null, resizeFrame: null, status: 'connecting' };
    const observer = new ResizeObserver(() => adjustTerminalSizeFromStorage(terminalId));
    observer.observe(document.getElementById('terms'));
    terminals[terminalId].resizeObserver = observer;

    term.onResize((size) => {
        const sock = terminals[terminalId]?.socket;
        if (sock?.readyState === WebSocket.OPEN) {
            sock.send(JSON.stringify({
                command: 'resizeXtermHandlerCommand',
                cols: size.cols,
                rows: size.rows,
                isCodex: true,
            }));
        }
    });

    term.attachCustomKeyEventHandler((event) => {
        if (event.type !== 'keydown' || event.altKey || event.ctrlKey === event.metaKey) return true;
        const info = terminals[terminalId];
        if (!info) return true;
        const key = event.key.toLowerCase();
        const copy = key === 'c' && (event.shiftKey || (event.ctrlKey && (info.selecting || term.hasSelection())));
        const paste = key === 'v' && (event.shiftKey || event.ctrlKey);
        if (!copy && !paste) return true;
        event.preventDefault();
        window.FMTerminalActions.run(copy ? 'copy' : 'paste', info, {clearAfterCopy: copy && !event.shiftKey});
        return false;
    });

    // onData captura teclado + sequências de mouse geradas pelo xterm
    // quando apps como htop habilitam mouse reporting (ex: \x1b[?1000h)
    term.onData((data) => {
        const sock = terminals[terminalId]?.socket;
        if (!sock || sock.readyState !== WebSocket.OPEN) return;
        try {
            runCommand(sock, data);
        } catch (e) {
            console.error('Erro ao processar entrada:', e);
        }
    });

    setTerminalStatus(terminalId, 'connecting');
    initWebSocket(term, options);
    selectTerminalTab(terminalId);
    return { term, container };
}

// ---- Context menu ----
(function () {
    const ctxMenu = document.getElementById('terminal-ctx-menu');
    let ctxTarget = null, ctxTrigger = null;

    function hideCtxMenu(restoreFocus = false) {
        ctxMenu.style.display = 'none';
        ctxTarget = null;
        if (restoreFocus && ctxTrigger?.isConnected) ctxTrigger.focus();
    }

    window.showTerminalCtxMenu = function (x, y, terminalId, labelEl, options, trigger) {
        ctxTarget = { terminalId, labelEl, options };
        ctxTrigger = trigger || document.activeElement;
        // Keep menu inside the viewport
        ctxMenu.style.display = 'block';
        const menuW = ctxMenu.offsetWidth, menuH = ctxMenu.offsetHeight;
        const left = Math.max(8, Math.min(x, window.innerWidth - menuW - 8));
        const top = Math.max(8, Math.min(y, window.innerHeight - menuH - 8));
        ctxMenu.style.left = left + 'px';
        ctxMenu.style.top = top + 'px';
        ctxMenu.style.display = 'block';
        ctxMenu.querySelector('button')?.focus();
    };

    ctxMenu.addEventListener('click', async (e) => {
        const item = e.target.closest('[data-action]');
        if (!item || !ctxTarget) return;
        const { terminalId, labelEl, options } = ctxTarget;
        hideCtxMenu();

        switch (item.dataset.action) {
            case 'rename':
                startRename(labelEl, options);
                break;

            case 'duplicate': {
                const sessions = getStoredTerminals();
                const newId = Math.random().toString(36).substring(2, 15)
                           + Math.random().toString(36).substring(2, 15);
                const srcName = options.name || 'Terminal';
                const newName = srcName + ' (cópia)';
                const token = await waitForToken();
                sessions.push({ id: newId, name: newName });
                terminalSessionsRevision++;
                setStoredTerminals(sessions);
                createTerminalTab({ id: newId, name: newName, token });
                await apiSaveTerminal({ id: newId, name: newName });
                break;
            }

            case 'new':
                document.getElementById('new-terminal').click();
                break;

            case 'close-others': {
                const allIds = [...document.querySelectorAll('#tabsTerm li[data-terminal-id]')]
                    .map(li => li.dataset.terminalId)
                    .filter(id => id !== terminalId);
                if (allIds.length && !window.confirm(`Encerrar ${allIds.length} outras sessões? Os shells e seus processos serão encerrados.`)) break;
                for (const id of allIds) await closeTerminalTab(id);
                selectTerminalTab(terminalId);
                break;
            }

            case 'close':
                await closeTerminalTab(terminalId);
                break;
        }
    });

    // Close on outside click or Escape
    document.addEventListener('click', (e) => {
        if (!ctxMenu.contains(e.target)) hideCtxMenu();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') hideCtxMenu(true);
        if (e.key === 'Tab' && ctxMenu.contains(e.target)) hideCtxMenu();
    });
    ctxMenu.addEventListener('keydown', e => {
        const buttons = [...ctxMenu.querySelectorAll('button')];
        const index = buttons.indexOf(document.activeElement);
        const next = {ArrowDown: (index + 1) % buttons.length, ArrowUp: (index - 1 + buttons.length) % buttons.length, Home: 0, End: buttons.length - 1}[e.key];
        if (next !== undefined) { e.preventDefault(); buttons[next].focus(); }
    });
})();

// ---- Rename (double-click on tab label) ----
function startRename(labelEl, options) {
    const terminalId = options.id;
    const currentName = labelEl.textContent;
    labelEl.classList.add('editing');

    const input = document.createElement('input');
    input.type = 'text';
    input.value = currentName;
    input.className = 'terminal-rename-input';
    labelEl.textContent = '';
    labelEl.appendChild(input);
    input.focus();
    input.select();

    const finishRename = async () => {
        const newName = input.value.trim() || currentName;
        labelEl.classList.remove('editing');
        labelEl.textContent = newName;
        options.name = newName;
            document.querySelector(`#tabsTerm [data-terminal-id="${terminalId}"] .close-tab`)?.setAttribute('aria-label', 'Encerrar sessão ' + newName);

        const sessions = getStoredTerminals();
        const idx = sessions.findIndex(s => s.id === terminalId);
        if (idx !== -1) sessions[idx].name = newName;
        terminalSessionsRevision++;
        setStoredTerminals(sessions);
        await apiSaveTerminal({ id: terminalId, name: newName });
    };

    input.addEventListener('blur', finishRename);
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') input.blur();
        if (e.key === 'Escape') { input.value = currentName; input.blur(); }
    });
}

// ---- Select/show tab ----
function selectTerminalTab(terminalId) {
    document.querySelectorAll('#tabsTerm .nav-link').forEach(l => l.classList.remove('active'));
    document.querySelectorAll('#terms .xterm-container').forEach(c => { c.style.display = 'none'; });
    const li = document.querySelector(`#tabsTerm [data-terminal-id="${terminalId}"]`);
    if (li) li.querySelector('.nav-link').classList.add('active');
    document.querySelectorAll('#tabsTerm [data-terminal-id] .nav-link').forEach(link => {
        if (link.classList.contains('active')) link.setAttribute('aria-current', 'true');
        else link.removeAttribute('aria-current');
    });
    const container = document.getElementById(terminalId);
    if (container) container.style.display = 'block';
    adjustTerminalSizeFromStorage(terminalId);
}

// ---- Close tab ----
async function closeTerminalTab(terminalId) {
    const termInfo = terminals[terminalId];
    if (!termInfo) return;
    delete terminals[terminalId];
    clearTimeout(termInfo.retryTimer);
    cancelAnimationFrame(termInfo.resizeFrame);
    termInfo.resizeObserver?.disconnect();
    if (termInfo.socket) termInfo.socket.onclose = null;
    if (termInfo?.socket?.readyState === WebSocket.OPEN) {
        try {
            termInfo.socket.send(JSON.stringify({
                command: 'closeXtermHandlerCommand',
                isCodex: true,
            }));
        } catch {}
        termInfo.socket.onclose = null;
        termInfo.socket.close();
    }

    document.querySelector(`#tabsTerm [data-terminal-id="${terminalId}"]`)?.remove();
    document.getElementById(terminalId)?.remove();
    termInfo.socket?.close();
    termInfo.terminal.dispose();

    const sessions = getStoredTerminals().filter(s => s.id !== terminalId);
    terminalSessionsRevision++;
    setStoredTerminals(sessions);
    await apiDeleteTerminal(terminalId);

    const remaining = document.querySelectorAll('#tabsTerm li[data-terminal-id]');
    if (remaining.length > 0) {
        const id = remaining[remaining.length - 1].dataset.terminalId;
        selectTerminalTab(id);
        document.querySelector(`#tabsTerm [data-terminal-id="${id}"] .nav-link`)?.focus();
    }
}

// ---- Vertical resizer ----
document.getElementById('resizer').addEventListener('mousedown', (e) => {
    e.preventDefault();
    window.addEventListener('mousemove', onResizerMove);
    window.addEventListener('mouseup', () => window.removeEventListener('mousemove', onResizerMove));
});

function onResizerMove(e) {
    if (document.getElementById('edit-code').classList.contains('terminal-workspace')) return;
    const footer = document.getElementById('footer-terminal');
    const chrome = Math.max(0, footer.offsetHeight - document.getElementById('terms').clientHeight);
    const height = Math.max(80, Math.min(window.innerHeight - 130 - chrome, window.innerHeight - e.clientY - chrome));
    footer.style.setProperty('--terminal-panel-height', `${height}px`);
    const editorHeight = Math.max(80, window.innerHeight - height - chrome - 80);
    const editorEl = document.getElementById('editor');
    if (editorEl) editorEl.style.height = `${editorHeight}px`;
    localStorage.setItem('xtermDimensions', JSON.stringify({height, editor: {height: editorHeight}}));
    Object.keys(terminals).forEach(adjustTerminalSizeFromStorage);
}

function adjustTerminalSizeFromStorage(terminalId) {
    const info = terminals[terminalId];
    const el = document.getElementById(terminalId);
    if (!info || !el || el.style.display === 'none' || info.resizeFrame !== null) return;
    info.resizeFrame = requestAnimationFrame(() => {
        info.resizeFrame = null;
        if (terminals[terminalId] !== info || !el.getClientRects().length) return;
        const area = document.getElementById('terms');
        if (!area.clientWidth || !area.clientHeight) return;
        el.style.width = '100%'; el.style.height = '100%';
        info.fitAddon.fit();
    });
}

// ---- WebSocket ----
async function reconnectTerminal(id) {
    const info = terminals[id];
    if (!info || info.status === 'unauthorized' || info.status === 'connected' || info.opening) return;
    clearTimeout(info.retryTimer);
    if (info.socket) {
        info.socket.onopen = info.socket.onclose = info.socket.onmessage = info.socket.onerror = null;
        info.socket.close();
    }
    setTerminalStatus(id, 'connecting');
    return initWebSocket(info.terminal, info.options);
}
async function initWebSocket(terminal, options) {
    const info = terminals[options.id];
    if (!info || info.opening) return;
    info.opening = true;
    const token = getToken();
    if (!token) {
        info.opening = false;
        setTerminalStatus(options.id, 'unauthorized');
        return;
    }
    if (terminals[options.id] !== info) return;
    const retry = () => {
        clearTimeout(info.retryTimer);
        info.retryTimer = setTimeout(() => {
            if (terminals[options.id] === info) initWebSocket(terminal, options);
        }, 3000);
    };
    options.token = token;

    const terminalId = options.id;
    const hostname = window.location.port
        ? `${window.location.hostname}:${window.location.port}`
        : window.location.hostname;
    const protocol = window.location.protocol === 'https:' ? 'wss' : 'ws';
    const wsToken = `${token}-${terminalId}`;
    let socket;
    try { socket = new WebSocket(await FMAuth.socketUrl(`${protocol}://${hostname}/${wsToken}`)); }
    catch (error) {
        info.opening = false;
        if (terminals[terminalId] !== info) return;
        setTerminalStatus(terminalId, FMAuth.shouldRetry(error) ? 'reconnecting' : 'unauthorized');
        if (FMAuth.shouldRetry(error)) retry();
        return;
    }

    if (terminals[terminalId] !== info) { socket.close(); return; }
    info.socket = socket;
    info.opening = false;

    socket.onopen = () => {
        if (terminals[terminalId] !== info) { socket.close(); return; }
        setTerminalStatus(terminalId, 'connected');
        socket.send(JSON.stringify({
            command: 'startXtermHandlerCommand',
            token: wsToken,
            isCodex: true,
        }));
        socket.send(JSON.stringify({command: 'resizeXtermHandlerCommand', cols: terminal.cols, rows: terminal.rows, isCodex: true}));
    };

    socket.onmessage = (event) => {
        if (event.data) {
            if (event.data.includes('\x1b[H') && event.data.includes('\x1b[2J')) {
                terminal.clear();
            }
            terminal.write(event.data);
        }
    };

    socket.onclose = () => {
        if (terminals[terminalId] === info && FMAuth.shouldRetry()) {
            setTerminalStatus(terminalId, 'reconnecting');
            console.warn(`Terminal "${terminalId}" desconectado, reconectando em 3s...`);
            retry();
        } else if (terminals[terminalId] === info) {
            setTerminalStatus(terminalId, 'unauthorized');
        }
    };

    socket.onerror = (err) => {
        console.error(`Erro no WebSocket do terminal "${terminalId}":`, err);
    };
}

window.runCommand = (socket, command) => {
    if (socket?.readyState === WebSocket.OPEN) {
        try {
            socket.send(JSON.stringify({
                command,
                isCodex: true,
            }));
        } catch (e) {
            console.error('Erro ao enviar comando:', e);
        }
    }
};

// ---- "+" button: create new terminal ----
let creatingTerminal = false;
async function createTabTerminal() {
    if (creatingTerminal) return false;
    creatingTerminal = true;
    document.dispatchEvent(new CustomEvent('fm:terminal-create-start'));
    try {
        // Unlike waitForToken(), ready() rejects missing/expired credentials,
        // instead of silently waiting forever for a token to appear.
        await FMAuth.ready();
        const token = getToken();
        if (!token) throw new Error('Credencial de acesso ausente. Faça login novamente.');
        const sessions = getStoredTerminals();
        const id = Math.random().toString(36).substring(2, 15) + Math.random().toString(36).substring(2, 15);
        const name = `Shell do servidor ${sessions.length + 1}`;
        const saved = await apiSaveTerminal({ id, name });
        if (saved?.success !== true) throw new Error(saved?.error || 'Não foi possível salvar a sessão no servidor.');
        sessions.push({ id, name });
        terminalSessionsRevision++;
        setStoredTerminals(sessions);
        createTerminalTab({ id, name, token });
        document.dispatchEvent(new CustomEvent('fm:terminal-create-success'));
        return true;
    } catch (error) {
        document.dispatchEvent(new CustomEvent('fm:terminal-create-error', {
            detail: {message: error?.message || 'Não foi possível abrir uma nova sessão.'}
        }));
        return false;
    } finally {
        creatingTerminal = false;
        document.dispatchEvent(new CustomEvent('fm:terminal-create-end'));
    }
}
document.getElementById('new-terminal').addEventListener('click', event => {
    event.preventDefault();
    void createTabTerminal();
});

// Public entrypoint for every create button; no synthetic clicks on a hidden tab.
window.createTabTerminal = createTabTerminal;

// ---- Page load: restore saved terminals ----
document.onreadystatechange = async function () {
    if (document.readyState !== 'complete') return;
    try { await FMAuth.ready(); }
    catch { return; }

    const dims = getXtermDimensions();
    if (dims?.height) document.getElementById('footer-terminal').style.setProperty('--terminal-panel-height', `${Math.max(80, Math.min(window.innerHeight - 130, dims.height))}px`);
    const editorEl = document.getElementById('editor');
    const filesArchitecture = document.getElementById('filesArchitecture');
    if (!window.matchMedia('(max-width: 767.98px)').matches) {
        if (editorEl && dims?.editor?.height) editorEl.style.height = `${dims.editor.height}px`;
        if (filesArchitecture && dims?.filesArchitecture?.height) filesArchitecture.style.height = `${dims.filesArchitecture.height}px`;
    }

    await waitForToken();
    try { await loadTerminalSessions(); }
    catch (error) { document.dispatchEvent(new CustomEvent('fm:terminal-load-error', {detail: {status: error.status}})); }
};

window.addEventListener('fm:auth-denied', () => {
    for (const [id, info] of Object.entries(terminals)) {
        clearTimeout(info.retryTimer);
        setTerminalStatus(id, 'unauthorized');
        if (info.socket) { info.socket.onclose = null; info.socket.close(); }
    }
});
