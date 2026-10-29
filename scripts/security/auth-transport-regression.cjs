'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const {JSDOM} = require('jsdom');
const fs = require('node:fs');
const path = require('node:path');
test('HTTP, XHR, WebSocket and media transports keep durable credentials out of URLs', async () => {
    const dom = new JSDOM('<div id="fp">fixture-owner</div>', {url: 'https://localhost/', runScripts: 'outside-only'});
    const w = dom.window, calls = [], id = '1'.repeat(64);
    w.Request = Request; w.Headers = Headers;
    w.fetch = async (url, options) => { calls.push({url: String(url), options}); return {ok: true, json: async () => url === '/checkToken' ? ({success: true}) : ({ticket: id})}; };
    w.XMLHttpRequest = class {
        open(method, url) { this.url = url; }
        setRequestHeader(name, value) { this.header = [name, value]; }
        send() {}
    };
    try {
        w.eval(fs.readFileSync(path.resolve(__dirname, '../../js/auth-transport.js'), 'utf8'));
        await w.fetch('/getFile?tokenBrowser=fixture-owner&path=%2Ffixture');
        assert.equal(calls[0].url.includes('tokenBrowser'), false);
        assert.equal(calls[0].options.headers.get('Authorization'), 'Bearer fixture-owner');
        await w.fetch('/getFile?tokenBrowser=other', {headers: {Authorization: 'Bearer fixture-owner'}});
        assert.ok(calls[1].url.includes('tokenBrowser=other'), 'conflicting identities remain visible to the server and are denied');
        const xhr = new w.XMLHttpRequest(); xhr.open('POST', '/uploadFile?tokenBrowser=fixture-owner'); xhr.send();
        assert.equal(xhr.url.includes('fixture-owner'), false); assert.deepEqual(xhr.header, ['Authorization', 'Bearer fixture-owner']);
        const socket = await w.FMAuth.socketUrl('wss://localhost/fixture-owner-terminal');
        assert.equal(socket, 'wss://localhost/ws/' + id);
        const media = await w.FMAuth.mediaUrl('/fixture/image.png');
        assert.equal(media.includes('fixture-owner'), false); assert.ok(media.includes('accessTicket=' + id));
        await assert.rejects(w.FMAuth.socketUrl('wss://other.invalid/fixture-owner'), /externo/);
        const terminalSource = fs.readFileSync(path.resolve(__dirname, '../../plugins/Request/modules/modalEditCode.html'), 'utf8');
        const start = terminalSource.indexOf('                    window.runCommand =');
        w.eval(terminalSource.slice(start, terminalSource.indexOf('                    // ---- "+" button', start)));
        let sent;
        w.runCommand({readyState: w.WebSocket.OPEN, url: socket, send: raw => { sent = JSON.parse(raw); }}, 'synthetic-input');
        assert.equal(sent.command, 'synthetic-input'); assert.equal(sent.isCodex, true);
        assert.equal(Object.hasOwn(sent, 'token'), false, 'terminal input uses the authenticated connection rather than interpreting a ticket as the owner');

    } finally { w.close(); }
});

function transport(fetch) {
    const dom = new JSDOM('<div id="fp">fixture-owner</div>', {url: 'https://localhost/', runScripts: 'outside-only'});
    dom.window.Request = Request; dom.window.Headers = Headers;
    dom.window.fetch = fetch;
    dom.window.eval(fs.readFileSync(path.resolve(__dirname, '../../js/auth-transport.js'), 'utf8'));
    return dom;
}
test('concurrent startup consumers validate once before requesting tickets', async () => {
    const calls = [], id = '2'.repeat(64);
    let release;
    const checking = new Promise(resolve => { release = resolve; });
    const dom = transport(async url => {
        calls.push(url);
        if (url === '/checkToken') { await checking; return {ok: true, json: async () => ({success: true})}; }
        return {ok: true, json: async () => ({ticket: id})};
    });
    try {
        const a = dom.window.FMAuth.socketUrl('wss://localhost/fixture-owner');
        const b = dom.window.FMAuth.socketUrl('wss://localhost/fixture-owner-lsp');
        await new Promise(resolve => setTimeout(resolve, 10));
        assert.deepEqual(calls, ['/checkToken']);
        release(); await Promise.all([a, b]);
        assert.deepEqual(calls, ['/checkToken', '/accessTicket', '/accessTicket']);
    } finally { release(); dom.window.close(); }
});
test('401 and 403 stop authorization retries and never issue startup tickets', async () => {
    for (const status of [401, 403]) {
        const calls = [];
        const dom = transport(async url => { calls.push(url); return {ok: false, status}; });
        try {
            for (let i = 0; i < 3; i++) {
                await assert.rejects(dom.window.FMAuth.socketUrl('wss://localhost/fixture-owner'), error => {
                    assert.equal(error.status, status);
                    assert.equal(dom.window.FMAuth.shouldRetry(error), false);
                    return true;
                });
            }
            assert.deepEqual(calls, ['/checkToken']);
        } finally { dom.window.close(); }
    }
});
test('revoked access after validation stops ticket retries and reports an auth error', async () => {
    const calls = [];
    const dom = transport(async url => {
        calls.push(url);
        return url === '/checkToken' ? {ok: true, json: async () => ({success: true})} : {ok: false, status: 401};
    });
    let denied = 0;
    dom.window.addEventListener('fm:auth-denied', () => denied++);
    try {
        await assert.rejects(dom.window.FMAuth.socketUrl('wss://localhost/fixture-owner'), error => error.status === 401);
        await assert.rejects(dom.window.FMAuth.socketUrl('wss://localhost/fixture-owner-lsp'), error => error.status === 401);
        assert.deepEqual(calls, ['/checkToken', '/accessTicket']);
        assert.equal(denied, 1);
        assert.equal(dom.window.FMAuth.shouldRetry(), false);
    } finally { dom.window.close(); }
});
test('transient authorization failures can be retried', async () => {
    let calls = 0;
    const dom = transport(async () => ++calls === 1 ? {ok: false, status: 503} : {ok: true, json: async () => ({success: true})});
    try {
        await assert.rejects(dom.window.FMAuth.ready(), error => dom.window.FMAuth.shouldRetry(error));
        assert.equal((await dom.window.FMAuth.ready()).success, true);
        assert.equal(calls, 2);
    } finally { dom.window.close(); }
});
