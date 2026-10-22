'use strict';
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const ROOT = path.resolve(__dirname, '../..');
function tokens() {
    try { const value = JSON.parse(fs.readFileSync(process.env.FILEMANAGER_TOKENS_FILE || path.join(ROOT, 'database/tokens.lotus'), 'utf8')); return value && typeof value === 'object' && !Array.isArray(value) ? value : {}; }
    catch { return {}; }
}
function owner(socket) {
    if (typeof socket !== 'string') return '';
    const matches = Object.keys(tokens()).filter(key => socket === key || (socket.startsWith(key + '-') && /^[a-zA-Z0-9_-]{1,128}$/.test(socket.slice(key.length + 1))));
    return matches.length === 1 ? matches[0] : '';
}
function valid(socket) {
    const record = tokens()[owner(socket)];
    return !!record && !record.revoked && (record.terminal ?? !record.allowedRoots) === true && Number(record.expire) > Date.now() / 1000;
}
function secret() {
    try { return (process.env.FILEMANAGER_BRIDGE_SECRET || fs.readFileSync(path.join(ROOT, '.runtime/bridge-secret'), 'utf8')).trim(); }
    catch { return ''; }
}
function allowed(request, socket) {
    const expected = Buffer.from(secret());
    const supplied = Buffer.from(String(request.headers['x-filemanager-bridge'] || ''));
    return !request.headers.origin && expected.length >= 32 && expected.length === supplied.length
        && crypto.timingSafeEqual(expected, supplied) && valid(socket);
}
function session(request) {
    return String(request.url || '').split('?')[0].replace(/^\//, '');
}
function reject(socket) { process.stderr.write('{"event":"connection_denied","channel":"bridge"}\n'); socket.end('HTTP/1.1 403 Forbidden\r\nConnection: close\r\n\r\n'); }
module.exports = {allowed, valid, owner, session, reject};
