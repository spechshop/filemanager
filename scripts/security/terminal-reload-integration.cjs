'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs'), os = require('node:os'), path = require('node:path'), http = require('node:http'), net = require('node:net');
const {spawn} = require('node:child_process'), {once} = require('node:events');
const WebSocket = require('ws');
const ROOT = path.resolve(__dirname, '../..');
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
async function port() {const s=net.createServer();s.listen(0,'127.0.0.1');await once(s,'listening');const p=s.address().port;await new Promise(r=>s.close(r));return p;}
async function until(predicate,label) {for(let n=0;n<120;n++){if(predicate())return;await delay(25);}throw Error(label);}
async function connect(url) {const ws=new WebSocket(url,{origin:'http://localhost'});ws.on('error',()=>{});await once(ws,'open');return ws;}
async function close(ws) {if(ws.readyState===WebSocket.CLOSED)return;ws.close();await once(ws,'close');await delay(80);}
test('refresh and restore keep a single terminal relay and exactly one echo per keystroke', {timeout:25000}, async()=>{
    const tmp=fs.mkdtempSync(path.join(os.tmpdir(),'fm-terminal-reload-'));
    fs.mkdirSync(path.join(tmp,'files'));
    const tokenFile=path.join(tmp,'tokens.json');fs.writeFileSync(tokenFile,JSON.stringify({'reload-owner':{expire:Math.floor(Date.now()/1000)+3600,terminal:true,admin:true}}));
    const bridge=http.createServer(),wss=new WebSocket.Server({noServer:true});
    const clients=new Set(),commands=[];
    // Deterministic upgrade delay exposes concurrent start/resize callbacks in Swoole.
    bridge.on('upgrade',(req,socket,head)=>setTimeout(()=>{if(!socket.destroyed)wss.handleUpgrade(req,socket,head,ws=>wss.emit('connection',ws));},60));
    wss.on('connection',ws=>{clients.add(ws);ws.on('close',()=>clients.delete(ws));ws.on('message',data=>{
        const value=data.toString();commands.push(value);
        if(value==='x')for(const client of clients)if(client.readyState===WebSocket.OPEN)client.send('x');
    });});
    bridge.listen(0,'127.0.0.1');await once(bridge,'listening');const relayPort=await port();
    const child=spawn(process.env.SECURITY_PHP_BINARY||'php',[path.join(ROOT,'scripts/security/fixtures/swoole-server.php')],{cwd:tmp,env:{...process.env,SECURITY_HTTP_PORT:String(relayPort),FILEMANAGER_UPLOAD_PORT:'0',FILEMANAGER_PTY_PORT:String(bridge.address().port),FILEMANAGER_DATA_DIR:tmp,FILEMANAGER_TOKENS_FILE:tokenFile,FILEMANAGER_ORIGIN:'http://localhost',FILEMANAGER_BRIDGE_SECRET:'s'.repeat(64)},stdio:['ignore','pipe','pipe']});
    let logs='',ws;child.stdout.on('data',x=>logs+=x);child.stderr.on('data',x=>logs+=x);
    const observations=[];
    try {
        await until(()=>logs.includes('SECURITY_FIXTURE_READY')||child.exitCode!==null,'fixture readiness');assert.equal(child.exitCode,null,logs);
        for(let refresh=0;refresh<6;refresh++) {
            ws=await connect(`ws://127.0.0.1:${relayPort}/reload-owner-one`);
            let output='';ws.on('message',data=>output+=data.toString());
            ws.send(JSON.stringify({isCodex:true,command:'startXtermHandlerCommand'}));
            ws.send(JSON.stringify({isCodex:true,command:'resizeXtermHandlerCommand',cols:100,rows:30}));
            await delay(220);output='';const prior=commands.filter(x=>x==='x').length;
            ws.send(JSON.stringify({isCodex:true,command:'x'}));await until(()=>output.length>0,'echo');await delay(100);
            observations.push({refresh,bridges:clients.size,input:commands.filter(x=>x==='x').length-prior,output});
            await close(ws);ws=null;
        }
        console.log(JSON.stringify({observations}));
        for(const sample of observations){assert.equal(sample.input,1,'single input at refresh '+sample.refresh);assert.equal(sample.output,'x','single echo at refresh '+sample.refresh);assert.equal(sample.bridges,1,'single PTY bridge at refresh '+sample.refresh);}
        await until(()=>clients.size===0,'all bridges released after final browser disconnect');
        // A browser disappearing during upgrade cannot bind its relay to a later browser.
        ws=await connect(`ws://127.0.0.1:${relayPort}/reload-owner-one`);
        ws.send(JSON.stringify({isCodex:true,command:'startXtermHandlerCommand'}));await close(ws);
        ws=await connect(`ws://127.0.0.1:${relayPort}/reload-owner-one`);
        let output='';ws.on('message',data=>output+=data.toString());
        ws.send(JSON.stringify({isCodex:true,command:'startXtermHandlerCommand'}));await delay(160);
        ws.send(JSON.stringify({isCodex:true,command:'x'}));await until(()=>output.length>0,'rapid reload echo');await delay(100);assert.equal(output,'x');assert.equal(clients.size,1);
    } finally {
        if(ws)ws.terminate();child.kill('SIGTERM');await Promise.race([once(child,'exit'),delay(1500)]);if(child.exitCode===null)child.kill('SIGKILL');
        for(const c of clients)c.terminate();wss.close();await new Promise(r=>bridge.close(r));fs.rmSync(tmp,{recursive:true,force:true});
    }
});
for(const backend of (process.env.SECURITY_RELOAD_BACKENDS||'node').split(',')) test(`real ${backend} shell keeps a single echo and the same PID through repeated browser reloads`,{timeout:25000},async()=>{
    assert(['node','php'].includes(backend));
    const tmp=fs.mkdtempSync(path.join(os.tmpdir(),'fm-real-reload-'));fs.mkdirSync(path.join(tmp,'files'));
    const tokenFile=path.join(tmp,'tokens.json');fs.writeFileSync(tokenFile,JSON.stringify({'reload-owner':{expire:Math.floor(Date.now()/1000)+3600,terminal:true,admin:true}}));
    const php=process.env.SECURITY_PHP_BINARY||'php',ptyPort=await port(),relayPort=await port();
    const env={...process.env,FILEMANAGER_DATA_DIR:tmp,FILEMANAGER_INITIAL_DIRECTORY:path.join(tmp,'files'),FILEMANAGER_TOKENS_FILE:tokenFile,FILEMANAGER_PTY_PORT:String(ptyPort),FILEMANAGER_ORIGIN:'http://localhost',FILEMANAGER_BRIDGE_SECRET:'s'.repeat(64)};
    const pty=spawn(backend==='node'?process.execPath:php,[path.join(ROOT,backend==='node'?'pty.js':'pty.php')],{cwd:ROOT,env,stdio:['ignore','pipe','pipe']});
    let ptyLogs='',relayLogs='',relay,ws;pty.stdout.on('data',b=>ptyLogs+=b);pty.stderr.on('data',b=>ptyLogs+=b);
    try {
        await until(()=>ptyLogs.includes('PTY server listening')||pty.exitCode!==null,'real PTY readiness');assert.equal(pty.exitCode,null,ptyLogs);
        relay=spawn(php,[path.join(ROOT,'scripts/security/fixtures/swoole-server.php')],{cwd:tmp,env:{...env,SECURITY_HTTP_PORT:String(relayPort),FILEMANAGER_UPLOAD_PORT:'0'},stdio:['ignore','pipe','pipe']});relay.stdout.on('data',b=>relayLogs+=b);relay.stderr.on('data',b=>relayLogs+=b);
        await until(()=>relayLogs.includes('SECURITY_FIXTURE_READY')||relay.exitCode!==null,'real relay readiness');assert.equal(relay.exitCode,null,relayLogs);
        let pid;
        for(let refresh=0;refresh<6;refresh++) {
            ws=await connect(`ws://127.0.0.1:${relayPort}/reload-owner-one`);let output='';ws.on('message',b=>output+=b.toString());
            ws.send(JSON.stringify({isCodex:true,command:'startXtermHandlerCommand'}));ws.send(JSON.stringify({isCodex:true,command:'resizeXtermHandlerCommand',cols:100,rows:30}));
            await delay(150);
            if(refresh===0){ws.send(JSON.stringify({isCodex:true,command:`printf '%s' $$ > '${tmp}/files/pid'; stty raw -echo; cat\n`}));await until(()=>fs.existsSync(path.join(tmp,'files/pid')),'shell PID');pid=fs.readFileSync(path.join(tmp,'files/pid'),'utf8');await delay(100);}
            output='';ws.send(JSON.stringify({isCodex:true,command:'x'}));await until(()=>output.length>0,'real shell echo');await delay(80);
            assert.equal(output,'x',`${backend}: refresh ${refresh}`);assert.equal(fs.readFileSync(path.join(tmp,'files/pid'),'utf8'),pid,'shell survives reload');
            await close(ws);ws=null;
        }
    } finally {
        if(ws)ws.terminate();
        for(const child of [relay,pty])if(child){child.kill('SIGTERM');await Promise.race([once(child,'exit'),delay(1500)]);if(child.exitCode===null)child.kill('SIGKILL');}
        fs.rmSync(tmp,{recursive:true,force:true});
    }
});
