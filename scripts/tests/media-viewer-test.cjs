'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM} = require('jsdom');

const root = path.resolve(__dirname, '../..');
const source = fs.readFileSync(path.join(root, 'plugins/Request/modules/makeTable/preloadScripts.html'), 'utf8');
const tick = () => new Promise(resolve => setImmediate(resolve));

function browser(mediaUrl = async file => '/media?path=' + encodeURIComponent(file)) {
    const dom = new JSDOM('<title>File manager</title>' + source.slice(source.indexOf('<div id="customImageViewer"')), {
        url: 'http://localhost', runScripts: 'dangerously',
    });
    const w = dom.window;
    w.eval(fs.readFileSync(path.join(root, 'js/security-ui.js'), 'utf8'));
    w.$ = selector => ({text: value => { w.document.querySelector(selector).textContent = value; }});
    w.getFilename = file => file.split('/').pop();
    w.FMAuth = {mediaUrl};
    w.fetch = async () => ({ok: true, json: async () => ({success: true, information: []})});
    const credential = w.document.createElement('div');
    credential.id = 'fp'; credential.textContent = 'fixture-owner'; w.document.body.append(credential);
    w.previewImages = [];
    const createElement = w.document.createElement.bind(w.document);
    w.document.createElement = (...args) => {
        const element = createElement(...args);
        if (args[0] === 'img') w.previewImages.push(element);
        return element;
    };
    w.HTMLMediaElement.prototype.pause = function () {};
    w.HTMLMediaElement.prototype.load = function () {};
    w.fileList = ['/one.jpg', '/two.png', '/three.webp'];
    w.eval(source.slice(source.indexOf('    let mediaViewerRequest'), source.indexOf('</script>', source.indexOf('    let mediaViewerRequest'))));
    return dom;
}

function delayedBrowser() {
    const requests = [];
    const dom = browser((file, operation) => new Promise((resolve, reject) => requests.push({file, operation, resolve, reject})));
    return {dom, w: dom.window, requests};
}

test('the fullscreen viewer stays open while navigating and never waits for thumbnails', async () => {
    const {dom, w, requests} = delayedBrowser();
    try {
        const first = w.showImage('/one.jpg');
        assert.equal(w.document.getElementById('customImageViewer').style.display, 'block');
        assert.ok(w.document.querySelector('[role="status"]'));
        requests[0].resolve('/first');
        await first;
        assert.equal(w.document.getElementById('viewerImage').getAttribute('src'), '/first');
        const second = w.navNext();
        assert.equal(w.document.getElementById('customImageViewer').style.display, 'block');
        assert.equal(w.__currentIndex, 1);
        requests.at(-1).resolve('/second');
        await second;
        assert.equal(w.document.getElementById('viewerImage').getAttribute('src'), '/second');
    } finally { dom.window.close(); }
});

test('rapid navigation ignores stale media and thumbnail results', async () => {
    const {dom, w, requests} = delayedBrowser();
    try {
        const first = w.showImage('/one.jpg');
        const firstRequests = requests.slice();
        const second = w.navNext();
        const secondMain = requests.at(-1);
        const third = w.navNext();
        requests.at(-1).resolve('/newest');
        await third;
        firstRequests.forEach(request => request.resolve('/stale'));
        secondMain.resolve('/also-stale');
        await Promise.all([first, second]);
        assert.equal(w.__currentIndex, 2);
        assert.equal(w.document.getElementById('nameFileModalMedia').textContent, '/three.webp');
        assert.equal(w.document.getElementById('viewerImage').getAttribute('src'), '/newest');
        assert.equal(w.document.querySelectorAll('#thumbNav img').length, 0);
        assert.equal(w.document.getElementById('rightArrow').disabled, true);
    } finally { dom.window.close(); }
});

test('closing during loading cannot reopen or repopulate the viewer', async () => {
    const {dom, w, requests} = delayedBrowser();
    try {
        const opening = w.showImage('/one.jpg');
        w.closeImageViewer();
        requests.forEach(request => request.resolve('/late'));
        await opening;
        assert.equal(w.document.getElementById('customImageViewer').style.display, 'none');
        assert.equal(w.document.getElementById('modalMedia').childElementCount, 0);
        assert.equal(w.document.getElementById('thumbNav').childElementCount, 0);
        assert.equal(w.__fileList, undefined);
    } finally { dom.window.close(); }
});

test('authorization and file errors keep navigation and closing usable', async () => {
    const {dom, w, requests} = delayedBrowser();
    try {
        const failed = w.showImage('/one.jpg');
        requests.forEach(request => request.reject(new Error('denied')));
        await failed;
        await tick();
        assert.ok(w.document.querySelector('#modalMedia [role="alert"]'));
        const next = w.navNext();
        requests.at(-1).resolve('/broken');
        await next;
        w.document.getElementById('viewerImage').dispatchEvent(new w.Event('error'));
        assert.ok(w.document.querySelector('#modalMedia [role="alert"]'));
        assert.equal(w.document.getElementById('imageZoomControls').hidden, true);
        w.document.getElementById('mediaViewerClose').click();
        assert.equal(w.document.getElementById('customImageViewer').style.display, 'none');
    } finally { dom.window.close(); }
});

test('zoom supports buttons, wheel, double click and bounded dragging; changing files resets it', async () => {
    const dom = browser(), w = dom.window;
    try {
        await w.showImage('/one.jpg');
        const image = w.document.getElementById('viewerImage');
        Object.defineProperties(image, {clientWidth: {value: 400}, clientHeight: {value: 200}});
        image.setPointerCapture = () => {};
        w.document.getElementById('imageZoomIn').click();
        assert.equal(w.document.getElementById('imageZoomLevel').textContent, '125%');
        const wheel = new w.WheelEvent('wheel', {deltaY: -100, bubbles: true, cancelable: true});
        image.dispatchEvent(wheel);
        assert.equal(wheel.defaultPrevented, true);
        assert.match(image.style.transform, /scale\(1.5\)/);
        image.onpointerdown({button: 0, pointerId: 1, clientX: 0, clientY: 0, preventDefault() {}});
        image.onpointermove({pointerId: 1, clientX: 500, clientY: -500});
        assert.match(image.style.transform, /translate\(100px, -50px\)/);
        image.onpointerup();
        assert.equal(image.style.cursor, 'grab');
        image.dispatchEvent(new w.MouseEvent('dblclick'));
        assert.match(image.style.transform, /translate\(0px, 0px\) scale\(1\)/);
        image.dispatchEvent(new w.MouseEvent('dblclick'));
        assert.match(image.style.transform, /scale\(2\)/);
        await w.navNext();
        assert.equal(w.document.getElementById('imageZoomLevel').textContent, '100%');
        assert.equal(w.document.getElementById('imageZoomOut').disabled, true);
    } finally { dom.window.close(); }
});

test('viewer shortcuts preserve existing keyboard handlers and native video controls', async () => {
    const dom = browser(), w = dom.window;
    try {
        const existingHandler = () => {};
        w.onkeydown = existingHandler;
        await w.showImage('/one.jpg');
        w.dispatchEvent(new w.KeyboardEvent('keydown', {key: '+', cancelable: true}));
        assert.equal(w.document.getElementById('imageZoomLevel').textContent, '125%');
        w.dispatchEvent(new w.KeyboardEvent('keydown', {key: 'ArrowRight', cancelable: true}));
        await tick();
        assert.equal(w.__currentIndex, 1);
        const video = w.document.createElement('video');
        w.document.getElementById('modalMedia').append(video);
        video.dispatchEvent(new w.KeyboardEvent('keydown', {key: 'ArrowRight', bubbles: true, cancelable: true}));
        assert.equal(w.__currentIndex, 1);
        w.dispatchEvent(new w.KeyboardEvent('keydown', {key: 'Escape'}));
        assert.equal(w.document.getElementById('customImageViewer').style.display, 'none');
        assert.equal(w.onkeydown, existingHandler);
    } finally { dom.window.close(); }
});

test('videos and audio are stopped and released when navigating or closing', async () => {
    const dom = browser(), w = dom.window;
    try {
        w.fileList = ['/video.mp4', '/song.mp3', '/one.jpg'];
        const paused = [], loaded = [];
        w.HTMLMediaElement.prototype.pause = function () { paused.push(this); };
        w.HTMLMediaElement.prototype.load = function () { loaded.push(this); };
        await w.showImage('/video.mp4');
        const video = w.document.querySelector('#modalMedia video');
        assert.ok(video.controls);
        assert.equal(w.document.getElementById('imageZoomControls').hidden, true);
        await w.navNext();
        const audio = w.document.querySelector('#modalMedia audio');
        assert.ok(audio.controls);
        assert.ok(paused.includes(video));
        assert.ok(loaded.includes(video));
        assert.equal(video.hasAttribute('src'), false);
        w.closeImageViewer();
        assert.ok(paused.includes(audio));
        assert.ok(loaded.includes(audio));
        assert.equal(audio.querySelector('source').hasAttribute('src'), false);
    } finally { dom.window.close(); }
});

test('hostile filenames remain text in the viewer and thumbnails', async () => {
    const dom = browser(), w = dom.window;
    try {
        const file = '/<img src=x onerror="window.pwned=1">.jpg';
        w.fileList = [file];
        await w.showImage(file);
        assert.equal(w.document.getElementById('nameFileModalMedia').textContent, file);
        assert.equal(w.document.getElementById('viewerImage').alt, w.getFilename(file));
        assert.equal(w.document.querySelector('.media-thumbnail').title, file);
        assert.equal(w.pwned, undefined);
        assert.equal(w.document.querySelectorAll('[onerror], svg').length, 0);
    } finally { dom.window.close(); }
});

test('the gallery includes the entire folder and combines filename search with media filters', async () => {
    const dom = browser(), w = dom.window;
    try {
        w.fileList = [...Array.from({length: 30}, (_, index) => `/photo-${index}.jpg`), '/holiday.mp4', '/holiday.mp3'];
        await w.showImage('/photo-12.jpg');
        await tick();
        const cards = [...w.document.querySelectorAll('.media-thumbnail')];
        assert.equal(cards.length, 32);
        assert.equal(w.document.getElementById('mediaGalleryCount').textContent, '32 arquivos');
        assert.equal(w.document.getElementById('mediaFilePosition').textContent, '13 de 32');
        w.filterMediaGallery('video');
        assert.deepEqual([...w.document.querySelectorAll('.media-thumbnail')].map(card => card.dataset.path), ['/holiday.mp4']);
        const search = w.document.getElementById('mediaGallerySearch');
        search.value = 'PHOTO';
        search.dispatchEvent(new w.Event('input'));
        assert.equal(w.document.getElementById('mediaGalleryEmpty').hidden, false);
        w.filterMediaGallery('image');
        assert.equal([...w.document.querySelectorAll('.media-thumbnail')].length, 30);
        assert.equal(w.document.getElementById('mediaGalleryEmpty').hidden, true);
        await w.navNext();
        assert.equal(w.document.querySelectorAll('.media-thumbnail')[0].dataset.path, '/photo-0.jpg');
        assert.equal(search.value, 'PHOTO');
        assert.equal(w.document.querySelector('.media-thumbnail[aria-current="true"]').dataset.path, '/photo-13.jpg');
    } finally { dom.window.close(); }
});

test('general gallery opens an empty parent and adds grouped folders as streamed results arrive', async () => {
    const dom = browser(), w = dom.window;
    let stream;
    w.TextDecoder = TextDecoder;
    w.fileList = [];
    w.fetch = async () => ({ok: true, headers: new Headers({'Content-Type': 'application/x-ndjson'}), body: new ReadableStream({start(controller) { stream = controller; }})});
    const send = record => stream.enqueue(new TextEncoder().encode(JSON.stringify({success: true, ...record}) + '\n'));
    try {
        await w.openMediaGallery('/album');
        await tick();
        assert.equal(w.document.getElementById('customImageViewer').classList.contains('gallery-expanded'), true);
        assert.equal(w.document.getElementById('mediaGalleryProgress').hidden, false);
        assert.equal(w.document.getElementById('mediaGalleryEmpty').hidden, true);
        assert.equal(w.document.getElementById('mediaGalleryToggle').disabled, true);
        assert.equal(w.__fileList.length, 0, 'no fabricated file is added for the parent directory');
        send({information: ['/album/viagem/foto.jpg'], scannedFolders: 2, pendingFolders: 1, done: false});
        await tick();
        assert.equal(w.document.querySelectorAll('.media-thumbnail').length, 1, 'first batch is visible before the stream completes');
        assert.match(w.document.getElementById('mediaGalleryStatus').textContent, /2 pastas verificadas/);
        assert.equal(w.document.querySelector('.media-folder-group h3 span').textContent, 'viagem');
        // Split a multibyte filename and JSON line across network chunks.
        const bytes = new TextEncoder().encode(JSON.stringify({success: true, information: ['/album/férias/ação.png'], done: false}) + '\n');
        const split = bytes.indexOf(195) + 1;
        stream.enqueue(bytes.slice(0, split)); stream.enqueue(bytes.slice(split));
        send({information: [], done: true, truncated: false}); stream.close();
        await tick();
        assert.equal(w.document.querySelectorAll('.media-folder-group').length, 2);
        assert.equal(w.document.getElementById('mediaGalleryProgress').hidden, true);
        const filter = w.document.getElementById('mediaFolderFilter');
        filter.value = '/album/férias'; filter.dispatchEvent(new w.Event('change'));
        assert.deepEqual([...w.document.querySelectorAll('.media-thumbnail:not([hidden])')].map(button => button.dataset.path), ['/album/férias/ação.png']);
        assert.equal(w.document.querySelectorAll('.media-folder-group').length, 1);
        w.document.getElementById('mediaGroupFolders').click();
        assert.equal(w.document.getElementById('thumbNav').classList.contains('media-ungrouped'), true);
        w.document.querySelector('.media-thumbnail:not([hidden])').click();
        await tick();
        assert.equal(w.document.getElementById('viewerImage').alt, 'ação.png');
        assert.equal(w.document.getElementById('mediaGalleryToggle').disabled, false);
    } finally { dom.window.close(); }
});

test('interrupted streaming searches keep discovered files and expose retry', async () => {
    const dom = browser(), w = dom.window;
    w.TextDecoder = TextDecoder;
    w.fetch = async () => ({ok: true, headers: new Headers({'Content-Type': 'application/x-ndjson'}), body: new ReadableStream({start(controller) {
        controller.enqueue(new TextEncoder().encode(JSON.stringify({success: true, information: ['/nested/new.jpg'], done: false}) + '\n'));
        controller.close();
    }})});
    try {
        await w.showImage('/one.jpg'); await tick();
        assert.equal(w.__fileList.includes('/nested/new.jpg'), true);
        assert.match(w.document.getElementById('mediaGalleryStatus').textContent, /Não foi possível concluir/);
        assert.equal(w.document.getElementById('mediaGalleryRetry').hidden, false);
        assert.equal(w.document.getElementById('mediaGalleryProgress').hidden, true);
        w.stopMediaDiscovery();
        assert.match(w.document.getElementById('mediaGalleryStatus').textContent, /interrompida/);
    } finally { dom.window.close(); }
});

test('expanded gallery returns to the selected media and restores page scroll and focus on exit', async () => {
    const dom = browser(), w = dom.window;
    try {
        const outside = w.document.createElement('button');
        w.document.body.appendChild(outside);
        outside.focus();
        w.document.body.style.overflow = 'auto';
        await w.showImage('/one.jpg');
        assert.equal(w.document.body.style.overflow, 'hidden');
        w.document.getElementById('mediaGalleryToggle').click();
        assert.equal(w.document.getElementById('customImageViewer').classList.contains('gallery-expanded'), true);
        assert.equal(w.document.activeElement.id, 'mediaGallerySearch');
        w.document.querySelector('.media-thumbnail[data-path="/three.webp"]').click();
        await tick();
        assert.equal(w.__currentIndex, 2);
        assert.equal(w.document.getElementById('customImageViewer').classList.contains('gallery-expanded'), false);
        w.closeImageViewer();
        assert.equal(w.document.body.style.overflow, 'auto');
        assert.equal(w.document.activeElement, outside);
    } finally { dom.window.close(); }
});

test('thumbnails load on intersection with bounded concurrency and cancel their queue on close', async () => {
    const {dom, w, requests} = delayedBrowser();
    try {
        let observer;
        w.IntersectionObserver = class {
            constructor(callback, options) { this.callback = callback; this.options = options; this.elements = []; observer = this; }
            observe(element) { this.elements.push(element); }
            unobserve() {}
            disconnect() { this.disconnected = true; }
        };
        w.fileList = Array.from({length: 100}, (_, index) => `/photo-${index}.jpg`);
        const opening = w.showImage(w.fileList[0]);
        assert.equal(requests.length, 1);
        assert.equal(observer.elements.length, 100);
        assert.equal(observer.options.root.id, 'thumbNav');
        observer.callback(observer.elements.slice(0, 20).map(target => ({target, isIntersecting: true})));
        assert.equal(requests.length, 4);
        requests[1].resolve('/thumbnail');
        await tick();
        assert.equal(requests.length, 4, 'authorization alone must not release a transfer slot');
        w.previewImages[0].dispatchEvent(new w.Event('load'));
        await tick();
        assert.equal(requests.length, 5, 'the next transfer starts only after the image loads');
        w.closeImageViewer();
        assert.equal(observer.disconnected, true);
        requests.forEach(request => request.resolve('/late'));
        await opening;
        await tick();
        assert.equal(requests.length, 5);
        assert.equal(w.document.getElementById('thumbNav').childElementCount, 0);
    } finally { dom.window.close(); }
});

test('horizontal touch and mouse drags navigate, while short, vertical, cancelled and zoomed drags do not', async () => {
    const dom = browser(), w = dom.window;
    const drag = async (dx, dy, end = 'up', pointerType = 'touch') => {
        const image = w.document.getElementById('viewerImage');
        image.setPointerCapture = () => {};
        const event = {button: 0, pointerId: 7, isPrimary: true, pointerType, clientX: 180, clientY: 100, preventDefault() {}};
        image.onpointerdown(event);
        image.onpointermove({...event, clientX: 180 + dx, clientY: 100 + dy});
        image['onpointer' + end]({...event, clientX: 180 + dx, clientY: 100 + dy});
        await tick();
    };
    try {
        await w.showImage('/one.jpg');
        await drag(-100, 8);
        assert.equal(w.__currentIndex, 1);
        await drag(100, 0, 'up', 'mouse');
        assert.equal(w.__currentIndex, 0);
        await drag(-20, 0);
        await drag(-60, 120);
        await drag(-100, 0, 'cancel');
        await drag(100, 0);
        assert.equal(w.__currentIndex, 0);
        w.changeImageZoom(1);
        await drag(-120, 0);
        assert.equal(w.__currentIndex, 0, 'zoomed dragging pans without changing files');
        w.resetImageZoom();
        const image = w.document.getElementById('viewerImage');
        image.onpointerdown({button: 0, pointerId: 1, clientX: 100, clientY: 0, preventDefault() {}});
        image.onpointerdown({isPrimary: false});
        image.onpointerup({pointerId: 1, clientX: 0, clientY: 0});
        assert.equal(w.__currentIndex, 0, 'multitouch cancels navigation');
    } finally { dom.window.close(); }
});

test('recursive results preserve selection and filters and can be found by subfolder name', async () => {
    const dom = browser(), w = dom.window;
    let respond, request;
    w.fetch = (url, options) => { request = {url, options}; return new Promise(resolve => { respond = resolve; }); };
    try {
        await w.showImage('/one.jpg');
        await w.navNext();
        const firstCard = w.document.querySelector('.media-thumbnail');
        const search = w.document.getElementById('mediaGallerySearch');
        search.value = 'viagem';
        w.filterMediaGallery('image');
        respond({ok: true, json: async () => ({success: true, information: ['/one.jpg', '/viagem/foto.JPG', '/viagem/nested/outra.png', '/viagem/video.mp4']})});
        await tick();
        assert.equal(new URL(request.url, 'http://localhost').searchParams.get('media'), 'recursive');
        assert.equal(request.options.headers.Authorization, 'Bearer fixture-owner');
        assert.equal(firstCard.isConnected, false); // filtered-out cards are released by the bounded grid
        assert.equal(w.__fileList[w.__currentIndex], '/two.png');
        assert.equal(search.value, 'viagem');
        assert.deepEqual([...w.document.querySelectorAll('.media-thumbnail:not([hidden])')].map(button => button.dataset.path), ['/viagem/foto.JPG', '/viagem/nested/outra.png']);
        await w.showImage('/viagem/foto.JPG');
        await w.navNext();
        assert.equal(w.__fileList[w.__currentIndex], '/viagem/nested/outra.png');
        assert.match(w.document.getElementById('mediaGalleryStatus').textContent, /Inclui/);
    } finally { dom.window.close(); }
});

test('late recursive results cannot affect a closed or reopened gallery and failures stay visible', async () => {
    const dom = browser(), w = dom.window, requests = [];
    w.fetch = (url, options) => new Promise(resolve => requests.push({options, resolve}));
    try {
        await w.showImage('/one.jpg');
        w.closeImageViewer();
        assert.equal(requests[0].options.signal.aborted, true);
        await w.showImage('/two.png');
        requests[0].resolve({ok: true, json: async () => ({success: true, information: ['/stale/image.jpg']})});
        requests[1].resolve({ok: false});
        await tick();
        assert.equal(w.__fileList.includes('/stale/image.jpg'), false);
        assert.match(w.document.getElementById('mediaGalleryStatus').textContent, /Não foi possível/);
        assert.equal(w.document.getElementById('viewerImage').alt, 'two.png');
    } finally { dom.window.close(); }
});

test('100 gallery photos and file previews share transfer slots, including failures and folder changes', async () => {
    const dom = browser(), w = dom.window;
    try {
        w.fileList = Array.from({length: 100}, (_, index) => `/photo-${index}.jpg`);
        const list = w.document.createElement('div');
        list.innerHTML = '<img data-fm-preview="/list-one.jpg"><img data-fm-preview="/list-two.jpg">';
        w.document.body.append(list);
        w.loadFilePreviews(list);
        await w.showImage(w.fileList[0]);
        await tick();
        let completed = 0, peak = 0;
        for (let round = 0; round < 110; round++) {
            const active = w.previewImages.filter(image => image.onload !== null && image.hasAttribute('src'));
            peak = Math.max(peak, active.length);
            assert.ok(active.length <= 3, 'table and gallery must share a global bound');
            if (!active.length) break;
            for (const image of active) {
                image.dispatchEvent(new w.Event(completed++ === 10 ? 'error' : 'load'));
            }
            await tick();
        }
        assert.equal(peak, 3);
        assert.equal(completed, 102, 'all 100 photos and both list previews finish');
        assert.equal(w.document.querySelectorAll('#thumbNav img').length, 99);
        list.innerHTML = '<img data-fm-preview="/old.jpg">';
        w.loadFilePreviews(list);
        await tick();
        const old = w.previewImages.at(-1);
        list.innerHTML = '<img data-fm-preview="/new.jpg">';
        w.loadFilePreviews(list);
        await tick();
        assert.equal(old.hasAttribute('src'), false, 'changing folders cancels an active stale preview');
    } finally { dom.window.close(); }
});

test('large gallery pages keep DOM bounded and filters search the whole collection', async () => {
    const dom = browser(), w = dom.window;
    try {
        w.fileList = Array.from({length: 1500}, (_, i) => `/album/foto-${i}.png`);
        await w.showImage(w.fileList[0]);
        assert.equal(w.document.querySelectorAll('.media-thumbnail').length, 120);
        assert.equal(w.document.getElementById('mediaGalleryCount').textContent, '1500 arquivos');
        w.document.getElementById('mediaPageNext').click();
        assert.match(w.document.getElementById('mediaPageLabel').textContent, /^2 /);
        w.document.getElementById('mediaGallerySearch').value = 'foto-1499';
        w.filterMediaGallery();
        assert.equal(w.document.querySelectorAll('.media-thumbnail').length, 1);
        assert.equal(w.document.querySelector('.media-thumbnail').dataset.path, '/album/foto-1499.png');
        w.closeImageViewer();
        assert.equal(w.document.getElementById('thumbNav').childElementCount, 0);
    } finally { dom.window.close(); }
});

test('session favorites and albums remain accessible without writing browser storage', async () => {
    const dom = browser(), w = dom.window;
    try {
        await w.showImage('/one.jpg');
        w.galleryFavorite();
        w.filterMediaGallery('favorite');
        assert.equal(w.document.querySelectorAll('.media-thumbnail').length, 1);
        w.prompt = () => '<álbum privado>';
        w.galleryAlbum();
        assert.equal(w.document.getElementById('mediaAlbumFilter').value, '<álbum privado>');
        w.closeImageViewer();
        w.fetch = async () => ({ok: true, json: async () => ({success: true, information: ['/one.jpg']})});
        await w.openMediaGallery('/');
        await tick();
        w.filterMediaGallery('favorite');
        assert.equal(w.document.querySelector('.media-thumbnail').dataset.path, '/one.jpg');
        assert.equal(w.localStorage.length, 0);
        assert.equal(w.sessionStorage.length, 0);
    } finally { dom.window.close(); }
});

test('scope restart aborts previous discovery and grouping uses supplied modification dates', async () => {
    const dom = browser(), w = dom.window;
    try {
        const requests = [];
        w.fetch = (url, options) => { requests.push({url, options}); return new Promise(() => {}); };
        await w.openMediaGallery('/album');
        w.document.getElementById('mediaScope').value = 'current';
        w.restartMediaGallery();
        assert.equal(requests[0].options.signal.aborted, true);
        assert.equal(new URL(requests[1].url, 'http://localhost').searchParams.get('scope'), 'current');
        w.renderMediaThumbnails(['/album/foto.png'], true);
        w.document.getElementById('mediaGroupBy').value = 'date';
        w.filterMediaGallery();
        assert.match(w.document.querySelector('.media-folder-group h3').textContent, /Data indisponível/);
        w.closeImageViewer();
    } finally { dom.window.close(); }
});

test('gallery keeps its own directory across file-manager navigation and supports breadcrumbs/back', async () => {
    const dom = browser(), w = dom.window;
    try {
        const path = w.document.createElement('span'); path.id = 'pathInvisible'; path.textContent = '/files'; w.document.body.append(path);
        const requests = [];
        w.fetch = (url, options) => { requests.push({url, options}); return new Promise(() => {}); };
        await w.openMediaGallery('/photos');
        await w.openMediaGallery('/photos/trip');
        assert.equal(requests[0].options.signal.aborted, true);
        assert.equal(path.textContent, '/files');
        w.closeImageViewer(); path.textContent = '/documents';
        await w.openMediaGallery();
        assert.equal(new URL(requests.at(-1).url, 'http://localhost').searchParams.get('path'), '/photos/trip');
        w.document.getElementById('galleryBack').click();
        assert.equal(new URL(requests.at(-1).url, 'http://localhost').searchParams.get('path'), '/photos');
        w.document.querySelector('#mediaFolderPath button').click();
        assert.equal(new URL(requests.at(-1).url, 'http://localhost').searchParams.get('path'), '/');
        assert.equal(path.textContent, '/documents');
        assert.equal(w.localStorage.length, 0);
        w.closeImageViewer();
    } finally { dom.window.close(); }
});

test('scan busy indicator clears on cancellation and thumbnail animation clears on success/error', async () => {
    const dom = browser(), w = dom.window;
    try {
        w.fetch = () => new Promise(() => {});
        await w.showImage('/one.jpg'); await tick();
        assert.equal(w.document.getElementById('mediaGallery').getAttribute('aria-busy'), 'true');
        assert.equal(w.document.querySelectorAll('.is-loading').length, 3);
        const active = w.previewImages.filter(image => image.onload && image.hasAttribute('src'));
        active[0].dispatchEvent(new w.Event('load'));
        active[1].dispatchEvent(new w.Event('error'));
        assert.equal(w.document.querySelectorAll('.is-loading').length, 1);
        w.stopMediaDiscovery();
        assert.equal(w.document.getElementById('mediaGallery').getAttribute('aria-busy'), 'false');
        w.closeImageViewer();
        assert.equal(w.document.querySelectorAll('.is-loading').length, 0);
    } finally { dom.window.close(); }
});

test('folders discovered without media appear while scan is still running', async () => {
    const dom = browser(), w = dom.window;
    try {
        let stream;
        w.TextDecoder = TextDecoder;
        w.fetch = async () => ({ok: true, headers: {get: () => 'application/x-ndjson'}, body: new ReadableStream({start(controller) { stream = controller; }})});
        await w.openMediaGallery('/root'); w.filterMediaGallery('folder');
        stream.enqueue(new TextEncoder().encode(JSON.stringify({success: true, information: [], folders: ['/root/Pictures'], done: false}) + '\n'));
        await tick();
        assert.equal(w.document.querySelector('.media-thumbnail').dataset.path, '/root/Pictures');
        assert.equal(w.document.getElementById('mediaGalleryCount').textContent, '1 pasta');
        assert.equal(w.document.getElementById('mediaGallery').getAttribute('aria-busy'), 'true');
        w.closeImageViewer();
    } finally { dom.window.close(); }
});
