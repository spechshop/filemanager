(function (root) {
    'use strict';
    let entries = [], selected = new Set(), busy = false, version = 0;
    let previousFocus = null, previousOverflow = '', countVersion = 0;
    const itemCount = count => `${count} ${count === 1 ? 'item' : 'itens'}`;
    const el = id => document.getElementById(id);
    const panel = () => el('trashView');
    const settings = {background: '#1d2431', color: '#edf1f7', confirmButtonColor: '#4779c5', cancelButtonText: 'Cancelar'};

    async function request(action, data = {}) {
        await root.FMAuth.ready();
        const response = await fetch('/trash', {
            method: 'POST', cache: 'no-store', referrerPolicy: 'no-referrer',
            headers: {'Content-Type': 'application/json', Authorization: `Bearer ${el('fp').textContent.trim()}`},
            body: JSON.stringify({action, ...data}),
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.message || 'Não foi possível acessar a lixeira. Verifique sua conexão e suas permissões.');
        return result;
    }

    function dialog(options) {
        return root.Swal.fire({...settings, target: panel().hidden ? document.body : panel(), ...options});
    }

    function status(message, error = false) {
        el('trashStatus').hidden = !message;
        el('trashStatusText').textContent = message;
        el('trashStatus').classList.toggle('trash-error', error);
    }

    function visibleEntries() {
        const query = el('trashSearch').value.trim().toLocaleLowerCase();
        return entries.filter(item => `${item.name} ${item.originalPath}`.toLocaleLowerCase().includes(query));
    }

    function updateActions() {
        const visible = visibleEntries();
        el('trashRestoreSelected').disabled = busy || !selected.size;
        el('trashDeleteSelected').disabled = busy || !selected.size;
        el('trashEmpty').disabled = busy || !entries.length;
        el('trashRefresh').disabled = busy;
        el('trashSelectAll').disabled = busy || !visible.length;
        const count = visible.filter(item => selected.has(item.id)).length;
        el('trashSelectAll').checked = visible.length > 0 && count === visible.length;
        el('trashSelectAll').indeterminate = count > 0 && count < visible.length;
        el('trashSelectedCount').textContent = selected.size ? `${selected.size} ${selected.size === 1 ? 'selecionado' : 'selecionados'}` : `${itemCount(entries.length)} na lixeira`;
        panel().querySelectorAll('[data-trash-item-action], .trash-item input').forEach(control => { control.disabled = busy; });
    }

    function size(bytes) {
        if (bytes === null || bytes === undefined) return '—';
        if (bytes < 1024) return `${bytes} B`;
        const unit = Math.min(3, Math.floor(Math.log(bytes) / Math.log(1024)));
        return `${(bytes / 1024 ** unit).toLocaleString('pt-BR', {maximumFractionDigits: 1})} ${['B', 'KB', 'MB', 'GB'][unit]}`;
    }

    function badge(count) {
        el('trashCountBadge').textContent = count;
        el('trashCountBadge').hidden = count === 0;
    }

    async function updateCount() {
        const current = ++countVersion;
        try {
            const result = await request('list');
            if (current === countVersion && result.success && Array.isArray(result.entries)) badge(result.entries.length);
        } catch (error) { /* Opening the dedicated view offers a retry if unavailable. */ }
    }

    function render() {
        const list = el('trashItems');
        list.replaceChildren();
        const visible = visibleEntries();
        const fragment = document.createDocumentFragment();
        for (const item of visible) {
            const row = document.createElement('li');
            row.className = 'trash-item';
            const check = document.createElement('input');
            check.type = 'checkbox';
            check.checked = selected.has(item.id);
            check.setAttribute('aria-label', `Selecionar ${item.name}`);
            check.onchange = () => { check.checked ? selected.add(item.id) : selected.delete(item.id); updateActions(); };
            const identity = document.createElement('div');
            identity.className = 'trash-item-identity';
            const name = document.createElement('strong');
            name.textContent = item.name;
            name.title = item.name;
            const kind = document.createElement('small');
            kind.textContent = item.kind === 'folder' ? 'Pasta' : item.kind === 'link' ? 'Link simbólico' : 'Arquivo';
            identity.append(name, kind);
            const original = document.createElement('span');
            original.className = 'trash-item-origin';
            original.textContent = item.originalPath;
            original.title = item.originalPath;
            const date = document.createElement('time');
            date.dateTime = new Date(item.deletedAt * 1000).toISOString();
            date.textContent = new Date(item.deletedAt * 1000).toLocaleString('pt-BR');
            const amount = document.createElement('span');
            amount.className = 'trash-item-size';
            amount.textContent = size(item.size);
            const actions = document.createElement('div');
            actions.className = 'trash-item-actions';
            for (const [action, label] of [['restore', 'Restaurar'], ['purge', 'Excluir definitivamente']]) {
                const button = document.createElement('button');
                button.type = 'button';
                button.dataset.trashItemAction = action;
                button.textContent = label;
                button.setAttribute('aria-label', `${label}: ${item.name}`);
                button.onclick = () => perform(action, [item.id]);
                actions.appendChild(button);
            }
            row.append(check, identity, original, date, amount, actions);
            fragment.appendChild(row);
        }
        list.appendChild(fragment);
        el('trashNoItems').hidden = visible.length > 0;
        el('trashNoItemsTitle').textContent = entries.length ? 'Nenhum resultado' : 'Sua lixeira está vazia';
        el('trashNoItemsText').textContent = entries.length ? 'Tente buscar outro nome ou caminho.' : 'Os arquivos e pastas removidos aparecerão aqui para restauração.';
        ++countVersion;
        badge(entries.length);
        updateActions();
    }

    async function refresh() {
        const current = ++version;
        status('Carregando lixeira…');
        el('trashRetry').hidden = true;
        el('trashNoItems').hidden = true;
        try {
            const result = await request('list');
            if (current !== version || panel().hidden) return;
            if (!result.success || !Array.isArray(result.entries)) throw new Error('Não foi possível carregar a lixeira. Tente novamente.');
            entries = result.entries;
            selected = new Set([...selected].filter(id => entries.some(item => item.id === id)));
            status('');
            render();
        } catch (error) {
            if (current !== version || panel().hidden) return;
            status(error.message, true);
            el('trashRetry').hidden = false;
        }
    }

    async function refreshFiles() {
        const tasks = [];
        if (root.FMFileList && el('fileListPanel')) tasks.push(root.FMFileList.load({force: true}));
        if (typeof root.refAll === 'function' && el('filesArchitecture')) tasks.push(root.refAll());
        await Promise.allSettled(tasks);
    }

    async function runBatch(action, ids, extra = {}) {
        const combined = {completed: [], errors: []};
        for (let i = 0; i < ids.length; i += 100) {
            const result = await request(action, {ids: ids.slice(i, i + 100), ...extra});
            combined.completed.push(...(result.completed || []));
            combined.errors.push(...(result.errors || []));
        }
        return combined;
    }

    async function perform(action, ids = [...selected]) {
        if (busy || (action !== 'empty' && !ids.length)) return;
        if (action !== 'restore') {
            const count = action === 'empty' ? entries.length : ids.length;
            const result = await dialog({icon: 'warning', title: action === 'empty' ? 'Esvaziar a lixeira?' : 'Excluir definitivamente?',
                text: `${itemCount(count)}: exclusão permanente, sem possibilidade de restauração.`,
                showCancelButton: true, confirmButtonText: 'Excluir definitivamente', confirmButtonColor: '#c94f61'});
            if (!result.isConfirmed || busy) return;
        }
        busy = true;
        updateActions();
        status(action === 'restore' ? 'Restaurando itens…' : 'Excluindo definitivamente…');
        try {
            let result = action === 'empty' ? await request('empty') : await runBatch(action, ids);
            const conflicts = (result.errors || []).filter(error => error.code === 'conflict');
            if (action === 'restore' && conflicts.length) {
                const decision = await dialog({icon: 'info', title: 'Já existem arquivos no destino',
                    text: `${itemCount(conflicts.length)} com destino ocupado. Deseja restaurar com outro nome e preservar os arquivos existentes?`,
                    showCancelButton: true, confirmButtonText: 'Restaurar com outro nome'});
                if (decision.isConfirmed) {
                    const retried = await runBatch('restore', conflicts.map(error => error.item), {conflict: 'rename'});
                    result.completed.push(...retried.completed);
                    result.errors = [...result.errors.filter(error => error.code !== 'conflict'), ...retried.errors];
                }
            }
            await refreshFiles();
            if (!panel().hidden) await refresh();
            else await updateCount();
            const errors = result.errors || [];
            const completed = result.completed || [];
            if (errors.length) await dialog({icon: 'warning', title: `${completed.length} concluído(s), ${errors.length} pendente(s)`,
                text: [...new Set(errors.map(error => error.message))].join('\n'), confirmButtonText: 'Entendi'});
            else if (!panel().hidden) status(`${itemCount(completed.length)}: ${action === 'restore' ? 'restauração concluída no local de origem' : 'exclusão definitiva concluída'}.`);
        } catch (error) {
            if (!panel().hidden) {
                await refresh();
                status(error.message, true);
            } else await dialog({icon: 'error', title: 'Operação não concluída', text: error.message});
        } finally {
            busy = false;
            updateActions();
        }
    }

    async function move(paths) {
        if (busy) return false;
        if (!paths.length) return dialog({icon: 'info', title: 'Nenhum item selecionado', text: 'Selecione os arquivos ou pastas que deseja mover para a lixeira.'});
        const confirmation = await dialog({icon: 'question', title: 'Mover para a lixeira?',
            text: `${itemCount(paths.length)}: mover da pasta atual para a lixeira, com possibilidade de restauração.`,
            showCancelButton: true, confirmButtonText: 'Mover para a lixeira'});
        if (!confirmation.isConfirmed || busy) return false;
        busy = true;
        try {
            const combined = {completed: [], errors: []};
            for (let i = 0; i < paths.length; i += 100) {
                const result = await request('move', {files: paths.slice(i, i + 100)});
                combined.completed.push(...(result.completed || []));
                combined.errors.push(...(result.errors || []));
            }
            await refreshFiles();
            await updateCount();
            const answer = await dialog({icon: combined.errors.length ? 'warning' : 'success', title: 'Lixeira',
                text: `${itemCount(combined.completed.length)} na lixeira.${combined.errors.length ? ` ${combined.errors.length} não puderam ser movidos. ${combined.errors[0].message}` : ' Você pode restaurá-los quando precisar.'}`,
                showCancelButton: true, confirmButtonText: 'Abrir lixeira', cancelButtonText: 'Fechar'});
            if (answer.isConfirmed) open();
            return combined.errors.length === 0;
        } catch (error) {
            await refreshFiles();
            await dialog({icon: 'error', title: 'Não foi possível mover para a lixeira', text: error.message});
            return false;
        } finally {
            busy = false;
            updateActions();
        }
    }

    function keydown(event) {
        if (root.Swal.isVisible?.()) return;
        if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(); }
        else if (event.key === 'Tab') {
            const controls = [...panel().querySelectorAll('button:not(:disabled), input:not(:disabled)')].filter(node => node.getClientRects().length && !node.closest('[hidden]'));
            const first = controls[0], last = controls[controls.length - 1];
            if (first && ((event.shiftKey ? document.activeElement === first : document.activeElement === last) || !panel().contains(document.activeElement))) {
                event.preventDefault(); (event.shiftKey ? last : first).focus();
            }
        }
    }

    function open() {
        if (panel().hidden) {
            previousFocus = document.activeElement;
            previousOverflow = document.body.style.overflow;
        }
        panel().hidden = false;
        document.body.style.overflow = 'hidden';
        selected.clear();
        entries = [];
        el('trashSearch').value = '';
        render();
        el('trashClose').focus();
        root.addEventListener('keydown', keydown, true);
        return refresh();
    }

    function close() {
        ++version;
        panel().hidden = true;
        root.removeEventListener('keydown', keydown, true);
        document.body.style.overflow = previousOverflow;
        if (previousFocus?.isConnected) previousFocus.focus({preventScroll: true});
    }

    function selectAll(checked) {
        for (const item of visibleEntries()) checked ? selected.add(item.id) : selected.delete(item.id);
        render();
    }

    root.FMTrash = {open, close, refresh, filter: render, selectAll, perform, move, updateCount};
})(window);
