'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM} = require('jsdom');
const root = path.resolve(__dirname, '../..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const tick = () => new Promise(resolve => setImmediate(resolve));
const entry = (id, name) => ({id: id.repeat(32), name, originalPath: '/workspace/' + name, kind: 'file', deletedAt: 1700000000, size: 1024});
function browser(initial = [entry('a', 'one.txt'), entry('b', 'two.txt')]) {
    const dom = new JSDOM('<div id="fp">owner</div><span id="trashCountBadge"></span><div id="fileListPanel"></div>' + read('plugins/Request/modules/trash.html'), {url: 'http://localhost', runScripts: 'dangerously'});
    const w = dom.window;
    const requests = [], dialogs = [], answers = [];
    let entries = initial.slice(), refreshed = 0;
    w.FMAuth = {ready: async () => {}};
    w.FMFileList = {load: async options => { assert.equal(options.force, true); ++refreshed; }};
    w.Swal = {fire: async options => { dialogs.push(options); return answers.shift() || {isConfirmed: false}; }, isVisible: () => false};
    w.fetch = async (url, options) => {
        assert.equal(url, '/trash');
        assert.equal(options.headers.Authorization, 'Bearer owner');
        const data = JSON.parse(options.body);
        requests.push(data);
        if (data.action === 'list') return {ok: true, json: async () => ({success: true, entries})};
        let completed = [];
        if (data.action === 'move') {
            completed = data.files.map((file, index) => ({...entry(String(index + 1), file.split('/').pop()), originalPath: file}));
            entries.push(...completed);
        } else {
            const ids = data.action === 'empty' ? entries.map(item => item.id) : data.ids;
            completed = entries.filter(item => ids.includes(item.id)).map(item => ({id: item.id, restoredPath: item.originalPath}));
            entries = entries.filter(item => !ids.includes(item.id));
        }
        return {ok: true, json: async () => ({success: true, completed, errors: []})};
    };
    w.eval(read('js/trash.js'));
    return {dom, w, requests, dialogs, answers, el: id => w.document.getElementById(id), refreshCount: () => refreshed};
}

test('the dedicated trash lists origin and date safely, filters and restores selected visible items', async () => {
    const hostile = '<img src=x onerror="window.pwned=1">.txt';
    const {dom, w, requests, el, refreshCount} = browser([entry('a', hostile), entry('b', 'notes.txt')]);
    try {
        await w.FMTrash.open();
        assert.equal(el('trashView').hidden, false);
        assert.equal(w.document.querySelectorAll('.trash-item').length, 2);
        assert.equal(w.document.querySelector('.trash-item strong').textContent, hostile);
        assert.equal(w.document.querySelectorAll('#trashItems img, #trashItems [onerror]').length, 0);
        assert.equal(w.pwned, undefined);
        assert.equal(w.document.querySelector('.trash-item-origin').textContent, '/workspace/' + hostile);
        el('trashSearch').value = 'notes';
        w.FMTrash.filter();
        assert.equal(w.document.querySelectorAll('.trash-item').length, 1);
        w.FMTrash.selectAll(true);
        await w.FMTrash.perform('restore');
        assert.deepEqual(requests.find(request => request.action === 'restore').ids, ['b'.repeat(32)]);
        assert.equal(refreshCount(), 1);
        el('trashSearch').value = '';
        w.FMTrash.filter();
        assert.equal(w.document.querySelectorAll('.trash-item').length, 1);
        assert.equal(w.document.querySelector('.trash-item strong').textContent, hostile);
    } finally { dom.window.close(); }
});

test('moving to trash is explicit, cancellable, and never calls permanent deletion', async () => {
    const {dom, w, requests, dialogs, answers, refreshCount} = browser([]);
    try {
        await w.FMTrash.move(['/workspace/one.txt']);
        assert.equal(requests.length, 0);
        assert.equal(dialogs[0].confirmButtonText, 'Mover para a lixeira');
        answers.push({isConfirmed: true}, {isConfirmed: false});
        assert.equal(await w.FMTrash.move(['/workspace/one.txt']), true);
        assert.deepEqual(requests.map(request => request.action), ['move', 'list']);
        assert.equal(refreshCount(), 1);
        assert.equal(dialogs.at(-1).confirmButtonText, 'Abrir lixeira');
    } finally { dom.window.close(); }
});

test('permanent deletion and emptying require confirmation and update the list', async () => {
    const {dom, w, requests, dialogs, answers, el} = browser();
    try {
        await w.FMTrash.open();
        await w.FMTrash.perform('purge', ['a'.repeat(32)]);
        assert.equal(requests.some(request => request.action === 'purge'), false);
        assert.match(dialogs.at(-1).text, /sem possibilidade de restauração/);
        answers.push({isConfirmed: true});
        await w.FMTrash.perform('purge', ['a'.repeat(32)]);
        assert.equal(w.document.querySelectorAll('.trash-item').length, 1);
        answers.push({isConfirmed: true});
        await w.FMTrash.perform('empty');
        assert.equal(el('trashNoItems').hidden, false);
        assert.equal(el('trashEmpty').disabled, true);
        assert.equal(requests.at(-2).action, 'empty');
    } finally { dom.window.close(); }
});

test('restore conflicts offer an explicit renamed retry and preserve other files', async () => {
    const {dom, w, answers, requests, dialogs} = browser();
    try {
        await w.FMTrash.open();
        const normal = w.fetch;
        w.fetch = async (url, options) => {
            const data = JSON.parse(options.body);
            if (data.action === 'restore' && !data.conflict) {
                return {ok: true, json: async () => ({success: false, completed: [], errors: [{item: data.ids[0], code: 'conflict', message: 'Já existe um arquivo.'}]})};
            }
            return normal(url, options);
        };
        answers.push({isConfirmed: true});
        await w.FMTrash.perform('restore', ['a'.repeat(32)]);
        assert.equal(dialogs[0].confirmButtonText, 'Restaurar com outro nome');
        assert.equal(requests.find(request => request.action === 'restore').conflict, 'rename');
        assert.equal(w.document.querySelectorAll('.trash-item').length, 1);
    } finally { dom.window.close(); }
});

test('failed loading can be retried and closing prevents late results from reopening the panel', async () => {
    const {dom, w, el} = browser();
    try {
        const normal = w.fetch;
        w.fetch = async () => { throw new Error('Sem conexão'); };
        await w.FMTrash.open();
        assert.equal(el('trashRetry').hidden, false);
        assert.equal(el('trashStatusText').textContent, 'Sem conexão');
        w.fetch = normal;
        await w.FMTrash.refresh();
        assert.equal(el('trashRetry').hidden, true);
        assert.equal(w.document.querySelectorAll('.trash-item').length, 2);
        let resolve;
        w.fetch = () => new Promise(done => { resolve = done; });
        const loading = w.FMTrash.open();
        await tick();
        w.FMTrash.close();
        resolve({ok: true, json: async () => ({success: true, entries: [entry('c', 'late')]})});
        await loading;
        assert.equal(el('trashView').hidden, true);
        assert.equal(w.document.querySelectorAll('.trash-item').length, 0);
        assert.equal(w.document.body.style.overflow, '');
    } finally { dom.window.close(); }
});
