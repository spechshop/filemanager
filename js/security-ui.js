/* Keep untrusted strings out of executable HTML and inline JavaScript contexts. */
(function (root) {
    'use strict';
    const html = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const argument = value => html(JSON.stringify(String(value ?? '')));
    function breadcrumbs(container, path, navigate) {
        container.replaceChildren();
        const parts = String(path).split('/');
        parts.forEach((name, index) => {
            if (!name) return;
            container.append(document.createTextNode(' / '));
            const link = document.createElement('a');
            link.href = '#'; link.textContent = name;
            link.className = index === parts.length - 1 ? 'text-blue' : 'text-secondary';
            link.addEventListener('click', event => { event.preventDefault(); navigate(parts.slice(0, index + 1).join('/')); });
            container.append(link);
        });
    }
    function highlight(node, text, query) {
        node.replaceChildren(); text = String(text); query = String(query);
        const start = query ? text.toLowerCase().indexOf(query.toLowerCase()) : -1;
        if (start < 0) { node.textContent = text; return; }
        node.append(document.createTextNode(text.slice(0, start)));
        const match = document.createElement('span'); match.style.color = '#ffc107'; match.style.fontWeight = 'bold';
        match.textContent = text.slice(start, start + query.length); node.append(match, document.createTextNode(text.slice(start + query.length)));
    }
    root.FMUI = {html, argument, breadcrumbs, highlight};
})(typeof window === 'undefined' ? globalThis : window);
