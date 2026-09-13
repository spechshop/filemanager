import os
import pathlib,subprocess,json,shutil,time,os,signal,ssl,urllib.request,ctypes
base=pathlib.Path(os.environ.get('FILEMANAGER_BENCH', '/tmp/filemanager-perf-reproduce'));src=pathlib.Path(os.environ['FILEMANAGER_REPO']);root=base/'fullstack'
shutil.copytree(base/'after',root,ignore=shutil.ignore_patterns('node_modules','vendor','.git'),dirs_exist_ok=True)
for n in ['node_modules','vendor']:
 if not (root/n).exists():(root/n).symlink_to(base/'before'/n,target_is_directory=True)
for name in ['codex-agent.js','server.php','middleware.php','pty.js','plugins/Start/server/server.php','plugins/Start/server/fileWatcher.php','plugins/Message/server/server.php','plugins/Extension/plugins/terminal.php']:
 s=(src/name).read_text()
 for a,b in [('6060','16067'),('3057','13057'),('3090','13090'),('3091','13097')]:s=s.replace(a,b)
 (root/name).write_text(s)
cfgp=root/'plugins/configInterface.json';cfg=json.loads(cfgp.read_text());cfg['port']=18087;cfg['fileManager']['autoRestart']=True;cfg['fileManager']['services']['pty']=True;cfg['fileManager']['services']['codex']=True;cfg['serverSettings']['http_compression_level']=6;cfgp.write_text(json.dumps(cfg))
(root/'.env').write_text(f'CODEX_AGENT_PORT=13097\nCODEX_BIN={src}/.runtime/codex/bin/codex\n')
ctypes.CDLL(None).prctl(36,1,0,0,0)
def snapshot():
 rows=[]
 for p in pathlib.Path('/proc').iterdir():
  if not p.name.isdigit():continue
  try:
   cwd=os.readlink(p/'cwd')
   if cwd!=str(root) and not cwd.startswith(str(root)+'/'):continue
   s=(p/'stat').read_text().rsplit(')',1)[1].split();rows.append({'pid':int(p.name),'ppid':int(s[1]),'state':s[0],'fds':len(list((p/'fd').iterdir())),'threads':int(s[17]),'rss_kb':int(s[21])*4,'cmd':(p/'cmdline').read_bytes().replace(b'\0',b' ').decode()})
  except OSError:pass
 return rows
(base/'fullstack-server.log').write_text('')
(base/'fullstack-clients.jsonl').write_text('')
pty=subprocess.Popen(['node','pty.js'],cwd=root,stdout=open(base/'fullstack-pty.log','w'),stderr=subprocess.STDOUT,start_new_session=True)
agent=subprocess.Popen(['node','codex-agent.js'],cwd=root,stdout=open(base/'fullstack-codex.log','w'),stderr=subprocess.STDOUT,start_new_session=True)
time.sleep(8);results=[];server=None
try:
 for i in range(30):
  server=subprocess.Popen(['php','middleware.php'],cwd=root,stdout=open(base/'fullstack-server.log','a'),stderr=subprocess.STDOUT,start_new_session=True)
  t=time.monotonic()
  while time.monotonic()-t<12:
   try:
    with urllib.request.urlopen('https://127.0.0.1:18087/checkToken?tokenBrowser=perf',context=ssl._create_unverified_context(),timeout=1) as r:assert json.loads(r.read())['success']
    break
   except Exception:time.sleep(.1)
  else:raise AssertionError('HTTP did not recover')
  subprocess.run(['node',str(base/'fullstack-client.cjs'),str(root),str(i)],stdout=open(base/'fullstack-clients.jsonl','a'),check=True)
  snap=snapshot();assert not any(p['state']=='Z' for p in snap)
  assert len([p for p in snap if 'node pty.js' in p['cmd']])==1
  assert len([p for p in snap if 'node codex-agent.js' in p['cmd']])==1
  server.terminate();server.wait(timeout=8);time.sleep(.15)
  steady=snapshot();assert not any('php middleware.php' in p['cmd'] for p in steady)
  row={'cycle':i,'startup_seconds':time.monotonic()-t,'running':snap,'between':steady};results.append(row);print(json.dumps(row),flush=True)
 # Explicit terminal close, then service shutdown, must reap the shell and app-server.
 subprocess.run(['node','-e',"const W=require('./node_modules/ws');const w=new W('ws://127.0.0.1:16067/perf-session');w.on('open',()=>w.send('closeXtermHandlerCommand'));setTimeout(()=>{w.terminate();process.exit()},300)"],cwd=root,check=True)
 pty.terminate();agent.terminate();pty.wait(timeout=8);agent.wait(timeout=8);time.sleep(.5)
 # Reap any grandchildren adopted by the test harness and retain evidence if any existed.
 adopted=[]
 while True:
  try:
   pid,status=os.waitpid(-1,os.WNOHANG)
   if pid==0:break
   adopted.append([pid,status])
  except ChildProcessError:break
 final=snapshot();assert final==[],final
 assert 'FATAL ERROR' not in (base/'fullstack-server.log').read_text()
 assert 'worker exit timeout' not in (base/'fullstack-server.log').read_text()
 print(json.dumps({'passed':True,'cycles':30,'adopted':adopted,'final':final}),flush=True)
finally:
 for p in [server,pty,agent]:
  if p is not None and p.poll() is None:
   os.killpg(p.pid,signal.SIGTERM)
   try:p.wait(timeout=5)
   except subprocess.TimeoutExpired:os.killpg(p.pid,signal.SIGKILL);p.wait()
 (base/'fullstack-results.json').write_text(json.dumps(results,indent=2))
