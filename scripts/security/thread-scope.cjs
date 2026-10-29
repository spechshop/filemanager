'use strict';
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const auth = require('./bridge-auth.cjs');
class ThreadScope {
    constructor(file) { this.file = file; }
    identity(session) {
        if (!auth.valid(session, 'admin')) throw new Error('Session expired');
        return crypto.createHash('sha256').update(auth.owner(session)).digest('hex');
    }
    read() {
        let source;
        try { source = fs.readFileSync(this.file, 'utf8'); }
        catch (error) { if (error.code === 'ENOENT') return Object.create(null); throw error; }
        const data = JSON.parse(source);
        if (!data || typeof data !== 'object' || Array.isArray(data) || Object.values(data).some(value => typeof value !== 'string' || !/^[a-f0-9]{64}$/.test(value))) throw new Error('Invalid thread ownership store');
        return Object.assign(Object.create(null), data);
    }
    allows(threadId, session) {
        return typeof threadId === 'string' && this.read()[threadId] === this.identity(session);
    }
    require(threadId, session) {
        if (!this.allows(threadId, session)) throw new Error('A conversa não pertence a esta sessão.');
    }
    claim(threadId, session) {
        if (typeof threadId !== 'string' || !/^[a-zA-Z0-9_-]{1,128}$/.test(threadId)) throw new Error('Invalid thread identifier');
        const owner = this.identity(session), records = this.read();
        if (records[threadId] && records[threadId] !== owner) throw new Error('A conversa não pertence a esta sessão.');
        records[threadId] = owner;
        fs.mkdirSync(path.dirname(this.file), {recursive: true, mode: 0o700});
        const temporary = this.file + '.' + crypto.randomBytes(12).toString('hex');
        try { fs.writeFileSync(temporary, JSON.stringify(records), {mode: 0o600, flag: 'wx'}); fs.renameSync(temporary, this.file); }
        finally { if (fs.existsSync(temporary)) fs.unlinkSync(temporary); }
    }
}
module.exports = ThreadScope;
