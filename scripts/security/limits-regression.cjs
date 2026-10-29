'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const auth = require('./bridge-auth.cjs');
test('quotas isolate owners, bound total processes, release idempotently and respect permissions', () => {
    const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'fm-limits-'));
    const previous = process.env.FILEMANAGER_TOKENS_FILE;
    process.env.FILEMANAGER_TOKENS_FILE = path.join(temp, 'tokens.json');
    fs.writeFileSync(process.env.FILEMANAGER_TOKENS_FILE, JSON.stringify({a: {expire: 9999999999}, b: {expire: 9999999999}, restricted: {expire: 9999999999, allowedRoots: ['/fixture'], terminal: true}}));
    try {
        const a = auth.acquire('TEST', 'a-one', 1, 2); assert.equal(typeof a, 'function');
        assert.equal(auth.acquire('TEST', 'a-two', 1, 2), null);
        const b = auth.acquire('TEST', 'b-one', 1, 2); assert.equal(typeof b, 'function');
        assert.equal(auth.acquire('TEST', 'restricted-one', 1, 2), null);
        a(); a(); b();
        const recovered = auth.acquire('TEST', 'a-two', 1, 2); assert.equal(typeof recovered, 'function'); recovered();
        assert.equal(auth.acquire('TEST', 'unknown-one', 1, 2), null);
        assert.equal(auth.valid('restricted-one'), true, 'explicit shell permission preserved');
        assert.equal(auth.acquire('CODEX_TURNS', 'restricted-one', 1, 2), null, 'shell permission does not silently grant Codex administration');
        let closed = false, sent = false;
        assert.equal(auth.send({bufferedAmount: 4194305, close: () => { closed = true; }, send: () => { sent = true; }}, 'fixture'), false);
        assert.equal(closed, true); assert.equal(sent, false);
    } finally { if (previous === undefined) delete process.env.FILEMANAGER_TOKENS_FILE; else process.env.FILEMANAGER_TOKENS_FILE = previous; fs.rmSync(temp, {recursive: true, force: true}); }
});
