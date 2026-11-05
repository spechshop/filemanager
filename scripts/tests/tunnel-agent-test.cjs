'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const crypto = require('node:crypto');
const {spawn, execFileSync} = require('node:child_process');
const ROOT = path.resolve(__dirname, '../..');
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
async function until(fn, timeout = 10000) {
    const deadline = Date.now() + timeout;
    while (Date.now() < deadline) { const result = await fn(); if (result) return result; await delay(50); }
    throw new Error('Timed out waiting for Bash agent');
}
function fixture(tampered = false) {
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'fm-bash-agent-'));
    const bin = path.join(directory, 'bin'); fs.mkdirSync(bin);
    for (const name of ['bash', 'mktemp', 'awk', 'grep', 'sleep', 'rm', 'cat', 'uname', 'chmod', 'mv', 'sha256sum', 'tar', 'head', 'cp']) {
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
    fs.writeFileSync(path.join(bin, 'curl'), `#!/bin/bash
output='' report='' download=false
while [ "$#" -gt 0 ]; do
    case "$1" in
        --output) output=$2; shift;;
        --data-binary) report=\${2#@}; shift;;
        https://github.com/cloudflare/cloudflared/releases/download/*) download=true; printf '%s' "$1" > "$TUNNEL_FIXTURE_DIR/download-url";;
    esac
    shift
done
if [ "$download" = true ]; then cp "$TUNNEL_FIXTURE_DIR/payload" "$output"; exit; fi
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
    const child = spawn('/bin/bash', [script], {env: {...process.env, PATH: bin, TMPDIR: directory, TUNNEL_FIXTURE_DIR: directory}, stdio: ['ignore', 'pipe', 'pipe']});
    let output = '';
    child.stdout.on('data', chunk => {output += chunk;}); child.stderr.on('data', chunk => {output += chunk;});
    return {directory, child, output: () => output,
        reports: () => fs.existsSync(path.join(directory, 'reports')) ? fs.readFileSync(path.join(directory, 'reports'), 'utf8').trim().split('\n').filter(Boolean).map(JSON.parse) : []};
}
test('Bash downloads and verifies an executable, reports the remote service and stops without Python, jq or sudo', {timeout: 20000}, async () => {
    const f = fixture();
    try {
        await until(() => {assert.equal(f.child.exitCode, null, f.output()); return f.reports().some(row => row.status === 'online');});
        const args = fs.readFileSync(path.join(f.directory, 'child.args'), 'utf8').trim().split('\n');
        assert.ok(args.includes('http://127.0.0.1:8080'));
        assert.equal(fs.readFileSync(path.join(f.directory, 'download-url'), 'utf8'), 'https://github.com/cloudflare/cloudflared/releases/download/fixture/cloudflared-linux-amd64');
        const pid = Number(fs.readFileSync(path.join(f.directory, 'child.pid'), 'utf8'));
        assert.equal(f.reports().find(row => row.status === 'online').url, 'https://downloaded-agent.trycloudflare.com');
        fs.writeFileSync(path.join(f.directory, 'stop'), 'stop');
        await until(() => f.child.exitCode !== null);
        assert.match(f.output(), /encerrado pelo painel/);
        assert.throws(() => process.kill(pid, 0), {code: 'ESRCH'});
        assert.equal(fs.readdirSync(f.directory).filter(name => name.startsWith('filemanager-tunnel.')).length, 0);
    } finally { if (f.child.exitCode === null) {f.child.kill('SIGTERM'); await delay(1500);} fs.rmSync(f.directory, {recursive: true, force: true}); }
});
test('Bash refuses a modified download before executing it and reports the error', {timeout: 15000}, async () => {
    const f = fixture(true);
    try {
        await until(() => f.child.exitCode !== null);
        assert.notEqual(f.child.exitCode, 0);
        assert.match(f.output(), /SHA256.*falhou/);
        assert.equal(fs.existsSync(path.join(f.directory, 'child.pid')), false);
        assert.equal(f.reports().at(-1).status, 'error');
        assert.equal(fs.readdirSync(f.directory).filter(name => name.startsWith('filemanager-tunnel.')).length, 0);
    } finally { if (f.child.exitCode === null) {f.child.kill('SIGTERM'); await delay(1500);} fs.rmSync(f.directory, {recursive: true, force: true}); }
});
