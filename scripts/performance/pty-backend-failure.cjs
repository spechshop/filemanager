const root=process.argv[2], W=require(root+'/node_modules/ws');
const {spawn}=require('child_process'), fs=require('fs');
const base=process.env.FILEMANAGER_BENCH, sleep=ms=>new Promise(r=>setTimeout(r,ms));
const log=fs.openSync(base+'/pty-backend-failure.log','w');
const start=(command,args)=>spawn(command,args,{cwd:root,stdio:['ignore',log,log]});
async function stop(child) {
    if(child.exitCode!==null||child.signalCode!==null)return;
    const exited=new Promise(r=>child.once('exit',r));child.kill('SIGTERM');
    const deadline=setTimeout(()=>child.kill('SIGKILL'),5000);
    await exited;clearTimeout(deadline);
    if(child.signalCode==='SIGKILL')throw Error('Forced process exit');
}
let middleware=start('php',['middleware.php']),pty=start('node',['pty.js']),ws;
async function connect(){
    ws=new W('wss://127.0.0.1:18091/perf-death',{rejectUnauthorized:false});
    await new Promise((r,j)=>{ws.once('open',r);ws.once('error',j)});
}
async function marker(name){
    let output='';const listener=d=>output+=d.toString();ws.on('message',listener);
    ws.send(JSON.stringify({token:'perf-death',isCodex:true,command:`printf '${name}\\n'\n`}));
    await sleep(500);ws.off('message',listener);
    if(!output.includes(name))throw Error('No terminal output: '+name);
}
(async()=>{
    await sleep(3000);await connect();await marker('BEFORE_DEATH');
    await stop(pty);
    const sample=spawn('python3',[base+'/sample.py',root,'45',base+'/pty-backend-dead.cpu.json'],{stdio:['ignore',fs.openSync(base+'/pty-backend-dead.summary','w'),'inherit']});
    if(await new Promise(r=>sample.once('exit',r))!==0)throw Error('Sampling failed');
    pty=start('node',['pty.js']);await sleep(1000);
    if(ws.readyState!==W.OPEN)await connect();
    await marker('AFTER_DEATH');
    ws.send(JSON.stringify({token:'perf-death',isCodex:true,command:'closeXtermHandlerCommand'}));
    await sleep(200);ws.terminate();await stop(middleware);await stop(pty);
    console.log(JSON.stringify({passed:true,backendDeath:true,deadIdleSeconds:45,reconnected:true,forcedExit:false}));
})().catch(e=>{console.error(e);process.exitCode=1}).finally(async()=>{
    ws?.terminate();await stop(middleware);await stop(pty);
});
