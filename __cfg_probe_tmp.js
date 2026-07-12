const WebSocket=require('ws');const path=require('path');const {pathToFileURL}=require('url');
const ws=new WebSocket('ws://127.0.0.1:3057/cfg-test');let id=0;const pending=new Map();
function send(o){ws.send(JSON.stringify(o));}
function request(m,p){const rid=++id;send({jsonrpc:'2.0',id:rid,method:m,params:p});return new Promise(r=>pending.set(rid,r));}
function notify(m,p){send({jsonrpc:'2.0',method:m,params:p});}
const fileUri=pathToFileURL('/home/lotus/projetos/filemanager/__cfg_probe.php').toString();
const code="<?php\nuse libspech\\Sip\\trunkController;\n$phone = new trunkController('', '', '0.0.0.0');\n$phone->\n";
const KINDNAME={2:'Method',10:'Property'};
function cfgFor(section){
  // Responde com maxItems alto para a seção "intelephense"
  return { completion: { maxItems: 3000, insertUseDeclaration: true, fullyQualifyGlobalConstantsAndFunctions: false, triggerParameterHints: true, snippets: true } , format:{enable:true}, files:{maxSize:5000000} };
}
ws.on('open',async()=>{
  await request('initialize',{processId:null,rootUri:null,capabilities:{textDocument:{completion:{completionItem:{snippetSupport:true}},contextSupport:true},workspace:{configuration:true,workspaceFolders:true}},initializationOptions:{globalStoragePath:'/tmp/intelephense',storagePath:'/tmp/intelephense'}});
  notify('initialized',{});
  setTimeout(()=>{
    notify('textDocument/didOpen',{textDocument:{uri:fileUri,languageId:'php',version:1,text:code}});
    setTimeout(async()=>{
      const r=await request('textDocument/completion',{textDocument:{uri:fileUri},position:{line:3,character:8},context:{triggerKind:2,triggerCharacter:'>'}});
      const items=Array.isArray(r)?r:(r&&r.items)||[];
      console.log('isIncomplete=',(r&&r.isIncomplete),' total=',items.length);
      const counts={};items.forEach(i=>{counts[KINDNAME[i.kind]||i.kind]=(counts[KINDNAME[i.kind]||i.kind]||0)+1;});
      console.log('por kind:',JSON.stringify(counts));
      ws.close();process.exit(0);
    },6000);
  },12000);
});
ws.on('message',(d)=>{const j=typeof d==='string'?d:d.toString('utf8');let m;try{m=JSON.parse(j);}catch(e){return;}
  if(m.id!==undefined&&m.id!==null&&pending.has(m.id)){pending.get(m.id)(m.result);pending.delete(m.id);return;}
  if(m.method==='workspace/configuration'){const its=(m.params&&m.params.items)||[];console.error('CFG sections:',JSON.stringify(its.map(x=>x.section)));send({jsonrpc:'2.0',id:m.id,result:its.map(x=>cfgFor(x.section))});return;}
  if(m.method==='client/registerCapability'||m.method==='window/workDoneProgress/create'){send({jsonrpc:'2.0',id:m.id,result:null});return;}
});
setTimeout(()=>{console.log('TIMEOUT');process.exit(1);},40000);
