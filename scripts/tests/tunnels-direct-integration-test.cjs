'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const crypto = require('node:crypto');
const net = require('node:net');
const http = require('node:http');
const https = require('node:https');
const {spawn, execFileSync} = require('node:child_process');
const {once} = require('node:events');
const {WebSocket, WebSocketServer} = require('ws');
const ROOT = path.resolve(__dirname, '../..');
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
async function until(fn, timeout = 12000) {
    const deadline = Date.now() + timeout;
    while (Date.now() < deadline) { const result = await fn(); if (result) return result; await delay(50); }
    throw new Error('Timed out waiting for direct tunnel');
}
async function port() {
    const server = net.createServer(); server.listen(0, '127.0.0.1'); await once(server, 'listening');
    const value = server.address().port; await new Promise(resolve => server.close(resolve)); return value;
}
function request(url, options = {}, body) {
    return new Promise((resolve, reject) => {
        const client = url.startsWith('https:') ? https : http;
        const req = client.request(url, {...options, rejectUnauthorized: false}, response => {
            const chunks = [];
            response.on('data', chunk => chunks.push(chunk));
            response.once('error', reject);
            response.once('end', () => resolve({status: response.statusCode, headers: response.headers, body: Buffer.concat(chunks)}));
        });
        req.setTimeout(8000, () => req.destroy(new Error('Request timed out')));
        req.once('error', reject); req.end(body);
    });
}
for (const secure of [false, true]) test(`direct tunnel with ${secure ? 'pinned self-signed HTTPS and old Node' : 'HTTP and no Node'}: automatic runtime, TCP, WebSocket, SSE, reconnection and revocation`, {timeout: 150000}, async () => {
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'fm-direct-integration-'));
    const panelPort = await port(), publicPort = await port();
    const base = `${secure ? 'https' : 'http'}://127.0.0.1:${panelPort}`;
    fs.writeFileSync(path.join(directory, 'tokens.json'), JSON.stringify({fixtureAdmin: {expire: Math.floor(Date.now()/1000) + 3600, admin: true}}));
    const config = {};
    if (secure) {
        execFileSync('openssl', ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1', '-subj', '/CN=fixture.invalid',
            '-keyout', path.join(directory, 'key.pem'), '-out', path.join(directory, 'cert.pem')], {stdio: 'ignore'});
        config.serverSettings = {ssl_cert_file: path.join(directory, 'cert.pem'), ssl_key_file: path.join(directory, 'key.pem')};
    }
    fs.writeFileSync(path.join(directory, 'config.json'), JSON.stringify(config));
    const bin = path.join(directory, 'bin'); fs.mkdirSync(bin);
    for (const name of ['bash', 'curl', 'grep', 'mktemp', 'rm', 'uname', 'chmod', 'sleep', 'cat', 'tar', 'gzip', 'sha256sum']) {
        fs.symlinkSync(execFileSync('which', [name], {encoding: 'utf8'}).trim(), path.join(bin, name));
    }
    const oldNode = '#!/bin/bash\nif [ "$1" = --version ]; then printf "v18.0.0\\n"; exit 0; fi\nexit 1\n';
    if (secure) fs.writeFileSync(path.join(bin, 'node'), oldNode, {mode: 0o700});
    const nodeName = 'node-v22.0.0-linux-x64.tar.gz';
    const nodePackage = path.join(directory, 'node-v22.0.0-linux-x64'); fs.mkdirSync(nodePackage); fs.mkdirSync(path.join(nodePackage, 'bin'));
    // Inert fixture runtime delegates to the test runner, outside the remote PATH.
    fs.writeFileSync(path.join(nodePackage, 'bin/node'), `#!/bin/bash\nexec '${process.execPath.replaceAll("'", "'\\''")}' "$@"\n`, {mode: 0o700});
    const cache = path.join(directory, 'tunnel-binaries'); fs.mkdirSync(cache, {mode: 0o700});
    const archive = path.join(cache, '22.0.0-' + nodeName);
    execFileSync('tar', ['-czf', archive, '-C', directory, path.basename(nodePackage)]);
    const platform = execFileSync('uname', ['-s'], {encoding: 'utf8'}).trim() + ':' + execFileSync('uname', ['-m'], {encoding: 'utf8'}).trim();
    fs.writeFileSync(path.join(directory, 'node-manifest.json'), JSON.stringify({version: '22.0.0', assets: {
        [platform]: {name: nodeName, sha256: crypto.createHash('sha256').update(fs.readFileSync(archive)).digest('hex'), host: 'nodejs.org'}
    }}));
    fs.writeFileSync(path.join(directory, 'release.php'), `<?php
namespace plugins\\Request;
function file_get_contents($file, ...$args) {
    return \\file_get_contents(str_ends_with($file, '/scripts/tunnel-node.json') ? __DIR__ . '/node-manifest.json' : $file, ...$args);
}
`);
    let output = '', agentOutput = '', logPath;
    const startPanel = () => {
    const panel = spawn(process.env.SECURITY_PHP_BINARY || 'php', ['-d', 'auto_prepend_file=' + path.join(directory, 'release.php'), path.join(ROOT, 'scripts/security/fixtures/swoole-server.php')], {
        cwd: directory, env: {...process.env, SECURITY_HTTP_PORT: String(panelPort), SECURITY_WORKERS: '2', FILEMANAGER_UPLOAD_PORT: '0',
            FILEMANAGER_ORIGIN: base, FILEMANAGER_DATA_DIR: directory, FILEMANAGER_TOKENS_FILE: path.join(directory, 'tokens.json'),
            FILEMANAGER_CONFIG_FILE: path.join(directory, 'config.json'),
            TUNNEL_FIXTURE_CERT: secure ? path.join(directory, 'cert.pem') : '', TUNNEL_FIXTURE_KEY: secure ? path.join(directory, 'key.pem') : ''},
        stdio: ['ignore', 'pipe', 'pipe']
    });
    panel.stdout.on('data', chunk => {output += chunk;}); panel.stderr.on('data', chunk => {output += chunk;});
    return panel;
    };
    let server = startPanel();
    const handler = (req, res) => {
        if (req.url === '/events') {
            res.writeHead(200, {'Content-Type': 'text/event-stream'}); res.write('data: first\n\n');
            const timer = setTimeout(() => res.write('data: second\n\n'), 100);
            req.on('close', () => clearTimeout(timer)); return;
        }
        const chunks = []; req.on('data', chunk => chunks.push(chunk));
        req.on('end', () => {
            res.writeHead(201, {'Content-Type': 'application/octet-stream', 'X-Remote-Path': req.url, 'Set-Cookie': 'remote=fixture; HttpOnly'});
            res.end(chunks.length ? Buffer.concat(chunks) : Buffer.from('REMOTE_SERVICE_CONTENT'));
        });
    };
    const origin = secure ? https.createServer({key: fs.readFileSync(path.join(directory, 'key.pem')), cert: fs.readFileSync(path.join(directory, 'cert.pem'))}, handler) : http.createServer(handler);
    const wsOrigin = new WebSocketServer({server: origin});
    wsOrigin.on('connection', socket => socket.on('message', data => socket.send(data)));
    origin.listen(0, '127.0.0.1'); await once(origin, 'listening');
    const api = async (values = null, expected = 200) => {
        const response = await request(base + '/fileManagerSettings' + (values ? '' : '?settingGroup=tunnels'), {
            method: values ? 'POST' : 'GET', headers: {Authorization: 'Bearer fixtureAdmin', ...(values ? {'Content-Type': 'application/x-www-form-urlencoded'} : {})}
        }, values ? new URLSearchParams({settingGroup: 'tunnels', panelUrl: base, ...values}).toString() : undefined);
        const data = JSON.parse(response.body); assert.equal(response.status, expected, JSON.stringify(data)); return data;
    };
    const state = async () => (await api()).tunnels[0];
    const launch = async command => {
        const child = spawn('/bin/bash', ['-o', 'pipefail', '-c', command], {env: {...process.env, PATH: bin, TMPDIR: directory}, stdio: ['ignore', 'pipe', 'pipe']});
        child.stdout.on('data', chunk => {agentOutput += chunk;}); child.stderr.on('data', chunk => {agentOutput += chunk;});
        const [code] = await once(child, 'close'); assert.equal(code, 0, agentOutput);
        logPath = agentOutput.match(/^Log: (.+)$/m)?.[1]; assert.ok(logPath, agentOutput);
    };
    async function deniedSocket(secret, session) {
        const socket = new WebSocket(base.replace(/^http/, 'ws') + '/fileManagerTunnelDirect', {rejectUnauthorized: false,
            headers: {Authorization: `Bearer ${secret}`, 'X-Tunnel-Session': session}});
        const [code] = await once(socket, 'close'); assert.equal(code, 1008);
    }
    try {
        await until(() => {assert.equal(server.exitCode, null, output); return output.includes('SECURITY_FIXTURE_READY');});
        const fields = {action: 'create', mode: 'direct', remotePort: String(publicPort), localHost: '127.0.0.1',
            protocol: secure ? 'https' : 'http', localPort: String(origin.address().port)};
        const created = await api(fields);
        assert.ok(!created.command.includes('fixtureAdmin'));
        await api(fields, 422); // A second definition cannot reserve the same port.
        await deniedSocket('fixtureAdmin', 'b'.repeat(48));
        await deniedSocket('b'.repeat(64), 'c'.repeat(48));
        if (secure) {
            const wrong = created.command.replace(/sha256\/\/[A-Za-z0-9+/=]+/, 'sha256//' + Buffer.alloc(32).toString('base64'));
            const child = spawn('/bin/bash', ['-o', 'pipefail', '-c', wrong], {stdio: 'ignore'});
            const [code] = await once(child, 'close'); assert.equal(code, 90);
            assert.equal((await state()).status, 'waiting');
        }
        await launch(created.command);
        const online = await until(async () => { const entry = await state(); return entry.status === 'online' && entry; });
        const publicUrl = `${secure ? 'https' : 'http'}://127.0.0.1:${publicPort}`;
        assert.equal(online.mode, 'direct'); assert.equal(online.url, publicUrl);
        assert.deepEqual(fs.readdirSync(path.dirname(logPath)).filter(name => !name.startsWith('work.')).sort(), ['agent.log', 'agent.pid']);
        assert.match(fs.readFileSync(logPath, 'utf8'), /Baixando Node.js privado/);
        if (secure) assert.equal(fs.readFileSync(path.join(bin, 'node'), 'utf8'), oldNode, 'System Node must remain unchanged');
        else assert.equal(fs.existsSync(path.join(bin, 'node')), false, 'Do not install Node into PATH');
        assert.match(fs.readFileSync(logPath, 'utf8'), /Link do túnel direto/);
        assert.doesNotMatch(fs.readFileSync(logPath, 'utf8'), /cloudflare|TLS.*mudou/);
        const registry = JSON.parse(fs.readFileSync(path.join(directory, 'tunnels/registry.json')));
        await deniedSocket(created.command.match(/\/tunnel\/([a-f0-9]{64})/)[1], 'c'.repeat(48));
        assert.ok(registry[created.id].session);
        const response = await request(publicUrl + '/absolute/asset.js?x=1', {headers: {'X-Test': 'forward'}});
        assert.equal(response.status, 201); assert.equal(response.body.toString(), 'REMOTE_SERVICE_CONTENT');
        assert.equal(response.headers['x-remote-path'], '/absolute/asset.js?x=1');
        assert.deepEqual(response.headers['set-cookie'], ['remote=fixture; HttpOnly']);
        const binary = crypto.randomBytes(1024 * 1024);
        await Promise.all(Array.from({length: 4}, async (_, i) => {
            const result = await request(publicUrl + `/echo?stream=${i}`, {method: 'POST', headers: {'Content-Length': binary.length}}, binary);
            assert.deepEqual(result.body, binary);
        }));
        // A slow reader must apply backpressure without dropping packets or growing an unbounded queue.
        const large = crypto.randomBytes(8 * 1024 * 1024);
        await new Promise((resolve, reject) => {
            const client = secure ? https : http;
            const req = client.request(publicUrl + '/slow', {method: 'POST', rejectUnauthorized: false, headers: {'Content-Length': large.length}}, response => {
                const chunks = []; response.pause();
                const resume = setTimeout(() => response.resume(), 200);
                response.on('data', chunk => chunks.push(chunk));
                response.once('error', error => {clearTimeout(resume); reject(error);});
                response.once('end', () => {try {assert.deepEqual(Buffer.concat(chunks), large); resolve();} catch (error) {reject(error);}});
            });
            req.setTimeout(8000, () => req.destroy(new Error('Slow transfer timed out')));
            req.once('error', reject); req.end(large);
        });
        const socket = new WebSocket(publicUrl.replace(/^http/, 'ws') + '/socket', {rejectUnauthorized: false});
        await once(socket, 'open'); const message = once(socket, 'message'); socket.send(binary.subarray(0, 100000));
        assert.deepEqual((await message)[0], binary.subarray(0, 100000)); socket.close(); await once(socket, 'close');
        await new Promise((resolve, reject) => {
            const client = secure ? https : http;
            const stream = client.get(publicUrl + '/events', {rejectUnauthorized: false}, response => {
                let body = ''; response.on('data', chunk => {body += chunk; if (body.includes('data: second')) {clearTimeout(timer); stream.destroy(); resolve();}});
                response.on('error', () => {});
            });
            const timer = setTimeout(() => {stream.destroy(); reject(new Error('SSE did not stream'));}, 5000);
            stream.on('error', error => {clearTimeout(timer); reject(error);});
        });
        server.kill('SIGUSR1'); // Worker reload closes the transport. The same agent must restore the same address.
        await until(() => fs.readFileSync(logPath, 'utf8').includes('Reconectando'));
        await until(async () => { const entry = await state(); return entry.status === 'online' && entry.url === publicUrl; });
        assert.equal((await request(publicUrl + '/after-reload')).body.toString(), 'REMOTE_SERVICE_CONTENT');
        const launcherPid = Number(fs.readFileSync(path.join(path.dirname(logPath), 'agent.pid'), 'utf8'));
        const nodePid = () => fs.readFileSync(`/proc/${launcherPid}/task/${launcherPid}/children`, 'utf8').trim().split(/\s+/)
            .map(Number).find(pid => { try { return fs.readFileSync(`/proc/${pid}/cmdline`, 'utf8').includes('/agent.cjs'); } catch { return false; } });
        const killed = await until(nodePid);
        process.kill(killed, 'SIGKILL');
        await until(() => /tentativa 1\/3/.test(fs.readFileSync(logPath, 'utf8')));
        await until(() => {const next = nodePid(); return next && next !== killed;});
        await until(async () => {try { return (await request(publicUrl + '/after-kill')).body.toString() === 'REMOTE_SERVICE_CONTENT'; } catch {return false;} });
        if (!secure) {
            const session = JSON.parse(fs.readFileSync(path.join(directory, 'tunnels/registry.json')))[created.id].session;
            server.kill('SIGTERM'); await once(server, 'exit');
            // Outlast the 40s server heartbeat lease. Keep the same registry,
            // credential and agent; no bootstrap command is generated again.
            await delay(45000);
            assert.equal(nodePid() > 0, true);
            assert.match(fs.readFileSync(logPath, 'utf8'), /mantendo a mesma sessão/);
            const readyOffset = output.length;
            server = startPanel();
            await until(() => output.slice(readyOffset).includes('SECURITY_FIXTURE_READY'));
            await until(async () => {try { return (await request(publicUrl + '/after-server-restart')).body.toString() === 'REMOTE_SERVICE_CONTENT'; } catch {return false;} }, 40000);
            assert.equal(JSON.parse(fs.readFileSync(path.join(directory, 'tunnels/registry.json')))[created.id].session, session);
            assert.equal((await state()).url, publicUrl);
            assert.match(fs.readFileSync(logPath, 'utf8'), /restabelecida/);
        }
        await api({action: 'stop', id: created.id});
        await until(() => !fs.existsSync(path.join(path.dirname(logPath), 'agent.pid')));
        assert.deepEqual(fs.readdirSync(path.dirname(logPath)), ['agent.log'], 'Private runtime and credentials must be removed after shutdown');
        assert.equal((await state()).url, '');
        assert.match(fs.readFileSync(logPath, 'utf8'), /encerrado pelo painel/);
        await assert.rejects(request(publicUrl), /ECONNREFUSED/);
        const rotated = await api({action: 'command', id: created.id});
        agentOutput = ''; await launch(rotated.command);
        await until(async () => (await state()).status === 'online');
        const replacement = await api({action: 'command', id: created.id});
        await until(() => !fs.existsSync(path.join(path.dirname(logPath), 'agent.pid')));
        await assert.rejects(request(publicUrl), /ECONNREFUSED/);
        agentOutput = ''; await launch(replacement.command);
        await until(async () => (await state()).status === 'online');
        const tokens = JSON.parse(fs.readFileSync(path.join(directory, 'tokens.json')));
        tokens.fixtureAdmin.revoked = true; fs.writeFileSync(path.join(directory, 'tokens.json'), JSON.stringify(tokens));
        await until(() => !fs.existsSync(path.join(path.dirname(logPath), 'agent.pid')));
        await assert.rejects(request(publicUrl), /ECONNREFUSED/);
        tokens.fixtureAdmin.revoked = false; fs.writeFileSync(path.join(directory, 'tokens.json'), JSON.stringify(tokens));
        const finalCommand = await api({action: 'command', id: created.id});
        agentOutput = ''; await launch(finalCommand.command);
        await until(async () => (await state()).status === 'online');
        await api({action: 'delete', id: created.id});
        await until(() => !fs.existsSync(path.join(path.dirname(logPath), 'agent.pid')));
        assert.equal((await api()).tunnels.length, 0);
        await assert.rejects(request(publicUrl), /ECONNREFUSED/);
        assert.doesNotMatch(output, /Fatal error|Uncaught|Warning:/);
    } catch (error) {
        error.message += '\nServer: ' + output + '\nAgent: ' + (logPath && fs.existsSync(logPath) ? fs.readFileSync(logPath, 'utf8') : agentOutput);
        throw error;
    } finally {
        if (logPath && fs.existsSync(path.join(path.dirname(logPath), 'agent.pid'))) {
            const pid = Number(fs.readFileSync(path.join(path.dirname(logPath), 'agent.pid'), 'utf8'));
            try {process.kill(pid, 'SIGTERM');} catch (error) {if (error.code !== 'ESRCH') throw error;}
            await until(() => !fs.existsSync(path.join(path.dirname(logPath), 'agent.pid')));
        }
        server.kill('SIGTERM'); await Promise.race([once(server, 'exit').catch(() => {}), delay(2000)]);
        if (server.exitCode === null) server.kill('SIGKILL');
        for (const socket of wsOrigin.clients) socket.terminate(); wsOrigin.close();
        origin.closeAllConnections(); await new Promise(resolve => origin.close(resolve));
        fs.rmSync(directory, {recursive: true, force: true});
    }
});
