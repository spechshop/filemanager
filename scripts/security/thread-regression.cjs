'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const ThreadScope = require('./thread-scope.cjs');
test('thread ownership persists across reconnect and blocks other users, unknown legacy threads and revoked sessions without writes', () => {
    const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'fm-thread-'));
    const previous = process.env.FILEMANAGER_TOKENS_FILE;
    process.env.FILEMANAGER_TOKENS_FILE = path.join(tmp, 'tokens.json');
    const tokens = {owner: {expire: Math.floor(Date.now() / 1000) + 3600}, other: {expire: Math.floor(Date.now() / 1000) + 3600}};
    const save = () => fs.writeFileSync(process.env.FILEMANAGER_TOKENS_FILE, JSON.stringify(tokens)); save();
    try {
        const file = path.join(tmp, 'owners.json'), scope = new ThreadScope(file);
        scope.claim('thread-one', 'owner-codex-agent');
        const before = fs.readFileSync(file, 'utf8');
        assert.equal(scope.allows('thread-one', 'owner-new-connection'), true);
        assert.equal(scope.allows('thread-one', 'other-codex-agent'), false);
        assert.throws(() => scope.require('thread-one', 'other-codex-agent'));
        assert.throws(() => scope.claim('thread-one', 'other-codex-agent'));
        assert.throws(() => scope.require('unknown-legacy-thread', 'owner-codex-agent'));
        assert.equal(fs.readFileSync(file, 'utf8'), before, 'denied access does not rewrite ownership');
        assert.equal(new ThreadScope(file).allows('thread-one', 'owner-reconnected'), true);
        tokens.owner.revoked = true; save();
        assert.throws(() => scope.require('thread-one', 'owner-codex-agent'));
        assert.throws(() => scope.claim('new-thread', 'owner-codex-agent'));
        assert.equal(fs.readFileSync(file, 'utf8'), before, 'revocation cannot claim or modify any thread');
        assert.equal(before.includes('owner'), false, 'store contains hashes, not credentials');
        assert.equal(fs.statSync(file).mode & 0o777, 0o600);
    } finally { if (previous === undefined) delete process.env.FILEMANAGER_TOKENS_FILE; else process.env.FILEMANAGER_TOKENS_FILE = previous; fs.rmSync(tmp, {recursive: true, force: true}); }
});
test('actual bridge action handlers reject another user before RPC and filter thread history', async () => {
    const vm = require('node:vm');
    const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'fm-thread-actions-'));
    const previous = process.env.FILEMANAGER_TOKENS_FILE;
    process.env.FILEMANAGER_TOKENS_FILE = path.join(tmp, 'tokens.json');
    fs.writeFileSync(process.env.FILEMANAGER_TOKENS_FILE, JSON.stringify({owner: {expire: 9999999999}, other: {expire: 9999999999}}));
    try {
        const scope = new ThreadScope(path.join(tmp, 'owners.json'));
        scope.claim('owned-thread', 'owner-codex-agent'); scope.claim('other-thread', 'other-codex-agent');
        const before = fs.readFileSync(scope.file, 'utf8'); const calls = [];
        const source = fs.readFileSync(path.resolve(__dirname, '../../codex-agent.js'), 'utf8');
        const context = vm.createContext({codexReady: true, threadScope: scope,
            rpc: async (method, params) => { calls.push([method, params]); return {data: [{id: 'owned-thread'}, {id: 'other-thread'}, {id: 'legacy-unowned'}]}; }});
        vm.runInContext(source.slice(source.indexOf('async function performAction('), source.indexOf('\nconst server = http.createServer')), context);
        const client = {authSession: 'other-codex-agent'};
        await assert.rejects(context.performAction(client, {action: 'thread.resume', threadId: 'owned-thread'}), /não pertence/);
        assert.equal(calls.length, 0, 'denied resume produces no app-server RPC side effect');
        assert.equal(fs.readFileSync(scope.file, 'utf8'), before, 'denied resume does not claim the thread');
        const result = await context.performAction(client, {action: 'thread.list'});
        assert.deepEqual(Array.from(result.data, thread => thread.id), ['other-thread']);
    } finally { if (previous === undefined) delete process.env.FILEMANAGER_TOKENS_FILE; else process.env.FILEMANAGER_TOKENS_FILE = previous; fs.rmSync(tmp, {recursive: true, force: true}); }
});
