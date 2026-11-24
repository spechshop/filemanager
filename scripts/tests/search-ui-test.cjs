'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM} = require('jsdom');

const root = path.resolve(__dirname, '../..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const page = new JSDOM(read('plugins/Request/pages/index.html'));
const panel = page.window.document.getElementById('offcanvasExample').outerHTML;
page.window.close();
const source = read('plugins/Request/modules/includes/offCanvas.html').match(/<script>([\s\S]*?)<\/script>/)[1];
const tick = () => new Promise(resolve => setImmediate(resolve));

function browser() {
    const dom = new JSDOM(`<div id="fp">owner-with-hyphen</div><div id="pathInvisible">/files/a & b</div>${panel}`, {
        url: 'https://localhost:8080', runScripts: 'outside-only',
    });
    const w = dom.window, sockets = [], addresses = [], alerts = [];
    class Socket extends w.EventTarget {
        static CONNECTING = 0; static OPEN = 1; static CLOSING = 2; static CLOSED = 3;
        constructor(url) { super(); this.url = url; this.readyState = Socket.CONNECTING; this.sent = []; sockets.push(this); }
        open() { this.readyState = Socket.OPEN; this.onopen(); this.dispatchEvent(new w.Event('open')); }
        send(data) { this.sent.push(JSON.parse(data)); }
        close() { this.readyState = Socket.CLOSED; this.onclose(); this.dispatchEvent(new w.Event('close')); }
        receive(payload) {
            const requestId = this.sent.filter(item => item.action === 'start').at(-1)?.requestId;
            this.onmessage({data: JSON.stringify({searchInFile: true, payload: {requestId, ...payload}})});
        }
    }
    w.WebSocket = Socket;
    w.FMAuth = {socketUrl: async address => { addresses.push(address); return 'wss://localhost:8080/ws/ticket'; }};
    w.Swal = {fire: options => alerts.push(options)};
    w.getIconFile = () => '';
    w.eval(read('js/jquery-3.3.1.min.js'));
    w.eval(source);
    const el = id => w.document.getElementById(id);
    async function start() {
        el('contentOffCanvasSearch').value = 'KEY=';
        w.searchInternal();
        await tick();
        const socket = sockets.at(-1);
        socket.open();
        await tick();
        return socket;
    }
    return {dom, w, el, sockets, addresses, alerts, start};
}

test('search uses the authorized socket identity for start, pause, resume and cancel', async () => {
    const b = browser();
    try {
        const socket = await b.start();
        assert.deepEqual(b.addresses, ['wss://localhost:8080/owner-with-hyphen-search']);
        assert.equal(socket.url, 'wss://localhost:8080/ws/ticket');
        assert.match(socket.sent[0].requestId, /^s[0-9]+-[a-z0-9]+$/);
        assert.deepEqual(socket.sent[0], {
            searchInFile: true, action: 'start', token: 'owner-with-hyphen-search',
            requestId: socket.sent[0].requestId,
            search: 'KEY=', path: '/files/a & b', limit: 1000,
        });
        b.w.toggleSearchPause();
        b.w.toggleSearchPause();
        b.w.cancelSearch();
        assert.deepEqual(socket.sent.map(command => command.action), ['start', 'pause', 'resume', 'cancel']);
        assert.ok(socket.sent.every(command => command.token === 'owner-with-hyphen-search'));
        assert.ok(socket.sent.every(command => command.requestId === socket.sent[0].requestId));
    } finally { b.dom.window.close(); }
});

test('live results finish with counts and remain available when the connection closes', async () => {
    const b = browser();
    try {
        const socket = await b.start();
        socket.receive({type: 'status', status: 'running', message: 'Busca iniciada.'});
        socket.receive({type: 'result', item: {path: '/files/a & b/.env', sizeLabel: '42 B'}, found: 1, scanned: 2});
        assert.match(b.el('listFoundSearch').textContent, /\.env/);
        socket.receive({type: 'status', status: 'complete', found: 1, scanned: 2});
        const status = b.el('searchStatus').textContent;
        assert.match(status, /concluída.*encontrados: 1.*analisados: 2/);
        assert.equal(b.el('searchPauseBtn').disabled, true);
        assert.equal(b.el('searchCancelBtn').disabled, true);
        socket.close();
        assert.equal(b.el('searchStatus').textContent, status);
    } finally { b.dom.window.close(); }
});

test('disconnecting an active search stops the waiting state and permits a new connection', async () => {
    const b = browser();
    try {
        const socket = await b.start();
        socket.close();
        assert.match(b.el('searchStatus').textContent, /conexão.*encerrada/);
        assert.equal(b.el('searchPauseBtn').disabled, true);
        assert.equal(b.el('searchCancelBtn').disabled, true);
        assert.equal(b.el('searchStartBtn').disabled, false);
        const next = await b.start();
        assert.notEqual(next, socket);
        assert.equal(next.sent[0].action, 'start');
    } finally { b.dom.window.close(); }
});

test('closing before the connection opens reports an error instead of waiting forever', async () => {
    const b = browser();
    try {
        b.w.beginSearch('KEY=');
        await tick();
        b.sockets[0].close();
        await tick();
        assert.match(b.el('searchStatus').textContent, /Não foi possível iniciar/);
        assert.equal(b.el('searchCancelBtn').disabled, true);
        assert.equal(b.alerts.length, 1);
    } finally { b.dom.window.close(); }
});

test('authorization failures leave a visible error and restore the controls', async () => {
    const b = browser();
    try {
        b.w.FMAuth.socketUrl = async () => { throw new Error('Unauthorized'); };
        b.w.beginSearch('KEY=');
        await tick();
        assert.match(b.el('searchStatus').textContent, /Não foi possível iniciar/);
        assert.equal(b.sockets.length, 0);
        assert.equal(b.el('searchCancelBtn').disabled, true);
        assert.equal(b.alerts.length, 1);
    } finally { b.dom.window.close(); }
});

test('rapid repeated starts share the connection and only start the latest request', async () => {
    const b = browser();
    try {
        b.w.beginSearch('OLD');
        b.w.beginSearch('NEW');
        await tick();
        assert.equal(b.addresses.length, 1, 'only one socket ticket should be issued');
        assert.equal(b.sockets.length, 1);
        const socket = b.sockets[0];
        socket.open();
        await tick();
        assert.equal(socket.sent.length, 1);
        assert.equal(socket.sent[0].search, 'NEW');
        assert.equal(b.el('searchCancelBtn').disabled, false);
    } finally { b.dom.window.close(); }
});

test('stale events and old completion cannot overwrite a replacement search', async () => {
    const b = browser();
    try {
        const socket = await b.start();
        const oldId = socket.sent[0].requestId;
        b.w.beginSearch('NEW');
        await tick();
        assert.equal(socket.sent.length, 2);
        const freshId = socket.sent[1].requestId;
        assert.notEqual(freshId, oldId);
        socket.receive({requestId: oldId, type: 'result', item: {path: '/files/old.txt'}, found: 1, scanned: 1});
        socket.receive({requestId: oldId, type: 'status', status: 'complete', found: 1, scanned: 1});
        assert.equal(b.el('listFoundSearch').textContent.includes('old.txt'), false);
        assert.equal(b.el('searchCancelBtn').disabled, false);
        socket.receive({requestId: freshId, type: 'result', item: {path: '/files/new.txt'}, found: 1, scanned: 2});
        assert.ok(b.el('listFoundSearch').textContent.includes('new.txt'));
        socket.receive({requestId: freshId, type: 'status', status: 'complete', found: 1, scanned: 2});
        assert.equal(b.el('searchCancelBtn').disabled, true);
        assert.match(b.el('searchStatus').textContent, /concluída.*encontrados: 1/);
    } finally { b.dom.window.close(); }
});

test('cancel while connecting prevents any delayed start command', async () => {
    const b = browser();
    try {
        b.w.beginSearch('TO-CANCEL');
        await tick();
        const socket = b.sockets[0];
        b.w.cancelSearch();
        socket.open();
        await tick();
        assert.equal(socket.sent.length, 0);
        assert.equal(b.el('searchCancelBtn').disabled, true);
        b.w.beginSearch('RETRY');
        await tick();
        assert.equal(socket.sent.length, 1);
        assert.equal(socket.sent[0].search, 'RETRY');
    } finally { b.dom.window.close(); }
});

test('a rejected search from the server is not confused with the previous request', async () => {
    const b = browser();
    try {
        const socket = await b.start();
        const first = socket.sent[0].requestId;
        b.w.beginSearch('INVALID-PATH');
        await tick();
        const second = socket.sent[1].requestId;
        socket.receive({type:'status', status:'error', requestId:second, message:'A pasta de busca não está disponível ou não é permitida.'});
        assert.match(b.el('searchStatus').textContent, /não está disponível/);
        assert.equal(b.el('searchCancelBtn').disabled, true);
        socket.receive({type:'status', status:'complete', requestId:first, found:2, scanned:10});
        assert.match(b.el('searchStatus').textContent, /não está disponível/);
    } finally { b.dom.window.close(); }
});
