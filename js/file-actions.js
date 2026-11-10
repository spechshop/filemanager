(function (root) {
    'use strict';
    const extension = path => path.split('.').pop().toLowerCase();
    const isDocument = path => ['md', 'markdown', 'html', 'htm'].includes(extension(path));
    const parentPath = path => path.slice(0, path.lastIndexOf('/')) || '/';
    let menu, menuFocus, viewer, frame, previewStatus, previewPath = '', previewRequest, previewVersion = 0;
    let previewFocus, bodyOverflow;

    function notify(message, error = false) {
        if (typeof root.toastLotus === 'function') root.toastLotus(message, error ? 'Erro' : 'Arquivos', 3000, error ? 'error' : 'success');
    }

    async function copyPath(path) {
        try {
            if (navigator.clipboard?.writeText) await navigator.clipboard.writeText(path);
            else {
                const field = document.createElement('textarea');
                field.value = path;
                field.style.cssText = 'position:fixed;left:-9999px;top:0';
                document.body.append(field);
                const focused = document.activeElement;
                try {
                    field.select();
                    if (!document.execCommand('copy')) throw new Error('Clipboard unavailable');
                } finally { field.remove(); focused?.focus({preventScroll: true}); }
            }
            notify('Caminho copiado.');
        } catch (error) { notify('Não foi possível copiar o caminho. Verifique a permissão da área de transferência.', true); }
    }

    function open(item) {
        if (item.typeFile === 'folder') return root.setPath(item.path);
        if (isDocument(item.path)) return preview(item.path);
        if (item.isImage || item.isMedia) return root.showImage(item.path);
        return root.showEditor(item.typeFile, item.path);
    }

    function closeMenu(restoreFocus = false) {
        menu?.remove(); menu = null;
        if (restoreFocus && menuFocus?.isConnected) menuFocus.focus({preventScroll: true});
    }

    function showMenu(event, target) {
        const path = target.getAttribute('fullpath') || target.dataset.fullpath;
        const item = root.currentFileItems?.find(item => item.path === path);
        if (!item) return;
        event.preventDefault();
        closeMenu();
        menuFocus = target;
        menu = document.createElement('div');
        menu.id = 'fileContextMenu';
        menu.setAttribute('role', 'menu');
        menu.setAttribute('aria-label', 'Ações do arquivo ou pasta');
        const title = document.createElement('div');
        title.className = 'file-context-title';
        title.textContent = item.path.split('/').pop() || '/';
        menu.append(title);
        const add = (label, action, danger = false) => {
            const button = document.createElement('button');
            button.type = 'button'; button.textContent = label;
            button.setAttribute('role', 'menuitem');
            if (danger) button.className = 'file-context-danger';
            button.onclick = () => { closeMenu(true); Promise.resolve().then(action).catch(() => notify('Não foi possível concluir a ação.', true)); };
            menu.append(button);
        };
        add(item.typeFile === 'folder' ? 'Abrir pasta' : isDocument(path) ? 'Visualizar documento' : 'Abrir', () => open(item));
        if (item.typeFile === 'folder') add('Abrir galeria desta pasta', () => root.openMediaGallery(path));
        else {
            add('Editar arquivo', () => root.showEditor(item.typeFile, path));
            add('Baixar arquivo', () => root.downloadFile(path));
        }
        add('Copiar caminho', () => copyPath(path));
        add('Copiar caminho da pasta pai', () => copyPath(parentPath(path)));
        add('Renomear', () => root.renameItem(path));
        add('Mover para a lixeira', () => root.deleteFile(path), true);
        menu.onkeydown = event => {
            const buttons = [...menu.querySelectorAll('button')];
            const index = buttons.indexOf(document.activeElement);
            if (event.key === 'Escape') { event.preventDefault(); closeMenu(true); return; }
            if (event.key === 'Tab') { closeMenu(true); return; }
            const next = {ArrowDown: (index + 1) % buttons.length, ArrowUp: (index + buttons.length - 1) % buttons.length, Home: 0, End: buttons.length - 1};
            if (Object.hasOwn(next, event.key)) { event.preventDefault(); buttons[next[event.key]].focus(); }
        };
        document.body.append(menu);
        const bounds = target.getBoundingClientRect();
        const x = event.clientX ?? bounds.left, y = event.clientY ?? bounds.bottom;
        menu.style.left = Math.max(8, Math.min(x, innerWidth - menu.offsetWidth - 8)) + 'px';
        menu.style.top = Math.max(8, Math.min(y, innerHeight - menu.offsetHeight - 8)) + 'px';
        menu.querySelector('button').focus({preventScroll: true});
    }

    const fileTarget = target => target instanceof Element ? target.closest('#filesList td[fullpath], #viewGridFiles [data-fullpath]') : null;
    document.addEventListener('contextmenu', event => {
        const target = fileTarget(event.target);
        if (target && !event.shiftKey) showMenu(event, target);
    });
    document.addEventListener('keydown', event => {
        const target = fileTarget(event.target);
        if (target && (event.key === 'ContextMenu' || (event.shiftKey && event.key === 'F10'))) showMenu(event, target);
    });
    document.addEventListener('pointerdown', event => { if (menu && !menu.contains(event.target)) closeMenu(); });
    document.addEventListener('scroll', event => { if (menu && !menu.contains(event.target)) closeMenu(); }, true);
    root.addEventListener('resize', () => closeMenu());

    function createViewer() {
        viewer = document.createElement('dialog');
        viewer.id = 'documentViewer';
        viewer.setAttribute('aria-labelledby', 'documentViewerTitle');
        const header = document.createElement('header');
        const title = document.createElement('div');
        title.id = 'documentViewerTitle';
        const actions = document.createElement('div');
        actions.className = 'document-viewer-actions';
        const add = (label, action) => {
            const button = document.createElement('button');
            button.type = 'button'; button.textContent = label; button.onclick = action; actions.append(button);
            return button;
        };
        add('Editar', () => { const path = previewPath; viewer.close(); root.showEditor(extension(path), path); });
        add('Baixar', () => root.downloadFile(previewPath));
        add('Atualizar', () => preview(previewPath));
        add('Fechar', () => viewer.close()).id = 'documentViewerClose';
        header.append(title, actions);
        previewStatus = document.createElement('p');
        previewStatus.id = 'documentViewerStatus';
        previewStatus.setAttribute('role', 'status');
        frame = document.createElement('iframe');
        frame.id = 'documentViewerFrame';
        frame.title = 'Conteúdo do documento';
        frame.setAttribute('sandbox', 'allow-popups allow-popups-to-escape-sandbox');
        frame.referrerPolicy = 'no-referrer';
        viewer.append(header, previewStatus, frame);
        viewer.addEventListener('close', () => {
            ++previewVersion; previewRequest?.abort(); frame.removeAttribute('srcdoc');
            document.body.style.overflow = bodyOverflow;
            if (previewFocus?.isConnected) previewFocus.focus({preventScroll: true});
        });
        document.body.append(viewer);
    }

    function previewMarkup(content, markdown) {
        // A template is inert: document HTML never enters the manager's live DOM.
        const template = document.createElement('template');
        template.innerHTML = content;
        template.content.querySelectorAll('script, iframe, frame, frameset, object, embed, base, meta, template').forEach(node => node.remove());
        for (const element of template.content.querySelectorAll('*')) {
            for (const attribute of [...element.attributes]) {
                if (/^on/i.test(attribute.name) || ['srcdoc', 'autofocus'].includes(attribute.name)) element.removeAttribute(attribute.name);
                if (['href', 'src', 'action', 'formaction', 'xlink:href'].includes(attribute.name)
                    && /^(?:javascript|vbscript):/i.test(attribute.value.replace(/[\u0000-\u0020]/g, ''))) element.removeAttribute(attribute.name);
            }
            if (element.tagName === 'A') { element.setAttribute('target', '_blank'); element.setAttribute('rel', 'noopener noreferrer'); }
        }
        const style = markdown ? `body{max-width:900px;margin:32px auto;padding:0 24px;color:#24292f;font:16px/1.65 system-ui;overflow-wrap:anywhere}h1,h2{border-bottom:1px solid #d8dee4;padding-bottom:.3em}pre{padding:16px;background:#f6f8fa;overflow:auto;border-radius:8px}code{font-family:monospace;background:#eff1f3}pre code{background:none}img{max-width:100%}table{border-collapse:collapse;display:block;overflow:auto}td,th{padding:8px 12px;border:1px solid #d8dee4}blockquote{margin-left:0;padding-left:16px;border-left:4px solid #d0d7de;color:#57606a}a{color:#0969da}` : 'body{margin:16px}img{max-width:100%}';
        const policy = "default-src 'none'; script-src 'none'; style-src 'unsafe-inline' https: http:; img-src data: https: http:; font-src data: https: http:; base-uri 'none'; form-action 'none'";
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="referrer" content="no-referrer"><meta http-equiv="Content-Security-Policy" content="' + policy + '"><style>' + style + '</style></head><body>' + template.innerHTML + '</body></html>';
    }

    async function preview(path) {
        closeMenu();
        if (!viewer) createViewer();
        previewRequest?.abort();
        previewRequest = new AbortController();
        const version = ++previewVersion;
        previewPath = path;
        if (!viewer.open) {
            previewFocus = document.activeElement;
            bodyOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
            viewer.showModal();
        }
        document.getElementById('documentViewerTitle').textContent = path;
        previewStatus.textContent = 'Carregando documento…';
        previewStatus.hidden = false; frame.hidden = true; frame.removeAttribute('srcdoc');
        document.getElementById('documentViewerClose').focus();
        try {
            const query = new URLSearchParams({path});
            const response = await fetch('/getFile?' + query, {
                headers: {Authorization: 'Bearer ' + document.getElementById('fp').textContent.trim()},
                signal: previewRequest.signal,
            });
            if (!response.ok) throw new Error('Read failed');
            const data = await response.json();
            if (version !== previewVersion) return;
            if (!data.success || typeof data.information !== 'string') throw new Error('Invalid document');
            const text = data.encoded ? new TextDecoder().decode(Uint8Array.from(atob(data.information), c => c.charCodeAt(0))) : data.information;
            const markdown = ['md', 'markdown'].includes(extension(path));
            if (markdown && !root.marked?.parse) throw new Error('Markdown unavailable');
            frame.srcdoc = previewMarkup(markdown ? root.marked.parse(text, {gfm: true}) : text, markdown);
            frame.hidden = false;
            previewStatus.hidden = markdown;
            previewStatus.textContent = markdown ? '' : 'Prévia HTML estática. Scripts e formulários não são executados.';
        } catch (error) {
            if (version !== previewVersion || previewRequest.signal.aborted) return;
            previewStatus.textContent = 'Não foi possível visualizar este documento. Tente atualizar ou abra no editor.';
        }
    }

    root.FMFileActions = {preview, copyPath, closeMenu};
})(window);
