import os,pathlib,shutil,json,subprocess,time,signal,concurrent.futures
b=pathlib.Path(os.environ['FILEMANAGER_BENCH']);src=pathlib.Path(os.environ['FILEMANAGER_REPO'])
names=['server.php','middleware.php','pty.js','codex-agent.js','plugins/Database/call/call.php','plugins/Request/apps/checkToken.php','plugins/Request/router/server.php','plugins/Request/views/controller.php','plugins/OpenConnection/websocket/OpenConnection.php','plugins/Message/server/server.php','plugins/Start/server/server.php','plugins/Start/server/fileWatcher.php','plugins/Extension/plugins/terminal.php']
def fixture(name,port,ptyport=16060,codexport=13091):
 root=b/name
 if name!='after':shutil.copytree(b/'after',root,ignore=shutil.ignore_patterns('node_modules','vendor','.git'),dirs_exist_ok=True)
 for n in ['node_modules','vendor']:
  if not (root/n).exists():(root/n).symlink_to(b/'before'/n,target_is_directory=True)
 for n in names:
  s=(src/n).read_text()
  if n in ['middleware.php','pty.js','plugins/Message/server/server.php']:
   for x,y in [('6060',str(ptyport)),('3091',str(codexport)),('3057','13157'),('3090','13190')]:s=s.replace(x,y)
  (root/n).write_text(s)
 cfgp=root/'plugins/configInterface.json';cfg=json.loads(cfgp.read_text());cfg['host']='127.0.0.1';cfg['port']=port;cfg['fileManager']['autoRestart']=False;cfg['fileManager']['services']=dict.fromkeys(['pty','codex','lsp','gpt'],False);cfgp.write_text(json.dumps(cfg))
 (root/'database/tokens.lotus').write_text(json.dumps({'perf':{'expire':2000000000,'nameClient':'Benchmark'}}));(root/'.env').write_text(f'CODEX_AGENT_PORT={codexport}\nCODEX_BIN={src}/.runtime/codex/bin/codex\n')
 return root,cfgp,cfg

def start(root,args,name):
 f=open(b/(name+'.log'),'w');return subprocess.Popen(args,cwd=root,stdout=f,stderr=f,start_new_session=True)
def stop(p):
 if p.poll() is None:
  p.terminate();p.wait(timeout=8)
def sample(root,port,name,seconds,client=None):
 sampler=subprocess.Popen(['python3',str(b/'sample.py'),str(root),str(seconds),str(b/f'after-{name}.json')],stdout=open(b/f'after-{name}.summary','w'))
 if client:c=subprocess.Popen(['node',str(b/'client.cjs'),str(root),client,str(seconds)],env={**os.environ,'BENCH_PORT':str(port)},stdout=open(b/f'after-{name}.client','w'))
 assert sampler.wait()==0
 if client:assert c.wait()==0,name
 print(name,flush=True)

def ui():
 root,cfgp,cfg=fixture('after',18082);cfg['fileManager']['autoRestart']=True;cfgp.write_text(json.dumps(cfg));p=start(root,['php','middleware.php'],'after-final-ui-server');time.sleep(10)
 try:
  for name,secs in [('panel',120),('browse',60),('editor',60)]:sample(root,18082,name,secs,name)
  for script,name,duration,total in [('browser.cjs','browser',120,145),('browser-ui.cjs','editor-ui',30,120)]:
   (root/'files/edit.php').write_text('<?php\n$x = 1;\n')
   sam=subprocess.Popen(['python3',str(b/'sample.py'),str(root),str(total),str(b/f'after-{name}-cpu.json')],stdout=open(b/f'after-{name}.summary','w'))
   subprocess.run(['node',str(b/script),str(root),str(duration)],env={**os.environ,'BENCH_PORT':'18082','BROWSER_EDIT':'1'},stdout=open(b/f'after-{name}.jsonl','w'),stderr=subprocess.STDOUT,check=True);assert sam.wait()==0;print(name,flush=True)
 finally:stop(p)
def terminal():
 root,cp,cfg=fixture('final-terminal',18096,16076);p=start(root,['php','middleware.php'],'after-final-terminal-server');pty=start(root,['node','pty.js'],'after-final-terminal-pty');time.sleep(10)
 try:
  for name in ['terminal-idle','terminal-active','terminal-multi']:sample(root,18096,name,60,name)
  sample(root,18096,'terminal-disconnected',60)
  subprocess.run(['node','-e',"const W=require('./node_modules/ws');for(let i=0;i<8;i++){const w=new W('ws://127.0.0.1:16076/perf-t'+i);w.on('open',()=>w.send('closeXtermHandlerCommand'))}setTimeout(()=>process.exit(),1000)"],cwd=root,check=True)
 finally:stop(p);stop(pty)
def codex():
 root,cp,cfg=fixture('final-codex',18097,16077,13077);p=start(root,['php','middleware.php'],'after-final-codex-server');agent=None;time.sleep(10)
 try:
  sample(root,18097,'codex-disabled',90);cfg['fileManager']['services']['codex']=True;cp.write_text(json.dumps(cfg));agent=start(root,['node','codex-agent.js'],'after-final-codex-agent');time.sleep(10);assert 'inicializado' in (b/'after-final-codex-agent.log').read_text()
  sample(root,18097,'codex-idle',90);sample(root,18097,'codex-active',90,'codex-active')
 finally:stop(p);stop(agent) if agent else None
def idle(auto):
 root,cp,cfg=fixture('final-idle-'+str(auto),18098 if auto else 18099,16078,13078);cfg['fileManager']['autoRestart']=auto;cp.write_text(json.dumps(cfg));p=start(root,['php','middleware.php'],'after-final-idle-'+str(auto));time.sleep(15)
 try:sample(root,cfg['port'],'idle' if auto else 'idle-no-autorestart',180)
 finally:stop(p)
# Prepare the shared final source before concurrent copies read it.
fixture('after',18082)
with concurrent.futures.ThreadPoolExecutor(max_workers=5) as ex:
 futures=[ex.submit(ui),ex.submit(terminal),ex.submit(codex),ex.submit(idle,True),ex.submit(idle,False)]
 for f in futures:f.result()
