'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const crypto = require('node:crypto');
const {execFileSync, spawnSync} = require('node:child_process');
const script = path.resolve(__dirname, '../repair-websocket-package.cjs');

function fixture(t) {
    const root = fs.mkdtempSync(path.join(os.tmpdir(), 'fm-ws-test-'));
    t.after(() => fs.rmSync(root, {recursive: true, force: true}));
    fs.mkdirSync(path.join(root, 'package'));
    fs.writeFileSync(path.join(root, 'package/index.js'), 'exports.WebSocketServer = class WebSocketServer {};');
    const archive = path.join(root, 'fixture.tgz');
    execFileSync('tar', ['-czf', archive, '-C', root, 'package']);
    const bytes = fs.readFileSync(archive);
    const entry = {version: '1.0.0', resolved: 'https://registry.npmjs.org/ws/-/ws-1.0.0.tgz',
        integrity: 'sha512-' + crypto.createHash('sha512').update(bytes).digest('base64')};
    fs.mkdirSync(path.join(root, 'node_modules/ws'), {recursive: true});
    fs.writeFileSync(path.join(root, 'node_modules/ws/previous'), 'preserve');
    const preload = path.join(root, 'fetch.cjs');
    fs.writeFileSync(preload, `global.fetch = async () => new Response(require('node:fs').readFileSync(${JSON.stringify(archive)}));`);
    return {root, entry, preload, run() {
        fs.writeFileSync(path.join(root, 'package-lock.json'), JSON.stringify({packages: {'node_modules/ws': entry}}));
        return spawnSync(process.execPath, ['--require', preload, script, root], {encoding: 'utf8'});
    }};
}

test('verified ws replaces an incomplete installation and loads', t => {
    const f = fixture(t), result = f.run();
    assert.equal(result.status, 0, result.stderr);
    assert.equal(typeof require(path.join(f.root, 'node_modules/ws')).WebSocketServer, 'function');
    assert.equal(fs.existsSync(path.join(f.root, 'node_modules/ws/previous')), false);
    assert.deepEqual(fs.readdirSync(path.join(f.root, '.runtime')), []);
});

test('checksum mismatch preserves the existing installation', t => {
    const f = fixture(t);
    f.entry.integrity = 'sha512-' + Buffer.alloc(64).toString('base64');
    const result = f.run();
    assert.equal(result.status, 1);
    assert.match(result.stderr, /Integridade/);
    assert.equal(fs.readFileSync(path.join(f.root, 'node_modules/ws/previous'), 'utf8'), 'preserve');
    assert.deepEqual(fs.readdirSync(path.join(f.root, '.runtime')), []);
});

test('download failure is reported without replacing existing files', t => {
    const f = fixture(t);
    fs.writeFileSync(f.preload, 'global.fetch = async () => new Response("unavailable", {status: 503});');
    const result = f.run();
    assert.equal(result.status, 1);
    assert.match(result.stderr, /HTTP 503/);
    assert.equal(fs.existsSync(path.join(f.root, 'node_modules/ws/previous')), true);
});

test('untrusted package source is rejected before download', t => {
    const f = fixture(t);
    f.entry.resolved = 'https://example.com/ws-1.0.0.tgz';
    fs.writeFileSync(f.preload, 'global.fetch = async () => { throw new Error("unexpected fetch"); };');
    const result = f.run();
    assert.equal(result.status, 1);
    assert.match(result.stderr, /origem\/integridade/);
    assert.doesNotMatch(result.stderr, /unexpected fetch/);
});
