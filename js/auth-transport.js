/* Keep long-lived credentials out of HTTP URLs and WebSocket handshakes.
 * Legacy explicit query credentials remain supported by the server for clients.
 */
(function (root) {
    'use strict';
    const nativeFetch = root.fetch.bind(root);
    function prepare(input, options = {}) {
        const original = input instanceof Request ? input : null;
        const url = new URL(original ? original.url : String(input), location.href);
        if (url.origin !== location.origin) return [input, options];
        const token = url.searchParams.get('tokenBrowser');
        if (token === null) return [input, options];
        url.searchParams.delete('tokenBrowser');
        const headers = new Headers(options.headers || original?.headers);
        // Preserve conflicting supplied credentials: the server will reject them.
        if (headers.has('Authorization') && headers.get('Authorization') !== `Bearer ${token}`) return [input, options];
        headers.set('Authorization', `Bearer ${token}`);
        return [original ? new Request(url, original) : url.href, {...options, headers, referrerPolicy: 'no-referrer'}];
    }
    root.fetch = (input, options) => nativeFetch(...prepare(input, options));
    const open = XMLHttpRequest.prototype.open;
    const send = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open = function (method, address, ...args) {
        const url = new URL(String(address), location.href);
        this.fmToken = url.origin === location.origin ? url.searchParams.get('tokenBrowser') : null;
        if (this.fmToken !== null) url.searchParams.delete('tokenBrowser');
        return open.call(this, method, url.href, ...args);
    };
    XMLHttpRequest.prototype.send = function (body) {
        if (this.fmToken !== null && this.fmToken !== undefined) this.setRequestHeader('Authorization', `Bearer ${this.fmToken}`);
        return send.call(this, body);
    };
    function token() { return document.getElementById('fp')?.textContent.trim() || ''; }
    let validation = null, validatedCredential = null, deniedCredential = null;
    class AuthorizationError extends Error {
        constructor(message, status) { super(message); this.status = status; }
    }
    function denied(credential) {
        if (deniedCredential === credential) return;
        deniedCredential = credential;
        root.dispatchEvent(new CustomEvent('fm:auth-denied', {detail: {status: 401}}));
    }
    function shouldRetry(error) {
        return deniedCredential !== token() && ![401, 403].includes(error?.status);
    }
    async function request(address, credential, body) {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 10000);
        try {
            const response = await nativeFetch(address, {method: 'POST', headers: {'Authorization': `Bearer ${credential}`, 'Content-Type': 'application/json'}, body: JSON.stringify(body), signal: controller.signal, cache: 'no-store', referrerPolicy: 'no-referrer'});
            if (!response.ok) {
                if (response.status === 401) denied(credential);
                throw new AuthorizationError('Não foi possível autorizar a conexão.', response.status);
            }
            return await response.json();
        } finally { clearTimeout(timeout); }
    }
    async function ready() {
        // The transport loads in <head>, before #fp and the credential bootstrap.
        if (document.readyState === 'loading') await new Promise(resolve => document.addEventListener('DOMContentLoaded', resolve, {once: true}));
        const credential = token();
        if (!credential) throw new AuthorizationError('Credencial ausente.', 401);
        if (deniedCredential === credential) throw new AuthorizationError('Acesso não autorizado.', 401);
        if (validatedCredential !== credential || !validation) {
            validatedCredential = credential;
            validation = request('/checkToken', credential, {}).then(result => {
                if (result.success !== true) { denied(credential); throw new AuthorizationError('Acesso não autorizado.', 401); }
                return result;
            }).catch(error => {
                if (shouldRetry(error)) validation = null;
                throw error;
            });
        }
        return validation;
    }
    async function ticket(scope) {
        await ready();
        const result = await request('/accessTicket', token(), scope);
        if (!/^[a-f0-9]{64}$/.test(result.ticket)) throw new Error('Resposta de autorização inválida.');
        return result.ticket;
    }
    async function socketUrl(address) {
        const url = new URL(address, location.href);
        if (url.host !== location.host) throw new Error('WebSocket externo não autorizado.');
        const id = await ticket({kind: 'websocket', socket: decodeURIComponent(url.pathname.slice(1))});
        url.pathname = '/ws/' + id; url.search = ''; return url.href;
    }
    async function mediaUrl(path, operation = 'imageBase64') {
        const id = await ticket({kind: 'media', operation, path});
        return `/${operation}?accessTicket=${id}&path=${encodeURIComponent(path)}`;
    }
    root.FMAuth = {ready, shouldRetry, socketUrl, mediaUrl};
})(window);
