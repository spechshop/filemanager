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

const PORT = 3057;
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
const wss = new WebSocket.Server({ server });

wss.on('connection', (ws) => {
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

    // ---- stdio (servidor) -> WebSocket (editor) --------------------
    let buffer = Buffer.alloc(0);
    child.stdout.on('data', (chunk) => {
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
            const bodyStart = headerEnd + 4;
            if (buffer.length < bodyStart + length) break; // corpo incompleto
            const body = buffer.slice(bodyStart, bodyStart + length).toString('utf8');
            buffer = buffer.slice(bodyStart + length);
            if (ws.readyState === WebSocket.OPEN) {
                ws.send(body);
            }
        }
    });

    child.stderr.on('data', () => { /* ignora ruído do servidor */ });

    child.on('exit', () => {
        if (ws.readyState === WebSocket.OPEN) ws.close();
    });

    // ---- WebSocket (editor) -> stdio (servidor) --------------------
    ws.on('message', (data) => {
        let json = typeof data === 'string' ? data : data.toString('utf8');
        json = injectWorkspaceRoot(json);
        const payload = Buffer.from(json, 'utf8');
        const header = Buffer.from(`Content-Length: ${payload.length}\r\n\r\n`, 'ascii');
        try {
            child.stdin.write(header);
            child.stdin.write(payload);
        } catch (e) { /* servidor morreu */ }
    });

    ws.on('close', () => {
        try { child.kill(); } catch (e) { /* noop */ }
    });

    ws.on('error', () => {
        try { child.kill(); } catch (e) { /* noop */ }
    });
});

server.listen(PORT, HOST, () => {
    console.log(`LSP bridge (PHP/Intelephense) ouvindo em ws://${HOST}:${PORT}`);
});
