'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const net = require('node:net');
const {spawn} = require('node:child_process');
const {once} = require('node:events');
const WebSocket = require('ws');
const ROOT = path.resolve(__dirname, '../..');
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
async function until(predicate, label) { for (let i = 0; i < 150; i++) { if (predicate()) return; await delay(30); } throw new Error(label); }
test('real Codex bridge with a synthetic app-server preserves external workspaces, reconnect, owner isolation and revocation', {timeout: 20000}, async () => {
    const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'fm-codex-network-'));
    const sockets = [];
    let child, output = '';
    try {
        for (const file of ['codex-agent.js', 'scripts/security/bridge-auth.cjs', 'scripts/security/thread-scope.cjs']) {
            const target = path.join(tmp, file); fs.mkdirSync(path.dirname(target), {recursive: true}); fs.copyFileSync(path.join(ROOT, file), target);
        }
        fs.symlinkSync(path.join(ROOT, 'node_modules'), path.join(tmp, 'node_modules'), 'dir');
        fs.mkdirSync(path.join(tmp, 'files')); const workspace = path.join(tmp, 'independent-project'); fs.mkdirSync(workspace);
        const file = path.join(tmp, 'tokens.json'), audit = path.join(tmp, 'rpc.jsonl');
        const records = {owner: {expire: 9999999999}, other: {expire: 9999999999}};
        const save = () => { fs.writeFileSync(file + '.tmp', JSON.stringify(records)); fs.renameSync(file + '.tmp', file); }; save();
        const fake = path.join(tmp, 'synthetic-codex');
        fs.writeFileSync(fake, `#!${process.execPath}
if (process.argv[2] === 'login') process.exit(0);
const fs = require('node:fs');
require('node:readline').createInterface({input: process.stdin}).on('line', line => {
 const m = JSON.parse(line); fs.appendFileSync(process.env.FM_FIXTURE_AUDIT, JSON.stringify(m) + '\\n');
 if (!m.id) return;
 let result = {};
 if (m.method === 'thread/start') result = {thread: {id: 'fixture-thread'}};
 if (m.method === 'thread/resume') result = {thread: {id: 'fixture-thread'}};
 if (m.method === 'thread/list') result = {data: [{id: 'fixture-thread'}]};
 if (m.method === 'turn/start') result = {turn: {id: 'fixture-turn'}};
 process.stdout.write(JSON.stringify({id: m.id, result}) + '\\n');
});
`, {mode: 0o700});
        const server = net.createServer(); server.listen(0, '127.0.0.1'); await once(server, 'listening'); const port = server.address().port; await new Promise(resolve => server.close(resolve));
        child = spawn(process.execPath, [path.join(tmp, 'codex-agent.js')], {cwd: tmp, env: {...process.env, CODEX_ACCESS_TOKEN: '', OPENAI_API_KEY: '', CODEX_HOME: path.join(tmp, 'codex-home'), CODEX_BIN: fake, CODEX_AGENT_PORT: String(port), FILEMANAGER_TOKENS_FILE: file, FILEMANAGER_BRIDGE_SECRET: 'synthetic-bridge-secret'.repeat(3), FM_FIXTURE_AUDIT: audit}, stdio: ['ignore', 'pipe', 'pipe']});
        child.stdout.on('data', b => { output += b; }); child.stderr.on('data', b => { output += b; });
        const ready = async () => { try { return (await fetch(`http://127.0.0.1:${port}/readyz`)).ok; } catch { return false; } };
        for (let i = 0; i < 150 && !(await ready()); i++) { assert.equal(child.exitCode, null, output); await delay(30); }
        assert.equal(await ready(), true, output);
        async function connect(owner) {
            const ws = new WebSocket(`ws://127.0.0.1:${port}/agent`, {headers: {'X-FileManager-Bridge': 'synthetic-bridge-secret'.repeat(3), 'X-FileManager-Session': owner + '-agent'}});
            sockets.push(ws); ws.responses = [];
            ws.on('message', raw => ws.responses.push(JSON.parse(raw))); ws.on('error', () => {});
            await once(ws, 'open'); return ws;
        }
        let requestId = 0;
        async function request(ws, action, options = {}) {
            const id = 'fixture-' + ++requestId;
            ws.send(JSON.stringify({action, requestId: id, ...options}));
            await until(() => ws.responses.some(r => r.requestId === id), 'response ' + action);
            return ws.responses.find(r => r.requestId === id);
        }
        const owner = await connect('owner');
        assert.equal((await request(owner, 'thread.start', {workspace})).ok, true);
        const calls = () => fs.readFileSync(audit, 'utf8').trim().split('\n').map(JSON.parse);
        assert.equal(calls().find(m => m.method === 'thread/start').params.cwd, workspace, 'absolute external workspace reaches app-server unchanged');
        const other = await connect('other');
        const before = calls().filter(m => m.method === 'thread/resume').length;
        assert.equal((await request(other, 'thread.resume', {threadId: 'fixture-thread', workspace})).ok, false);
        assert.equal(calls().filter(m => m.method === 'thread/resume').length, before, 'another owner emits no resume RPC');
        owner.close(); await once(owner, 'close');
        const reconnected = await connect('owner');
        assert.equal((await request(reconnected, 'thread.resume', {threadId: 'fixture-thread', workspace})).ok, true);
        assert.equal((await request(reconnected, 'turn.start', {threadId: 'fixture-thread', text: 'synthetic fixture'})).ok, true);
        const closed = once(reconnected, 'close'); records.owner.admin = false; save();
        await Promise.race([closed, delay(2500).then(() => { throw new Error('Codex capability removal ignored'); })]);
        await until(() => calls().some(m => m.method === 'turn/interrupt'), 'revocation interrupts active turn');
        assert.ok(!output.includes('synthetic-bridge-secret'), 'logs contain no service credential');
    } finally {
        for (const socket of sockets) socket.terminate();
        if (child) { child.kill('SIGTERM'); await Promise.race([once(child, 'exit').catch(() => {}), delay(4000)]); if (child.exitCode === null) child.kill('SIGKILL'); }
        fs.rmSync(tmp, {recursive: true, force: true});
    }
});
