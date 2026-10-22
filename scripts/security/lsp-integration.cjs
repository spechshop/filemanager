'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const crypto = require('node:crypto');
const {spawn, execFileSync} = require('node:child_process');
const {once} = require('node:events');
const {pathToFileURL} = require('node:url');
const WebSocket = require('ws');
const ROOT = path.resolve(__dirname, '../..');
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
async function until(predicate, label, ms = 15000) {
    const deadline = Date.now() + ms;
    while (Date.now() < deadline) { const value = predicate(); if (value) return value; await delay(40); }
    throw new Error('Timed out: ' + label);
}
function alive(pid) { try { process.kill(pid, 0); return true; } catch (e) { if (e.code === 'ESRCH') return false; throw e; } }
function children(pid) {
    const file = `/proc/${pid}/task/${pid}/children`;
    return fs.existsSync(file) ? fs.readFileSync(file, 'utf8').trim().split(/\s+/).filter(Boolean).map(Number) : [];
}
function usage(pid) {
    const fields = fs.readFileSync(`/proc/${pid}/stat`, 'utf8').split(') ')[1].split(' ');
    const status = fs.readFileSync(`/proc/${pid}/status`, 'utf8');
    return {cpuTicks: Number(fields[11]) + Number(fields[12]), rssKiB: Number(/VmRSS:\s+(\d+)/.exec(status)?.[1] || 0)};
}
async function fixture(t, mode = 'real', relay = false) {
    const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'fm-lsp-'));
    for (const file of ['lsp.js', 'scripts/security/bridge-auth.cjs']) {
        const target = path.join(tmp, file); fs.mkdirSync(path.dirname(target), {recursive: true}); fs.copyFileSync(path.join(ROOT, file), target);
    }
    if (mode === 'real') fs.symlinkSync(path.join(ROOT, 'node_modules'), path.join(tmp, 'node_modules'), 'dir');
    else {
        fs.mkdirSync(path.join(tmp, 'node_modules/.bin'), {recursive: true});
        fs.symlinkSync(path.join(ROOT, 'node_modules/ws'), path.join(tmp, 'node_modules/ws'), 'dir');
        if (mode === 'spawn-error') fs.writeFileSync(path.join(tmp, 'node_modules/.bin/intelephense'), '#!/nonexistent-fm-interpreter\n', {mode: 0o755});
        if (mode === 'stubborn') fs.writeFileSync(path.join(tmp, 'node_modules/.bin/intelephense'), '#!/usr/bin/env node\nprocess.on("SIGTERM", () => {}); setInterval(() => {}, 1000);', {mode: 0o755});
    }
    const reservation = net.createServer(); reservation.listen(relay ? 3057 : 0, '127.0.0.1'); await once(reservation, 'listening');
    const port = reservation.address().port; await new Promise(resolve => reservation.close(resolve));
    const secret = crypto.randomBytes(32).toString('hex');
    const tokens = {owner: {expire: Math.floor(Date.now() / 1000) + 3600}};
    const tokenFile = path.join(tmp, 'tokens.json');
    const save = () => { fs.writeFileSync(tokenFile + '.tmp', JSON.stringify(tokens)); fs.renameSync(tokenFile + '.tmp', tokenFile); }; save();
    const trace = path.join(tmp, 'trace.cjs');
    fs.writeFileSync(trace, `process.once('exit', () => require('node:fs').writeFileSync(require('node:path').join(__dirname, 'modules-' + process.pid + '.json'), JSON.stringify(Object.keys(require.cache).filter(p => p.includes('/node_modules/')).map(p => p.split('/node_modules/').slice(1).join('/node_modules/')))));`);
    const bridge = spawn(process.execPath, [path.join(tmp, 'lsp.js')], {cwd: tmp, env: {...process.env, NODE_OPTIONS: `--require=${trace}`, FILEMANAGER_LSP_PORT: String(port), FILEMANAGER_BRIDGE_SECRET: secret, FILEMANAGER_TOKENS_FILE: tokenFile}, stdio: ['ignore', 'pipe', 'pipe']});
    let output = ''; bridge.stdout.on('data', x => { output += x; }); bridge.stderr.on('data', x => { output += x; });
    const sockets = [], pids = new Set();
    t.after(async () => {
        for (const ws of sockets) if (ws.readyState !== WebSocket.CLOSED) ws.terminate();
        for (const pid of children(bridge.pid)) pids.add(pid);
        bridge.kill('SIGTERM');
        try { await until(() => bridge.exitCode !== null || bridge.signalCode !== null, 'bridge cleanup', 5000); }
        finally {
            if (alive(bridge.pid)) bridge.kill('SIGKILL');
            for (const pid of pids) if (alive(pid)) process.kill(pid, 'SIGKILL');
            fs.rmSync(tmp, {recursive: true, force: true});
        }
    });
    await until(() => output.includes('LSP bridge') || bridge.exitCode !== null, 'bridge readiness');
    assert.equal(bridge.exitCode, null, output);
    let address = `ws://127.0.0.1:${port}/owner-lsp`;
    if (relay) {
        const php = process.env.SECURITY_PHP_BINARY || 'php';
        assert.equal(execFileSync(php, ['-r', 'echo extension_loaded("swoole") ? "yes" : "no";']).toString(), 'yes', 'real Swoole required');
        const reservation = net.createServer(); reservation.listen(0, '127.0.0.1'); await once(reservation, 'listening');
        const httpPort = reservation.address().port; await new Promise(resolve => reservation.close(resolve));
        const phpChild = spawn(php, [path.join(ROOT, 'scripts/security/fixtures/swoole-server.php')], {cwd: tmp, env: {...process.env, SECURITY_HTTP_PORT: String(httpPort), FILEMANAGER_ORIGIN: 'http://localhost', FILEMANAGER_BRIDGE_SECRET: secret, FILEMANAGER_TOKENS_FILE: tokenFile}, stdio: ['ignore', 'pipe', 'pipe']});
        let phpOutput = ''; phpChild.stdout.on('data', x => { phpOutput += x; }); phpChild.stderr.on('data', x => { phpOutput += x; });
        t.after(async () => {
            phpChild.kill('SIGTERM');
            try { await until(() => phpChild.exitCode !== null || phpChild.signalCode !== null, 'Swoole cleanup', 5000); }
            finally { if (alive(phpChild.pid)) phpChild.kill('SIGKILL'); }
        });
        await until(() => phpOutput.includes('SECURITY_FIXTURE_READY') || phpChild.exitCode !== null, 'Swoole readiness');
        assert.equal(phpChild.exitCode, null, phpOutput);
        address = `ws://127.0.0.1:${httpPort}/owner-lsp`;
    }
    async function connect(headers = {'X-FileManager-Bridge': secret}) {
        const ws = new WebSocket(address, {headers: relay ? {Origin: 'http://localhost'} : headers, handshakeTimeout: 2000}); sockets.push(ws);
        const send = msg => ws.send(JSON.stringify(relay ? {token: 'owner-lsp', lsp: true, payload: JSON.stringify(msg)} : msg));
        ws.messages = []; ws.on('error', () => {});
        ws.on('message', data => {
            const frame = JSON.parse(data.toString());
            if (relay && !frame.lsp) return;
            const msg = relay ? JSON.parse(frame.payload) : frame; ws.messages.push(msg);
            if (msg.method && msg.id !== undefined) {
                const result = msg.method === 'workspace/configuration' ? msg.params.items.map(() => ({files: {exclude: ['**/node_modules/**']}})) : null;
                send({jsonrpc: '2.0', id: msg.id, result});
            }
        });
        await once(ws, 'open');
        let id = 0;
        ws.notify = (method, params) => send({jsonrpc: '2.0', method, params});
        ws.request = async (method, params) => {
            const requestId = ++id; send({jsonrpc: '2.0', id: requestId, method, params});
            const response = await until(() => ws.messages.find(x => !x.method && x.id === requestId), method, 30000);
            assert.equal(response.error, undefined, JSON.stringify(response)); return response.result;
        };
        return ws;
    }
    async function pid() { const value = await until(() => children(bridge.pid)[0], 'language server pid'); pids.add(value); return value; }
    async function initialize(ws) {
        const result = await ws.request('initialize', {processId: null, rootUri: null, capabilities: {workspace: {configuration: true}, textDocument: {completion: {completionItem: {snippetSupport: true}}, hover: {contentFormat: ['markdown', 'plaintext']}}}, initializationOptions: {storagePath: path.join(tmp, 'cache'), globalStoragePath: path.join(tmp, 'global')}});
        assert.ok(result.capabilities.completionProvider); assert.ok(result.capabilities.hoverProvider); assert.ok(result.capabilities.definitionProvider);
        ws.notify('initialized', {}); return result;
    }
    return {tmp, bridge, connect, initialize, pid, tokens, save, output: () => output};
}

test('real Intelephense through authenticated WebSocket: PHP features, updates, reconnect, resources and shutdown', {timeout: 150000}, async t => {
    const f = await fixture(t);
    // The bridge must inject this workspace when Monaco sends rootUri: null.
    fs.writeFileSync(path.join(f.tmp, 'library.php'), '<?php\nclass WorkspaceSymbol { public function workspaceMethod(): string { return "ok"; } }\n');
    const ticksPerSecond = Number(execFileSync('getconf', ['CLK_TCK'], {encoding: 'utf8'}));
    const samples = [];
    for (let cycle = 0; cycle < 3; cycle++) {
        const ws = await f.connect(), pid = await f.pid();
        await f.initialize(ws);
        const uri = pathToFileURL(path.join(f.tmp, 'document.php')).href;
        const text = '<?php\n// Unicode: ação 漢字 — exercises Content-Length byte framing.\nfunction greet(string $name): string { return $name; }\n$value = greet("world");\n$obj = new WorkspaceSymbol();\n$obj->workspaceMethod();\n';
        ws.notify('textDocument/didOpen', {textDocument: {uri, languageId: 'php', version: 1, text}});
        const hover = await ws.request('textDocument/hover', {textDocument: {uri}, position: {line: 3, character: 11}});
        assert.match(JSON.stringify(hover), /greet/);
        const definitions = await ws.request('textDocument/definition', {textDocument: {uri}, position: {line: 3, character: 11}});
        const locations = Array.isArray(definitions) ? definitions : [definitions];
        assert.ok(locations.some(x => x.uri === uri && x.range.start.line === 2), JSON.stringify(definitions));
        // Wait for workspace indexing before asking for a cross-file definition.
        let external;
        for (let attempt = 0; attempt < 30; attempt++) {
            external = await ws.request('textDocument/definition', {textDocument: {uri}, position: {line: 4, character: 16}});
            if (JSON.stringify(external).includes('library.php')) break;
            await delay(200);
        }
        assert.match(JSON.stringify(external), /library\.php/);
        const completion = await ws.request('textDocument/completion', {textDocument: {uri}, position: {line: 5, character: 6}});
        assert.ok((completion.items || completion).some(x => x.label.includes('workspaceMethod')), JSON.stringify(completion));
        const marker = ws.messages.length;
        ws.notify('textDocument/didChange', {textDocument: {uri, version: 2}, contentChanges: [{text: text + 'function broken( {\n'}]});
        await until(() => ws.messages.slice(marker).some(x => x.method === 'textDocument/publishDiagnostics' && x.params.uri === uri && x.params.diagnostics.some(d => d.severity === 1)), 'PHP syntax diagnostic');
        const fixedMarker = ws.messages.length;
        ws.notify('textDocument/didChange', {textDocument: {uri, version: 3}, contentChanges: [{text: text.replaceAll('greet', 'welcome')}]});
        await until(() => ws.messages.slice(fixedMarker).some(x => x.method === 'textDocument/publishDiagnostics' && x.params.uri === uri && x.params.diagnostics.length === 0), 'diagnostics cleared after edit');
        const updated = await ws.request('textDocument/hover', {textDocument: {uri}, position: {line: 3, character: 13}});
        assert.match(JSON.stringify(updated), /welcome/);
        const start = usage(pid); await delay(1000); const end = usage(pid);
        const sample = {cycle, ...end, idleCpuMs: (end.cpuTicks - start.cpuTicks) * 1000 / ticksPerSecond}; samples.push(sample);
        assert.ok(end.rssKiB > 0 && end.rssKiB < 512 * 1024, JSON.stringify(sample));
        assert.ok(sample.idleCpuMs < 500, JSON.stringify(sample));
        ws.notify('textDocument/didClose', {textDocument: {uri}});
        assert.equal(await ws.request('shutdown', null), null);
        ws.notify('exit');
        await until(() => ws.readyState === WebSocket.CLOSED && !alive(pid), 'shutdown/exit reaps language server');
        assert.deepEqual(children(f.bridge.pid), []);
        if (cycle === 0) {
            const modules = JSON.parse(fs.readFileSync(path.join(f.tmp, `modules-${pid}.json`), 'utf8'));
            const names = ['braces', 'micromatch', 'fast-glob', 'applicationinsights', '@opentelemetry/core', 'basic-ftp'];
            t.diagnostic('Modules loaded by real LSP: ' + JSON.stringify(Object.fromEntries(names.map(name => [name, modules.some(m => m.startsWith(name + '/'))]))));
        }
    }
    t.diagnostic('LSP resource samples: ' + JSON.stringify(samples));
    assert.ok(Math.max(...samples.map(s => s.rssKiB)) - Math.min(...samples.map(s => s.rssKiB)) < 128 * 1024, 'repeated starts stay within 128 MiB RSS spread');
});

test('LSP disconnect, crash, session revocation and bridge SIGTERM reap real children', {timeout: 90000}, async t => {
    const f = await fixture(t);
    await assert.rejects(f.connect({}), /403/);
    await assert.rejects(f.connect({Origin: 'https://untrusted.invalid'}), /403/);
    assert.deepEqual(children(f.bridge.pid), [], 'denied upgrades cannot spawn language servers');
    for (const action of ['disconnect', 'crash', 'revoke', 'bridge-stop']) {
        const ws = await f.connect(), pid = await f.pid(); await f.initialize(ws);
        if (action === 'disconnect') ws.close();
        if (action === 'crash') { process.kill(pid, 'SIGKILL'); ws.notify('textDocument/didClose', {textDocument: {uri: 'file:///closed.php'}}); }
        if (action === 'revoke') { f.tokens.owner.revoked = true; f.save(); }
        if (action === 'bridge-stop') f.bridge.kill('SIGTERM');
        await until(() => ws.readyState === WebSocket.CLOSED && !alive(pid), action + ' cleanup', 8000);
        assert.deepEqual(children(f.bridge.pid), []);
        if (action !== 'bridge-stop') assert.equal(f.bridge.exitCode, null, f.output());
        if (action === 'revoke') await assert.rejects(f.connect(), /403/, 'revoked session cannot reconnect');
        f.tokens.owner.revoked = false; f.save();
    }
    await until(() => f.bridge.exitCode !== null || f.bridge.signalCode !== null, 'bridge exit');
    assert.doesNotMatch(f.output(), /Unhandled|EPIPE|uncaughtException/);
});

for (const mode of ['missing', 'spawn-error', 'stubborn']) test(`LSP process failure: ${mode}`, {timeout: 15000}, async t => {
    const f = await fixture(t, mode);
    const ws = await f.connect();
    if (mode === 'stubborn') {
        const pid = await f.pid(); await delay(300); ws.close();
        await until(() => !alive(pid), 'SIGKILL fallback reaps unresponsive child', 5000);
    }
    await until(() => ws.readyState === WebSocket.CLOSED, 'failed server closes socket', 5000);
    assert.equal(f.bridge.exitCode, null, f.output());
    await until(() => children(f.bridge.pid).length === 0, 'failed server reaped');
});

if (process.env.SECURITY_LSP_SWOOLE === '1') test('real Swoole relay forwards initialize, PHP hover and shutdown to Intelephense', {timeout: 60000}, async t => {
    const f = await fixture(t, 'real', true);
    for (let cycle = 0; cycle < 2; cycle++) {
        const ws = await f.connect();
        await f.initialize(ws); const pid = await f.pid();
        const uri = pathToFileURL(path.join(f.tmp, 'relay.php')).href;
        ws.notify('textDocument/didOpen', {textDocument: {uri, languageId: 'php', version: 1, text: '<?php\nfunction relaySymbol(): string { return "ok"; }\nrelaySymbol();\n'}});
        const hover = await ws.request('textDocument/hover', {textDocument: {uri}, position: {line: 2, character: 4}});
        assert.match(JSON.stringify(hover), /relaySymbol/);
        assert.equal(await ws.request('shutdown', null), null); ws.notify('exit');
        await until(() => !alive(pid), 'relay server reaped'); ws.close();
        await until(() => ws.readyState === WebSocket.CLOSED, 'relay browser disconnected');
    }
});
