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
    throw new Error('Timed out waiting for direct bootstrap');
}
function fixture({installed = false, panelFails = false, tampered = false, incompatible = false, blocked = false, hashTool = 'sha256sum', crashes = 0, oomEvidence = false, offline = false, pinRejected = false} = {}) {
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'fm-direct-bootstrap-'));
    const bin = path.join(directory, 'bin'); fs.mkdirSync(bin);
    if (offline) fs.writeFileSync(path.join(directory, 'offline'), 'offline');
    fs.writeFileSync(path.join(directory, 'memory.events'), 'oom_kill 17\n');
    for (const name of new Set(['bash', 'mktemp', 'grep', 'sleep', 'rm', 'cat', 'uname', 'chmod', hashTool, 'tar', 'gzip', 'cp', 'id', 'df', 'awk', 'cut', 'rmdir', 'true', 'cksum'])) {
        fs.symlinkSync(execFileSync('which', [name], {encoding: 'utf8'}).trim(), path.join(bin, name));
    }
    if (installed) fs.symlinkSync(process.execPath, path.join(bin, 'node'));
    const platform = execFileSync('uname', ['-s'], {encoding: 'utf8'}).trim() + ':' + execFileSync('uname', ['-m'], {encoding: 'utf8'}).trim();
    let cases = '';
    for (const compatibility of [false, true]) {
        const name = `node-v22.0.0-linux-x64${compatibility ? '-glibc-217' : ''}`;
        const packageBin = path.join(directory, name, 'bin'); fs.mkdirSync(packageBin, {recursive: true});
        const runtime = incompatible && !compatibility ? '#!/bin/bash\nexit 1\n'
            : `#!/bin/bash\nexec '${process.execPath.replaceAll("'", "'\\''")}' "$@"\n`;
        fs.writeFileSync(path.join(packageBin, 'node'), runtime, {mode: 0o700});
        const archive = path.join(directory, name + '.tar.gz');
        execFileSync('tar', ['-czf', archive, '-C', directory, name]);
        const digest = crypto.createHash('sha256').update(fs.readFileSync(archive)).digest('hex');
        if (tampered) fs.appendFileSync(archive, 'tampered');
        cases += `${platform}${compatibility ? ':compat' : ''}) FM_ASSET='${name}.tar.gz'; FM_SHA256='${digest}'; FM_NODE_URL='https://nodejs.org/dist/v22.0.0/${name}.tar.gz';;\n`;
    }
    fs.writeFileSync(path.join(bin, 'curl'), `#!/bin/bash
args=("$@")
output='' report='' url='' asset='' source=''
while [ "$#" -gt 0 ]; do
    case "$1" in
        --output) output=$2; shift;;
        --data-binary) report=\${2#@}; shift;;
        https://panel.fixture/fileManagerTunnelAgent*) url=$1;;
        https://nodejs.org/*) url=$1; source=origin;;
    esac
    shift
done
if [ -n "$report" ]; then
    cat "$report" >> "$TUNNEL_FIXTURE_DIR/reports"; printf '\\n' >> "$TUNNEL_FIXTURE_DIR/reports"
    ${pinRejected ? 'exit 90' : ':'}
    if [ -f "$TUNNEL_FIXTURE_DIR/stop" ]; then printf '{"success":false}' > "$output"; printf 403
    elif [ -f "$TUNNEL_FIXTURE_DIR/offline" ]; then printf '{"success":false}' > "$output"; printf 503
    else printf '{"success":true}' > "$output"; printf 200; fi
    exit 0
fi
if [ "$source" = origin ]; then
    printf '%s\\n' "\${args[@]}" >> "$TUNNEL_FIXTURE_DIR/origin.args"
    asset=\${url##*/}
else
    printf '%s\\n' "\${args[@]}" >> "$TUNNEL_FIXTURE_DIR/panel.args"
    ${panelFails ? 'exit 22' : ':'}
    asset=\${url#*asset=}; asset=\${asset%%&*}
fi
${blocked ? 'while [ ! -f "$TUNNEL_FIXTURE_DIR/release" ]; do sleep 0.1; done' : ':'}
cp "$TUNNEL_FIXTURE_DIR/$asset" "$output"
`, {mode: 0o700});
    const payload = `const fs = require('node:fs'); fs.unlinkSync(__filename);
fs.writeFileSync(process.env.TUNNEL_FIXTURE_DIR + '/started', String(process.pid));
const runsFile = process.env.TUNNEL_FIXTURE_DIR + '/runs';
const runs = fs.existsSync(runsFile) ? fs.readFileSync(runsFile, 'utf8').trim().split('\\n').length + 1 : 1;
fs.appendFileSync(runsFile, process.pid + ':' + Date.now() + '\\n');
if (runs <= ${crashes}) {
    if (${oomEvidence}) fs.writeFileSync(process.env.TUNNEL_FIXTURE_DIR + '/memory.events', 'oom_kill ' + (17 + runs) + '\\n');
    process.kill(process.pid, 'SIGKILL');
}
process.on('SIGTERM', () => process.exit(0)); setInterval(() => {}, 1000);
`;
    const header = ["FM_ENDPOINT='https://panel.fixture/fileManagerTunnelAgent'", `FM_SECRET='${'a'.repeat(64)}'`, `FM_SESSION='${'b'.repeat(48)}'`, "FM_PANEL_PIN=''"].join('\n');
    const source = fs.readFileSync(path.join(ROOT, 'scripts/tunnel-direct-agent.sh'), 'utf8')
        .replace('# FILEMANAGER_EXEC_HELPER', () => fs.readFileSync(path.join(ROOT, 'scripts/runtime-exec.sh'), 'utf8').split('if [ "${BASH_SOURCE[0]}" = "$0" ]; then')[0]).replace('# FILEMANAGER_CONFIG', header).replace('# FILEMANAGER_NODE_PLATFORMS', cases)
        .replace('FILEMANAGER_AGENT_BASE64', Buffer.from(payload).toString('base64'));
    // Controlled counters exercise diagnosis; no real OOM is induced.
    const testedSource = source.replace('memory_file=$(fm_memory_file)', 'memory_file="$TUNNEL_FIXTURE_DIR/memory.events"');
    const script = path.join(directory, 'bootstrap.sh'); fs.writeFileSync(script, testedSource);
    const child = spawn('/bin/bash', [script], {env: {...process.env, PATH: bin, TMPDIR: directory, TUNNEL_FIXTURE_DIR: directory}, stdio: ['ignore', 'pipe', 'pipe']});
    let output = '', closed = false;
    child.stdout.on('data', chunk => {output += chunk;}); child.stderr.on('data', chunk => {output += chunk;});
    child.once('close', () => {closed = true;});
    const logPath = () => output.match(/^Log: (.+)$/m)?.[1];
    const reports = () => fs.existsSync(path.join(directory, 'reports')) ? fs.readFileSync(path.join(directory, 'reports'), 'utf8').trim().split('\n').filter(Boolean).map(JSON.parse) : [];
    return {directory, child, closed: () => closed, logPath,
        log: () => output + (logPath() && fs.existsSync(logPath()) ? fs.readFileSync(logPath(), 'utf8') : ''),
        reports,
        started: () => fs.existsSync(path.join(directory, 'started')),
        runs: () => fs.existsSync(path.join(directory, 'runs')) ? fs.readFileSync(path.join(directory, 'runs'), 'utf8').trim().split('\n') : [],
        stopped: () => logPath() && reports().some(r => ['offline', 'error'].includes(r.status))
            && fs.readdirSync(path.dirname(logPath())).every(name => name === 'agent.log'),
        cleanup: async (remove = true) => {
            const pidFile = logPath() && path.join(path.dirname(logPath()), 'agent.pid');
            if (pidFile && fs.existsSync(pidFile)) {
                try {process.kill(Number(fs.readFileSync(pidFile, 'utf8')), 'SIGTERM');} catch (error) {if (error.code !== 'ESRCH') throw error;}
                await until(() => !fs.existsSync(pidFile));
            }
            if (child.exitCode === null) child.kill('SIGTERM');
            if (remove) fs.rmSync(directory, {recursive: true, force: true});
        }};
}
for (const oomEvidence of [false, true]) test(`SIGKILL restarts the self-deleting payload, OOM evidence=${oomEvidence}`, {timeout: 20000}, async () => {
    const f = fixture({installed: true, crashes: 1, oomEvidence});
    try {
        await until(() => f.runs().length === 2);
        assert.match(f.log(), /status 137/);
        assert.match(f.log(), /tentativa 1\/3/);
        if (oomEvidence) assert.match(f.log(), /oom_kill.*17 para 18/);
        else { assert.match(f.log(), /origem não identificada/); assert.doesNotMatch(f.log(), /Evidência:/); }
        assert.doesNotMatch(f.log(), /Cannot find module/);
        assert.ok(Number(f.runs()[1].split(':')[1]) - Number(f.runs()[0].split(':')[1]) >= 1800);
    } finally { await f.cleanup(); }
});
test('repeated SIGKILL stops after three restarts and reports error', {timeout: 30000}, async () => {
    const f = fixture({installed: true, crashes: 10});
    try {
        await until(f.stopped, 25000);
        assert.equal(f.runs().length, 4);
        assert.match(f.log(), /em 2s.*1\/3/);
        assert.match(f.log(), /em 4s.*2\/3/);
        assert.match(f.log(), /em 8s.*3\/3/);
        assert.ok(f.reports().some(r => r.status === 'error' && /último status 137/.test(r.message)));
        assert.doesNotMatch(f.log(), /a{64}|b{48}/);
    } finally { await f.cleanup(); }
});
test('panel stop during restart backoff cancels recovery', {timeout: 12000}, async () => {
    const f = fixture({installed: true, crashes: 1});
    try {
        await until(() => /tentativa 1\/3/.test(f.log()));
        fs.writeFileSync(path.join(f.directory, 'stop'), 'stop');
        await until(f.stopped);
        assert.equal(f.runs().length, 1);
        assert.match(f.log(), /encerrado pelo painel/);
    } finally { await f.cleanup(); }
});
test('a graceful Node stop is not restarted', {timeout: 12000}, async () => {
    const f = fixture({installed: true});
    try {
        await until(f.started);
        process.kill(Number(fs.readFileSync(path.join(f.directory, 'started'), 'utf8')), 'SIGTERM');
        await until(f.stopped);
        assert.equal(f.runs().length, 1);
        assert.doesNotMatch(f.log(), /Reiniciando Node/);
        assert.ok(f.reports().some(r => r.status === 'offline'));
    } finally { await f.cleanup(); }
});
test('TLS pin rejection is diagnosed distinctly and never retried', {timeout: 12000}, async () => {
    const f = fixture({installed: true, pinRejected: true});
    try {
        await until(f.stopped);
        assert.equal(f.started(), false);
        assert.match(f.log(), /chave TLS.*mudou/);
        assert.doesNotMatch(f.log(), /encerrado pelo painel|Reiniciando Node|Painel indisponível/);
    } finally { await f.cleanup(); }
});
test('preparation survives a panel outage longer than the old 25s deadline', {timeout: 45000}, async () => {
    const f = fixture({installed: true, offline: true});
    try {
        await delay(27000);
        assert.equal(f.started(), false);
        assert.equal(f.stopped(), false);
        fs.unlinkSync(path.join(f.directory, 'offline'));
        await until(f.started, 12000);
        assert.match(f.log(), /mesma sessão/);
        assert.equal(f.runs().length, 1);
    } finally { await f.cleanup(); }
});
for (const options of [{installed: true}, {panelFails: true}, {incompatible: true}, {tampered: true}, {blocked: true}, {hashTool: 'openssl'}]) {
    test(`direct bootstrap: ${Object.keys(options)[0]}`, {timeout: 20000}, async () => {
        const f = fixture(options);
        try {
            await until(f.closed, 5000); assert.equal(f.child.exitCode, options.tampered ? 1 : 0, f.log());
            assert.match(f.log(), /Preparando o túnel direto nesta máquina/);
            if (options.blocked) {
                await until(() => fs.existsSync(path.join(f.directory, 'panel.args')));
                const pid = Number(fs.readFileSync(path.join(path.dirname(f.logPath()), 'agent.pid'), 'utf8'));
                process.kill(pid, 'SIGHUP');
                await until(() => f.reports().filter(report => report.status === 'starting').length >= 2);
                fs.writeFileSync(path.join(f.directory, 'stop'), 'stop');
                await until(f.stopped); assert.equal(f.started(), false, f.log());
                assert.match(f.log(), /encerrado pelo painel/);
            } else if (options.tampered) {
                await until(() => f.reports().some(report => report.status === 'error'));
                await until(f.stopped); assert.equal(f.started(), false, f.log());
                assert.match(f.log(), /SHA256.*falhou/);
                assert.match(f.log(), /encerrou durante a preparação/);
            } else {
                await until(f.started);
                assert.ok(f.reports().some(report => report.status === 'starting'));
                if (options.installed) assert.equal(fs.existsSync(path.join(f.directory, 'panel.args')), false);
                if (options.incompatible) assert.match(f.log(), /glibc antiga/);
                if (options.panelFails) assert.match(f.log(), /Tentando a origem/);
                await f.cleanup(false);
                assert.equal(f.stopped(), true, 'Runtime and credentials were not cleaned up');
            }
            if (options.panelFails || options.tampered) {
                const args = fs.readFileSync(path.join(f.directory, 'origin.args'), 'utf8');
                assert.ok(!args.includes('--config') && !args.includes('Authorization') && !args.includes('a'.repeat(64)), 'Panel credential leaked to runtime host');
            }
        } finally { await f.cleanup(); }
    });
}
