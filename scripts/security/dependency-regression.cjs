'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const net = require('node:net');
const http = require('node:http');
const {once} = require('node:events');
const {execFileSync} = require('node:child_process');
const {getUri} = require('get-uri');
const {ProxyAgent} = require('proxy-agent');
const {createRequire} = require('node:module');
const getUriRequire = createRequire(require.resolve('get-uri'));
async function listen(server) { server.listen(0, '127.0.0.1'); await once(server, 'listening'); return server.address().port; }
async function contents(stream) { let text = ''; for await (const chunk of stream) text += chunk; return text; }
// A real local FTP peer exercises the exact API get-uri uses, including LIST fallback.
async function ftpFixture(t) {
    const commands = [], sockets = new Set(), servers = new Set();
    function track(socket) { sockets.add(socket); socket.on('close', () => sockets.delete(socket)); socket.on('error', () => {}); return socket; }
    const server = net.createServer(socket => {
        track(socket); socket.setEncoding('utf8'); socket.write('220 fixture ready\r\n');
        let buffer = '', passive, data;
        socket.on('data', chunk => {
            buffer += chunk;
            while (buffer.includes('\r\n')) {
                const i = buffer.indexOf('\r\n'), line = buffer.slice(0, i); buffer = buffer.slice(i + 2);
                const [command, ...rest] = line.split(' '), arg = rest.join(' '); commands.push(command);
                if (command === 'USER') socket.write('331 password required\r\n');
                else if (command === 'PASS') socket.write('230 logged in\r\n');
                else if (command === 'FEAT') socket.write('211 End\r\n');
                else if (command === 'PWD') socket.write('257 "/"\r\n');
                else if (command === 'MDTM') socket.write(arg.includes('missing') ? '550 missing\r\n' : arg.includes('fallback') ? '500 unsupported\r\n' : '213 20261001000000\r\n');
                else if (command === 'EPSV') {
                    passive = net.createServer(peer => { data = track(peer); }); servers.add(passive);
                    passive.listen(0, '127.0.0.1', () => socket.write(`229 Entering Extended Passive Mode (|||${passive.address().port}|)\r\n`));
                } else if (command === 'LIST' || command === 'RETR') {
                    socket.write('150 opening data\r\n');
                    assert.ok(data, 'passive data connection established');
                    const payload = command === 'LIST' ? 'type=file;size=20;modify=20261001000000; fallback.pac\r\n' : 'function FindProxyForURL(url, host) { return "DIRECT"; }';
                    data.end(payload, () => { passive.close(); socket.write('226 complete\r\n'); });
                } else if (command === 'QUIT') socket.end('221 goodbye\r\n');
                else socket.write('200 OK\r\n');
            }
        });
    });
    const port = await listen(server); servers.add(server);
    t.after(async () => { for (const socket of sockets) socket.destroy(); await Promise.all([...servers].map(s => s.listening ? new Promise(resolve => s.close(resolve)) : Promise.resolve())); });
    return {base: `ftp://127.0.0.1:${port}`, commands};
}

test('basic-ftp override preserves get-uri downloads, MDTM, LIST fallback, cache and missing file errors', {timeout: 15000}, async t => {
    assert.equal(getUriRequire('basic-ftp/package.json').version, '6.2.3');
    const ftp = await ftpFixture(t);
    const direct = await getUri(ftp.base + '/proxy.pac');
    assert.match(await contents(direct), /FindProxyForURL/);
    await assert.rejects(getUri(ftp.base + '/proxy.pac', {cache: direct}), {code: 'ENOTMODIFIED'});
    assert.match(await contents(await getUri(ftp.base + '/fallback.pac')), /FindProxyForURL/);
    await assert.rejects(getUri(ftp.base + '/missing.pac'), {code: 'ENOTFOUND'});
    assert.ok(ftp.commands.includes('LIST')); assert.ok(ftp.commands.includes('RETR')); assert.ok(ftp.commands.includes('MDTM'));
});

test('proxy-agent loads PAC over FTP and completes an HTTP request', {timeout: 15000}, async t => {
    const ftp = await ftpFixture(t);
    const server = http.createServer((req, res) => res.end('PAC-OK'));
    const port = await listen(server);
    const agent = new ProxyAgent({getProxyForUrl: () => 'pac+' + ftp.base + '/proxy.pac'});
    t.after(async () => { agent.destroy(); server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); });
    const body = await new Promise((resolve, reject) => {
        const req = http.get(`http://127.0.0.1:${port}/`, {agent}, res => contents(res).then(resolve, reject));
        req.on('error', reject); req.setTimeout(5000, () => req.destroy(new Error('PAC request timed out')));
    });
    assert.equal(body, 'PAC-OK'); assert.ok(ftp.commands.includes('RETR'));
});

test('FTP hostile listing parser remains bounded and still parses ordinary entries', {timeout: 10000}, () => {
    const parser = getUriRequire.resolve('basic-ftp/dist/parseList.js');
    // Bound execution in a subprocess: a regression cannot freeze the test runner.
    const script = `const assert = require('node:assert/strict');
const {parseList} = require(process.argv[1]);
const normal = '-rw-r--r-- 1 owner group 42 Jan 1 2020 file.txt';
const hostile = '-rw-r--r-- 1 ' + 'a '.repeat(65536) + '!';
const rows = parseList(hostile + '\\r\\n' + normal + '\\r\\n');
assert.ok(rows.some(row => row.name === 'file.txt' && row.size === 42));`;
    execFileSync(process.execPath, ['-e', script, parser], {timeout: 3000, stdio: 'pipe'});
});
