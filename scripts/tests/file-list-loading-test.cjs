'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM} = require('jsdom');

const root = path.resolve(__dirname, '../..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const page = read('plugins/Request/pages/index.html');
const parsed = new JSDOM(page);
const panel = parsed.window.document.getElementById('fileListPanel').outerHTML;
parsed.window.close();
const styles = [...page.matchAll(/<style>([\s\S]*?)<\/style>/g)].map(match => match[1]).filter(style => style.includes('#fileListPanel')).join('\n');
const tick = () => new Promise(resolve => setImmediate(resolve));
const items = file => [{name: file.split('/').pop(), path: file, typeFile: 'txt', size: '1 B', permissions: 'rw', lastModified: 'now'}];

function browser(mode = 'table') {
    const dom = new JSDOM(`<style>${styles}</style><div id="fp">owner</div><div id="pathInvisible">/old</div><div id="visualPath"></div>${panel}`, {
        url: 'http://localhost', runScripts: 'dangerously',
    });
    const w = dom.window;
    w.console.log = () => {};
    w.eval(read('js/security-ui.js'));
    w.eval(read('js/file-list.js'));
    const source = read('plugins/Request/modules/makeTable/preloadScripts.html');
    w.allImagePaths = [];
    w.eval(source.slice(source.indexOf('    function isImageExtension('), source.indexOf('    // Funções para sortable')));
    w.eval(source.slice(source.indexOf('    function setPath('), source.indexOf('    async function showCompressFile(')));
    const toggles = read('plugins/Request/modules/utils/toglleAllItens.html');
    w.eval(toggles.slice(toggles.indexOf('    function adjustPath('), toggles.indexOf("    document.addEventListener('DOMContentLoaded'")));
    w.eval(toggles.slice(toggles.indexOf('    window.setStyleIconsBox'), toggles.indexOf('</script>')));
    w.localStorage.setItem('viewMode', mode);
    w.setItemsInTbody(items('/old/old.txt'), 'filesList');
    const requests = [];
    w.fetch = (url, options) => new Promise((resolve, reject) => requests.push({url, signal: options.signal, resolve, reject}));
    const observer = new w.MutationObserver(() => w.FMFileList.load());
    observer.observe(w.document.getElementById('pathInvisible'), {childList: true});
    return {dom, w, requests, el: id => w.document.getElementById(id)};
}

function respond(request, directory, file = directory + '/new.txt') {
    request.resolve({ok: true, json: async () => ({success: true, currentPath: directory, information: items(file)})});
}

for (const mode of ['table', 'grid']) {
    test(`folder loading hides old ${mode} files immediately and preserves the chosen view`, async () => {
        const {dom, w, requests, el} = browser(mode);
        try {
            el('optionsTableListFiles').querySelector('input').checked = true;
            w.setPath('/home/lotus/projetos');
            assert.equal(el('fileListPanel').dataset.state, 'loading');
            assert.equal(el('fileListStatus').hidden, false);
            assert.equal(el('filesList').childElementCount, 0);
            assert.equal(el('viewGridFiles').childElementCount, 0);
            assert.equal(w.currentFileItems.length, 0);
            assert.equal(el('optionsTableListFiles').querySelector('input').checked, false);
            assert.equal(w.getComputedStyle(el('tableListFiles')).display, 'none');
            assert.equal(w.getComputedStyle(el('viewGridFiles')).display, 'none');
            const loading = w.FMFileList.load();
            await tick();
            assert.equal(requests.length, 1);
            respond(requests[0], '/home/lotus/projetos');
            await loading;
            await tick();
            assert.equal(el('fileListPanel').dataset.state, 'ready');
            assert.equal(el('fileListStatus').hidden, true);
            assert.equal(el('tableListFiles').getAttribute('aria-busy'), 'false');
            assert.equal(el('filesList').querySelector('td[fullpath]').getAttribute('fullpath'), '/home/lotus/projetos/new.txt');
            assert.equal(w.localStorage.getItem('viewMode'), mode);
            assert.equal(w.getComputedStyle(el(mode === 'grid' ? 'viewGridFiles' : 'tableListFiles')).display === 'none', false);
        } finally { dom.window.close(); }
    });
}

test('rapid folder changes cancel old requests and ignore late results and errors', async () => {
    const {dom, w, requests, el} = browser();
    try {
        w.setPath('/first');
        const first = w.FMFileList.load();
        await tick();
        w.setPath('/second');
        const second = w.FMFileList.load();
        await tick();
        assert.equal(requests[0].signal.aborted, true);
        requests[0].reject(new Error('late network failure'));
        await first;
        assert.equal(el('fileListPanel').dataset.state, 'loading');
        w.setPath('/third');
        const third = w.FMFileList.load();
        respond(requests[2], '/third');
        await third;
        respond(requests[1], '/second');
        await second;
        await tick();
        assert.equal(el('pathInvisible').textContent, '/third');
        assert.equal(el('filesList').querySelector('td[fullpath]').getAttribute('fullpath'), '/third/new.txt');
        assert.equal(el('fileListPanel').dataset.state, 'ready');
    } finally { dom.window.close(); }
});

test('network failures stop the spinner and allow retrying the current folder', async () => {
    const {dom, w, requests, el} = browser();
    try {
        w.setPath('/broken');
        const failed = w.FMFileList.load();
        requests[0].reject(new Error('offline'));
        await failed;
        assert.equal(el('fileListPanel').dataset.state, 'error');
        assert.equal(el('fileListSpinner').hidden, true);
        assert.equal(el('fileListRetry').hidden, false);
        assert.equal(el('tableListFiles').getAttribute('aria-busy'), 'false');
        assert.equal(w.getComputedStyle(el('tableListFiles')).display, 'none');
        el('fileListRetry').click();
        const retry = w.FMFileList.load();
        assert.equal(el('fileListSpinner').hidden, false);
        respond(requests[1], '/broken');
        await retry;
        assert.equal(el('fileListStatus').hidden, true);
    } finally { dom.window.close(); }
});

test('server-corrected paths do not reload the listing and special path characters stay encoded', async () => {
    const {dom, w, requests, el} = browser();
    try {
        const directory = '/home/lotus/a&b #1/á';
        w.setPath(directory + '/file.txt');
        const loading = w.FMFileList.load();
        assert.equal(new URL(requests[0].url, 'http://localhost').searchParams.get('path'), directory + '/file.txt');
        respond(requests[0], directory);
        await loading;
        await tick();
        assert.equal(requests.length, 1);
        assert.equal(el('pathInvisible').textContent, directory);
        assert.equal(w.localStorage.getItem('pathInvisible'), directory);
        assert.equal(el('fileListPanel').dataset.state, 'ready');
    } finally { dom.window.close(); }
});
