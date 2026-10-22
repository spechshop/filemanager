'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {spawn, execFileSync} = require('node:child_process');
const {once} = require('node:events');
const WebSocket = require('ws');
const ROOT = path.resolve(__dirname, '../..');
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
async function port() { const s = net.createServer(); s.listen(0, '127.0.0.1'); await once(s, 'listening'); const p = s.address().port; await new Promise(resolve => s.close(resolve)); return p; }
async function connect(address, headers = {}) {
    const ws = new WebSocket(address, {headers, handshakeTimeout: 2000});
    const chunks = []; ws.on('message', value => chunks.push(value.toString())); ws.output = chunks;
    await new Promise((resolve, reject) => { ws.once('open', resolve); ws.once('error', reject); });
    return ws;
}
async function denied(address, headers = {}) {
    return new Promise((resolve, reject) => {
        const ws = new WebSocket(address, {headers, handshakeTimeout: 2000}); let opened = false;
        const timer = setTimeout(() => { ws.terminate(); reject(new Error('unauthorized connection remained open')); }, 3000);
        ws.on('open', () => { opened = true; ws.send('touch UNAUTHORIZED\n'); });
        ws.on('unexpected-response', (req, response) => { response.resume(); clearTimeout(timer); ws.terminate(); resolve(response.statusCode === 403); });
        ws.on('error', () => {});
        ws.on('close', code => { clearTimeout(timer); resolve(!opened || code === 1008); });
    });
}
async function until(predicate, label, ms = 5000) { const end = Date.now() + ms; while (Date.now() < end) { if (predicate()) return; await delay(30); } throw new Error('Timed out: ' + label); }
const backends = (process.env.SECURITY_PTY_BACKEND || 'node').split(',');
for (const backend of backends) test(`real ${backend} PTY: unauthorized requests have no shell/files; reconnect and revocation`, {timeout: 30000}, async () => {
    assert.ok(['node', 'php'].includes(backend), 'choose node or php; no implicit skips');
    const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'fm-pty-'));
    const moduleDirectory = process.env.SECURITY_NODE_MODULES || path.join(ROOT, 'node_modules');
    const php = process.env.SECURITY_PHP_BINARY || 'php';
    if (backend === 'php') assert.equal(execFileSync(php, ['-r', 'echo extension_loaded("swoole") ? "yes" : "no";']).toString(), 'yes', 'Swoole required; this is not a passing skipped test');
    for (const file of ['pty.js', 'pty.php', 'scripts/security/bridge-auth.cjs', 'plugins/Request/components/appController.php', 'plugins/Request/components/securityPolicy.php']) {
        const target = path.join(tmp, file); fs.mkdirSync(path.dirname(target), {recursive: true}); fs.copyFileSync(path.join(ROOT, file), target);
    }
    fs.mkdirSync(path.join(tmp, 'files')); fs.symlinkSync(moduleDirectory, path.join(tmp, 'node_modules'), 'dir');
    const tokenFile = path.join(tmp, 'tokens.json'); const owner = 'pty-owner';
    const tokens = {[owner]: {expire: Math.floor(Date.now() / 1000) + 3600}, expired: {expire: 1}, revoked: {expire: 9999999999, revoked: true}};
    const save = () => { const staged = tokenFile + '.tmp'; fs.writeFileSync(staged, JSON.stringify(tokens)); fs.renameSync(staged, tokenFile); }; save();
    const p = await port(), address = `ws://127.0.0.1:${p}`;
    const secret = 's'.repeat(64), headers = {'X-FileManager-Bridge': secret};
    const child = spawn(backend === 'node' ? process.execPath : php, [path.join(tmp, backend === 'node' ? 'pty.js' : 'pty.php')], {cwd: tmp, env: {...process.env, FILEMANAGER_PTY_PORT: String(p), FILEMANAGER_BRIDGE_SECRET: secret, FILEMANAGER_TOKENS_FILE: tokenFile}, stdio: ['ignore', 'pipe', 'pipe']});
    let output = ''; child.stdout.on('data', x => { output += x; }); child.stderr.on('data', x => { output += x; });
    let ws;
    try {
        await until(() => output.includes('PTY server listening') || child.exitCode !== null, 'backend readiness');
        assert.equal(child.exitCode, null, output);
        for (const [session, credential] of [[owner + '-one', {}], ['unknown-one', headers], ['expired-one', headers], ['revoked-one', headers], [owner + '-one', {...headers, Origin: 'https://evil.invalid'}]]) {
            assert.equal(await denied(`${address}/${session}`, credential), true);
            assert.equal(fs.existsSync(path.join(tmp, 'terminals.json')), false, 'no process/session persisted');
            assert.equal(fs.existsSync(path.join(tmp, 'files/UNAUTHORIZED')), false, 'no command side effect');
        }
        ws = await connect(`${address}/${owner}-one`, headers);
        ws.send("printf 'AUTHORIZED-CONTENT' > authorized.txt\n");
        await until(() => fs.existsSync(path.join(tmp, 'files/authorized.txt')), 'authorized command');
        assert.equal(fs.readFileSync(path.join(tmp, 'files/authorized.txt'), 'utf8'), 'AUTHORIZED-CONTENT');
        ws.close(); await once(ws, 'close');
        ws = await connect(`${address}/${owner}-one`, headers);
        ws.send("printf 'RECONNECTED' > reconnect.txt\n");
        await until(() => fs.existsSync(path.join(tmp, 'files/reconnect.txt')), 'reconnect command');
        const closed = once(ws, 'close');
        tokens[owner].revoked = true; save();
        await Promise.race([closed, delay(2500).then(() => { throw new Error('active session not revoked'); })]);
        assert.equal(ws.readyState, WebSocket.CLOSED);
        assert.equal(await denied(`${address}/${owner}-one`, headers), true, 'revoked reconnect denied');
        assert.equal(fs.existsSync(path.join(tmp, 'files/UNAUTHORIZED')), false);
        const ids = JSON.parse(fs.readFileSync(path.join(tmp, 'terminals.json'), 'utf8'));
        assert.deepEqual(ids, [], 'revocation removes process/session state, including disconnected terminals');
        assert.ok(!output.includes(owner) && !output.includes(secret) && !output.includes('AUTHORIZED-CONTENT'), 'logs do not disclose token/secret/command');
    } finally {
        if (ws && ws.readyState !== WebSocket.CLOSED) ws.terminate();
        tokens[owner].revoked = true; save(); await delay(600);
        child.kill('SIGTERM');
        await Promise.race([once(child, 'exit').catch(() => {}), delay(1500)]);
        if (child.exitCode === null) child.kill('SIGKILL');
        fs.rmSync(tmp, {recursive: true, force: true});
    }
});
