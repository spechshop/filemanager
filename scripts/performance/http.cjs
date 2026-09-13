const https=require('https'),http2=require('http2');
const [port,url,seconds0,concurrency0,encoding='gzip',protocol='http1']=process.argv.slice(2);const seconds=Number(seconds0),concurrency=Number(concurrency0);
const agent=new https.Agent({keepAlive:true,maxSockets:concurrency,rejectUnauthorized:false});const sessions=protocol==='h2'?[http2.connect(`https://127.0.0.1:${port}`,{rejectUnauthorized:false})]:[];
const lat=[];let count=0,errors=0,bytes=0,pending=0,done=false;const t=performance.now(),until=t+seconds*1000;
function next(){if(done||performance.now()>=until)return;pending++;const s=performance.now();const req=protocol==='h2'?sessions[0].request({':path':url,'accept-encoding':encoding}):https.get({host:'127.0.0.1',port,path:url,agent,headers:{'Accept-Encoding':encoding}},res=>{if(res.statusCode!==200)errors++;res.on('data',b=>bytes+=b.length);res.on('end',finish);});
if(protocol==='h2'){req.on('response',headers=>{if(headers[':status']!==200)errors++});req.on('data',b=>bytes+=b.length);req.on('end',finish);req.end()}
let ended=false;function finish(){if(ended)return;ended=true;pending--;count++;lat.push(performance.now()-s);next()}
req.on('error',()=>{errors++;finish()});req.setTimeout(10000,()=>req.destroy());}
for(let i=0;i<concurrency;i++)next();
setTimeout(()=>{done=true;setTimeout(()=>{agent.destroy();for(const s of sessions)s.destroy();lat.sort((a,b)=>a-b);const elapsed=(performance.now()-t)/1000;console.log(JSON.stringify({url,seconds,elapsed,concurrency,encoding,protocol,count,bytes,errors,pending,rps:count/seconds,p50:lat[Math.floor(lat.length*.5)],p95:lat[Math.floor(lat.length*.95)],p99:lat[Math.floor(lat.length*.99)]}));},100)},seconds*1000);
