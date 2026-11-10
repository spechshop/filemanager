'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const {JSDOM} = require('jsdom');
const toast = fs.readFileSync('js/jquery.toast.min.js', 'utf8');
const loading = fs.readFileSync('plugins/Request/modules/loadingPage.html', 'utf8');
function fixture() {
    const dom = new JSDOM('<div id="fp">owner</div><div id="body"></div><div id="navContent"></div><div id="nav-tabContent"></div><div id="angryPart"></div><div id="filesArchitecture"></div>', {url: 'https://localhost', runScripts: 'outside-only'});
    const w = dom.window;
    w.$ = selector => ({text: value => value === undefined ? 'owner' : undefined, modal() {}, css: (key, value) => w.document.querySelectorAll(selector).forEach(e => e.style[key] = value)});
    w.initializeBrowserCredential = () => {};
    w.FMUI = {html: x => x, argument: JSON.stringify};
    w.getIconFile = () => '';
    w.eval(loading.slice(loading.indexOf('    async function fetchFileTree'), loading.indexOf('    let isContextMenuShown')));
    w.setTimeout = fn => { w.start = fn; };
    w.showAuthenticationError = () => { w.denied = true; };
    w.eval(toast.slice(toast.indexOf('checkTokenBrowser = setTimeout'), toast.indexOf('function copyDivToClipboard')));
    return {dom, w};
}
test('a failed listing does not revoke valid access; retry renders directories', async () => {
    const {dom,w} = fixture();
    try {
        w.FMAuth = {ready: async () => ({success: true})};
        w.fetch = async () => ({ok: false, status: 503, headers: new Headers()});
        await w.start();
        assert.equal(w.denied, undefined);
        assert.equal(w.document.getElementById('navContent').style.display, 'block');
        assert.match(w.document.getElementById('filesArchitecture').textContent, /HTTP 503/);
        w.fetch = async () => ({ok: true, headers: new Headers(), json: async () => ({projects: []})});
        await w.refAll();
        assert.ok(w.document.querySelector('#filesArchitecture .folder'));
    } finally { dom.window.close(); }
});
test('invalid credentials still block startup before any listing', async () => {
    const {dom,w} = fixture();
    try {
        w.FMAuth = {ready: async () => {throw {status: 401};}};
        w.fetch = () => {throw new Error('listing must not run');};
        await w.start();
        assert.equal(w.denied, true);
    } finally {dom.window.close();}
});
