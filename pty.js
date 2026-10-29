const bridgeAuth = require('./scripts/security/bridge-auth.cjs');
const WebSocket = require('ws');
const os = require('os');
const pty = require('node-pty');
const http = require('http');
const url = require('url');
const fs = require('fs');
const path = require('path');

const server = http.createServer();
const wss = new WebSocket.Server({ noServer: true, maxPayload: bridgeAuth.limit('WS_MESSAGE_BYTES', 131072) });

server.on('upgrade', (request, socket, head) => {
    if (!bridgeAuth.allowed(request, bridgeAuth.session(request))) return bridgeAuth.reject(socket);
    wss.handleUpgrade(request, socket, head, ws => wss.emit('connection', ws, request));
});

const shell = os.platform() === 'win32' ? 'powershell.exe' : 'bash';
const terminals = new Map();
const terminalClients = new Map();
const OUTPUT_CACHE_SIZE = 50000; // bytes to keep per terminal for reconnect replay

const TERMINALS_FILE = process.env.FILEMANAGER_DATA_DIR ? path.join(process.env.FILEMANAGER_DATA_DIR, 'terminals.json') : './terminals.json';

const filesDir = process.env.FILEMANAGER_INITIAL_DIRECTORY || path.join(process.cwd(), 'files');
if (!fs.existsSync(filesDir)) {
    fs.mkdirSync(filesDir, { recursive: true });
    console.log('Directory "files" created.');
}

/**
 * O runtime instalado pelo diagnóstico é isolado em .runtime e, por isso,
 * não altera o PATH do processo PHP que iniciou este serviço. Sem este
 * ajuste o pty.js roda com o Node gerenciado, mas o shell aberto por ele pode
 * continuar encontrando uma versão antiga do Node instalada no sistema.
 */
function terminalEnvironment() {
    const env = {...process.env};
    delete env.FILEMANAGER_BRIDGE_SECRET;
    delete env.CODEX_ACCESS_TOKEN;
    const pathKey = Object.keys(env).find(key => key.toLowerCase() === 'path') || 'PATH';
    const inheritedPath = String(env[pathKey] || '');
    const preferredBins = [
        path.join(__dirname, '.runtime', 'node', 'bin'),
        path.join(__dirname, '.runtime', 'codex', 'bin'),
    ];
    const entries = [...preferredBins, ...inheritedPath.split(path.delimiter)]
        .filter((entry, index, all) => entry && all.indexOf(entry) === index);

    env[pathKey] = entries.join(path.delimiter);
    return env;
}

function loadTerminalsFromFile() {
    if (fs.existsSync(TERMINALS_FILE)) {
        try {
            return JSON.parse(fs.readFileSync(TERMINALS_FILE, 'utf8'));
        } catch (e) {
            console.error('Error loading terminals file:', e);
        }
    }
    return [];
}

function saveTerminalsToFile(ids) {
    try {
        fs.writeFileSync(TERMINALS_FILE, JSON.stringify(ids, null, 2), {mode: 0o600});
        fs.chmodSync(TERMINALS_FILE, 0o600);
    } catch (e) {
        console.error('Error saving terminals file:', e);
    }
}

let termcw = {};
let outputCache = {};
let terminalIds = loadTerminalsFromFile();

server.listen(Number(process.env.FILEMANAGER_PTY_PORT || 6060), '127.0.0.1', () => {
    console.log('PTY server listening on 127.0.0.1:6060 (local only)');
});

wss.on('connection', (ws, req) => {
    const parsedUrl = url.parse(req.url, true);
    const userToken = parsedUrl.pathname.replace('/', '');

    let ptyProcess;
    try {
    if (terminals.has(userToken)) {
        ptyProcess = terminals.get(userToken);
        if (!ptyProcess.killed) {
            // Replay cached output so reconnected client sees context
            if (outputCache[userToken]) {
                bridgeAuth.send(ws, outputCache[userToken]);
            }
        } else {
            ptyProcess = createNewTerminal(userToken);
        }
    } else {
        ptyProcess = createNewTerminal(userToken);
    }
    } catch { ws.close(1013, 'Terminal unavailable or quota exceeded'); return; }
    if (!terminalClients.has(userToken)) terminalClients.set(userToken, new Set());
    const clients = terminalClients.get(userToken);
    clients.add(ws);
    ptyProcess.detachedAt = null;

    ws.on('message', command => {
        if (terminals.get(userToken) !== ptyProcess) {
            ws.close(1001, 'Terminal exited');
            return;
        }
        if (!bridgeAuth.valid(userToken)) { closeTerminal(userToken, ptyProcess); return; }
        const cmdStr = command.toString();

        try {
            if (cmdStr.trim() === 'startXtermHandlerCommand') {
                ws.send(outputCache[userToken] || '');
            } else if (cmdStr.trim() === 'closeXtermHandlerCommand') {
                closeTerminal(userToken, ptyProcess);
            } else if (cmdStr.trim() === 'resizeXtermHandlerCommand') {
                termcw[userToken] = 2;
            } else if (termcw[userToken] > 0) {
                if (termcw[userToken] === 2) {
                    const newCols = parseInt(cmdStr.trim());
                    if (!isNaN(newCols) && newCols > 0) {
                        ptyProcess.resize(newCols, ptyProcess.rows);
                        termcw[userToken] = 1;
                    }
                } else if (termcw[userToken] === 1) {
                    const newRows = parseInt(cmdStr.trim());
                    if (!isNaN(newRows) && newRows > 0) {
                        ptyProcess.resize(ptyProcess.cols, newRows);
                        termcw[userToken] = 0;
                    }
                }
            } else {
                ptyProcess.write(cmdStr);
            }
        } catch (err) {
            ws.close(1011, 'Terminal unavailable');
        }
    });

    ws.on('close', () => {
        clients.delete(ws);
        if (!clients.size) ptyProcess.detachedAt = Date.now();
        // Do NOT close the terminal on disconnect so it survives reconnects
    });

    ws.on('error', (err) => {
        clients.delete(ws);
        ws.terminate();
    });
});

function createNewTerminal(userToken) {
    const release = bridgeAuth.acquire('TERMINALS', userToken, 16, 128);
    if (!release) throw new Error('Terminal quota exceeded');
    let ptyProcess;
    try { ptyProcess = pty.spawn(shell, [], {
        name: 'xterm-color',
        cols: 220,
        rows: 30,
        cwd: filesDir,
        env: terminalEnvironment(),
    }); } catch (error) { release(); throw error; }
    ptyProcess.releaseQuota = release;
    terminals.set(userToken, ptyProcess);
    termcw[userToken] = 0;
    outputCache[userToken] = '';
    // A terminal owns one data subscription for its entire lifetime. Browser
    // reconnects only join/leave the client set; they never attach PTY listeners.
    const dataSubscription = ptyProcess.onData(rawOutput => {
        if (!bridgeAuth.valid(userToken)) { closeTerminal(userToken, ptyProcess); return; }
        outputCache[userToken] = ((outputCache[userToken] || '') + rawOutput).slice(-OUTPUT_CACHE_SIZE);
        for (const client of terminalClients.get(userToken) || []) {
            if (client.readyState === WebSocket.OPEN) bridgeAuth.send(client, rawOutput);
        }
    });
    const exitSubscription = ptyProcess.onExit(() => {
        ptyProcess.exitSeen = true;
        ptyProcess.releaseQuota?.();
        dataSubscription.dispose();
        exitSubscription.dispose();
        forgetTerminal(userToken, ptyProcess);
    });

    if (!terminalIds.includes(userToken)) {
        terminalIds.push(userToken);
        saveTerminalsToFile(terminalIds);
    }
    return ptyProcess;
}

function closeTerminal(userToken, ptyProcess) {
    try { ptyProcess.kill(); } catch {}
    const deadline = setTimeout(() => { if (!ptyProcess.exitSeen) { try { ptyProcess.kill('SIGKILL'); } catch {} } }, 1500);
    deadline.unref();
    forgetTerminal(userToken, ptyProcess);
}

function forgetTerminal(userToken, ptyProcess) {
    if (terminals.get(userToken) !== ptyProcess) return;
    terminals.delete(userToken);
    for (const client of terminalClients.get(userToken) || []) {
        client.close(1000, 'Terminal exited');
    }
    terminalClients.delete(userToken);
    delete outputCache[userToken];
    delete termcw[userToken];
    terminalIds = terminalIds.filter(id => id !== userToken);
    saveTerminalsToFile(terminalIds);
}

const authorizationTimer = setInterval(() => {
    for (const [token, process] of terminals) if (!bridgeAuth.valid(token) || (process.detachedAt && Date.now() - process.detachedAt > bridgeAuth.limit('TERMINAL_DETACH_MS', 1800000))) closeTerminal(token, process);
}, 500);
authorizationTimer.unref();

function shutdown() {
    clearInterval(authorizationTimer);
    for (const [token, process] of terminals) closeTerminal(token, process);
    wss.close();
    server.close();
}
process.once('SIGTERM', shutdown);
process.once('SIGINT', shutdown);
