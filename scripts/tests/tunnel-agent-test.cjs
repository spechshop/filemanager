'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const crypto = require('node:crypto');
const http = require('node:http');
const {once} = require('node:events');
const {spawn, execFileSync} = require('node:child_process');
const ROOT = path.resolve(__dirname, '../..');
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
async function until(fn, timeout = 10000) {
    const deadline = Date.now() + timeout;
    while (Date.now() < deadline) { const result = await fn(); if (result) return result; await delay(50); }
    throw new Error('Timed out waiting for Bash agent');
}
function fixture({tampered = false, panelFails = false, panelTampered = false, downloadServer = ''} = {}) {
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'fm-bash-agent-'));
    const bin = path.join(directory, 'bin'); fs.mkdirSync(bin);
    for (const name of ['bash', 'nohup', 'mktemp', 'awk', 'grep', 'sleep', 'rm', 'cat', 'uname', 'chmod', 'mv', 'sha256sum', 'tar', 'head', 'cp']) {
        fs.symlinkSync(execFileSync('which', [name], {encoding: 'utf8'}).trim(), path.join(bin, name));
    }
    for (const name of ['python', 'python3', 'node', 'jq']) fs.symlinkSync('/bin/false', path.join(bin, name));
    const executable = `#!/bin/bash
printf '%s' "$$" > "$TUNNEL_FIXTURE_DIR/child.pid"
printf '%s\\n' "$@" > "$TUNNEL_FIXTURE_DIR/child.args"
printf '%s\\n' 'https://downloaded-agent.trycloudflare.com' 'Registered tunnel connection connIndex=0'
while true; do sleep 0.1; done
`;
    fs.writeFileSync(path.join(directory, 'payload'), tampered ? executable + '# tampered\n' : executable);
    fs.writeFileSync(path.join(directory, 'panel-payload'), tampered || panelTampered ? executable + '# tampered\n' : executable);
    const nativeCurl = execFileSync('which', ['curl'], {encoding: 'utf8'}).trim();
    fs.writeFileSync(path.join(bin, 'curl'), `#!/bin/bash
args=("$@")
output='' report='' download='' url=''
while [ "$#" -gt 0 ]; do
    case "$1" in
        --output) output=$2; shift;;
        --data-binary) report=\${2#@}; shift;;
        https://panel.fixture/fileManagerTunnelAgent\?asset=*) download=panel; url=$1;;
        https://github.com/cloudflare/cloudflared/releases/download/*) download=github; url=$1;;
    esac
    shift
done
if [ -n "$download" ]; then
    printf '%s\\n' "\${args[@]}" > "$TUNNEL_FIXTURE_DIR/$download.args"
    printf '%s' "$url" > "$TUNNEL_FIXTURE_DIR/$download.url"
    if [ "$download" = panel ]; then
        ${panelFails ? "printf 'curl: (22) The requested URL returned error: 504\\n' >&2; exit 22" : ':'}
        if [ -n '${downloadServer}' ]; then
            unset 'args[\${#args[@]}-1]'
            exec '${nativeCurl}' "\${args[@]}" '${downloadServer}'
        fi
        cp "$TUNNEL_FIXTURE_DIR/panel-payload" "$output"; exit
    fi
    cp "$TUNNEL_FIXTURE_DIR/payload" "$output"; exit
fi
cat "$report" >> "$TUNNEL_FIXTURE_DIR/reports"
printf '\\n' >> "$TUNNEL_FIXTURE_DIR/reports"
if [ -f "$TUNNEL_FIXTURE_DIR/stop" ]; then
    printf '{"success":false}' > "$output"; printf 403
else
    printf '{"success":true}' > "$output"; printf 200
fi
`, {mode: 0o700});
    const header = ["FM_ENDPOINT='https://panel.fixture/fileManagerTunnelAgent'", `FM_SECRET='${'a'.repeat(64)}'`, `FM_SESSION='${'b'.repeat(48)}'`,
        "FM_TARGET='http://127.0.0.1:8080'", "FM_PANEL_PIN=''", 'FM_NO_TLS_VERIFY=false'].join('\n');
    const platform = execFileSync('uname', ['-s'], {encoding: 'utf8'}).trim() + ':' + execFileSync('uname', ['-m'], {encoding: 'utf8'}).trim();
    const cases = `${platform}) FM_ASSET='cloudflared-linux-amd64'; FM_SHA256='${crypto.createHash('sha256').update(executable).digest('hex')}';;`;
    const source = fs.readFileSync(path.join(ROOT, 'scripts/tunnel-agent.sh'), 'utf8').replace('# FILEMANAGER_CONFIG', header)
        .replace('# FILEMANAGER_PLATFORMS', cases).replaceAll('FILEMANAGER_CLOUDFLARED_VERSION', 'fixture');
    const script = path.join(directory, 'agent.sh'); fs.writeFileSync(script, source);
    const child = spawn('/bin/bash', [script], {env: {...process.env, PATH: bin, TMPDIR: directory, TUNNEL_FIXTURE_DIR: directory}, detached: true, stdio: ['ignore', 'pipe', 'pipe']});
    let output = '', closed = false;
    child.on('close', () => {closed = true;});
    child.stdout.on('data', chunk => {output += chunk;}); child.stderr.on('data', chunk => {output += chunk;});
    const logPath = () => output.match(/^Log: (.+)$/m)?.[1];
    return {directory, executable, child, output: () => output, closed: () => closed, logPath,
        log: () => output + (logPath() && fs.existsSync(logPath()) ? fs.readFileSync(logPath(), 'utf8') : ''),
        stopped: () => logPath() && fs.readdirSync(path.dirname(logPath())).every(name => name === 'agent.log'),
        stop: async () => {
            const pidFile = logPath() && path.join(path.dirname(logPath()), 'agent.pid');
            if (pidFile && fs.existsSync(pidFile)) {
                try {process.kill(Number(fs.readFileSync(pidFile, 'utf8')), 'SIGTERM');} catch (error) {if (error.code !== 'ESRCH') throw error;}
                await until(() => !fs.existsSync(pidFile));
            }
            if (child.exitCode === null) child.kill('SIGTERM');
        },
        reports: () => fs.existsSync(path.join(directory, 'reports')) ? fs.readFileSync(path.join(directory, 'reports'), 'utf8').trim().split('\n').filter(Boolean).map(JSON.parse) : []};
}
test('Bash releases the terminal, survives session hangup, downloads and verifies the agent and stops from the panel', {timeout: 20000}, async () => {
    let downloadRequests = 0, f;
    const downloads = http.createServer((request, response) => {
        downloadRequests++;
        assert.equal(request.headers.authorization, 'Bearer ' + 'a'.repeat(64));
        if (downloadRequests === 1) {response.writeHead(504); response.end('upstream timeout');}
        else response.end(f.executable);
    });
    downloads.listen(0, '127.0.0.1'); await once(downloads, 'listening');
    f = fixture({downloadServer: `http://127.0.0.1:${downloads.address().port}/asset`});
    try {
        await until(f.closed, 3000);
        assert.equal(f.child.exitCode, 0, f.log());
        assert.match(f.output(), /iniciado em segundo plano/);
        await until(() => f.reports().some(row => row.status === 'online'));
        const reports = f.reports().length;
        process.kill(-f.child.pid, 'SIGHUP');
        await until(() => f.reports().length > reports);
        assert.equal(f.reports().at(-1).status, 'online', f.log());
        const args = fs.readFileSync(path.join(f.directory, 'child.args'), 'utf8').trim().split('\n');
        assert.ok(args.includes('http://127.0.0.1:8080'));
        assert.equal(downloadRequests, 2, 'Native curl must retry a 504 response');
        assert.equal(fs.existsSync(path.join(f.directory, 'github.args')), false, 'GitHub must not be required by the remote machine');
        assert.equal(fs.readFileSync(path.join(f.directory, 'panel.url'), 'utf8'), `https://panel.fixture/fileManagerTunnelAgent?asset=cloudflared-linux-amd64&session=${'b'.repeat(48)}`);
        const pid = Number(fs.readFileSync(path.join(f.directory, 'child.pid'), 'utf8'));
        assert.equal(f.reports().find(row => row.status === 'online').url, 'https://downloaded-agent.trycloudflare.com');
        fs.writeFileSync(path.join(f.directory, 'stop'), 'stop');
        await until(f.stopped);
        assert.match(f.log(), /encerrado pelo painel/);
        assert.throws(() => process.kill(pid, 0), {code: 'ESRCH'});
        assert.deepEqual(fs.readdirSync(path.dirname(f.logPath())), ['agent.log']);
        assert.equal(fs.statSync(f.logPath()).mode & 0o777, 0o600);
    } finally {
        await f.stop(); fs.rmSync(f.directory, {recursive: true, force: true});
        await new Promise(resolve => downloads.close(resolve));
    }
});
for (const options of [{panelFails: true}, {panelTampered: true}]) test(`Bash falls back to GitHub when the panel download is ${options.panelFails ? 'unavailable' : 'corrupted'}`, {timeout: 15000}, async () => {
    const f = fixture(options);
    try {
        await until(() => f.reports().some(row => row.status === 'online'));
        assert.equal(fs.readFileSync(path.join(f.directory, 'github.url'), 'utf8'), 'https://github.com/cloudflare/cloudflared/releases/download/fixture/cloudflared-linux-amd64');
        const args = fs.readFileSync(path.join(f.directory, 'github.args'), 'utf8');
        assert.ok(!args.includes('--config') && !args.includes('Authorization') && !args.includes('a'.repeat(64)), 'Panel credential must never go to GitHub');
        assert.match(args, /--retry\n2\n/);
        assert.match(f.log(), /Tentando o GitHub/);
        if (options.panelFails) assert.match(f.log(), /curl: \(22\).*504/, 'A final download failure must remain visible');
    } finally { await f.stop(); fs.rmSync(f.directory, {recursive: true, force: true}); }
});
test('Bash refuses a modified download before executing it and reports the error', {timeout: 15000}, async () => {
    const f = fixture({tampered: true});
    try {
        await until(f.closed, 3000);
        assert.equal(f.child.exitCode, 0, f.log());
        await until(() => f.reports().at(-1)?.status === 'error');
        await until(f.stopped);
        assert.match(f.log(), /SHA256.*falhou/);
        assert.equal(fs.existsSync(path.join(f.directory, 'child.pid')), false);
        assert.equal(f.reports().at(-1).status, 'error');
        assert.deepEqual(fs.readdirSync(path.dirname(f.logPath())), ['agent.log']);
    } finally { await f.stop(); fs.rmSync(f.directory, {recursive: true, force: true}); }
});
