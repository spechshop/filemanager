const root=process.argv[2], scenario=process.argv[3], seconds=Number(process.argv[4]||60);
const WS=require(root+'/node_modules/ws');const https=require('https');const fs=require('fs');
const port=Number(process.env.BENCH_PORT||18080);
const start=Date.now(), until=start+seconds*1000;let count=0,errors=0,bytes=0;const lat=[];const clients=[];
const agent=new https.Agent({keepAlive:true,rejectUnauthorized:false,maxSockets:16});
function request(path,body){return new Promise(resolve=>{let t=performance.now();const r=https.request({host:'127.0.0.1',port,path,agent,method:body?'POST':'GET',headers:body?{'Content-Type':'application/json'}:{}},res=>{let data='';res.on('data',c=>{data+=c;bytes+=c.length});res.on('end',()=>{count++;lat.push(performance.now()-t);if(res.statusCode!==200)errors++;try{if(JSON.parse(data).success===false)errors++}catch{}resolve()})});r.on('error',()=>{errors++;resolve()});r.setTimeout(10000,()=>r.destroy());r.end(body)})}
async function main(){
 if(scenario==='panel'){
  const ws=new WS(`wss://127.0.0.1:${port}/perf`,{rejectUnauthorized:false});clients.push(ws);let sent;
  const send=()=>{if(ws.readyState===1){sent=performance.now();ws.send(JSON.stringify({token:'perf'}))}};
  ws.on('open',send);ws.on('error',()=>errors++);ws.on('message',d=>{count++;bytes+=d.length;lat.push(performance.now()-sent);let o=JSON.parse(d);if(o.success&&o.cpu&&o.memory&&o.disk){if(process.env.PANEL_DELAY)setTimeout(send,Number(process.env.PANEL_DELAY));else send()}});
 }else if(scenario.startsWith('terminal')){
  for(let i=0;i<(scenario==='terminal-multi'?8:1);i++){
   const token='perf-t'+i;const ws=new WS(`wss://127.0.0.1:${port}/`+token,{rejectUnauthorized:false});clients.push(ws);
   ws.on('error',()=>errors++);ws.on('message',d=>{count++;bytes+=d.length});ws.on('open',()=>{ws.send(JSON.stringify({token,isCodex:true,command:'startXtermHandlerCommand'}));if(scenario==='terminal-active')ws.send(JSON.stringify({token,isCodex:true,command:"for i in {1..600}; do printf 'performance output %s\\n' \"$i\"; sleep 0.1; done\n"}));});
  }
 }else if(scenario==='codex-active'){
  const ws=new WS(`wss://127.0.0.1:${port}/perf-codex-agent`,{rejectUnauthorized:false});clients.push(ws);ws.on('error',()=>errors++);ws.on('open',()=>ws.send(JSON.stringify({token:'perf-codex-agent',codexAgent:true,payload:{action:'health',requestId:'perf'}})));ws.on('message',d=>{count++;bytes+=d.length});
 }else if(scenario==='browse'||scenario==='editor'){
  while(Date.now()<until){
   if(scenario==='browse')for(const size of ['small','medium','large'])await request('/syncPath?tokenBrowser=perf&path='+encodeURIComponent(root+'/files/'+size));
   else{await request('/getFile?tokenBrowser=perf&path='+encodeURIComponent(root+'/files/edit.php'));await request('/refactorFile?tokenBrowser=perf',JSON.stringify({code:'<?php\n$x = '+count+';\necho $x;',nameFile:'edit.php'}));}
   await new Promise(r=>setTimeout(r,200));
  }
 }
}
main().catch(e=>{console.error(e);errors++});
setTimeout(()=>{if(count===0)errors++;for(const ws of clients)ws.terminate();agent.destroy();lat.sort((a,b)=>a-b);console.log(JSON.stringify({scenario,seconds:(Date.now()-start)/1000,count,errors,bytes,rate:count/seconds,p50:lat[Math.floor(lat.length*.5)],p95:lat[Math.floor(lat.length*.95)],p99:lat[Math.floor(lat.length*.99)]}));process.exit(errors?1:0)},seconds*1000);
