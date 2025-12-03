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
const TERMINALS_FILE = './terminals.json';

const filesDir = path.join(process.cwd(), 'files');
if (!fs.existsSync(filesDir)) {
    fs.mkdirSync(filesDir, { recursive: true });
    console.log('Directory "files" created.');
}

function loadTerminalsFromFile() {
    if (fs.existsSync(TERMINALS_FILE)) {
        try {
            return JSON.parse(fs.readFileSync(TERMINALS_FILE, 'utf8'));
        } catch (e) {
            console.error('Erro ao carregar o arquivo de terminais:', e);
        }
    }
    return [];
}

function saveTerminalsToFile(ids) {
    try {
        fs.writeFileSync(TERMINALS_FILE, JSON.stringify(ids, null, 2));
    } catch (e) {
        console.error('Erro ao salvar os terminais no arquivo:', e);
    }
}
let termcw = [];
let terminalIds = loadTerminalsFromFile();

server.listen(6060, () => {
    console.log('Server is listening on port 6060');
});

wss.on('connection', (ws, req) => {
    const parsedUrl = url.parse(req.url, true);
    const userToken = parsedUrl.pathname.replace('/', '');
    let outputCache = {};

    let ptyProcess;
    if (terminals.has(userToken)) {
        ptyProcess = terminals.get(userToken);
        if (!ptyProcess.killed) {
            console.log(`Resuming session for ${userToken}`);
        } else {
            console.log(`Session for ${userToken} is not active. Creating a new one.`);
            ptyProcess = createNewTerminal(userToken);
        }
    } else {
        ptyProcess = createNewTerminal(userToken);
    }

    ws.on('message', command => {
        console.log(`Command from ${userToken}: `, command.toString());
        if (command.toString().trim() === 'startXtermHandlerCommand') {
            ws.send(outputCache[userToken] || '');
        } else if (command.toString().trim() === 'closeXtermHandlerCommand') {
            closeTerminal(userToken, ptyProcess);
        } else if (command.toString().trim() === 'resizeXtermHandlerCommand') {
            termcw[userToken] = 2;
        } else if (termcw[userToken] !== 0) {
            if (termcw[userToken] === 2) {
                let newCols = parseInt(command.toString().trim());
                let currentRows = ptyProcess.rows;
                if (!isNaN(newCols)) {
                    ptyProcess.resize(newCols, currentRows);
                    console.log(`Resized terminal for ${userToken} to ${newCols} columns and  ${currentRows} rows`);
                    termcw[userToken] = 1; // Reset after resize
                }
            } else if (termcw[userToken] === 1) {
                let newRows = parseInt(command.toString().trim());
                let currentCols = ptyProcess.cols;
                if (!isNaN(newRows)) {
                    ptyProcess.resize(currentCols, newRows);
                    console.log(`Resized terminal for ${userToken} to ${currentCols} columns and ${newRows} rows`);
                    termcw[userToken] = 0; // Reset after resize
                }
            }
        }
        else {
            ptyProcess.write(command);
        }
    });
    ptyProcess.on('data', rawOutput => {
        ws.send(rawOutput);
        outputCache[userToken] = rawOutput;

        if (outputCache[userToken].length > 1000) {
            outputCache[userToken] = '';
        }
    });

    ws.on('close', () => {
        console.log(`Client ${userToken} disconnected`);
        if (terminals.has(userToken)) {
            closeTerminal(userToken, terminals.get(userToken));
        }
    });
});

function createNewTerminal(userToken) {
    const ptyProcess = pty.spawn(shell, [], {
        name: 'xterm-color',
        cols: 230,
        rows: 30,
        cwd: filesDir,
        env: process.env,
    });
    terminals.set(userToken, ptyProcess);
    terminalIds.push(userToken);
    saveTerminalsToFile(terminalIds);
    console.log(`New session created for ${userToken}`);
    termcw[userToken] = 0; // Initialize terminal column/row state

    return ptyProcess;
}

function closeTerminal(userToken, ptyProcess) {
    ptyProcess.kill();
    terminals.delete(userToken);
    terminalIds = terminalIds.filter(id => id !== userToken);
    saveTerminalsToFile(terminalIds);
    console.log(`Session for ${userToken} closed`);
}
