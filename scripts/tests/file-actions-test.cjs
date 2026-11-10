const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM} = require('jsdom');
const root = path.resolve(__dirname, '../..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const tick = () => new Promise(resolve => setImmediate(resolve));

function browser() {
    const dom = new JSDOM('<div id="fp">fixture-owner</div><table id="tableListFiles"><tbody id="filesList"></tbody></table><div id="viewGridFiles"></div>', {url: 'http://localhost', runScripts: 'dangerously'});
    const w = dom.window, calls = [], requests = [];
    w.eval(read('js/security-ui.js'));
    w.HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', ''); };
    w.HTMLDialogElement.prototype.close = function () { this.removeAttribute('open'); this.dispatchEvent(new w.Event('close')); };
    w.TextDecoder = TextDecoder;
    for (const fn of ['showEditor', 'showImage', 'setPath', 'openMediaGallery', 'renameItem', 'deleteFile', 'downloadFile', 'toastLotus']) w[fn] = (...args) => calls.push({fn, args});
    w.fetch = (url, options) => new Promise(resolve => requests.push({url, options, resolve}));
    w.marked = {parse(text, options) { calls.push({fn: 'markdown', args: [text, options]}); return '<h1>Documento</h1><p><strong>Texto</strong></p><table><tr><td>Valor</td></tr></table>'; }};
    w.eval(read('js/file-actions.js'));
    const source = read('plugins/Request/modules/makeTable/preloadScripts.html');
    w.allImagePaths = []; w.isImageExtension = () => false;
    w.eval(source.slice(source.indexOf('    function setItemsInTbody('), source.indexOf('    // Funções para sortable')));
    return {dom, w, calls, requests};
}
function list(w, path, typeFile) {
    w.setItemsInTbody([{path, name: path.split('/').pop(), typeFile, size: '1 B', permissions: 'rw', lastModified: 'now'}], 'filesList');
    return w.document.querySelector('td[fullpath]');
}
function rightClick(w, target) {
    const event = new w.MouseEvent('contextmenu', {bubbles: true, cancelable: true, clientX: 10000, clientY: 10000});
    target.dispatchEvent(event); return event;
}
function menuAction(w, label) { return [...w.document.querySelectorAll('#fileContextMenu button')].find(button => button.textContent === label); }

test('right-click names and icons opens a menu and copies exact hostile paths without opening the file', async () => {
    const {dom, w, calls} = browser();
    const copied = [];
    Object.defineProperty(w.navigator, 'clipboard', {value: {writeText: async text => copied.push(text)}});
    try {
        const path = '/docs/a <img src=x onerror=alert(1)> & "á".txt';
        const cell = list(w, path, 'txt');
        assert.equal(rightClick(w, cell.querySelector('img')).defaultPrevented, true);
        assert.equal(calls.length, 0);
        assert.equal(w.document.querySelectorAll('#fileContextMenu img, #fileContextMenu script').length, 0);
        assert.ok(parseInt(w.document.getElementById('fileContextMenu').style.left) < w.innerWidth);
        menuAction(w, 'Copiar caminho').click(); await tick();
        assert.deepEqual(copied, [path]);
        assert.equal(w.document.getElementById('fileContextMenu'), null);
        assert.equal(w.document.activeElement, cell);
        rightClick(w, cell.querySelector('span'));
        menuAction(w, 'Copiar caminho da pasta pai').click(); await tick();
        assert.equal(copied[1], '/docs');
        assert.equal(calls.filter(call => call.fn === 'showEditor').length, 0);
    } finally { w.close(); }
});

test('folder and grid menus dispatch the selected path and support keyboard dismissal', async () => {
    const {dom, w, calls} = browser();
    try {
        list(w, '/album/nested', 'folder');
        const gridItem = w.document.createElement('div'); gridItem.tabIndex = 0;
        gridItem.dataset.fullpath = '/album/nested'; gridItem.innerHTML = '<img><span>nested</span>';
        w.document.getElementById('viewGridFiles').append(gridItem);
        w.document.getElementById('viewGridFiles').style.display = 'grid';
        rightClick(w, gridItem.querySelector('img'));
        assert.equal(menuAction(w, 'Editar arquivo'), undefined);
        menuAction(w, 'Abrir galeria desta pasta').click(); await tick();
        assert.deepEqual(calls[0], {fn: 'openMediaGallery', args: ['/album/nested']});
        gridItem.dispatchEvent(new w.KeyboardEvent('keydown', {key: 'F10', shiftKey: true, bubbles: true, cancelable: true}));
        const menu = w.document.getElementById('fileContextMenu');
        menu.dispatchEvent(new w.KeyboardEvent('keydown', {key: 'End', bubbles: true}));
        assert.equal(w.document.activeElement.textContent, 'Mover para a lixeira');
        menu.dispatchEvent(new w.KeyboardEvent('keydown', {key: 'Escape', bubbles: true}));
        assert.equal(w.document.getElementById('fileContextMenu'), null);
        assert.equal(w.document.activeElement, gridItem);
        assert.equal(calls.filter(call => call.fn === 'deleteFile').length, 0);
        rightClick(w, gridItem); menuAction(w, 'Renomear').click(); await tick();
        assert.deepEqual(calls.at(-1), {fn: 'renameItem', args: ['/album/nested']});
    } finally { dom.window.close(); }
});

test('Markdown opens rendered preview on click and the edit button preserves the exact path', async () => {
    const {dom, w, calls, requests} = browser();
    try {
        const path = '/docs/guia & notas.MD';
        const cell = list(w, path, 'MD');
        cell.querySelector('div').click();
        assert.equal(requests.length, 1);
        assert.equal(new URL(requests[0].url, 'http://localhost').searchParams.get('path'), path);
        assert.equal(requests[0].options.headers.Authorization, 'Bearer fixture-owner');
        assert.equal(requests[0].url.includes('fixture-owner'), false);
        requests[0].resolve({ok: true, json: async () => ({success: true, information: '# Documento\n\n**Texto**'})});
        await tick();
        const frame = w.document.getElementById('documentViewerFrame');
        assert.match(frame.srcdoc, /<h1>Documento<\/h1>/);
        assert.equal(frame.hidden, false);
        assert.equal(w.document.getElementById('documentViewerStatus').hidden, true);
        assert.equal(calls.find(call => call.fn === 'markdown').args[0], '# Documento\n\n**Texto**');
        [...w.document.querySelectorAll('#documentViewer button')].find(button => button.textContent === 'Editar').click();
        assert.equal(w.document.getElementById('documentViewer').open, false);
        assert.deepEqual(calls.at(-1), {fn: 'showEditor', args: ['md', path]});
        assert.equal(w.document.body.style.overflow, '');
    } finally { w.close(); }
});

test('HTML previews preserve layout but isolate active content and remove navigation tricks', async () => {
    const {dom, w, requests} = browser();
    try {
        const opening = w.FMFileActions.preview('/index.html');
        requests[0].resolve({ok: true, json: async () => ({success: true, information: '<html><head><style>h1{color:red}</style><meta http-equiv="refresh" content="0;url=/deleteFile"><base href="https://evil.invalid"></head><body><h1 onclick="parent.pwned=1">Página</h1><script>parent.pwned=1</script><iframe srcdoc="bad"></iframe><a href="javascript:alert(1)">link</a></body></html>'})});
        await opening;
        const frame = w.document.getElementById('documentViewerFrame');
        assert.equal(frame.sandbox?.contains?.('allow-scripts') || frame.getAttribute('sandbox').includes('allow-scripts'), false);
        assert.equal(frame.getAttribute('sandbox').includes('allow-same-origin'), false);
        const parsed = new JSDOM(frame.srcdoc);
        try {
            assert.equal(parsed.window.document.querySelector('h1').textContent, 'Página');
            assert.match(frame.srcdoc, /h1\{color:red\}/);
            assert.equal(parsed.window.document.querySelectorAll('script, iframe, [onclick], base, meta[http-equiv="refresh"]').length, 0);
            assert.equal(parsed.window.document.querySelector('a').hasAttribute('href'), false);
            assert.match(parsed.window.document.querySelector('meta[http-equiv="Content-Security-Policy"]').content, /script-src 'none'/);
            assert.equal(w.pwned, undefined);
        } finally { parsed.window.close(); }
    } finally { dom.window.close(); }
});

test('stale preview responses cannot overwrite another document or reopen a closed viewer', async () => {
    const {dom, w, requests} = browser();
    try {
        const first = w.FMFileActions.preview('/first.html');
        const second = w.FMFileActions.preview('/second.html');
        assert.equal(requests[0].options.signal.aborted, true);
        requests[1].resolve({ok: true, json: async () => ({success: true, information: '<h1>Second</h1>'})});
        await second;
        requests[0].resolve({ok: true, json: async () => ({success: true, information: '<h1>First</h1>'})});
        await first;
        assert.match(w.document.getElementById('documentViewerFrame').srcdoc, /Second/);
        const third = w.FMFileActions.preview('/third.html');
        w.document.getElementById('documentViewerClose').click();
        requests[2].resolve({ok: true, json: async () => ({success: true, information: 'late'})});
        await third;
        assert.equal(w.document.getElementById('documentViewer').open, false);
        assert.equal(w.document.getElementById('documentViewerFrame').hasAttribute('srcdoc'), false);
        const failed = w.FMFileActions.preview('/denied.html');
        requests[3].resolve({ok: false}); await failed;
        assert.match(w.document.getElementById('documentViewerStatus').textContent, /Não foi possível/);
        assert.equal(w.document.getElementById('documentViewerFrame').hidden, true);
    } finally { w.close(); }
});
