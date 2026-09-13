const root=process.argv[2], W=require(root+'/node_modules/ws'), fs=require('fs');
const sleep=ms=>new Promise(r=>setTimeout(r,ms));let clients=[];
const tokens=token=>fs.writeFileSync(root+'/database/tokens.lotus',JSON.stringify({[token]:{expire:2000000000,nameClient:'Benchmark'}}));
async function connect(token){
    const ws=new W('wss://127.0.0.1:18095/'+token,{rejectUnauthorized:false});clients.push(ws);
    await new Promise((r,j)=>{ws.once('open',r);ws.once('error',j)});
    await new Promise((resolve,reject)=>{
        const timer=setTimeout(()=>reject(Error('No authenticated metrics for '+token)),3000);
        ws.on('message',raw=>{const data=JSON.parse(raw);if(data.success&&data.cpu){clearTimeout(timer);resolve()}});
        ws.send(JSON.stringify({token}));
    });
    return ws;
}
(async()=>{
    tokens('wsone');const previous=await connect('wsone');
    tokens('wstwo');await connect('wstwo');
    previous.send(JSON.stringify({token:'wsone'}));await sleep(200);
    if(previous.readyState===W.OPEN)throw Error('Revoked WebSocket token still accepted');
    console.log(JSON.stringify({passed:true,newHandshake:true,revokedOpenConnection:true}));
})().catch(e=>{console.error(e);process.exitCode=1}).finally(()=>clients.forEach(ws=>ws.terminate()));
