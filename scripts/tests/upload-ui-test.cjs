const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM} = require('jsdom');

function fixture() {
    const html = fs.readFileSync(path.join(__dirname, '../../plugins/Request/modules/upload/module.html'), 'utf8');
    const dom = new JSDOM(html + '<div id="fp">token</div><div id="pathInvisible">/files/a & b#c</div>', {runScripts: 'outside-only', url: 'http://localhost'});
    const w = dom.window;
    w.eval(fs.readFileSync(path.join(__dirname, '../../js/jquery-3.3.1.min.js'), 'utf8'));
    const requests = [], alerts = [];
    w.$.ajax = options => requests.push(options);
    w.$.fn.modal = () => {};
    w.asyncGet = async () => ({information: []});
    w.setItemsInTbody = () => {};
    w.Swal = {fire: options => alerts.push(options)};
    w.eval(w.document.querySelector('script').textContent);
    return {dom, w, requests, alerts};
}
const tick = () => new Promise(resolve => setImmediate(resolve));
const select = files => ({type: 'change', target: {files}});
async function finish(f, task) {
    for (let i = 0; ; i++) {
        await tick();
        if (!f.requests[i]) break;
        f.requests[i].success({success: true});
    }
    await task;
}
function fileEntry(file) { return {name: file.name, isFile: true, file: resolve => resolve(file)}; }
function directory(name, pages) {
    return {name, isDirectory: true, createReader() {
        let index = 0;
        return {readEntries: resolve => resolve(pages[index++] || [])};
    }};
}

test('large selections use bounded batches, encoded destination and safe text', async () => {
    const f = fixture();
    try {
        const files = Array.from({length: 1000}, (_, i) => new f.w.File(['data'], i === 0 ? '<img onerror=alert(1)>.txt' : `file-${i}.txt`));
        const task = f.w.handleFiles(select(files));
        await tick();
        assert.equal(f.requests.length, 1);
        assert.equal(f.requests[0].data.getAll('files[]').length, 64);
        assert.equal(new URL(f.requests[0].url, 'http://localhost').searchParams.get('path'), '/files/a & b#c');
        assert.equal(f.w.document.querySelectorAll('#file-list li').length, 1000);
        assert.equal(f.w.document.querySelector('#file-list img'), null);
        await finish(f, task);
        assert.equal(f.requests.reduce((sum, r) => sum + r.data.getAll('files[]').length, 0), 1000);
        assert.ok(f.requests.every(r => r.data.getAll('files[]').length <= 64));
        assert.equal(f.alerts.at(-1).icon, 'success');
        assert.equal(f.w.document.querySelector('#valueUploadProgress').textContent, '100%');
    } finally { f.dom.window.close(); }
});

test('mixed files and folders preserve paths, pagination, same basenames and empty directories', async () => {
    const f = fixture();
    try {
        const make = name => fileEntry(new f.w.File(['data'], name));
        const entries = [make('loose.txt'), directory('project', [
            [make('same.txt')],
            [directory('nested', [[make('same.txt')]]), directory('empty', [])],
        ])];
        const task = f.w.handleFiles({type: 'drop', stopPropagation() {}, preventDefault() {}, dataTransfer: {
            files: [], items: entries.map(entry => ({kind: 'file', webkitGetAsEntry: () => entry})),
        }});
        await finish(f, task);
        const paths = f.requests.flatMap(r => JSON.parse(r.data.get('uploadPaths')));
        const directories = f.requests.flatMap(r => JSON.parse(r.data.get('uploadDirectories')));
        assert.deepEqual(paths, ['loose.txt', 'project/same.txt', 'project/nested/same.txt']);
        assert.deepEqual(directories, ['project', 'project/nested', 'project/empty']);
        assert.equal(f.alerts.at(-1).icon, 'success');
    } finally { f.dom.window.close(); }
});

test('folder picker uses webkitRelativePath and empty-folder-only drops send a request', async () => {
    const f = fixture();
    try {
        assert.ok(f.w.document.getElementById('folder-input').hasAttribute('webkitdirectory'));
        const file = new f.w.File(['x'], 'config.json');
        Object.defineProperty(file, 'webkitRelativePath', {value: 'app/src/config.json'});
        await finish(f, f.w.handleFiles(select([file])));
        assert.deepEqual(JSON.parse(f.requests[0].data.get('uploadPaths')), ['app/src/config.json']);
        f.requests.length = 0;
        await finish(f, f.w.handleFiles({type: 'drop', stopPropagation() {}, preventDefault() {}, dataTransfer: {
            files: [], items: [{kind: 'file', webkitGetAsEntry: () => directory('empty', [])}],
        }}));
        assert.deepEqual(JSON.parse(f.requests[0].data.get('uploadDirectories')), ['empty']);
        assert.equal(f.requests[0].data.getAll('files[]').length, 0);
    } finally { f.dom.window.close(); }
});

test('empty selections send nothing; a failed batch stops the queue and allows retry', async () => {
    const f = fixture();
    try {
        await f.w.handleFiles(select([]));
        assert.equal(f.requests.length, 0);
        const files = Array.from({length: 70}, () => new f.w.File(['x'], 'a'));
        const task = f.w.handleFiles(select(files));
        await tick();
        f.requests[0].error();
        await task;
        assert.equal(f.requests.length, 1);
        assert.equal(f.alerts.at(-1).icon, 'error');
        const retry = f.w.handleFiles(select([files[0]]));
        await tick();
        f.requests[1].success({success: false});
        await retry;
        assert.equal(f.alerts.at(-1).icon, 'error');
    } finally { f.dom.window.close(); }
});
