'use strict';
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const ROOT = path.resolve(__dirname, '../..');
function tokens() {
    try { const value = JSON.parse(fs.readFileSync(process.env.FILEMANAGER_TOKENS_FILE || path.join(ROOT, 'database/tokens.lotus'), 'utf8')); return value && typeof value === 'object' && !Array.isArray(value) ? value : {}; }
    catch { return {}; }
}
function owner(socket, records = tokens()) {
    if (typeof socket !== 'string') return '';
    const matches = Object.keys(records).filter(key => socket === key || (socket.startsWith(key + '-') && /^[a-zA-Z0-9_-]{1,128}$/.test(socket.slice(key.length + 1))));
    return matches.length === 1 ? matches[0] : '';
}
function expiration(value) {
    if (!(typeof value === 'number' || (typeof value === 'string' && /^\d{1,16}$/.test(value)))) return 0;
    const number = Number(value);
    return Number.isSafeInteger(number) ? (number > 9999999999 ? Math.floor(number / 1000) : number) : 0;
}
function valid(socket, capability = 'terminal') {
    const records = tokens();
    const record = records[owner(socket, records)];
    const admin = capability !== 'admin' || (record?.admin ?? (record && !Object.hasOwn(record, 'allowedRoots') && record.terminal !== false)) === true;
    return admin && !!record && !record.revoked && (record.terminal ?? !Object.hasOwn(record, 'allowedRoots')) === true && expiration(record.expire) > Date.now() / 1000;
}
function secret() {
    try { return (process.env.FILEMANAGER_BRIDGE_SECRET || fs.readFileSync(path.join(process.env.FILEMANAGER_DATA_DIR || path.join(ROOT, '.runtime'), 'bridge-secret'), 'utf8')).trim(); }
    catch { return ''; }
}
function allowed(request, socket, capability = 'terminal') {
    const expected = Buffer.from(secret());
    const supplied = Buffer.from(String(request.headers['x-filemanager-bridge'] || ''));
    return !request.headers.origin && expected.length >= 32 && expected.length === supplied.length
        && crypto.timingSafeEqual(expected, supplied) && valid(socket, capability);
}
function session(request) {
    return String(request.url || '').split('?')[0].replace(/^\//, '');
}
function reject(socket) { process.stderr.write('{"event":"connection_denied","channel":"bridge"}\n'); socket.end('HTTP/1.1 403 Forbidden\r\nConnection: close\r\n\r\n'); }
function limit(name, fallback) {
    const value = Number(process.env['FILEMANAGER_' + name]);
    return Number.isSafeInteger(value) && value > 0 ? value : fallback;
}
const leases = new Map();
function acquire(service, socket, perOwner, total) {
    const identity = owner(socket);
    const records = leases.get(service) || new Map();
    if (!valid(socket, service.startsWith('CODEX') ? 'admin' : 'terminal') || [...records.values()].reduce((a, b) => a + b, 0) >= limit(service + '_TOTAL', total)
        || (records.get(identity) || 0) >= limit(service + '_PER_USER', perOwner)) return null;
    records.set(identity, (records.get(identity) || 0) + 1); leases.set(service, records);
    let released = false;
    return () => {
        if (released) return; released = true;
        const count = records.get(identity) - 1;
        if (count) records.set(identity, count); else records.delete(identity);
    };
}
function send(ws, payload) {
    if (ws.bufferedAmount > limit('OUTPUT_BUFFER_BYTES', 4194304)) { ws.close(1013, 'Output backpressure'); return false; }
    ws.send(payload); return true;
}
module.exports = {expiration, allowed, valid, owner, session, reject, limit, acquire, send};
