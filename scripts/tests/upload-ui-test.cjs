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
    w.Swal = {fire: options => alerts.push(options)};
    w.eval(w.document.querySelector('script').textContent);
    return {dom, w, requests, alerts};
}

test('large selections build a safe list and one multipart request with encoded destination', () => {
    const f = fixture();
    try {
        const files = Array.from({length: 1000}, (_, i) => new f.w.File(['data'], i === 0 ? '<img onerror=alert(1)>.txt' : `file-${i}.txt`));
        f.w.handleFiles({type: 'change', target: {files}});
        assert.equal(f.requests.length, 1);
        const request = f.requests[0];
        assert.equal(new URL(request.url, 'http://localhost').searchParams.get('path'), '/files/a & b#c');
        assert.equal(request.data.getAll('files[]').length, 1000);
        assert.equal(f.w.document.querySelectorAll('#file-list li').length, 1000);
        assert.equal(f.w.document.querySelector('#file-list img'), null);
        assert.equal(f.w.document.querySelector('#valueUploadProgress').textContent, '0%');
        assert.equal(request.dataType, 'json');
    } finally { f.dom.window.close(); }
});

test('empty selections send nothing, transport and application errors stay visible', async () => {
    const f = fixture();
    try {
        f.w.handleFiles({type: 'change', target: {files: []}});
        assert.equal(f.requests.length, 0);
        f.w.handleFiles({type: 'change', target: {files: [new f.w.File(['x'], 'a')]}});
        f.requests[0].error();
        assert.equal(f.alerts[0].icon, 'error');
        await f.requests[0].success({success: false});
        assert.equal(f.alerts[1].icon, 'error');
    } finally { f.dom.window.close(); }
});
