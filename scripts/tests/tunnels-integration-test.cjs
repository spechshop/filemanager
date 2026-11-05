'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const net = require('node:net');
const http = require('node:http');
const https = require('node:https');
const {spawn, spawnSync, execFileSync} = require('node:child_process');
const {once} = require('node:events');
const ROOT = path.resolve(__dirname, '../..');
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
async function until(fn, timeout = 10000) {
    const deadline = Date.now() + timeout;
    while (Date.now() < deadline) { const value = await fn(); if (value) return value; await delay(50); }
    throw new Error('Timed out waiting for tunnel state');
}
for (const secure of [false, true]) test(`curl | bash with ${secure ? 'self-signed HTTPS' : 'HTTP'} panel works without Python or jq and transmits the remote service`, {timeout: 30000}, async () => {
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'fm-tunnel-integration-'));
    const bin = path.join(directory, 'bin'); fs.mkdirSync(bin); fs.mkdirSync(path.join(directory, 'files'));
    fs.writeFileSync(path.join(directory, 'tokens.json'), JSON.stringify({fixtureAdmin: {expire: Math.floor(Date.now()/1000) + 3600, admin: true}}));
    const config = {};
    if (secure) {
        execFileSync('openssl', ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1', '-subj', '/CN=fixture.invalid',
            '-keyout', path.join(directory, 'key.pem'), '-out', path.join(directory, 'cert.pem')], {stdio: 'ignore'});
        config.serverSettings = {ssl_cert_file: path.join(directory, 'cert.pem'), ssl_key_file: path.join(directory, 'key.pem')};
    }
    fs.writeFileSync(path.join(directory, 'config.json'), JSON.stringify(config));
    for (const name of ['bash', 'curl', 'mktemp', 'awk', 'grep', 'sleep', 'rm', 'cat', 'uname', 'chmod', 'mv', 'sha256sum', 'tar', 'head']) {
        fs.symlinkSync(execFileSync('which', [name], {encoding: 'utf8'}).trim(), path.join(bin, name));
    }
    for (const name of ['python', 'python3', 'node', 'jq']) fs.symlinkSync('/bin/false', path.join(bin, name));
    const fake = path.join(bin, 'cloudflared');
    fs.writeFileSync(fake, `#!/bin/bash
printf '%s' "$$" > "$TUNNEL_FIXTURE_DIR/child.pid"
printf '%s\\n' "$@" > "$TUNNEL_FIXTURE_DIR/child.args"
target='' tls=()
while [ "$#" -gt 0 ]; do
    case "$1" in --url) target=$2; shift;; --no-tls-verify) tls=(--insecure);; esac
    shift
done
curl -fsS "\${tls[@]}" "$target" > "$TUNNEL_FIXTURE_DIR/origin-body" || exit 1
printf '%s\\n' 'https://fixture-public-link.trycloudflare.com'
while [ ! -f "$TUNNEL_FIXTURE_DIR/register" ]; do sleep 0.05; done
printf '%s\\n' 'Registered tunnel connection connIndex=0'
while true; do sleep 0.1; done
`, {mode: 0o700});
    const reservation = net.createServer(); reservation.listen(0, '127.0.0.1'); await once(reservation, 'listening');
    const port = reservation.address().port; await new Promise(resolve => reservation.close(resolve));
    const base = `${secure ? 'https' : 'http'}://127.0.0.1:${port}`;
    const handler = (request, response) => response.end('CONTENT_FROM_THE_MACHINE_RUNNING_BASH');
    const origin = secure ? https.createServer({key: fs.readFileSync(path.join(directory, 'key.pem')), cert: fs.readFileSync(path.join(directory, 'cert.pem'))}, handler) : http.createServer(handler);
    origin.listen(0, '127.0.0.1'); await once(origin, 'listening');
    const target = `${secure ? 'https' : 'http'}://127.0.0.1:${origin.address().port}`;
    let output = '', agentOutput = '', agent;
    const server = spawn(process.env.SECURITY_PHP_BINARY || 'php', [path.join(ROOT, 'scripts/security/fixtures/swoole-server.php')], {
        cwd: directory, env: {...process.env, SECURITY_HTTP_PORT: String(port), FILEMANAGER_UPLOAD_PORT: '0', FILEMANAGER_ORIGIN: base,
            FILEMANAGER_DATA_DIR: directory, FILEMANAGER_TOKENS_FILE: path.join(directory, 'tokens.json'), FILEMANAGER_CONFIG_FILE: path.join(directory, 'config.json'),
            TUNNEL_FIXTURE_CERT: secure ? path.join(directory, 'cert.pem') : '', TUNNEL_FIXTURE_KEY: secure ? path.join(directory, 'key.pem') : ''}, stdio: ['ignore', 'pipe', 'pipe']
    });
    server.stdout.on('data', chunk => {output += chunk;}); server.stderr.on('data', chunk => {output += chunk;});
    const fetchFixture = (url, options = {}) => new Promise((resolve, reject) => {
        const client = (secure ? https : http).request(url, {...options, rejectUnauthorized: false}, response => {
            let body = ''; response.on('data', chunk => {body += chunk;});
            response.on('end', () => resolve({status: response.statusCode, json: async () => JSON.parse(body)}));
        });
        client.on('error', reject); client.end(options.body?.toString());
    });
    const request = async (values = null) => {
        const response = await fetchFixture(base + '/fileManagerSettings' + (values ? '' : '?settingGroup=tunnels'), {
            method: values ? 'POST' : 'GET', headers: {Authorization: 'Bearer fixtureAdmin', ...(values ? {'Content-Type': 'application/x-www-form-urlencoded'} : {})},
            ...(values ? {body: new URLSearchParams({settingGroup: 'tunnels', panelUrl: base, ...values})} : {})
        });
        const data = await response.json(); assert.equal(response.status, 200, JSON.stringify(data)); return data;
    };
    const state = async () => (await request()).tunnels[0];
    try {
        await until(() => {assert.equal(server.exitCode, null, output); return output.includes('SECURITY_FIXTURE_READY');});
        const anonymous = await fetchFixture(base + '/fileManagerTunnelAgent'); assert.equal(anonymous.status, 401);
        const created = await request({action: 'create', name: 'Service on the machine running Bash', localHost: `${secure ? 'https' : 'http'}://127.0.0.1`, localPort: String(origin.address().port), noTlsVerify: secure ? 'true' : 'false'});
        assert.ok(!created.command.includes('\n') && !created.command.includes('fixtureAdmin') && !created.command.includes('python'));
        assert.ok(created.command.includes(base + '/tunnel/') && created.command.endsWith(' | bash'));
        if (secure) {
            assert.ok(created.command.includes('--pinnedpubkey'));
            const url = created.command.match(/https:\/\/[^']+\/tunnel\/[a-f0-9]{64}/)[0];
            const wrong = spawnSync('curl', ['-fsS', '--max-time', '3', '--insecure', '--pinnedpubkey', 'sha256//' + Buffer.alloc(32).toString('base64'), url]);
            assert.equal(wrong.status, 90, String(wrong.stderr));
            assert.equal((await state()).status, 'waiting', 'Invalid TLS pin must never download the agent');
        }
        agent = spawn('/bin/bash', ['-o', 'pipefail', '-c', created.command], {env: {...process.env, PATH: bin, TMPDIR: directory, TUNNEL_FIXTURE_DIR: directory}, stdio: ['ignore', 'pipe', 'pipe']});
        agent.stdout.on('data', chunk => {agentOutput += chunk;}); agent.stderr.on('data', chunk => {agentOutput += chunk;});
        await until(() => {assert.equal(agent.exitCode, null, agentOutput); return fs.existsSync(path.join(directory, 'child.pid'));});
        const starting = await state(); assert.equal(starting.status, 'starting'); assert.equal(starting.url, '');
        const child = {pid: Number(fs.readFileSync(path.join(directory, 'child.pid'), 'utf8')), args: fs.readFileSync(path.join(directory, 'child.args'), 'utf8').trim().split('\n')};
        assert.ok(child.args.includes(target)); assert.equal(child.args.includes('--no-tls-verify'), secure);
        fs.writeFileSync(path.join(directory, 'register'), 'ready');
        const online = await until(async () => {const row = await state(); return row.status === 'online' && row;});
        assert.equal(fs.readFileSync(path.join(directory, 'origin-body'), 'utf8'), 'CONTENT_FROM_THE_MACHINE_RUNNING_BASH');
        assert.equal(online.url, 'https://fixture-public-link.trycloudflare.com');
        assert.ok(!JSON.stringify(online).includes('secret'));
        await request({action: 'stop', id: created.id});
        await until(() => agent.exitCode !== null, 15000);
        assert.match(agentOutput, /encerrado pelo painel/);
        assert.throws(() => process.kill(child.pid, 0), {code: 'ESRCH'}, 'cloudflared child must be reaped');
        assert.equal(fs.readdirSync(directory).filter(name => name.startsWith('filemanager-tunnel.')).length, 0, 'Private temporary files left behind');
        assert.equal((await state()).url, '');
        await request({action: 'delete', id: created.id}); assert.equal((await request()).tunnels.length, 0);
    } finally {
        if (agent?.exitCode === null) agent.kill('SIGTERM');
        server.kill('SIGTERM');
        await Promise.race([once(server, 'exit').catch(() => {}), delay(2000)]);
        if (server.exitCode === null) server.kill('SIGKILL');
        await new Promise(resolve => origin.close(resolve));
        fs.rmSync(directory, {recursive: true, force: true});
    }
});
