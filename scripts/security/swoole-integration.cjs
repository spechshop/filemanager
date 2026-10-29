'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const net = require('node:net');
const {spawn, execFileSync} = require('node:child_process');
const {once} = require('node:events');
const WebSocket = require('ws');
const {JSDOM} = require('jsdom');
const ROOT = path.resolve(__dirname, '../..');
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
async function listen(server) { server.listen(0, '127.0.0.1'); await once(server, 'listening'); return server.address().port; }
async function socket(url, origin = 'http://localhost') {
    const ws = new WebSocket(url, {origin, handshakeTimeout: 1500}); ws.on('error', () => {});
    await new Promise((resolve, reject) => { ws.once('open', resolve); ws.once('error', reject); }); return ws;
}
async function closed(ws) { if (ws.readyState === WebSocket.CLOSED) return; await Promise.race([once(ws, 'close'), delay(2200).then(() => { throw new Error('denied connection stayed active'); })]); }
test('real Swoole HTTP + WebSocket denied operations have no file/upload/process/bridge effects', {timeout: 25000}, async () => {
    const php = process.env.SECURITY_PHP_BINARY || 'php';
    assert.equal(execFileSync(php, ['-r', 'echo extension_loaded("swoole") ? "yes" : "no";']).toString(), 'yes', 'Swoole required; no silent skip');
    const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'fm-swoole-')); fs.mkdirSync(path.join(tmp, 'files')); fs.mkdirSync(path.join(tmp, 'outside'));
    const sentinel = path.join(tmp, 'files/keep'); fs.writeFileSync(sentinel, 'KEEP'); const outside = path.join(tmp, 'outside/secret'); fs.writeFileSync(outside, 'PRIVATE');
    const tokens = {owner: {expire: Math.floor(Date.now() / 1000) + 3600, allowedRoots: [path.join(tmp, 'files')], terminal: true, admin: true}, other: {expire: Math.floor(Date.now() / 1000) + 3600}, expired: {expire: 1}};
    const tokenFile = path.join(tmp, 'tokens.json'); const save = () => fs.writeFileSync(tokenFile, JSON.stringify(tokens)); save();
    let bridgeConnections = 0;
    const bridge = net.createServer(connection => { bridgeConnections++; connection.destroy(); }); const bridgePort = await listen(bridge);
    const reservation = net.createServer(); const port = await listen(reservation); await new Promise(resolve => reservation.close(resolve));
    const uploadReservation = net.createServer(); const uploadPort = await listen(uploadReservation); await new Promise(resolve => uploadReservation.close(resolve));
    const base = `http://127.0.0.1:${port}`, uploadBase = `http://127.0.0.1:${uploadPort}`;
    const child = spawn(php, [path.join(ROOT, 'scripts/security/fixtures/swoole-server.php')], {cwd: tmp, env: {...process.env, SECURITY_HTTP_PORT: String(port), FILEMANAGER_UPLOAD_PORT: String(uploadPort), FILEMANAGER_DATA_DIR: tmp, FILEMANAGER_ORIGIN: 'http://localhost', FILEMANAGER_ALLOWED_ROOTS: JSON.stringify([path.join(tmp, 'files')]), FILEMANAGER_TOKENS_FILE: tokenFile, FILEMANAGER_PTY_PORT: String(bridgePort)}, stdio: ['ignore', 'pipe', 'pipe']});
    let output = ''; child.stdout.on('data', chunk => { output += chunk; }); child.stderr.on('data', chunk => { output += chunk; });
    const sockets = [];
    try {
        for (let i = 0; i < 100 && !output.includes('SECURITY_FIXTURE_READY') && child.exitCode === null; i++) await delay(30);
        assert.equal(child.exitCode, null, output); assert.ok(output.includes('SECURITY_FIXTURE_READY'), output);
        // Exercise the production browser bootstrap against real HTTP auth,
        // including the 401 that previously left its loading dots forever.
        const browser = new JSDOM(fs.readFileSync(path.join(ROOT, 'plugins/Request/modules/angryPart.html'), 'utf8')
            + '<div id="fp"></div><div id="loadingPage"></div><div id="navContent" style="display:none"></div><div id="messageModalWelcome"></div>',
            {url: base, runScripts: 'outside-only'});
        try {
            const w = browser.window, requests = [];
            w.Request = Request; w.Headers = Headers; w.AbortController = AbortController;
            w.fetch = (url, options) => { requests.push(new URL(url, base).pathname); return fetch(new URL(url, base), options); };
            w.eval(fs.readFileSync(path.join(ROOT, 'js/jquery-3.3.1.min.js'), 'utf8'));
            w.jQuery.fn.modal = () => {};
            let boot;
            const timer = w.setTimeout.bind(w);
            w.setTimeout = (fn, ms, ...args) => ms === 800 ? (boot = fn, 1) : timer(fn, ms, ...args);
            w.refAll = async () => {};
            w.localStorage.setItem('tokenBrowser', 'unregistered-fixture');
            w.eval(fs.readFileSync(path.join(ROOT, 'js/auth-transport.js'), 'utf8'));
            const bootstrap = fs.readFileSync(path.join(ROOT, 'js/jquery.toast.min.js'), 'utf8');
            w.eval(bootstrap.slice(bootstrap.indexOf('function initializeBrowserCredential()'), bootstrap.indexOf('\nfunction copyDivToClipboard')));
            const denied = await Promise.allSettled([
                w.FMAuth.socketUrl(base.replace('http:', 'ws:') + '/unregistered-fixture'),
                w.FMAuth.socketUrl(base.replace('http:', 'ws:') + '/unregistered-fixture-lsp'),
                boot(),
            ]);
            assert.equal(denied[0].reason.status, 401, String(denied[0].reason) + '\n' + output);
            assert.equal(denied[1].reason.status, 401, String(denied[1].reason));
            assert.deepEqual(requests, ['/checkToken'], 'one auth check, no unauthorized ticket requests');
            assert.equal(w.document.getElementById('loadingPage').style.display, 'none');
            assert.equal(w.document.getElementById('angryPart').style.display, 'block');
            assert.match(w.document.getElementById('authErrorTitle').textContent, /não autorizado/);
            assert.equal(w.FMAuth.shouldRetry(denied[0].reason), false);
            w.localStorage.setItem('tokenBrowser', 'owner');
            await boot();
            assert.equal(w.document.getElementById('navContent').style.display, 'block');
            assert.equal(w.document.getElementById('angryPart').style.display, 'none');
            const authorizedSocket = await w.FMAuth.socketUrl(base.replace('http:', 'ws:') + '/owner-browser');
            assert.match(authorizedSocket, /\/ws\/[a-f0-9]{64}$/);
            const connection = await socket(authorizedSocket); sockets.push(connection);
            assert.equal(connection.readyState, WebSocket.OPEN);
            const listing = await w.fetch('/syncPath?tokenBrowser=owner&path=');
            assert.equal(listing.status, 200);
            assert.ok((await listing.json()).information.some(entry => entry.path === sentinel));
        } finally { browser.window.close(); }
        for (const token of ['', 'invalid', 'expired', 'revoked']) {
            const response = await fetch(`${base}/deleteFile?tokenBrowser=${token}&path=${encodeURIComponent(sentinel)}`);
            assert.equal(response.status, 401); assert.equal(fs.readFileSync(sentinel, 'utf8'), 'KEEP');
        }
        for (const endpoint of ['deleteFile', 'downloadFile']) {
            const response = await fetch(`${base}/${endpoint}?tokenBrowser=owner&path=${encodeURIComponent(outside)}`);
            assert.equal(response.status, 403); assert.ok(!(await response.text()).includes('PRIVATE')); assert.equal(fs.readFileSync(outside, 'utf8'), 'PRIVATE');
        }
        const form = new FormData(); form.append('files[]', new Blob(['UPLOAD']), 'upload.txt');
        const upload = await fetch(`${uploadBase}/uploadFile?tokenBrowser=owner&path=${encodeURIComponent(path.join(tmp, 'outside'))}`, {method: 'POST', body: form});
        assert.equal(upload.status, 403); assert.equal(fs.existsSync(path.join(tmp, 'outside/upload.txt')), false);
        const validForm = new FormData(); validForm.append('files[]', new Blob(['VALID-UPLOAD']), 'valid.txt');
        const validUpload = await fetch(`${uploadBase}/uploadFile?tokenBrowser=owner&path=${encodeURIComponent(path.join(tmp, 'files'))}`, {method: 'POST', body: validForm});
        assert.equal(validUpload.status, 200); assert.equal((await validUpload.json()).success, true); assert.equal(fs.readFileSync(path.join(tmp, 'files/valid.txt'), 'utf8'), 'VALID-UPLOAD');
        const batchForm = new FormData();
        for (let i = 0; i < 40; i++) batchForm.append('files[]', new Blob([`batch-${i}`]), `batch & ${i}.txt`);
        batchForm.append('files[]', new Blob([]), 'empty.txt');
        const batchUpload = await fetch(`${uploadBase}/uploadFile?path=${encodeURIComponent(path.join(tmp, 'files'))}`, {method: 'POST', headers: {Authorization: 'Bearer owner'}, body: batchForm});
        assert.equal(batchUpload.status, 200); assert.equal((await batchUpload.json()).success, true);
        for (let i = 0; i < 40; i++) assert.equal(fs.readFileSync(path.join(tmp, `files/batch & ${i}.txt`), 'utf8'), `batch-${i}`);
        assert.equal(fs.statSync(path.join(tmp, 'files/empty.txt')).size, 0);
        const largeForm = new FormData(); largeForm.append('files[]', new Blob([Buffer.alloc(16 * 1024 * 1024, 65)]), 'large.bin');
        const largeUpload = await fetch(`${uploadBase}/uploadFile?path=${encodeURIComponent(path.join(tmp, 'files'))}`, {method: 'POST', headers: {Authorization: 'Bearer owner'}, body: largeForm});
        assert.equal(largeUpload.status, 200); assert.equal((await largeUpload.json()).success, true);
        assert.equal(fs.statSync(path.join(tmp, 'files/large.bin')).size, 16 * 1024 * 1024);
        const listing = await fetch(`${base}/syncPath?tokenBrowser=owner&path=`);
        assert.equal(listing.status, 200);
        const listed = await listing.json();
        assert.equal(listed.currentPath, path.join(tmp, 'files'));
        assert.ok(listed.information.some(file => file.path === sentinel));
        const tree = await fetch(`${base}/treeDetails?tokenBrowser=owner&dir=/`);
        assert.equal(tree.status, 200);
        assert.equal(tree.headers.get('X-FileManager-Root'), path.join(tmp, 'files'));
        const issued = await fetch(base + '/accessTicket', {method: 'POST', headers: {Authorization: 'Bearer owner'}, body: JSON.stringify({kind: 'websocket', socket: 'owner-ticket-session'})});
        assert.equal(issued.status, 200);
        const ticket = (await issued.json()).ticket;
        assert.match(ticket, /^[a-f0-9]{64}$/);
        const ticketSocket = await socket(`ws://127.0.0.1:${port}/ws/${ticket}`); sockets.push(ticketSocket);
        ticketSocket.send(JSON.stringify({token: 'owner-ticket-session'}));
        const replay = await socket(`ws://127.0.0.1:${port}/ws/${ticket}`); sockets.push(replay); await closed(replay);
        const mediaTicket = await fetch(base + '/accessTicket', {method: 'POST', headers: {Authorization: 'Bearer owner'}, body: JSON.stringify({operation: 'downloadFile', path: sentinel})});
        const scopedTicket = (await mediaTicket.json()).ticket;
        const media = await fetch(`${base}/downloadFile?accessTicket=${scopedTicket}&path=${encodeURIComponent(sentinel)}`);
        assert.equal(await media.text(), 'KEEP');
        const wrongPath = await fetch(`${base}/downloadFile?accessTicket=${scopedTicket}&path=${encodeURIComponent(outside)}`);
        assert.equal(wrongPath.status, 401);
        const wrongOperation = await fetch(`${base}/deleteFile?accessTicket=${scopedTicket}&path=${encodeURIComponent(sentinel)}`);
        assert.equal(wrongOperation.status, 403); assert.equal(fs.readFileSync(sentinel, 'utf8'), 'KEEP');
        const forbidden = await fetch(`${base}/deleteFile?tokenBrowser=owner&path=${encodeURIComponent(sentinel)}`, {headers: {Origin: 'https://evil.invalid'}});
        assert.equal(forbidden.status, 403); assert.equal(fs.readFileSync(sentinel, 'utf8'), 'KEEP');
        for (const url of ['/plugins/configInterface.json', '/package-lock.json', '/sessions-terminals.json', '/database/tokens.lotus']) {
            const response = await fetch(base + url); assert.equal(response.status, 404); assert.ok(!(await response.text()).includes('owner'));
        }
        const wrong = await socket(`ws://127.0.0.1:${port}/owner-terminal`, 'https://evil.invalid'); sockets.push(wrong); await closed(wrong);
        const missing = await socket(`ws://127.0.0.1:${port}/`); sockets.push(missing); await closed(missing);
        const stolen = await socket(`ws://127.0.0.1:${port}/owner-terminal`); sockets.push(stolen);
        stolen.send(JSON.stringify({token: 'other-terminal', isCodex: true, command: 'touch UNAUTHORIZED\n'})); await closed(stolen);
        assert.equal(bridgeConnections, 0, 'identity substitution must never connect to PTY');
        const active = await socket(`ws://127.0.0.1:${port}/owner-terminal`); sockets.push(active);
        tokens.owner.revoked = true; save(); await closed(active);
        const reconnect = await socket(`ws://127.0.0.1:${port}/owner-terminal`); sockets.push(reconnect); await closed(reconnect);
        assert.equal(bridgeConnections, 0); assert.equal(fs.existsSync(path.join(tmp, 'files/UNAUTHORIZED')), false);
        const response = await fetch(`${base}/deleteFile?tokenBrowser=owner&path=${encodeURIComponent(sentinel)}`);
        assert.equal(response.status, 401); assert.equal(fs.readFileSync(sentinel, 'utf8'), 'KEEP');
        assert.ok(!output.includes('tokenBrowser=') && !output.includes('touch UNAUTHORIZED'), 'logs exclude credentials and commands');
    } finally {
        for (const ws of sockets) if (ws.readyState !== WebSocket.CLOSED) ws.terminate();
        child.kill('SIGTERM'); await Promise.race([once(child, 'exit').catch(() => {}), delay(1500)]); if (child.exitCode === null) child.kill('SIGKILL');
        await new Promise(resolve => bridge.close(resolve)); fs.rmSync(tmp, {recursive: true, force: true});
    }
});
