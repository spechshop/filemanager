'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM} = require('jsdom');
const source = fs.readFileSync(path.resolve(__dirname, '../../plugins/Request/modules/fileManagerSettings.html'), 'utf8');
const settle = () => new Promise(resolve => setTimeout(resolve, 20));
function fixture() {
    const dom = new JSDOM('<div id="fp">fixture-owner</div>' + source, {url: 'https://panel.example', runScripts: 'outside-only'});
    const w = dom.window, calls = [], timers = [];
    let entries = [], copied, pending;
    w.fetch = async (url, options) => {
        calls.push({url, options});
        if (options.method === 'GET') return {ok: true, json: async () => ({success: true, tunnels: entries})};
        if (pending) await pending;
        return {ok: true, json: async () => ({success: true, command: "curl -fsS 'https://panel.example/tunnel/fixture' | bash", message: 'Salvo'})};
    };
    w.setInterval = fn => (timers.push(fn), timers.length);
    w.clearInterval = () => {};
    w.Swal = {fire: async () => ({isConfirmed: true})};
    Object.defineProperty(w.navigator, 'clipboard', {value: {writeText: async value => {copied = value;}}});
    w.eval(source.match(/<script>([\s\S]*?)<\/script>/)[1]);
    return {dom, w, d: w.document, calls, timers, entries: value => {entries = value;}, pending: value => {pending = value;}, copied: () => copied};
}
test('tunnel settings create a scoped command, poll live links, copy and safely render remote values', async () => {
    const f = fixture(), {d, w} = f;
    try {
        d.querySelector('[data-settings-pane="settingsTunnelsPane"]').click(); await settle();
        assert.equal(d.getElementById('saveFileManagerSettings').classList.contains('d-none'), true);
        assert.match(d.getElementById('tunnelsList').textContent, /Nenhum túnel/);
        d.getElementById('tunnelHost').value = 'https://localhost';
        d.getElementById('tunnelPort').value = '8443';
        d.getElementById('tunnelNoTlsVerify').checked = true;
        d.getElementById('tunnelForm').dispatchEvent(new w.Event('submit', {cancelable: true})); await settle();
        const create = f.calls.find(call => call.options.method === 'POST');
        const body = new URLSearchParams(create.options.body);
        assert.equal(body.get('localHost'), 'https://localhost'); assert.equal(body.get('localPort'), '8443');
        assert.equal(body.get('noTlsVerify'), 'true'); assert.equal(body.get('panelUrl'), 'https://panel.example');
        assert.equal(create.options.headers.Authorization, 'Bearer fixture-owner');
        assert.equal(create.url.includes('tokenBrowser'), false);
        d.getElementById('copyTunnelCommand').click(); await settle();
        assert.equal(f.copied(), "curl -fsS 'https://panel.example/tunnel/fixture' | bash");
        assert.match(d.getElementById('tunnelPanelAddress').textContent, /https:\/\/panel.example/);
        f.entries([{id: 'demo', name: '<img src=x onerror=alert(1)>', target: 'https://localhost:8443', status: 'online', url: 'https://demo.trycloudflare.com', message: '<script>bad()</script>'}]);
        await f.timers.at(-1)();
        assert.equal(d.querySelectorAll('#tunnelsList img, #tunnelsList script').length, 0);
        assert.equal(d.querySelector('#tunnelsList a').href, 'https://demo.trycloudflare.com/');
        d.querySelector('[data-tunnel-action="copy"]').click(); await settle();
        assert.equal(f.copied(), 'https://demo.trycloudflare.com');
        f.entries([{id: 'bad', name: 'bad', target: 'localhost', status: 'online', url: 'javascript:alert(1)'}]); await f.timers.at(-1)();
        assert.equal(d.querySelector('#tunnelsList a'), null);
        d.getElementById('fileManagerSettingsModal').dispatchEvent(new w.Event('hidden.bs.modal'));
        assert.equal(d.getElementById('tunnelCommand').value, ''); assert.equal(d.getElementById('tunnelsList').children.length, 0);
    } finally { w.close(); }
});
test('a command returned after closing settings stays hidden', async () => {
    const f = fixture(), {d, w} = f;
    let release;
    try {
        d.querySelector('[data-settings-pane="settingsTunnelsPane"]').click(); await settle();
        f.pending(new Promise(resolve => {release = resolve;}));
        d.getElementById('tunnelForm').dispatchEvent(new w.Event('submit', {cancelable: true}));
        d.getElementById('fileManagerSettingsModal').dispatchEvent(new w.Event('hidden.bs.modal'));
        release(); await settle();
        assert.equal(d.getElementById('tunnelCommand').value, '');
        assert.equal(d.getElementById('tunnelCommandBox').classList.contains('d-none'), true);
    } finally { release?.(); w.close(); }
});
