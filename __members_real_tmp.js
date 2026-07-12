const WebSocket=require('ws');const path=require('path');const {pathToFileURL}=require('url');
const ws=new WebSocket('ws://127.0.0.1:3057/real-test');let id=0;const pending=new Map();
function send(o){ws.send(JSON.stringify(o));}
function request(m,p){const rid=++id;send({jsonrpc:'2.0',id:rid,method:m,params:p});return new Promise(r=>pending.set(rid,r));}
function notify(m,p){send({jsonrpc:'2.0',method:m,params:p});}
const fileUri=pathToFileURL('/home/lotus/projetos/filemanager/__real_probe.php').toString();
const code="<?php\nuse libspech\\Sip\\trunkController;\n$phone = new trunkController('', '', '0.0.0.0');\n$phone->\n";
const KINDNAME={1:'Text',2:'Method',3:'Function',4:'Ctor',5:'Field',6:'Var',7:'Class',9:'Module',10:'Property',21:'Constant'};
ws.on('open',async()=>{
  await request('initialize',{processId:null,rootUri:null,capabilities:{textDocument:{completion:{completionItem:{snippetSupport:true,documentationFormat:['markdown','plaintext']},contextSupport:true}},workspace:{configuration:true,workspaceFolders:true}},initializationOptions:{storagePath:'/tmp/intelephense',globalStoragePath:'/tmp/intelephense'}});
  notify('initialized',{});
  setTimeout(()=>{
    notify('textDocument/didOpen',{textDocument:{uri:fileUri,languageId:'php',version:1,text:code}});
    setTimeout(async()=>{
      const r=await request('textDocument/completion',{textDocument:{uri:fileUri},position:{line:3,character:8},context:{triggerKind:2,triggerCharacter:'>'}});
      const items=Array.isArray(r)?r:(r&&r.items)||[];
      console.log('isIncomplete=',(r&&r.isIncomplete));
      console.log('total itens =',items.length);
      const counts={};items.forEach(i=>{counts[KINDNAME[i.kind]||i.kind]=(counts[KINDNAME[i.kind]||i.kind]||0)+1;});
      console.log('por kind:',JSON.stringify(counts));
      // primeiros 15 por sortText
      const sorted=items.slice().sort((a,b)=>String(a.sortText||a.label).localeCompare(String(b.sortText||b.label)));
      console.log('--- primeiros 15 (ordenados por sortText) ---');
      sorted.slice(0,15).forEach(i=>console.log('  '+i.label+' ['+(KINDNAME[i.kind]||i.kind)+'] sort='+JSON.stringify(i.sortText)));
      ws.close();process.exit(0);
    },6000);
  },12000);
});
ws.on('message',(d)=>{const j=typeof d==='string'?d:d.toString('utf8');let m;try{m=JSON.parse(j);}catch(e){return;}
  if(m.id!==undefined&&m.id!==null&&pending.has(m.id)){pending.get(m.id)(m.result);pending.delete(m.id);return;}
  if(m.method==='workspace/configuration'){const items=(m.params&&m.params.items)||[];send({jsonrpc:'2.0',id:m.id,result:items.map(()=>({completion:{maxItems:3000,insertUseDeclaration:true,fullyQualifyGlobalConstantsAndFunctions:false,triggerParameterHints:true,snippets:true},format:{enable:true},files:{maxSize:5000000}}))});return;}
  if(m.method==='client/registerCapability'||m.method==='window/workDoneProgress/create'){send({jsonrpc:'2.0',id:m.id,result:null});return;}
});
setTimeout(()=>{console.log('TIMEOUT');process.exit(1);},40000);
