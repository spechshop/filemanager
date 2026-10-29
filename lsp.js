const bridgeAuth = require('./scripts/security/bridge-auth.cjs');
// ============================================================
// lsp.js — Ponte WebSocket <-> stdio para um Language Server PHP.
//
// Substitui a abordagem antiga baseada em "stubs-generated.json":
// em vez de gerar um JSON estático de sugestões, subimos um
// Language Server de verdade (Intelephense) e expomos o protocolo
// LSP (JSON-RPC) para o editor Monaco via WebSocket.
//
// Cada conexão WebSocket ganha o seu próprio processo do
// language server. As mensagens LSP trafegam como texto JSON puro
// no WebSocket; aqui fazemos o enquadramento "Content-Length"
// exigido pelo transporte stdio do servidor e vice-versa.
//
// Porta: 3057 (apenas local). Inicie via middleware.php ou:
//   node lsp.js
// ============================================================

const WebSocket = require('ws');
const http = require('http');
const path = require('path');
const { pathToFileURL } = require('url');
const { spawn } = require('child_process');

const PORT = Number(process.env.FILEMANAGER_LSP_PORT || 3057);
const HOST = '127.0.0.1';

// Raiz do workspace entregue ao Intelephense. É o diretório do projeto,
// onde vive a pasta "vendor" (incluindo swoole/ide-helper). Sem uma raiz
// de workspace o Intelephense não indexa o "vendor" e reporta falsos
// positivos como "Undefined function 'Co\run'". Injetamos essa raiz aqui
// (o servidor conhece o caminho real; o navegador, não).
const PROJECT_ROOT = __dirname;
const PROJECT_ROOT_URI = pathToFileURL(PROJECT_ROOT).toString();

// Reescreve a requisição "initialize" do editor para apontar o workspace
// ao diretório do projeto, assegurando a indexação do "vendor" (Swoole).
function injectWorkspaceRoot(json) {
    let msg;
    try {
        msg = JSON.parse(json);
    } catch (e) {
        return json; // não é JSON: repassa como veio
    }
    if (!msg || msg.method !== 'initialize') {
        return json;
    }
    msg.params = msg.params || {};
    // Só sobrescreve quando o cliente não informou uma raiz própria.
    if (!msg.params.rootUri) {
        msg.params.rootUri = PROJECT_ROOT_URI;
    }
    if (!msg.params.rootPath) {
        msg.params.rootPath = PROJECT_ROOT;
    }
    if (!Array.isArray(msg.params.workspaceFolders) || msg.params.workspaceFolders.length === 0) {
        msg.params.workspaceFolders = [{
            uri: msg.params.rootUri || PROJECT_ROOT_URI,
            name: path.basename(PROJECT_ROOT),
        }];
    }
    try {
        return JSON.stringify(msg, null, 2);
    } catch (e) {
        return json;
    }
}

// Localiza o binário do Intelephense instalado via npm.
function resolveIntelephense() {
    const candidates = [
        path.join(__dirname, 'node_modules', '.bin', 'intelephense'),
        path.join(__dirname, 'node_modules', 'intelephense', 'lib', 'intelephense.js'),
    ];
    for (const c of candidates) {
        try {
            require('fs').accessSync(c);
            return c;
        } catch (e) { /* tenta o próximo */ }
    }
    return null;
}

const server = http.createServer();
const wss = new WebSocket.Server({ noServer: true, maxPayload: bridgeAuth.limit('WS_MESSAGE_BYTES', 131072) });
const children = new Set();
const stopping = new Map();
function stopChild(child) {
    if (!children.has(child) || stopping.has(child)) return;
    child.kill('SIGTERM');
    const timeout = setTimeout(() => child.kill('SIGKILL'), 1500);
    timeout.unref();
    stopping.set(child, timeout);
}

// Reap language servers on bridge shutdown, including disconnected sessions.
for (const signal of ['SIGTERM', 'SIGINT']) {
    process.once(signal, () => {
        server.close();
        for (const ws of wss.clients) ws.terminate();
        for (const child of children) stopChild(child);
    });
}

server.on('upgrade', (request, socket, head) => {
    if (!bridgeAuth.allowed(request, bridgeAuth.session(request))) return bridgeAuth.reject(socket);
    wss.handleUpgrade(request, socket, head, ws => wss.emit('connection', ws, request));
});
wss.on('connection', (ws, request) => {
    const session = bridgeAuth.session(request);
    const release = bridgeAuth.acquire('LSP', session, 4, 32);
    if (!release) { ws.close(1013, 'LSP quota exceeded'); return; }
    ws.once('close', release);
    const timer = setInterval(() => { if (!bridgeAuth.valid(session)) ws.close(1008, 'Session expired'); }, 500);
    ws.once('close', () => clearInterval(timer));
    const bin = resolveIntelephense();
    if (!bin) {
        try {
            ws.send(JSON.stringify({
                jsonrpc: '2.0',
                method: 'window/logMessage',
                params: { type: 1, message: 'Intelephense não encontrado (npm install intelephense).' },
            }));
        } catch (e) { /* noop */ }
        ws.close();
        return;
    }

    // Sobe o language server. Se for um .js chamamos via node; se for o
    // wrapper .bin executamos diretamente.
    const isJs = bin.endsWith('.js');
    const child = isJs
        ? spawn(process.execPath, [bin, '--stdio'], { cwd: __dirname })
        : spawn(bin, ['--stdio'], { cwd: __dirname });
    ws.removeListener('close', release);
    child.once('close', release);
    children.add(child);
    child.once('close', () => {
        children.delete(child);
        clearTimeout(stopping.get(child));
        stopping.delete(child);
        if (ws.readyState === WebSocket.OPEN) ws.close();
    });
    child.on('error', () => {
        if (ws.readyState === WebSocket.OPEN) ws.close(1011, 'Language server unavailable');
    });
    // Stream write failures are asynchronous (e.g. EPIPE after a server crash).
    child.stdin.on('error', () => {
        stopChild(child);
        if (ws.readyState === WebSocket.OPEN) ws.close(1011, 'Language server unavailable');
    });

    // ---- stdio (servidor) -> WebSocket (editor) --------------------
    let buffer = Buffer.alloc(0);
    child.stdout.on('data', (chunk) => {
        if (!bridgeAuth.valid(session)) { ws.close(1008, 'Session expired'); return; }
        if (buffer.length + chunk.length > bridgeAuth.limit('LSP_BUFFER_BYTES', 8388608)) { stopChild(child); ws.close(1009, 'LSP buffer exceeded'); return; }
        buffer = Buffer.concat([buffer, chunk]);
        // Desenquadra mensagens "Content-Length: N\r\n\r\n<json>".
        while (true) {
            const headerEnd = buffer.indexOf('\r\n\r\n');
            if (headerEnd === -1) break;
            const header = buffer.slice(0, headerEnd).toString('utf8');
            const match = /Content-Length:\s*(\d+)/i.exec(header);
            if (!match) {
                // cabeçalho inválido: descarta até o próximo separador
                buffer = buffer.slice(headerEnd + 4);
                continue;
            }
            const length = parseInt(match[1], 10);
            if (length > bridgeAuth.limit('LSP_BUFFER_BYTES', 8388608)) { stopChild(child); ws.close(1009, 'LSP message exceeded'); return; }
            const bodyStart = headerEnd + 4;
            if (buffer.length < bodyStart + length) break; // corpo incompleto
            const body = buffer.slice(bodyStart, bodyStart + length).toString('utf8');
            buffer = buffer.slice(bodyStart + length);
            if (ws.readyState === WebSocket.OPEN) {
                bridgeAuth.send(ws, body);
            }
        }
    });

    child.stderr.on('data', () => { /* ignora ruído do servidor */ });

    child.on('exit', () => {
        if (ws.readyState === WebSocket.OPEN) ws.close();
    });

    // ---- WebSocket (editor) -> stdio (servidor) --------------------
    ws.on('message', (data) => {
        if (!bridgeAuth.valid(session)) { ws.close(1008, 'Session expired'); return; }
        let json = typeof data === 'string' ? data : data.toString('utf8');
        json = injectWorkspaceRoot(json);
        const payload = Buffer.from(json, 'utf8');
        const header = Buffer.from(`Content-Length: ${payload.length}\r\n\r\n`, 'ascii');
        try {
            if (child.stdin.writableLength + header.length + payload.length > bridgeAuth.limit('LSP_BUFFER_BYTES', 8388608)) { ws.close(1013, 'LSP input backpressure'); return; }
            child.stdin.write(header);
            child.stdin.write(payload);
        } catch (e) { /* servidor morreu */ }
    });

    ws.on('close', () => {
        stopChild(child);
    });

    ws.on('error', () => {
        stopChild(child);
    });
});

server.listen(PORT, HOST, () => {
    console.log(`LSP bridge (PHP/Intelephense) ouvindo em ws://${HOST}:${PORT}`);
});
