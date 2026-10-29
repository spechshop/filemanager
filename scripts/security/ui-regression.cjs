'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM} = require('jsdom');
const ROOT = process.env.SECURITY_SOURCE || path.resolve(__dirname, '../..');
const read = file => fs.readFileSync(path.join(ROOT, file), 'utf8');
function browser() {
    const dom = new JSDOM('<div id="fp">owner</div><div id="visualPath"></div><div id="pathInvisible"></div><div id="path"></div><table id="tableListFiles"><tbody id="filesList"></tbody></table><div id="viewGridFiles"></div>', {url: 'http://localhost', runScripts: 'dangerously'});
    dom.window.eval(fs.readFileSync(path.resolve(__dirname, '../../js/security-ui.js'), 'utf8'));
    dom.window.$ = () => ({text: () => 'owner'});
    return dom;
}
const payloads = ['constructor', '__proto__', 'toString', 'hasOwnProperty', '<img src=x onerror="window.pwned=1">', "x');window.pwned=1;//", '"><svg onload="window.pwned=1">', '` ${window.pwned=1}', 'a&b"\'<>'];
test('stored filenames render as text; clicking keeps the complete filename as data', () => {
    const dom = browser(), w = dom.window;
    try {
        const source = read('plugins/Request/modules/makeTable/preloadScripts.html');
        w.eval(source.slice(source.indexOf('    function setItemsInTbody'), source.indexOf('    // Funções para sortable')));
        w.isImageExtension = () => false; w.allImagePaths = [];
        for (const payload of payloads) {
            const target = '/workspace/' + payload;
            let clicked;
            w.showEditor = (ext, file) => { clicked = file; };
            w.setItemsInTbody([{name: payload, path: target, typeFile: 'txt', size: '1 B', permissions: 'rw', lastModified: 'now'}], 'filesList');
            const tbody = w.document.getElementById('filesList');
            assert.equal(tbody.querySelector('td[fullpath] span').textContent, payload);
            assert.equal(tbody.querySelector('input').value, target);
            assert.equal(tbody.querySelectorAll('svg,script,[onerror]').length, 0);
            tbody.querySelector('td[fullpath] div').click();
            assert.equal(clicked, target);
            assert.equal(w.pwned, undefined);
        }
    } finally { dom.window.close(); }
});
test('stored chat markup and crafted markdown fences cannot create executable nodes', () => {
    const dom = browser(), w = dom.window;
    try {
        const source = read('plugins/Request/modules/utils/chat4o.html');
        w.eval(source.slice(source.indexOf('function parseMessage'), source.indexOf('</script>', source.indexOf('function parseMessage'))));
        for (const payload of payloads) {
            for (const message of [payload, '# ' + payload, '> ' + payload, '- ' + payload, '```x" onmouseover="window.pwned=1\n' + payload + '\n```']) {
                const node = w.parseMessage(message);
                w.document.body.append(node);
                assert.equal(node.querySelectorAll('img,svg,script,[onmouseover],[onerror]').length, 0);
                assert.equal(w.pwned, undefined);
                node.remove();
            }
        }
        assert.equal(w.parseMessage('**bold**').querySelector('b').textContent, 'bold');
    } finally { w.close(); }
});
test('reflected paths and search highlighting stay text, including regex metacharacters', () => {
    const dom = browser(), w = dom.window;
    try {
        const source = read('plugins/Request/modules/makeTable/preloadScripts.html');
        w.adjustPath = () => {};
        w.eval(read('js/file-list.js'));
        w.eval(source.slice(source.indexOf('    function setPath('), source.indexOf('    function backPath(')));
        for (const payload of payloads) {
            const value = '/workspace/' + payload;
            w.setPath(value);
            assert.equal(w.document.getElementById('pathInvisible').textContent, value);
            assert.equal(w.document.querySelectorAll('svg,script,[onerror]').length, 0);
            const span = w.document.createElement('span');
            w.FMUI.highlight(span, payload, '[');
            assert.equal(span.textContent, payload);
            assert.equal(span.querySelectorAll('img,svg,script').length, 0);
            assert.equal(w.pwned, undefined);
        }
    } finally { w.close(); }
});
test('file tree preserves hostile names and inline arguments without code execution', async () => {
    const dom = browser(), w = dom.window;
    try {
        const source = read('plugins/Request/modules/loadingPage.html');
        w.getIconFile = () => '';
        w.eval(source.slice(source.indexOf('    async function buildTreeHtml'), source.indexOf("    document.addEventListener('click'")));
        w.FileManagerWorkspaceRoot = '/authorized/workspace/';
        const rootTree = w.document.createElement('div');
        rootTree.innerHTML = await w.buildTreeHtml(['safe.txt'], '/');
        assert.equal(rootTree.querySelector('li').getAttribute('file-path'), '/authorized/workspace/safe.txt');
        for (const payload of payloads) {
            const div = w.document.createElement('div');
            div.innerHTML = await w.buildTreeHtml([payload], '/workspace/');
            assert.equal(div.querySelectorAll('svg,script,img,[onerror]').length, 0);
            div.querySelector('li').click();
            assert.equal(w.pwned, undefined);
        }
    } finally { w.close(); }
});
test('notification headers and bodies display untrusted messages as text', () => {
    const dom = browser(), w = dom.window;
    try {
        w.bootstrap = {Toast: class {show() {}}};
        w.eval(read('js/bootstrap-show-toast.js'));
        for (const payload of payloads) {
            w.bootstrap.showToast({header: payload, body: payload, headerSmall: payload});
            assert.equal(w.document.querySelectorAll('svg,script,[onerror]').length, 0);
            assert.ok(w.document.body.textContent.includes(payload));
        }
    } finally { w.close(); }
});
test('stored credentials and server welcome messages never enter HTML sinks', async () => {
    const dom = browser(), w = dom.window;
    try {
        w.eval(fs.readFileSync(path.resolve(__dirname, '../../js/jquery-3.3.1.min.js'), 'utf8'));
        w.jQuery.fn.modal = () => {};
        const welcome = w.document.createElement('div'); welcome.id = 'messageModalWelcome'; w.document.body.append(welcome);
        const source = read('js/jquery.toast.min.js');
        const snippet = source.slice(source.indexOf('function initializeBrowserCredential()'), source.indexOf('}, 800)', source.indexOf('checkTokenBrowser = setTimeout')) + '}, 800)'.length);
        let callback; w.setTimeout = fn => { callback = fn; return 1; }; w.refAll = () => {};
        for (const payload of payloads) {
            w.localStorage.setItem('tokenBrowser', payload);
            w.FMAuth = {ready: async () => ({success: true, message: payload})};
            w.eval(snippet); await callback();
            assert.equal(w.document.getElementById('fp').textContent, payload);
            assert.equal(welcome.textContent, payload);
            assert.equal(w.document.querySelectorAll('svg,script,[onerror]').length, 0);
            assert.equal(w.pwned, undefined);
        }
    } finally { w.close(); }
});

test('new browser credentials use independent random bytes and preserve registered credentials', () => {
    const first = browser(), second = browser();
    try {
        const source = read('js/jquery.toast.min.js');
        const code = source.slice(source.indexOf('function initializeBrowserCredential()'), source.indexOf('checkTokenBrowser = setTimeout'));
        first.window.eval(code); second.window.eval(code);
        const a = first.window.localStorage.getItem('tokenBrowser');
        const b = second.window.localStorage.getItem('tokenBrowser');
        assert.match(a, /^[a-f0-9]{64}$/);
        assert.match(b, /^[a-f0-9]{64}$/);
        assert.notEqual(a, b);
        assert.equal(first.window.initializeBrowserCredential(), a);
        first.window.localStorage.setItem('tokenBrowser', 'previously-registered');
        assert.equal(first.window.initializeBrowserCredential(), 'previously-registered');
        assert.equal(first.window.document.getElementById('fp').textContent, 'previously-registered');
    } finally { first.window.close(); second.window.close(); }
});

test('denied access and network failures leave loading and show an actionable auth screen', async () => {
    for (const status of [401, 403, 0]) {
        const dom = browser(), w = dom.window;
        try {
            w.document.body.insertAdjacentHTML('beforeend', read('plugins/Request/modules/angryPart.html') + '<div id="loadingPage"></div><div id="navContent"></div>');
            w.eval(read('js/jquery-3.3.1.min.js'));
            w.localStorage.setItem('tokenBrowser', 'registered-or-new-credential');
            w.FMAuth = {ready: async () => { throw Object.assign(new Error('denied'), {status}); }};
            const source = read('js/jquery.toast.min.js');
            let callback;
            w.setTimeout = fn => { callback = fn; return 1; };
            w.eval(source.slice(source.indexOf('function initializeBrowserCredential()'), source.indexOf('\nfunction copyDivToClipboard')));
            await callback();
            assert.equal(w.document.getElementById('loadingPage').style.display, 'none');
            assert.equal(w.document.getElementById('navContent').style.display, 'none');
            assert.equal(w.document.getElementById('angryPart').style.display, 'block');
            assert.equal(w.document.getElementById('browserCredential').textContent, 'registered-or-new-credential');
            assert.match(w.document.getElementById('authErrorTitle').textContent, status === 401 ? /não autorizado/ : status === 403 ? /bloqueado/ : /verificar/);
        } finally { w.close(); }
    }
});


test('lotus notifications stack safely and close or expire independently', () => {
    const dom = browser(), w = dom.window;
    try {
        const source = read('plugins/Request/modules/includes/head.html');
        const start = source.indexOf('    let toast;');
        const end = source.indexOf('    toastLotus = toast;') + '    toastLotus = toast;'.length;
        const timers = new Map();
        let nextTimer = 0;
        w.setInterval = callback => { timers.set(++nextTimer, callback); return nextTimer; };
        w.clearInterval = id => timers.delete(id);
        w.eval(source.slice(start, end));
        const payload = '<img src=x onerror="window.pwned=1">';
        w.toastLotus(payload, payload, 10, 'success');
        w.toastLotus('Segundo aviso', 'Informação', 10, 'info');
        const container = w.document.getElementById('lotus-toast-container');
        assert.equal(container.children.length, 2);
        assert.ok(container.textContent.includes(payload));
        assert.equal(container.querySelectorAll('img,script,[onerror]').length, 0);
        container.querySelector('button[aria-label="Fechar aviso"]').click();
        assert.equal(container.children.length, 1);
        assert.ok(container.textContent.includes('Segundo aviso'));
        for (const callback of [...timers.values()]) callback();
        assert.equal(container.children.length, 0);
        w.toastLotus('Novo aviso', 'Informação', 10, 'info');
        assert.equal(w.document.querySelectorAll('#lotus-toast-container').length, 1);
        assert.equal(container.children.length, 1);
    } finally { w.close(); }
});
