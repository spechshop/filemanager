const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {test} = require('node:test');
const {JSDOM} = require('jsdom');

const source = fs.readFileSync(path.join(__dirname, '../../plugins/Request/modules/modalEditCode.html'), 'utf8');
const save = source.slice(source.indexOf('        async function saveFile() {'), source.indexOf('        async function updateImports('));

function fixture(filePath = '/etc/example/settings.conf') {
    const model = {version: 2, getAlternativeVersionId() { return this.version; }, getValue() { return 'updated content'; }};
    const fields = {'#hiddenFileEdit': filePath, '#fp': 'fixture-token'};
    const requests = [], toasts = [];
    const $ = selector => ({text: () => fields[selector] || ''});
    $.ajax = options => { requests.push(options); };
    const context = vm.createContext({
        $, models: {[filePath]: model}, cleanModelVersions: {[filePath]: 1},
        editor: {getModel: () => model}, toastLotus: (...args) => toasts.push(args),
        runPhpStanDiagnostics() {}, async refreshLegacyProviders() {},
    });
    vm.runInContext(save, context);
    return {context, model, requests, toasts, fields, filePath};
}

for (const filePath of ['/etc/example/settings.conf', '/settings.conf', 'files/example.txt', 'example.txt']) {
    test(`saves the opened path with no title field: ${filePath}`, async () => {
        const f = fixture(filePath);
        await f.context.saveFile();
        const request = f.requests[0];
        assert.equal(request.data.name, path.posix.basename(filePath));
        assert.equal(request.data.path, path.posix.dirname(filePath));
        assert.equal(request.data.content, 'updated content');
        assert.equal(request.dataType, 'json');
        await request.success({success: true});
        assert.equal(f.context.cleanModelVersions[filePath], 2);
    });
}

test('a late save response tracks the original file and submitted version', async () => {
    const f = fixture();
    await f.context.saveFile();
    f.model.version = 3;
    f.fields['#hiddenFileEdit'] = '/another/file';
    f.context.models['/another/file'] = {};
    f.context.editor.getModel = () => f.context.models['/another/file'];
    await f.requests[0].success({success: true});
    assert.equal(f.context.cleanModelVersions[f.filePath], 2);
    assert.equal(f.context.cleanModelVersions['/another/file'], undefined);
});

test('permission and application errors stay visible and keep unsaved changes', async () => {
    const f = fixture();
    await f.context.saveFile();
    f.requests[0].error({status: 403});
    assert.match(f.toasts[0][0], /permissão/);
    await f.requests[0].success({success: false});
    assert.equal(f.toasts[1][3], 'error');
    assert.equal(f.context.cleanModelVersions[f.filePath], 1);
});

test('does not write another file while the selected model is still loading', async () => {
    const f = fixture();
    f.fields['#hiddenFileEdit'] = '/another/file';
    await f.context.saveFile();
    assert.equal(f.requests.length, 0);
    assert.equal(f.toasts[0][3], 'error');
});

test('the visible save button saves the active file without a keyboard shortcut', async () => {
    const markup = source.match(/<button[^>]*id="editorSaveButton"[\s\S]*?<\/button>/)[0];
    const dom = new JSDOM(markup);
    try {
        const f = fixture('/files/mobile.txt');
        f.context.document = dom.window.document;
        const start = source.indexOf("        const saveButton = document.getElementById('editorSaveButton');");
        const end = source.indexOf('        saveButton.disabled = false;', start) + '        saveButton.disabled = false;'.length;
        vm.runInContext(source.slice(start, end), f.context);
        const button = dom.window.document.getElementById('editorSaveButton');
        assert.match(button.textContent, /Salvar/);
        assert.equal(button.disabled, false);
        button.click();
        assert.equal(f.requests.length, 1);
        assert.equal(f.requests[0].data.name, 'mobile.txt');
        assert.equal(f.requests[0].data.content, 'updated content');
        await f.requests[0].success({success: true});
        assert.equal(f.context.cleanModelVersions[f.filePath], 2);
    } finally { dom.window.close(); }
});
