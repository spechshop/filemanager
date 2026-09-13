const fs=require('fs'),{spawn}=require('child_process'),http=require('http');const root=process.argv[2],label=process.argv[3];const W=require(root+'/node_modules/ws');const sleep=ms=>new Promise(r=>setTimeout(r,ms));
let source=fs.readFileSync(root+'/pty.js','utf8').replaceAll('16060','16061').replaceAll('6060','16061');
source=source.replace('const terminals = new Map();',`const terminals = new Map(); server.on('request',(req,res)=>{res.end(JSON.stringify([...terminals].map(([token,p])=>({token,pid:p.pid,listeners:p._socket?.listenerCount('data')}))))});`);
const dest=root+'/pty-probe.cjs';fs.writeFileSync(dest,source);const log=fs.openSync((process.env.FILEMANAGER_BENCH||'/tmp/filemanager-perf-reproduce')+'/'+label+'-pty-probe.log','w');const child=spawn('node',[dest],{cwd:root,stdio:['ignore',log,log]});
async function connect(){const ws=new W('ws://127.0.0.1:16061/perf-leak');let text='';ws.on('message',d=>text+=d.toString());await new Promise((r,j)=>{ws.on('open',r);ws.on('error',j)});return {ws,text:()=>text}}
(async()=>{await sleep(1500);let c=await connect();c.ws.send('stty -echo\n');await sleep(100);c.ws.close();await sleep(50);
for(let i=0;i<40;i++){c=await connect();c.ws.close();await sleep(15)}
c=await connect();c.ws.send("printf 'UNIQUE_MARKER_54321\\n'\n");await sleep(200);c.ws.close();await sleep(100);
c=await connect();await sleep(100);let stats=await(await fetch('http://127.0.0.1:16061/stats')).json();console.log(JSON.stringify({label,childPid:child.pid,stats,replayOccurrences:c.text().split('UNIQUE_MARKER_54321').length-1}));
c.ws.send('closeXtermHandlerCommand');await sleep(100);c.ws.close();await sleep(100);
})().catch(e=>{console.error(e);process.exitCode=1}).finally(()=>{child.kill();fs.unlinkSync(dest)});
