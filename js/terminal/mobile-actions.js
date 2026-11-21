/* Copy/paste are UI operations; Ctrl+V remains a literal terminal control key. */
(function () {
    'use strict';
    const modal = document.getElementById('edit-code');
    const status = document.getElementById('terminalActionStatus');
    const dialog = document.getElementById('terminalClipboardDialog');
    const title = document.getElementById('terminalClipboardTitle');
    const hint = document.getElementById('terminalClipboardHint');
    const text = document.getElementById('terminalClipboardText');
    const confirm = document.getElementById('terminalClipboardConfirm');
    let messageTimer = null, pending = null;
    function active() {
        const id = document.querySelector('#tabsTerm .nav-link.active')?.closest('[data-terminal-id]')?.dataset.terminalId;
        return id && terminals[id];
    }
    function message(value) {
        clearTimeout(messageTimer); status.textContent = value; status.hidden = false;
        messageTimer = setTimeout(() => status.hidden = true, 3500);
    }
    function sync() {
        const info = active();
        modal.classList.toggle('terminal-selecting', !!info?.selecting);
        document.querySelectorAll('[data-terminal-action="select"]').forEach(button => {
            button.setAttribute('aria-pressed', String(!!info?.selecting));
            button.textContent = info?.selecting ? 'Selecionando' : 'Selecionar';
        });
    }
    function setSelecting(info, enabled) {
        if (!info) return;
        info.selecting = enabled; sync();
        if (enabled) message('Arraste sobre o texto para selecionar. Use Copiar ou toque em Selecionando para voltar a rolar.');
    }
    function showDialog(kind, info, value) {
        pending = {kind, info};
        title.textContent = kind === 'copy' ? 'Copiar seleção' : 'Colar texto';
        hint.textContent = kind === 'copy'
            ? 'Toque e segure o texto abaixo para usar Copiar no menu do celular.'
            : 'Cole o texto abaixo usando o menu do celular. Ele só será enviado ao tocar em Colar no terminal.';
        text.readOnly = kind === 'copy'; text.value = value || '';
        confirm.hidden = kind === 'copy';
        if (!dialog.open) dialog.showModal();
        text.focus(); if (kind === 'copy') text.select();
    }
    function closeDialog() {
        pending = null; text.value = ''; dialog.close();
    }
    function connected(info) {
        return info && Object.values(terminals).includes(info) && info.status === 'connected';
    }
    async function run(action) {
        const info = active(); if (!info) return;
        if (action === 'select') {setSelecting(info, !info.selecting); return;}
        if (action === 'all') {info.terminal.selectAll(); setSelecting(info, true); return;}
        if (action === 'clear-selection') {info.terminal.clearSelection(); setSelecting(info, false); return;}
        if (action === 'top') {info.terminal.scrollToTop(); return;}
        if (action === 'bottom') {info.terminal.scrollToBottom(); return;}
        if (action === 'copy') {
            const selection = info.terminal.getSelection();
            if (!selection) {message('Selecione um trecho do terminal antes de copiar.'); return;}
            try {
                if (!navigator.clipboard?.writeText) throw new Error('clipboard unavailable');
                await navigator.clipboard.writeText(selection);
                setSelecting(info, false); message('Texto copiado.');
            } catch {showDialog('copy', info, selection);}
            return;
        }
        if (action === 'paste') {
            if (!connected(info)) {message('Aguarde a conexão para colar texto.'); return;}
            try {
                if (!navigator.clipboard?.readText) throw new Error('clipboard unavailable');
                const value = await navigator.clipboard.readText();
                if (!connected(info)) return;
                if (value) info.terminal.paste(value); else message('A área de transferência está vazia.');
            } catch {if (connected(info)) showDialog('paste', info, '');}
        }
    }
    confirm.addEventListener('click', () => {
        const entry = pending;
        if (entry?.kind !== 'paste' || !connected(entry.info)) {message('A sessão não está conectada.'); return;}
        if (text.value) entry.info.terminal.paste(text.value);
        closeDialog(); entry.info.terminal.focus();
    });
    document.getElementById('terminalClipboardClose').addEventListener('click', closeDialog);
    dialog.addEventListener('close', () => {pending = null; text.value = '';});
    modal.addEventListener('hidden.bs.modal', () => {if (dialog.open) closeDialog(); clearTimeout(messageTimer); status.hidden = true;});
    document.addEventListener('fm:terminal-change', sync);
    window.FMTerminalActions = {run, setSelecting, sync, message};
})();
