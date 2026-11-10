'use strict';
// Small, lockfile-pinned recovery when npm itself is killed by the host.
// Only ws is recovered here; this does not claim the full npm installation passed.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const {execFileSync} = require('node:child_process');

async function repair(root) {
    const lock = JSON.parse(fs.readFileSync(path.join(root, 'package-lock.json'), 'utf8'));
    const entry = lock.packages?.['node_modules/ws'];
    if (!entry || !/^https:\/\/registry\.npmjs\.org\/ws\/-\/ws-[0-9][^/]*\.tgz$/.test(entry.resolved)
        || !/^sha512-[A-Za-z0-9+/]+={0,2}$/.test(entry.integrity)) throw new Error('ws ausente ou sem origem/integridade válida no lockfile');
    const runtime = path.join(root, '.runtime');
    fs.mkdirSync(runtime, {recursive: true});
    const stage = fs.mkdtempSync(path.join(runtime, 'ws-repair-'));
    const target = path.join(root, 'node_modules/ws');
    const backup = path.join(stage, 'previous');
    try {
        const response = await fetch(entry.resolved, {redirect: 'error', signal: AbortSignal.timeout(60000)});
        if (!response.ok) throw new Error(`Download de ws: HTTP ${response.status}`);
        const chunks = []; let size = 0;
        for await (const chunk of response.body) {
            size += chunk.length;
            if (size > 4 * 1024 * 1024) throw new Error('Pacote ws excedeu o limite de 4 MiB');
            chunks.push(chunk);
        }
        const bytes = Buffer.concat(chunks);
        if ('sha512-' + crypto.createHash('sha512').update(bytes).digest('base64') !== entry.integrity) throw new Error('Integridade de ws não confere');
        const archive = path.join(stage, 'ws.tgz');
        fs.writeFileSync(archive, bytes, {mode: 0o600});
        const names = execFileSync('tar', ['-tzf', archive], {encoding: 'utf8'}).trim().split('\n');
        if (names.some(name => !name.startsWith('package/') || name.split('/').includes('..'))) throw new Error('Estrutura inválida no pacote ws');
        execFileSync('tar', ['-xzf', archive, '--no-same-owner', '--no-same-permissions', '-C', stage]);
        if (typeof require(path.join(stage, 'package')).WebSocketServer !== 'function') throw new Error('ws extraído não carrega');
        fs.mkdirSync(path.dirname(target), {recursive: true});
        if (fs.lstatSync(target, {throwIfNoEntry: false})) fs.renameSync(target, backup);
        try { fs.renameSync(path.join(stage, 'package'), target); }
        catch (error) { if (fs.existsSync(backup)) fs.renameSync(backup, target); throw error; }
        console.log(`ws ${entry.version} recuperado com SHA-512 do package-lock.json.`);
    } finally { fs.rmSync(stage, {recursive: true, force: true}); }
}
repair(path.resolve(process.argv[2] || process.cwd())).catch(error => { console.error(error.message); process.exitCode = 1; });
