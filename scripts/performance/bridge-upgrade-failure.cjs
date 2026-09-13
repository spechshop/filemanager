const root=process.argv[2], W=require(root+'/node_modules/ws'), net=require('net');
const sleep=ms=>new Promise(r=>setTimeout(r,ms)), sockets=new Set(), clients=[];
const backend=net.createServer(socket=>{sockets.add(socket);socket.on('close',()=>sockets.delete(socket))});
(async()=>{
    await new Promise(r=>backend.listen(13096,'127.0.0.1',r));
    const elapsed=await Promise.all(Array.from({length:20},(_,i)=>new Promise((resolve,reject)=>{
        const token='perf'+i,ws=new W('wss://127.0.0.1:18086/'+token,{rejectUnauthorized:false});clients.push(ws);
        let started;const deadline=setTimeout(()=>reject(Error('Upgrade timeout did not release request')),4500);
        ws.on('error',reject);ws.on('open',()=>{started=performance.now();ws.send(JSON.stringify({token,codexAgent:true,payload:{action:'health',requestId:'blocked'}}))});
        ws.on('message',raw=>{const message=JSON.parse(raw);if(message.codexAgent&&message.payload?.status==='error'){clearTimeout(deadline);resolve(performance.now()-started)}});
    })));
    await sleep(100);
    const https=require('https');const stats=await new Promise((resolve,reject)=>https.get('https://127.0.0.1:18086/perf-stats',{rejectUnauthorized:false},response=>{let data='';response.on('data',c=>data+=c);response.on('end',()=>resolve(JSON.parse(data)))}).on('error',reject));
    if(stats.bridgeWorkers!==0||stats.coroutines!==1)throw Error('Unresponsive upgrades retained coroutines');
    console.log(JSON.stringify({passed:true,connections:20,elapsedMs:elapsed,stats}));
})().catch(error=>{console.error(error);process.exitCode=1}).finally(()=>{clients.forEach(ws=>ws.terminate());for(const socket of sockets)socket.destroy();backend.close()});
