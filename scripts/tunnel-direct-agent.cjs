'use strict';
// FILEMANAGER_CONFIG
// No npm dependencies: HTTP upgrade, RFC 6455 framing and TCP use Node's standard library.
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const https = require('node:https');
const tls = require('node:tls');
const net = require('node:net');
const crypto = require('node:crypto');
const directory = path.dirname(__filename);
const pidFile = path.join(directory, 'agent.pid');
const endpoint = new URL(config.endpoint);
const target = new URL(config.target);
const headers = {Authorization: `Bearer ${config.secret}`, 'X-Tunnel-Session': config.session};
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
const certificateErrors = new Set(['DEPTH_ZERO_SELF_SIGNED_CERT', 'SELF_SIGNED_CERT_IN_CHAIN', 'CERT_HAS_EXPIRED',
    'CERT_NOT_YET_VALID', 'UNABLE_TO_VERIFY_LEAF_SIGNATURE', 'UNABLE_TO_GET_ISSUER_CERT_LOCALLY', 'ERR_TLS_CERT_ALTNAME_INVALID']);
let stopped = false, active, lastUrl = '';
fs.writeFileSync(pidFile, String(process.pid), {mode: 0o600});
fs.unlinkSync(__filename);

// Only send credentials on the self-signed fallback after verifying the exact pinned public key.
const pinnedAgent = new https.Agent({keepAlive: false});
pinnedAgent.createConnection = (options, callback) => {
    const socket = tls.connect({...options, rejectUnauthorized: false});
    let completed = false;
    const done = (error) => {
        if (completed) return;
        completed = true;
        if (error) socket.destroy();
        callback(error, error ? undefined : socket);
    };
    socket.once('error', done);
    socket.once('secureConnect', () => {
        try {
            const key = new crypto.X509Certificate(socket.getPeerCertificate().raw).publicKey.export({type: 'spki', format: 'der'});
            const actual = 'sha256//' + crypto.createHash('sha256').update(key).digest('base64');
            if (actual !== config.panelPin) throw Object.assign(new Error('A chave TLS do File Manager mudou. Gere outro comando.'), {fatal: true});
            done(null);
        } catch (error) { done(error); }
    });
};

function request(url, options, upgrade = false, pinned = false) {
    return new Promise((resolve, reject) => {
        const client = url.protocol === 'https:' ? https : http;
        const req = client.request(url, {...options, ...(pinned ? {agent: pinnedAgent} : {agent: false})});
        req.setTimeout(10000, () => req.destroy(new Error('Tempo de conexão com o File Manager excedido.')));
        req.once('error', reject);
        req.once('response', response => {
            let body = '';
            response.setEncoding('utf8');
            response.on('data', chunk => { body += chunk; if (body.length > 65536) req.destroy(new Error('Resposta inválida.')); });
            response.once('error', reject);
            response.once('end', () => {
                if (response.statusCode !== 200 || upgrade) {
                    reject(Object.assign(new Error(`File Manager respondeu HTTP ${response.statusCode}.`),
                        {fatal: [400, 401, 403, 404, 409, 422].includes(response.statusCode)}));
                } else resolve(body);
            });
        });
        req.once('upgrade', (response, socket, head) => {
            socket.setTimeout(0);
            const expected = crypto.createHash('sha1').update(options.headers['Sec-WebSocket-Key'] + '258EAFA5-E914-47DA-95CA-C5AB0DC85B11').digest('base64');
            if (response.statusCode !== 101 || response.headers['sec-websocket-accept'] !== expected
                || response.headers.upgrade?.toLowerCase() !== 'websocket') {
                socket.destroy(); reject(new Error('Resposta WebSocket inválida.')); return;
            }
            resolve({socket, head});
        });
        req.end(options.body);
    }).catch(error => {
        if (!pinned && url.protocol === 'https:' && config.panelPin && certificateErrors.has(error.code)) return request(url, options, upgrade, true);
        throw error;
    });
}

async function report(status) {
    const body = JSON.stringify({session: config.session, status, url: ''});
    const reply = await request(endpoint, {method: 'POST', headers: {...headers, 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body)}, body});
    if (JSON.parse(reply).success !== true) throw new Error('Status do túnel rejeitado.');
}

async function connect() {
    const url = new URL('/fileManagerTunnelDirect', endpoint);
    const key = crypto.randomBytes(16).toString('base64');
    const {socket, head} = await request(url, {headers: {...headers, Connection: 'Upgrade', Upgrade: 'websocket',
        'Sec-WebSocket-Version': '13', 'Sec-WebSocket-Key': key}}, true);
    if (stopped) { socket.destroy(); return; }
    active = socket;
    await new Promise((resolve, reject) => {
        const peers = new Map();
        let buffer = Buffer.alloc(0), lastAck = Date.now(), finished = false, congested = false;
        function finish(error) {
            if (finished) return;
            finished = true;
            clearInterval(timer);
            for (const peer of peers.values()) peer.destroy();
            peers.clear(); socket.destroy();
            active = undefined;
            error ? reject(error) : resolve();
        }
        function frame(opcode, payload) {
            if (socket.destroyed || socket.writableLength > 4194304) { finish(new Error('A conexão direta está congestionada.')); return; }
            const mask = crypto.randomBytes(4);
            const header = Buffer.alloc(payload.length < 126 ? 6 : 8);
            header[0] = 0x80 | opcode;
            if (payload.length < 126) { header[1] = 0x80 | payload.length; mask.copy(header, 2); }
            else { header[1] = 0x80 | 126; header.writeUInt16BE(payload.length, 2); mask.copy(header, 4); }
            const masked = Buffer.allocUnsafe(payload.length);
            for (let i = 0; i < payload.length; i++) masked[i] = payload[i] ^ mask[i % 4];
            if (!socket.write(Buffer.concat([header, masked]))) {
                congested = true;
                for (const peer of peers.values()) peer.pause();
            }
        }
        function send(type, id, data = Buffer.alloc(0)) {
            const packet = Buffer.allocUnsafe(5 + data.length);
            packet[0] = type; packet.writeUInt32BE(id, 1); data.copy(packet, 5); frame(2, packet);
        }
        function flush(peer, id) {
            while (peer.fmPending.length && peer.fmCredits > 0 && !congested && !finished) {
                const chunk = peer.fmPending.subarray(0, 32768);
                peer.fmPending = peer.fmPending.subarray(chunk.length);
                peer.fmCredits--; send(2, id, chunk);
            }
            if (peer.fmPending.length || peer.fmCredits === 0 || congested) peer.pause();
            else peer.resume();
        }
        function packet(data) {
            if (data.length < 5 || data.length > 32773) throw new Error('Pacote de túnel inválido.');
            const type = data[0], id = data.readUInt32BE(1);
            if (type === 6 && id === 0) {
                lastAck = Date.now();
                const publicUrl = data.subarray(5).toString('utf8');
                if (lastUrl !== publicUrl) { lastUrl = publicUrl; console.log(`Link do túnel direto: ${publicUrl}`); }
                return;
            }
            if (![1, 2, 3, 4, 7].includes(type) || id === 0 || (type !== 2 && data.length !== 5)) throw new Error('Stream de túnel inválido.');
            if (type === 1) {
                if (peers.has(id) || peers.size >= 64) { send(4, id); return; }
                const peer = net.createConnection({host: target.hostname.replace(/^\[|\]$/g, ''), port: Number(target.port) || (target.protocol === 'https:' ? 443 : 80), allowHalfOpen: true});
                peers.set(id, peer);
                peer.fmCredits = 8; peer.fmPending = Buffer.alloc(0);
                if (congested) peer.pause();
                const timeout = setTimeout(() => peer.destroy(), 10000);
                peer.once('connect', () => clearTimeout(timeout));
                peer.on('data', chunk => { peer.fmPending = peer.fmPending.length ? Buffer.concat([peer.fmPending, chunk]) : chunk; flush(peer, id); });
                peer.once('end', () => send(3, id));
                peer.on('error', () => {});
                peer.once('close', () => { clearTimeout(timeout); if (peers.get(id) === peer) { peers.delete(id); if (!finished && (!peer.readableEnded || !peer.writableFinished)) send(4, id); } });
            } else {
                const peer = peers.get(id);
                if (!peer) return;
                if (type === 2) {
                    if (peer.writableLength > 4194304 || peer.writableEnded) peer.destroy();
                    else peer.write(data.subarray(5), error => { if (!error && !finished && peers.get(id) === peer) send(7, id); });
                }
                if (type === 3) peer.end();
                if (type === 4) { peers.delete(id); peer.destroy(); }
                if (type === 7) { if (++peer.fmCredits > 8) throw new Error('Crédito de stream inválido.'); flush(peer, id); }
            }
        }
        function receive(chunk) {
            try {
                buffer = buffer.length ? Buffer.concat([buffer, chunk]) : chunk;
                while (buffer.length >= 2) {
                    const opcode = buffer[0] & 15, short = buffer[1] & 127;
                    if (!(buffer[0] & 0x80) || buffer[0] & 0x70 || buffer[1] & 0x80 || short === 127) throw new Error('Frame WebSocket inválido.');
                    const size = short === 126 ? 4 : 2;
                    if (buffer.length < size) return;
                    const length = short === 126 ? buffer.readUInt16BE(2) : short;
                    if (length > 32773 || (opcode >= 8 && length > 125)) throw new Error('Frame WebSocket muito grande.');
                    if (buffer.length < size + length) return;
                    const data = buffer.subarray(size, size + length);
                    buffer = buffer.subarray(size + length);
                    if (opcode === 2) packet(data);
                    else if (opcode === 9) frame(10, data);
                    // A worker reload can also close with 1008. The next authenticated HTTP
                    // report distinguishes a revoked capability from a recoverable disconnect.
                    else if (opcode === 8) { finish(new Error('Túnel encerrado pelo painel.')); return; }
                    else if (opcode !== 10) throw new Error('Opcode WebSocket inválido.');
                }
            } catch (error) { finish(error); }
        }
        const timer = setInterval(() => {
            if (Date.now() - lastAck > 25000) finish(new Error('Conexão com o File Manager perdida.'));
            else send(5, 0);
        }, 5000);
        socket.on('data', receive);
        socket.on('drain', () => { congested = false; for (const [id, peer] of peers) flush(peer, id); });
        socket.once('error', finish);
        socket.once('close', () => finish());
        if (head.length) receive(head);
    });
}

for (const signal of ['SIGINT', 'SIGTERM']) process.on(signal, () => { stopped = true; active?.destroy(); });
process.on('SIGHUP', () => {});
process.once('exit', () => { try { fs.unlinkSync(pidFile); } catch {} });
(async () => {
    try {
        while (!stopped) {
            try { await report('starting'); await connect(); }
            catch (error) {
                console.error(error.message);
                if (error.fatal) break;
            }
            if (!stopped) { console.log('Reconectando ao host do File Manager...'); await delay(2000); }
        }
    } finally { pinnedAgent.destroy(); }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
