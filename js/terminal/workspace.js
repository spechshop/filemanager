/* A single workspace shares the editor's terminal instances and transport. */
(function () {
    'use strict';
    const modal = document.getElementById('edit-code');
    const toggle = document.getElementById('terminalWorkspaceToggle');
    const library = document.getElementById('terminalSessionList');
    const search = document.getElementById('terminalSessionSearch');
    const libraryToggle = document.getElementById('terminalLibraryToggle');
    const zoomValue = document.getElementById('terminalZoomValue');
    const statusBar = document.getElementById('terminalStatusBar');
    const notice = document.getElementById('terminalNotice');
    const noticeText = document.getElementById('terminalNoticeText');
    const noticeAction = document.getElementById('terminalNoticeAction');
    const emptyTitle = document.getElementById('terminalEmptyTitle');
    const emptyText = document.querySelector('#mobileTerminalEmpty p');
    const emptyAction = document.getElementById('terminalEmptyAction');
    const cards = new Map();
    const shortStatus = {...terminalStatusLabels, unauthorized: 'Acesso negado'};
    let loadError = false, loadErrorStatus = null, loading = false, zoomKey = null, fontSizes = {}, recovery = null;
    function activeId() {
        return document.querySelector('#tabsTerm .nav-link.active')?.closest('[data-terminal-id]')?.dataset.terminalId;
    }
    function setLibrary(open) {
        modal.classList.toggle('terminal-library-open', open);
        libraryToggle.setAttribute('aria-expanded', String(open));
        libraryToggle.textContent = open ? 'Voltar à sessão' : 'Sessões';
        if (open) search.focus();
        refresh();
    }
    const MIN_FONT_SIZE = 4, MAX_FONT_SIZE = 48, DEFAULT_FONT_SIZE = 14;
    function setFontSize(id, value) {
        const info = terminals[id];
        if (!info || !Number.isFinite(value)) return;
        const size = Math.max(MIN_FONT_SIZE, Math.min(MAX_FONT_SIZE, Math.round(value)));
        if (info.terminal.options.fontSize === size) return;
        info.terminal.options.fontSize = size;
        info.zoomApplied = true;
        fontSizes[id] = size;
        if (zoomKey) { try { localStorage.setItem(zoomKey, JSON.stringify(fontSizes)); } catch {} }
        adjustTerminalSizeFromStorage(id);
        renderLibrary();
    }
    function zoom(change) {
        const id = activeId(), info = terminals[id];
        if (!info) return;
        setFontSize(id, change === 0 ? DEFAULT_FONT_SIZE : (info.terminal.options.fontSize || DEFAULT_FONT_SIZE) + change);
    }

    // Capture touches on a stable ancestor: the DOM renderer replaces its rows
    // while scrolling, so listeners on the original row lose the rest of a drag.
    const touchSurface = document.getElementById('terms');
    const fingers = new Map();
    let drag = null, pinch = null, multiTouch = false;
    let gestureFrame = null, momentumFrame = null, pendingPixels = 0, remainder = 0;
    let selectionTimer = null;
    function stopSelectionTimer() {clearTimeout(selectionTimer); selectionTimer = null;}
    function selectionPoint(info, x, y) {
        const screen = document.getElementById(info.options.id)?.querySelector('.xterm-screen');
        const rect = screen?.getBoundingClientRect();
        if (!rect?.width || !rect.height) return null;
        return {
            col: Math.max(0, Math.min(info.terminal.cols - 1, Math.floor((x - rect.left) * info.terminal.cols / rect.width))),
            row: info.terminal.buffer.active.viewportY + Math.max(0, Math.min(info.terminal.rows - 1, Math.floor((y - rect.top) * info.terminal.rows / rect.height))),
        };
    }
    function selectRange(info, a, b) {
        if (!a || !b) return;
        const cols = info.terminal.cols, start = Math.min(a.row * cols + a.col, b.row * cols + b.col);
        const end = Math.max(a.row * cols + a.col, b.row * cols + b.col);
        info.terminal.select(start % cols, Math.floor(start / cols), end - start + 1);
    }
    function cancelMomentum() {
        if (momentumFrame !== null) cancelAnimationFrame(momentumFrame);
        momentumFrame = null;
    }
    function scrollPixels(id, pixels) {
        const info = terminals[id];
        if (!info || activeId() !== id) return false;
        const screen = document.getElementById(id)?.querySelector('.xterm-screen');
        const rowHeight = screen?.getBoundingClientRect().height / info.terminal.rows;
        if (!rowHeight || !Number.isFinite(rowHeight)) return false;
        remainder += pixels;
        const lines = Math.trunc(remainder / rowHeight);
        if (!lines) return true;
        remainder -= lines * rowHeight;
        const before = info.terminal.buffer.active.viewportY;
        info.terminal.scrollLines(lines);
        return info.terminal.buffer.active.viewportY !== before;
    }
    function flushGesture() {
        if (gestureFrame !== null) cancelAnimationFrame(gestureFrame);
        gestureFrame = null;
        if (pinch && Number.isFinite(pinch.pending)) setFontSize(pinch.id, pinch.pending);
        else if (drag && pendingPixels) scrollPixels(drag.id, pendingPixels);
        pendingPixels = 0;
    }
    function queueGesture() {
        if (gestureFrame === null) gestureFrame = requestAnimationFrame(flushGesture);
    }
    function startMomentum(gesture) {
        cancelMomentum();
        if (performance.now() - gesture.time > 100 || Math.abs(gesture.velocity) < .1) return;
        let previous = performance.now(), elapsed = 0, velocity = gesture.velocity;
        function frame(now) {
            momentumFrame = null;
            if (!modal.classList.contains('show') || modal.classList.contains('terminal-library-open')) return;
            const dt = Math.max(0, Math.min(32, now - previous)); previous = now; elapsed += dt;
            if (!scrollPixels(gesture.id, velocity * dt)) return;
            velocity *= Math.exp(-dt / 180);
            if (Math.abs(velocity) > .04 && elapsed < 900) momentumFrame = requestAnimationFrame(frame);
        }
        momentumFrame = requestAnimationFrame(frame);
    }
    function consume(event) {event.preventDefault(); event.stopImmediatePropagation();}
    touchSurface.addEventListener('pointerdown', event => {
        if (event.pointerType !== 'touch' || modal.classList.contains('terminal-library-open')) return;
        const id = activeId(), info = terminals[id];
        if (!info) return;
        cancelMomentum(); stopSelectionTimer();
        touchSurface.setPointerCapture?.(event.pointerId);
        fingers.set(event.pointerId, {x: event.clientX, y: event.clientY});
        consume(event);
        if (fingers.size === 1 && !multiTouch) {
            remainder = 0;
            drag = {id, finger: event.pointerId, startX: event.clientX, startY: event.clientY, y: event.clientY, time: performance.now(), velocity: 0, moved: false, selecting: !!info.selecting};
            if (drag.selecting) {
                drag.anchor = selectionPoint(info, event.clientX, event.clientY);
                selectRange(info, drag.anchor, drag.anchor);
            } else {
                const held = drag;
                selectionTimer = setTimeout(() => {
                    selectionTimer = null;
                    if (drag !== held || held.moved || fingers.size !== 1 || activeId() !== id) return;
                    const point = selectionPoint(info, held.startX, held.startY);
                    if (!point) return;
                    held.selecting = true;
                    let first = point.col, last = point.col;
                    const line = info.terminal.buffer.active.getLine(point.row);
                    const wordCell = col => /[\p{L}\p{N}_./:@-]/u.test(line?.getCell(col)?.getChars() || ' ');
                    if (wordCell(first)) {
                        while (first > 0 && wordCell(first - 1)) first--;
                        while (last < info.terminal.cols - 1 && wordCell(last + 1)) last++;
                    }
                    held.anchor = {col: first, row: point.row};
                    selectRange(info, held.anchor, {col: last, row: point.row});
                    window.FMTerminalActions?.setSelecting(info, true);
                }, 450);
            }
        } else if (fingers.size === 2) {
            flushGesture(); drag = null; multiTouch = true;
            const [a, b] = [...fingers.values()];
            const distance = Math.hypot(a.x - b.x, a.y - b.y);
            pinch = distance > 8 ? {id, distance, size: info.terminal.options.fontSize || DEFAULT_FONT_SIZE, pending: null} : null;
        } else {flushGesture(); drag = pinch = null; multiTouch = true;}
    }, {capture: true});
    touchSurface.addEventListener('pointermove', event => {
        if (!fingers.has(event.pointerId)) return;
        consume(event);
        fingers.set(event.pointerId, {x: event.clientX, y: event.clientY});
        if (pinch && fingers.size === 2 && activeId() === pinch.id) {
            const [a, b] = [...fingers.values()];
            const distance = Math.hypot(a.x - b.x, a.y - b.y);
            if (Math.abs(distance - pinch.distance) < 4) return;
            pinch.pending = pinch.size * distance / pinch.distance;
            queueGesture();
        } else if (drag && !multiTouch && activeId() === drag.id) {
            if (drag.selecting) {
                const info = terminals[drag.id];
                if (info) selectRange(info, drag.anchor, selectionPoint(info, event.clientX, event.clientY));
                return;
            }
            const delta = drag.y - event.clientY;
            const now = performance.now(), dt = Math.max(1, now - drag.time);
            drag.velocity = .65 * Math.max(-3, Math.min(3, delta / dt)) + .35 * drag.velocity;
            drag.y = event.clientY; drag.time = now;
            if (Math.hypot(event.clientX - drag.startX, event.clientY - drag.startY) > 6) {drag.moved = true; stopSelectionTimer();}
            pendingPixels += delta;
            if (drag.moved) queueGesture();
        }
    }, {capture: true});
    touchSurface.addEventListener('pointerup', event => {
        if (!fingers.has(event.pointerId)) return;
        consume(event); stopSelectionTimer(); flushGesture();
        const completed = drag;
        fingers.delete(event.pointerId);
        if (touchSurface.hasPointerCapture?.(event.pointerId)) touchSurface.releasePointerCapture(event.pointerId);
        drag = pinch = null;
        if (!multiTouch && completed && !completed.selecting) {
            if (completed.moved) startMomentum(completed);
            else terminals[completed.id]?.terminal.focus();
        }
        if (!fingers.size) multiTouch = false;
    }, {capture: true});
    function cancelTouchGestures() {
        stopSelectionTimer();
        if (gestureFrame !== null) cancelAnimationFrame(gestureFrame);
        gestureFrame = null; pendingPixels = remainder = 0;
        drag = pinch = null; multiTouch = false; cancelMomentum();
        for (const id of fingers.keys()) if (touchSurface.hasPointerCapture?.(id)) touchSurface.releasePointerCapture(id);
        fingers.clear();
    }
    touchSurface.addEventListener('pointercancel', event => {if (fingers.has(event.pointerId)) {consume(event); cancelTouchGestures();}}, {capture: true});
    // Avoid xterm's independent touch handler and synthetic mouse input during
    // captured gestures; mouse/trackpad events keep their normal behavior.
    for (const type of ['touchstart', 'touchmove', 'touchend', 'touchcancel']) {
        touchSurface.addEventListener(type, consume, {capture: true, passive: false});
    }
    modal.addEventListener('hidden.bs.modal', cancelTouchGestures);
    window.addEventListener('blur', cancelTouchGestures);
    document.addEventListener('visibilitychange', () => {if (document.hidden) cancelTouchGestures();});
    if (window.FMAuth && window.crypto?.subtle) FMAuth.ready().then(async () => {
        const owner = document.getElementById('fp')?.textContent.trim() || getToken();
        const hash = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(owner));
        const identity = Array.from(new Uint8Array(hash), b => b.toString(16).padStart(2, '0')).join('');
        zoomKey = `fm:terminal-font:${identity}:${matchMedia('(max-width: 767.98px)').matches ? 'mobile' : 'desktop'}`;
        try { fontSizes = JSON.parse(localStorage.getItem(zoomKey) || '{}') || {}; } catch { fontSizes = {}; }
        renderLibrary();
    }).catch(() => {});
    function reviewAccess() {
        modal.addEventListener('hidden.bs.modal', () => document.getElementById('registeredCredential')?.focus(), {once: true});
        $('#edit-code').modal('hide');
        if (typeof showAuthenticationError === 'function') showAuthenticationError({status: 401});
    }
    function renderNotice(selected) {
        recovery = null;
        let text = '', action = '';
        if (loadError && Object.keys(terminals).length) {
            text = 'Não foi possível carregar as sessões. As sessões já abertas foram preservadas.';
            const denied = [401, 403].includes(loadErrorStatus);
            action = denied ? 'Revisar acesso' : loading ? 'Carregando…' : 'Tentar novamente'; recovery = denied ? reviewAccess : reloadSessions;
        } else if (selected?.status === 'unauthorized') {
            text = 'Acesso negado. Entrada bloqueada; histórico preservado.';
            action = 'Revisar acesso'; recovery = reviewAccess;
        } else if (selected?.status === 'reconnecting' || selected?.status === 'disconnected') {
            text = 'Conexão interrompida. Tentando reconectar; seu histórico foi preservado.';
            action = 'Tentar agora'; recovery = () => reconnectTerminal(activeId());
        }
        notice.hidden = !text;
        modal.classList.toggle('terminal-has-notice', !!text);
        noticeText.textContent = text;
        noticeAction.textContent = action;
        noticeAction.disabled = loading;
    }
    function renderLibrary() {
        const libraryOpen = modal.classList.contains('terminal-library-open');
        libraryToggle.textContent = libraryOpen ? 'Voltar à sessão' : 'Sessões';
        libraryToggle.setAttribute('aria-expanded', String(libraryOpen));
        for (const [id, info] of Object.entries(terminals)) {
            if (!info.zoomApplied && Number.isFinite(fontSizes[id])) {
                info.terminal.options.fontSize = Math.max(MIN_FONT_SIZE, Math.min(MAX_FONT_SIZE, fontSizes[id]));
                info.zoomApplied = true; adjustTerminalSizeFromStorage(id);
            }
        }
        const id = activeId(), selected = terminals[id];
        const size = selected?.terminal.options.fontSize || 14;
        statusBar.textContent = selected ? `Shell deste servidor · ${terminalStatusLabels[selected.status] || 'Conectando'}` : 'Nenhuma sessão selecionada';
        statusBar.dataset.status = selected?.status || '';
        zoomValue.textContent = Math.round(size / 14 * 100) + '%';
        zoomValue.title = `${size} px · Restaurar 14 px`;
        document.querySelectorAll('[data-terminal-zoom]').forEach(button => {
            const change = Number(button.dataset.terminalZoom);
            button.disabled = !selected || (change < 0 && size <= MIN_FONT_SIZE) || (change > 0 && size >= MAX_FONT_SIZE);
        });
        const filter = search.value.trim().toLocaleLowerCase();
        const tabs = [...document.querySelectorAll('#tabsTerm [data-terminal-id]')];
        const present = new Set(tabs.map(tab => tab.dataset.terminalId));
        for (const [key, card] of cards) if (!present.has(key)) {card.remove(); cards.delete(key);}
        let visible = 0;
        for (const tab of tabs) {
            const key = tab.dataset.terminalId, info = terminals[key];
            const name = info?.options.name || 'Terminal';
            let card = cards.get(key);
            if (!card) {
                card = document.createElement('div'); card.className = 'terminal-session-card';
                const button = document.createElement('button'); button.type = 'button'; button.className = 'terminal-session-select';
                const title = document.createElement('strong'), detail = document.createElement('span');
                detail.className = 'terminal-session-state';
                button.append(title, detail);
                button.addEventListener('click', () => {selectTerminalTab(key); setLibrary(false); terminals[key]?.terminal.focus();});
                const actions = document.createElement('button'); actions.type = 'button'; actions.className = 'terminal-session-actions'; actions.textContent = '⋯';
                actions.addEventListener('click', event => {
                    event.stopPropagation();
                    const rect = actions.getBoundingClientRect(), current = terminals[key];
                    if (current) showTerminalCtxMenu(rect.left, rect.bottom, key, tab.querySelector('.terminal-tab-label'), current.options, actions);
                });
                card.append(button, actions); cards.set(key, card); library.append(card);
            }
            const current = key === id;
            const button = card.children[0];
            card.classList.toggle('active', current);
            card.dataset.status = info?.status || 'connecting';
            if (current) button.setAttribute('aria-current', 'true'); else button.removeAttribute('aria-current');
            button.children[0].textContent = (current ? '✓ ' : '') + name;
            button.children[0].title = name;
            button.children[1].textContent = shortStatus[info?.status] || 'Conectando';
            card.children[1].setAttribute('aria-label', 'Ações de ' + name);
            card.hidden = !name.toLocaleLowerCase().includes(filter);
            if (!card.hidden) visible++;
        }
        let message = document.getElementById('terminalLibraryMessage');
        if (!message) {message = document.createElement('p'); message.id = 'terminalLibraryMessage'; library.append(message);}
        message.hidden = !!visible;
        message.textContent = loadError ? 'Não foi possível obter a lista de sessões.' : tabs.length ? 'Nenhuma sessão encontrada.' : 'Suas sessões aparecerão aqui.';
        emptyTitle.textContent = loadError ? 'Não foi possível carregar as sessões' : 'Seu terminal, seu espaço';
        emptyText.textContent = loadError ? 'Tente consultar a lista novamente.' : 'Abra uma sessão de shell neste servidor para começar.';
        emptyAction.textContent = loadError ? ([401, 403].includes(loadErrorStatus) ? 'Revisar acesso' : loading ? 'Carregando…' : 'Tentar novamente') : 'Nova sessão';
        emptyAction.disabled = loading;
        document.querySelectorAll('[data-terminal-create]').forEach(button => button.hidden = !tabs.length || loadError);
        renderNotice(selected);
        const mobileTerminal = matchMedia('(max-width: 767.98px)').matches && (modal.classList.contains('terminal-workspace') || modal.classList.contains('mobile-view-terminal'));
        toggle.textContent = mobileTerminal ? 'Editor' : modal.classList.contains('terminal-workspace') ? 'Voltar ao editor' : 'Expandir terminal';
        modal.classList.toggle('terminal-no-sessions', !tabs.length);
        window.FMTerminalActions?.sync();
    }
    async function reloadSessions() {
        if (loading) return;
        loading = true; renderLibrary();
        try {
            await loadTerminalSessions(); loadError = false;
        } catch (error) {
            loadError = true; loadErrorStatus = error.status;
        } finally {loading = false; refresh();}
    }
    function refresh() {
        renderLibrary();
        requestAnimationFrame(() => {
            const id = activeId(); if (id) adjustTerminalSizeFromStorage(id);
            if (typeof editor !== 'undefined') editor?.layout?.();
        });
    }
    function mode(dedicated) {
        modal.classList.remove('terminal-library-open');
        modal.classList.toggle('terminal-workspace', dedicated);
        toggle.setAttribute('aria-pressed', String(dedicated));
        if (typeof setMobileWorkspace === 'function') setMobileWorkspace(dedicated ? 'terminal' : 'code');
        refresh();
    }
    window.FMTerminalWorkspace = {open() {mode(true); $('#edit-code').modal('show');}, editor() {mode(false);}, refresh};
    toggle.addEventListener('click', () => {
        const mobileTerminal = matchMedia('(max-width: 767.98px)').matches && modal.classList.contains('mobile-view-terminal');
        mode(!modal.classList.contains('terminal-workspace') && !mobileTerminal);
    });
    modal.addEventListener('shown.bs.modal', refresh);
    modal.addEventListener('hidden.bs.modal', () => {setLibrary(false); mode(false);});
    window.addEventListener('resize', refresh);
    document.addEventListener('fm:terminal-layout', refresh);
    search.addEventListener('input', renderLibrary);
    libraryToggle.addEventListener('click', () => {
        if (!modal.classList.contains('terminal-workspace')) mode(true);
        setLibrary(!modal.classList.contains('terminal-library-open'));
    });
    document.querySelectorAll('[data-terminal-create]').forEach(button => button.addEventListener('click', () => {
        setLibrary(false); document.getElementById('new-terminal').click();
    }));
    emptyAction.addEventListener('click', () => {
        if (loadError) { if ([401, 403].includes(loadErrorStatus)) reviewAccess(); else reloadSessions(); }
        else document.getElementById('new-terminal').click();
    });
    noticeAction.addEventListener('click', () => recovery?.());
    new MutationObserver(renderLibrary).observe(document.getElementById('tabsTerm'), {
        subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ['class'],
    });
    document.addEventListener('fm:terminal-change', renderLibrary);
    document.addEventListener('fm:terminal-load-error', event => {loadError = true; loadErrorStatus = event.detail?.status; renderLibrary();});
    document.addEventListener('fm:terminal-loaded', () => {loadError = false; loadErrorStatus = null; renderLibrary();});
    document.querySelectorAll('[data-terminal-zoom]').forEach(button => {
        button.addEventListener('pointerdown', event => event.preventDefault());
        button.addEventListener('click', () => zoom(Number(button.dataset.terminalZoom)));
    });
    modal.addEventListener('keydown', event => {
        if (!(event.ctrlKey || event.metaKey) || !event.shiftKey || event.altKey) return;
        if (!document.getElementById('footer-terminal').contains(event.target)) return;
        const change = {Equal: 1, Minus: -1, Digit0: 0}[event.code];
        if (change === undefined) return;
        event.preventDefault(); event.stopImmediatePropagation(); zoom(change);
    }, true);
    renderLibrary();
})();
