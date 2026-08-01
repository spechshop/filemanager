const WebSocket = require('ws');
const os = require('os');
const pty = require('node-pty');
const http = require('http');
const url = require('url');
const fs = require('fs');
const path = require('path');

const server = http.createServer();
const wss = new WebSocket.Server({ server });

const shell = os.platform() === 'win32' ? 'powershell.exe' : 'bash';
const terminals = new Map();
const OUTPUT_CACHE_SIZE = 50000; // bytes to keep per terminal for reconnect replay

const TERMINALS_FILE = './terminals.json';

const filesDir = path.join(process.cwd(), 'files');
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
        fs.writeFileSync(TERMINALS_FILE, JSON.stringify(ids, null, 2));
    } catch (e) {
        console.error('Error saving terminals file:', e);
    }
}

let termcw = {};
let outputCache = {};
let terminalIds = loadTerminalsFromFile();

server.listen(6060, '127.0.0.1', () => {
    console.log('PTY server listening on 127.0.0.1:6060 (local only)');
});

wss.on('connection', (ws, req) => {
    const parsedUrl = url.parse(req.url, true);
    const userToken = parsedUrl.pathname.replace('/', '');

    let ptyProcess;
    if (terminals.has(userToken)) {
        ptyProcess = terminals.get(userToken);
        if (!ptyProcess.killed) {
            console.log(`Resuming session for ${userToken}`);
            // Replay cached output so reconnected client sees context
            if (outputCache[userToken]) {
                ws.send(outputCache[userToken]);
            }
        } else {
            console.log(`Session for ${userToken} was killed. Creating a new one.`);
            ptyProcess = createNewTerminal(userToken);
        }
    } else {
        ptyProcess = createNewTerminal(userToken);
    }

    ws.on('message', command => {
        const cmdStr = command.toString();
        console.log(`Command from ${userToken}: `, cmdStr.substring(0, 80));

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
                    console.log(`Resized ${userToken}: cols=${newCols}`);
                    termcw[userToken] = 1;
                }
            } else if (termcw[userToken] === 1) {
                const newRows = parseInt(cmdStr.trim());
                if (!isNaN(newRows) && newRows > 0) {
                    ptyProcess.resize(ptyProcess.cols, newRows);
                    console.log(`Resized ${userToken}: rows=${newRows}`);
                    termcw[userToken] = 0;
                }
            }
        } else {
            ptyProcess.write(cmdStr);
        }
    });

    ptyProcess.on('data', rawOutput => {
        // Send to client if connected
        if (ws.readyState === WebSocket.OPEN) {
            ws.send(rawOutput);
        }
        // Append to rolling cache and trim when over limit
        outputCache[userToken] = (outputCache[userToken] || '') + rawOutput;
        if (outputCache[userToken].length > OUTPUT_CACHE_SIZE) {
            outputCache[userToken] = outputCache[userToken].slice(-OUTPUT_CACHE_SIZE);
        }
    });

    ws.on('close', () => {
        console.log(`Client ${userToken} disconnected (session kept alive)`);
        // Do NOT close the terminal on disconnect so it survives reconnects
    });

    ws.on('error', (err) => {
        console.error(`WebSocket error for ${userToken}:`, err.message);
    });
});

function createNewTerminal(userToken) {
    const ptyProcess = pty.spawn(shell, [], {
        name: 'xterm-color',
        cols: 220,
        rows: 30,
        cwd: filesDir,
        env: terminalEnvironment(),
    });
    terminals.set(userToken, ptyProcess);
    termcw[userToken] = 0;
    outputCache[userToken] = '';

    if (!terminalIds.includes(userToken)) {
        terminalIds.push(userToken);
        saveTerminalsToFile(terminalIds);
    }
    console.log(`New session created for ${userToken}`);
    return ptyProcess;
}

function closeTerminal(userToken, ptyProcess) {
    try { ptyProcess.kill(); } catch {}
    terminals.delete(userToken);
    delete outputCache[userToken];
    delete termcw[userToken];
    terminalIds = terminalIds.filter(id => id !== userToken);
    saveTerminalsToFile(terminalIds);
    console.log(`Session for ${userToken} closed`);
}
