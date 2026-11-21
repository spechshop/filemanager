'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const {JSDOM} = require('jsdom');
const root = path.resolve(__dirname, '../..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const tick = () => new Promise(resolve => setTimeout(resolve, 120));
function fixture() {
    const d = new JSDOM(read('plugins/Request/modules/modalEditCode.html'), {url:'https://localhost', runScripts:'outside-only', pretendToBeVisual:true});
    const w = d.window, sockets = [];
    w.localStorage.tokenBrowser = 'fixture-owner';
    w.matchMedia = () => ({matches:false});
    w.FMAuth = {ready: () => new Promise(() => {}), socketUrl: async url => url, shouldRetry: () => true};
    w.fetch = async () => ({json:async () => ({success:true})});
    w.$ = () => ({modal:() => {}});
    w.setMobileWorkspace = () => {};
    w.ResizeObserver = class { observe() {} disconnect() {this.disconnected = true;} };
    w.FitAddon = {FitAddon: class {fit() {this.calls=(this.calls||0)+1;} dispose() {} }};
    w.Terminal = class {
        constructor(options) {this.options = options;this.cols=100;this.rows=30;}
        loadAddon(addon) {this.addon=addon;} open() {} onResize() {} onData() {}
        attachCustomKeyEventHandler() {} focus() {} clear() {} write() {} getSelection() {return this.selected || '';} selectAll() {this.selected='ALL';} clearSelection() {this.selected='';} select(col,row,length) {this.selection={col,row,length};this.selected='selected text';} scrollToTop() {} scrollToBottom() {} paste(value) {this.pasted=value;} dispose() {this.disposed=true;}
    };
    w.WebSocket = class {
        static OPEN=1;
        constructor() {this.readyState=1;this.messages=[];sockets.push(this);}
        send(data) {this.messages.push(JSON.parse(data));} close() {this.closed=true;}
    };
    const area=w.document.getElementById('terms');
    Object.defineProperties(area,{clientWidth:{value:800},clientHeight:{value:400}});
    w.HTMLElement.prototype.getClientRects = () => [{width:800,height:400}];
    const context=d.getInternalVMContext();
    for(const file of ['js/terminal/sessions.js','js/terminal/mobile-actions.js','js/terminal/workspace.js']) new vm.Script(read(file)).runInContext(context);
    return {d,w,sockets,context,info:id=>vm.runInContext(`terminals[${JSON.stringify(id)}]`,context)};
}
test('switching workspace preserves terminal, socket and safe session labels; close disposes and prevents reconnect', async () => {
    const f=fixture(),{w,d,sockets}=f;
    try {
        w.createTerminalTab({id:'one',name:'<img src=x onerror=alert(1)>'}); await tick();
        const info=f.info('one'), node=w.document.getElementById('one');
        for(let n=0;n<5;n++){w.FMTerminalWorkspace.open();w.FMTerminalWorkspace.editor();}
        assert.equal(f.info('one'),info); assert.equal(w.document.getElementById('one'),node);assert.equal(sockets.length,1);
        assert.equal(w.document.querySelector('#terminalSessionList img'),null);
        const callback=info.socket.onclose; callback();
        await w.closeTerminalTab('one');
        assert(info.terminal.disposed);assert(info.resizeObserver.disconnected);assert(info.socket.closed);assert.equal(f.info('one'),undefined);
        await tick();assert.equal(sockets.length,1);
    } finally {d.window.close();}
});
test('zoom changes only active terminal and fits the measured container without creating a socket', async () => {
    const f=fixture(),{w,d,sockets}=f;
    try {
        w.createTerminalTab({id:'one',name:'Build'});w.createTerminalTab({id:'two',name:'Shell'});await tick();
        w.FMTerminalWorkspace.open();
        w.document.querySelector('[data-terminal-zoom="1"]').click();await tick();
        assert.equal(f.info('two').terminal.options.fontSize,15);assert.equal(f.info('one').terminal.options.fontSize,14);
        assert(f.info('two').fitAddon.calls>0);assert.equal(sockets.length,2);
        for(let n=0;n<40;n++) w.document.querySelector('[data-terminal-zoom="1"]').click();
        assert.equal(f.info('two').terminal.options.fontSize,48);
        w.document.querySelector('[data-terminal-zoom="0"]').click();assert.equal(f.info('two').terminal.options.fontSize,14);
    } finally {d.window.close();}
});
test('connection states follow socket events and authorization loss cancels retry and disables input', async () => {
    const f=fixture(),{w,d}=f;
    try {
        w.createTerminalTab({id:'one',name:'Shell'});await tick();
        const info=f.info('one');info.socket.onopen();assert.equal(info.status,'connected');
        assert.equal(info.terminal.options.disableStdin,false);
        info.socket.onclose();assert.equal(info.status,'reconnecting');
        w.dispatchEvent(new w.Event('fm:auth-denied'));
        assert.equal(info.status,'unauthorized');assert(info.terminal.options.disableStdin);assert(info.socket.closed);
        assert.match(w.document.getElementById('terminalStatusBar').textContent,/Acesso expirado ou negado/);
    } finally {d.window.close();}
});
test('library state refresh preserves focused controls; menu supports keyboard and restores focus', async () => {
    const f=fixture(),{w,d}=f;
    try {
        w.createTerminalTab({id:'one',name:'Shell'});await tick();
        const select=w.document.querySelector('.terminal-session-select');select.focus();
        f.info('one').socket.onopen();
        assert.equal(w.document.activeElement,select);assert.equal(select.getAttribute('aria-current'),'true');
        assert.equal(w.document.querySelector('.terminal-session-state').textContent,'Conectado');
        const actions=w.document.querySelector('.terminal-session-actions');actions.focus();actions.click();
        const buttons=[...w.document.querySelectorAll('#terminal-ctx-menu button')];
        assert.equal(w.document.activeElement,buttons[0]);
        buttons[0].dispatchEvent(new w.KeyboardEvent('keydown',{key:'ArrowDown',bubbles:true}));
        assert.equal(w.document.activeElement,buttons[1]);
        buttons[1].dispatchEvent(new w.KeyboardEvent('keydown',{key:'Escape',bubbles:true}));
        assert.equal(w.document.activeElement,actions);
        assert.equal(w.document.getElementById('terminal-ctx-menu').style.display,'none');
        assert.equal(w.document.querySelector('.close-tab').tagName,'BUTTON');
    } finally {d.window.close();}
});
test('retrying list loading queries API and preserves existing terminal and socket', async () => {
    const f=fixture(),{w,d,sockets}=f;
    try {
        w.createTerminalTab({id:'one',name:'Shell'});await tick();const info=f.info('one');
        w.document.dispatchEvent(new w.CustomEvent('fm:terminal-load-error'));
        assert.equal(w.document.getElementById('terminalNotice').hidden,false);
        let requests=0;w.fetch=async()=>{requests++;return {ok:true,json:async()=>[{id:'one',name:'Shell'}]};};
        w.document.getElementById('terminalNoticeAction').click();await tick();
        assert.equal(requests,1);assert.equal(f.info('one'),info);assert.equal(sockets.length,1);
        assert.equal(w.document.getElementById('terminalNotice').hidden,true);
    } finally {d.window.close();}
});
test('manual reconnect reuses ID, cancels scheduled retry; denied access opens the existing access flow', async () => {
    const f=fixture(),{w,d,sockets}=f;
    try {
        w.createTerminalTab({id:'one',name:'Shell'});await tick();const info=f.info('one');
        info.socket.onopen();info.socket.onclose();
        w.document.getElementById('terminalNoticeAction').click();await tick();
        assert.equal(f.info('one'),info);assert.equal(sockets.length,2);assert(sockets[0].closed);
        let reviewed=false;w.showAuthenticationError=()=>{reviewed=true;};
        w.dispatchEvent(new w.Event('fm:auth-denied'));
        w.document.getElementById('terminalNoticeAction').click();await tick();
        assert(reviewed);assert.equal(sockets.length,2);
    } finally {d.window.close();}
});
test('empty list disables zoom and loading failure replaces the new-session action with real retry', async () => {
    const f=fixture(),{w,d}=f;
    try {
        assert([...w.document.querySelectorAll('[data-terminal-zoom]')].every(button=>button.disabled));
        w.document.dispatchEvent(new w.CustomEvent('fm:terminal-load-error'));
        assert.match(w.document.getElementById('terminalEmptyTitle').textContent,/Não foi possível/);
        let requests=0;w.fetch=async()=>{requests++;return {ok:true,json:async()=>[]};};
        w.document.getElementById('terminalEmptyAction').click();await tick();
        assert.equal(requests,1);assert.equal(w.document.getElementById('terminalEmptyAction').textContent,'Nova sessão');
    } finally {d.window.close();}
});
function touch(w,target,type,touches,changedTouches=touches) {
    const event=new w.Event(type,{bubbles:true,cancelable:true});
    Object.defineProperties(event,{touches:{value:touches},changedTouches:{value:changedTouches}});
    target.dispatchEvent(event);return event;
}
function pointer(w,target,type,id,x,y,pointerType='touch') {
    const event=new w.Event(type,{bubbles:true,cancelable:true});
    Object.assign(event,{pointerId:id,pointerType,clientX:x,clientY:y});target.dispatchEvent(event);return event;
}
test('captured pinch changes active text size and never becomes a one-finger scroll or terminal input', async()=>{
    const f=fixture(),{w,d,sockets}=f;
    try {
        w.createTerminalTab({id:'one',name:'Shell'});await tick();
        const surface=w.document.getElementById('terms');
        pointer(w,surface,'pointerdown',1,100,200);pointer(w,surface,'pointerdown',2,240,200);
        pointer(w,surface,'pointermove',2,140,200);await tick();
        assert.equal(f.info('one').terminal.options.fontSize,4);
        assert.equal(w.document.getElementById('terminalZoomValue').textContent,'29%');
        pointer(w,surface,'pointerup',2,140,200);
        pointer(w,surface,'pointermove',1,100,400);
        pointer(w,surface,'pointerup',1,100,400);
        assert.equal(sockets.length,1);assert.equal(f.info('one').socket.messages.length,0);
        w.document.querySelector('[data-terminal-zoom="0"]').click();
        pointer(w,surface,'pointerdown',1,100,200);pointer(w,surface,'pointerdown',2,160,200);
        pointer(w,surface,'pointermove',2,220,200);await tick();
        assert.equal(f.info('one').terminal.options.fontSize,28);
        pointer(w,surface,'pointercancel',2,220,200);
    } finally {d.window.close();}
});
test('vertical drag follows total finger distance; cancelled gestures and mouse movements do not scroll', async()=>{
    const f=fixture(),{w,d}=f;
    try {
        w.createTerminalTab({id:'one',name:'Shell'});await tick();const info=f.info('one');
        const screen=w.document.createElement('div');screen.className='xterm-screen';
        screen.getBoundingClientRect=()=>({height:600});w.document.getElementById('one').append(screen);
        info.terminal.buffer={active:{viewportY:1000}};
        info.terminal.scrollLines=n=>info.terminal.buffer.active.viewportY+=n;
        const surface=w.document.getElementById('terms');
        pointer(w,surface,'pointerdown',1,100,100);
        for(let y=120;y<=500;y+=20)pointer(w,surface,'pointermove',1,100,y);
        await tick();assert.equal(info.terminal.buffer.active.viewportY,980);
        pointer(w,surface,'pointercancel',1,100,500);await tick();
        assert.equal(info.terminal.buffer.active.viewportY,980);
        pointer(w,surface,'pointerdown',1,100,500);
        pointer(w,surface,'pointermove',1,100,100);await tick();
        assert.equal(info.terminal.buffer.active.viewportY,1000);
        pointer(w,surface,'pointercancel',1,100,100);
        const mouse=pointer(w,surface,'pointermove',3,100,600,'mouse');
        assert.equal(mouse.defaultPrevented,false);assert.equal(info.terminal.buffer.active.viewportY,1000);
    } finally {d.window.close();}
});
test('shortcut strip starts no command on touchstart, sends a stationary tap once and never fires a swipe',async()=>{
    const f=fixture(),{w,d,context}=f;
    try {
        const source=read('plugins/Request/modules/modalEditCode.html');
        const start=source.indexOf('    const mobileEditorModal =');
        new vm.Script(source.slice(start,source.indexOf('</script>',start))).runInContext(context);
        w.createTerminalTab({id:'one',name:'Shell'});await tick();const info=f.info('one');info.socket.onopen();info.socket.messages.length=0;
        const button=w.document.querySelector('[data-terminal-key="ctrlc"]');
        const at=x=>[{identifier:1,clientX:x,clientY:200}];
        assert.equal(touch(w,button,'touchstart',at(180)).defaultPrevented,false);
        assert.equal(info.socket.messages.length,0);
        touch(w,button,'touchmove',at(50));touch(w,button,'touchend',[],at(50));
        button.dispatchEvent(new w.MouseEvent('click',{bubbles:true,detail:1}));
        assert.equal(info.socket.messages.length,0,'swipe never interrupts the shell');
        touch(w,button,'touchstart',at(180));touch(w,button,'touchend',[],at(180));
        button.dispatchEvent(new w.MouseEvent('click',{bubbles:true,detail:1}));
        assert.equal(info.socket.messages.length,1);assert.equal(info.socket.messages[0].command,'\x03');
    } finally {d.window.close();}
});
test('mobile selection drags select buffer cells, copy writes the selection, and normal scrolling can resume',async()=>{
    const f=fixture(),{w,d}=f;
    try {
        w.createTerminalTab({id:'one',name:'Shell'});await tick();const info=f.info('one');
        const screen=w.document.createElement('div');screen.className='xterm-screen';
        screen.getBoundingClientRect=()=>({left:0,top:0,width:1000,height:600});w.document.getElementById('one').append(screen);
        info.terminal.buffer={active:{viewportY:30}};
        await w.FMTerminalActions.run('select');
        const area=w.document.getElementById('terms');pointer(w,area,'pointerdown',1,20,40);pointer(w,area,'pointermove',1,80,60);pointer(w,area,'pointerup',1,80,60);
        assert.deepEqual(JSON.parse(JSON.stringify(info.terminal.selection)),{col:2,row:32,length:107});
        assert.equal(w.document.querySelector('[data-terminal-action="select"]').getAttribute('aria-pressed'),'true');
        let copied;Object.defineProperty(w.navigator,'clipboard',{configurable:true,value:{writeText:async text=>copied=text}});
        info.terminal.selected='copied output';await w.FMTerminalActions.run('copy');
        assert.equal(copied,'copied output');assert.equal(info.selecting,false);
    } finally {d.window.close();}
});
test('long press selects a word without opening the keyboard or sending shell input',async()=>{
    const f=fixture(),{w,d}=f;
    try {
        w.createTerminalTab({id:'one',name:'Shell'});await tick();const info=f.info('one');
        const screen=w.document.createElement('div');screen.className='xterm-screen';screen.getBoundingClientRect=()=>({left:0,top:0,width:1000,height:600});w.document.getElementById('one').append(screen);
        info.terminal.buffer={active:{viewportY:0,getLine:()=>({getCell:col=>({getChars:()=>('hello world'[col]||' ')})})}};
        pointer(w,w.document.getElementById('terms'),'pointerdown',1,22,5);await new Promise(r=>setTimeout(r,480));
        assert.equal(info.selecting,true);assert.equal(info.terminal.selection.col,0);assert.equal(info.terminal.selection.length,5);
        pointer(w,w.document.getElementById('terms'),'pointerup',1,22,5);assert.equal(info.socket.messages.length,0);
    } finally {d.window.close();}
});
test('Ctrl+V sends control byte while Paste uses clipboard and preserves exact text without added Enter',async()=>{
    const f=fixture(),{w,d,context}=f;
    try {
        const source=read('plugins/Request/modules/modalEditCode.html'),start=source.indexOf('    const mobileEditorModal =');
        new vm.Script(source.slice(start,source.indexOf('</script>',start))).runInContext(context);
        w.createTerminalTab({id:'one',name:'Shell'});await tick();const info=f.info('one');info.socket.onopen();info.socket.messages.length=0;
        Object.defineProperty(w.navigator,'clipboard',{configurable:true,value:{readText:async()=>'<text>\nsecond line'}});
        w.document.querySelector('[data-terminal-key="ctrlv"]').click();
        assert.equal(info.socket.messages[0].command,'\x16');
        await w.FMTerminalActions.run('paste');
        assert.equal(info.terminal.pasted,'<text>\nsecond line');assert.equal(info.socket.messages.length,1);
    } finally {d.window.close();}
});
test('denied clipboard opens a native textarea fallback and pastes into the original session only',async()=>{
    const f=fixture(),{w,d}=f;
    try {
        const dialog=w.document.getElementById('terminalClipboardDialog');
        dialog.showModal=()=>dialog.setAttribute('open','');dialog.close=()=>dialog.removeAttribute('open');
        w.createTerminalTab({id:'one',name:'Shell'});await tick();const info=f.info('one');info.socket.onopen();
        Object.defineProperty(w.navigator,'clipboard',{configurable:true,value:{readText:async()=>{throw Error('denied');}}});
        await w.FMTerminalActions.run('paste');assert(dialog.open);
        w.document.getElementById('terminalClipboardText').value='literal text';w.document.getElementById('terminalClipboardConfirm').click();
        assert.equal(info.terminal.pasted,'literal text');assert(!dialog.open);
        info.terminal.selected='output';await w.FMTerminalActions.run('copy');assert(dialog.open);assert.equal(w.document.getElementById('terminalClipboardText').value,'output');
    } finally {d.window.close();}
});
