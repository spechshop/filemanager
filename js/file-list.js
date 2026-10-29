(function (root) {
    'use strict';

    let requestId = 0;
    let pending = null;
    let renderedPath = null;

    function loading(path) {
        const panel = document.getElementById('fileListPanel');
        if (!panel) return;
        panel.dataset.state = 'loading';
        document.getElementById('fileListStatus').hidden = false;
        document.getElementById('fileListSpinner').hidden = false;
        document.getElementById('fileListStatusText').textContent = 'Carregando pasta…';
        document.getElementById('fileListStatusPath').textContent = path;
        document.getElementById('fileListRetry').hidden = true;
        document.getElementById('filesList').replaceChildren();
        document.getElementById('viewGridFiles').replaceChildren();
        document.getElementById('tableListFiles').setAttribute('aria-busy', 'true');
        document.getElementById('viewGridFiles').setAttribute('aria-busy', 'true');
        root.currentFileItems = [];
        root.fileList = [];
        root.allImagePaths = [];
        const selectAll = document.querySelector('#optionsTableListFiles input');
        if (selectAll) selectAll.checked = selectAll.indeterminate = false;
        if (typeof root.updateSelectionActions === 'function') root.updateSelectionActions();
    }

    function load({force = false} = {}) {
        const pathElement = document.getElementById('pathInvisible');
        const path = pathElement.textContent;
        const panel = document.getElementById('fileListPanel');
        if (!force && pending?.path === path) return pending.promise;
        if (!force && renderedPath === path && panel.dataset.state === 'ready') return Promise.resolve();

        pending?.controller.abort();
        const id = ++requestId;
        const controller = new AbortController();
        const task = {path, controller, promise: null};
        pending = task;
        loading(path);
        const current = () => id === requestId && pathElement.textContent === path;
        task.promise = (async () => {
            try {
                const query = new URLSearchParams({
                    tokenBrowser: document.getElementById('fp').textContent,
                    path,
                });
                const response = await fetch('/syncPath?' + query, {signal: controller.signal});
                if (!response.ok) throw new Error('Unable to load directory');
                const data = await response.json();
                if (!current()) return;
                if (!data.success || !Array.isArray(data.information)) throw new Error('Invalid directory response');

                root.setItemsInTbody(data.information, 'filesList');
                const canonicalPath = typeof data.currentPath === 'string' ? data.currentPath : path;
                renderedPath = canonicalPath;
                if (pathElement.textContent !== canonicalPath) pathElement.textContent = canonicalPath;
                localStorage.setItem('pathInvisible', canonicalPath);
                root.FMUI.breadcrumbs(document.getElementById('visualPath'), canonicalPath, root.setPath);
                root.adjustPath();
                panel.dataset.state = 'ready';
                document.getElementById('fileListStatus').hidden = true;
            } catch (error) {
                if (error.name === 'AbortError' || !current()) return;
                panel.dataset.state = 'error';
                document.getElementById('fileListSpinner').hidden = true;
                document.getElementById('fileListStatusText').textContent = 'Não foi possível carregar esta pasta.';
                document.getElementById('fileListRetry').hidden = false;
            } finally {
                if (id === requestId) {
                    pending = null;
                    if (panel.dataset.state !== 'loading') {
                        document.getElementById('tableListFiles').setAttribute('aria-busy', 'false');
                        document.getElementById('viewGridFiles').setAttribute('aria-busy', 'false');
                    }
                }
            }
        })();
        return task.promise;
    }

    root.FMFileList = {loading, load};
})(window);
