const WS=require(process.argv[2]+'/node_modules/ws');const {spawn}=require('child_process');const fs=require('fs');
const root=process.argv[2],seconds=Number(process.argv[3]||120),port=Number(process.env.BENCH_PORT||18080);
const chrome=spawn('/usr/bin/google-chrome',['--headless=new','--no-sandbox','--disable-gpu','--ignore-certificate-errors','--remote-debugging-port=19222','--remote-allow-origins=*','--user-data-dir='+(process.env.FILEMANAGER_BENCH||'/tmp/filemanager-perf-reproduce')+'/chrome-profile','about:blank'],{stdio:['ignore','ignore',fs.openSync((process.env.FILEMANAGER_BENCH||'/tmp/filemanager-perf-reproduce')+'/chrome.log','a')]});
const sleep=ms=>new Promise(r=>setTimeout(r,ms));let ws;
(async()=>{let tabs;for(let i=0;i<50;i++){try{tabs=await(await fetch('http://127.0.0.1:19222/json')).json();break}catch{await sleep(200)}}
ws=new WS(tabs.find(t=>t.type==='page').webSocketDebuggerUrl);await new Promise(r=>ws.on('open',r));let seq=0;const pending=new Map();let messages=0,requests=0;const exceptions=[];
ws.on('message',b=>{let o=JSON.parse(b);if(o.id){pending.get(o.id)?.(o);pending.delete(o.id)}else if(o.method==='Network.webSocketFrameReceived')messages++;else if(o.method==='Network.requestWillBeSent')requests++;else if(o.method==='Runtime.exceptionThrown')exceptions.push(o.params.exceptionDetails.text+' '+(o.params.exceptionDetails.exception?.description||''))});
const call=(method,params={})=>new Promise(r=>{let id=++seq;pending.set(id,r);ws.send(JSON.stringify({id,method,params}))});
await call('Page.enable');await call('Network.enable');await call('Runtime.enable');await call('Performance.enable');
await call('Page.addScriptToEvaluateOnNewDocument',{source:`localStorage.setItem('tokenBrowser','perf');localStorage.setItem('pathInvisible',${JSON.stringify(root+'/files/small')});`});
await call('Page.navigate',{url:`https://127.0.0.1:${port}/`});await sleep(20000);
const initial=await call('Performance.getMetrics');const n=messages,r=requests;const t=Date.now();
console.log(JSON.stringify({phase:'ready',chromePid:chrome.pid,exceptions,ui:await call('Runtime.evaluate',{expression:'JSON.stringify({token:document.getElementById("fp")?.textContent,cpu:document.getElementById("cpuName")?.textContent,rows:document.querySelectorAll("#filesList tr").length})',returnByValue:true})}));
await sleep(seconds*1000);const final=await call('Performance.getMetrics');console.log(JSON.stringify({phase:'result',seconds:(Date.now()-t)/1000,messages:messages-n,requests:requests-r,initial,final,exceptions}));
await call('Browser.close');})().catch(e=>{console.error(e);process.exitCode=1}).finally(()=>{ws?.close();chrome.kill()});
