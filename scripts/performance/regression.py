import os
import pathlib,subprocess,shutil,json,os,signal,time,urllib.request,ssl,ctypes
base=pathlib.Path(os.environ.get('FILEMANAGER_BENCH', '/tmp/filemanager-perf-reproduce'));src=pathlib.Path(os.environ['FILEMANAGER_REPO']);dest=base/'regression'
shutil.copytree(base/'after',dest,ignore=shutil.ignore_patterns('node_modules','vendor','files','.git'),dirs_exist_ok=True)
for n in ['node_modules','vendor']:
 if not (dest/n).exists():(dest/n).symlink_to(base/'before'/n,target_is_directory=True)
(dest/'files').mkdir(exist_ok=True)
for name in subprocess.check_output(['git','ls-files','-z'],cwd=src).decode().split('\0')+['plugins/Start/server/fileWatcher.php']:
 if name and (src/name).is_file() and name!='plugins/configInterface.json':
  q=dest/name;q.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(src/name,q)
for name in ['middleware.php','pty.js','pty.php','plugins/Message/server/server.php']:
 p=dest/name;s=p.read_text()
 for a,b in [('6060','16063'),('3057','13053'),('3090','13093'),('3091','13094')]:s=s.replace(a,b)
 p.write_text(s)
cfgp=dest/'plugins/configInterface.json';cfg=json.loads(cfgp.read_text());cfg['port']=18084;cfg['allowObservable']=['php'];cfg['fileManager']['autoRestart']=True;cfg['fileManager']['services']={k:False for k in ['pty','lsp','gpt','codex']};cfgp.write_text(json.dumps(cfg,indent=4))
(dest/'database/tokens.lotus').write_text(json.dumps({'perf':{'expire':2000000000,'nameClient':'Benchmark'}}))
ctypes.CDLL(None).prctl(36,1,0,0,0)
ctx=ssl._create_unverified_context()
def get(path='/checkToken?tokenBrowser=perf'):
 with urllib.request.urlopen('https://127.0.0.1:18084'+path,context=ctx,timeout=2) as r:return r.read()
def current():
 try:return int((dest/'.runtime/server-address').read_text().split()[0])
 except (OSError,ValueError):return 0
def alive(pid):
 try:os.kill(pid,0);return True
 except ProcessLookupError:return False
def ready(old=None,timeout=20):
 t=time.monotonic()
 while time.monotonic()-t<timeout:
  pid=current()
  if pid and pid!=old and alive(pid):
   try:
    assert json.loads(get())['success'];return pid
   except Exception:pass
  time.sleep(.1)
 raise AssertionError('port did not recover')
def snapshot():
 result=[]
 for p in pathlib.Path('/proc').iterdir():
  if not p.name.isdigit():continue
  try:
   cwd=os.readlink(p/'cwd')
   if cwd!=str(dest) and not cwd.startswith(str(dest)+'/'):continue
   s=(p/'stat').read_text().rsplit(')',1)[1].split()
   result.append({'pid':int(p.name),'ppid':int(s[1]),'state':s[0],'threads':int(s[17]),'fds':len(list((p/'fd').iterdir())),'rss_kb':int(s[21])*4,'cmd':(p/'cmdline').read_bytes().replace(b'\0',b' ').decode()})
  except OSError:pass
 return result
for name in ['perf-observed.php','perf-other.php','perf-renamed.php']:
 (dest/'plugins/Request'/name).unlink(missing_ok=True)
log=open(base/'regression-server.log','w');parent=subprocess.Popen(['php','server.php','--fix'],cwd=dest,stdout=log,stderr=log,start_new_session=True)
results=[]
try:
 pid=ready();time.sleep(1);initial=snapshot();print(json.dumps({'initial':initial}),flush=True)
 watched=dest/'plugins/Request/perf-observed.php'
 def mutate(label,fn,expect=True):
  global pid
  old=pid;t=time.monotonic();fn()
  if expect:
   pid=ready(old,25);assert not alive(old),'old master still alive'
   time.sleep(.4)
  else:
   time.sleep(11);assert current()==old and alive(old),'ignored change restarted server'
  snap=snapshot();assert not any(p['state']=='Z' for p in snap),'zombie';assert len(snap)==4,(label,snap)
  row={'case':label,'seconds':time.monotonic()-t,'old':old,'new':pid,'processes':snap};results.append(row);print(json.dumps(row),flush=True)
 mutate('create',lambda:watched.write_text('<?php // baseline\n'))
 mutate('modify',lambda:watched.write_text('<?php // changed\n'))
 mutate('rapid-saves',lambda:[watched.write_text('<?php // save '+str(i)) for i in range(20)])
 other=dest/'plugins/Request/perf-other.php'
 mutate('several-files',lambda:(watched.write_text('<?php // batch'),other.write_text('<?php // batch')))
 mutate('rename',lambda:other.rename(other.with_name('perf-renamed.php')))
 mutate('delete',lambda:other.with_name('perf-renamed.php').unlink())
 for dirname in ['files','plugins/Request/node_modules','plugins/Request/vendor','plugins/Request/stubs']:
  d=dest/dirname/'perf';d.mkdir(parents=True,exist_ok=True)
  mutate('ignored-'+dirname,lambda d=d:(d/'ignored.php').write_text('<?php'),False)
 # Keep real TLS HTTP traffic and a metrics WebSocket alive across a watched save.
 env={**os.environ,'BENCH_PORT':'18084'}
 ws=subprocess.Popen(['node',str(base/'client.cjs'),str(dest),'panel','20'],env=env,stdout=open(base/'regression-ws-restart.json','w'))
 load=subprocess.Popen(['node',str(base/'http.cjs'),'18084','/checkToken?tokenBrowser=perf','20','4','identity'],stdout=open(base/'regression-http-restart.json','w'))
 mutate('http-and-websocket',lambda:watched.write_text('<?php // under traffic'))
 ws.wait();load.wait()
 for i in range(30):
  old=pid;t=time.monotonic();os.kill(old,signal.SIGTERM);pid=ready(old,15);time.sleep(.15)
  snap=snapshot();assert not alive(old),'old master alive';assert len(snap)==4,('count',snap);assert not any(p['state']=='Z' for p in snap)
  row={'cycle':i+1,'seconds':time.monotonic()-t,'processes':snap};results.append(row);print(json.dumps(row),flush=True)
 # Disable supervision, terminate middleware, verify everything exits and frees the port.
 cfg['fileManager']['autoRestart']=False;cfgp.write_text(json.dumps(cfg));os.kill(pid,signal.SIGTERM);parent.wait(timeout=12);time.sleep(.2)
 assert snapshot()==[],snapshot()
 print(json.dumps({'passed':True,'cycles':30,'checks':len(results),'final':snapshot()}),flush=True)
finally:
 if parent.poll() is None:
  cfg['fileManager']['autoRestart']=False;cfgp.write_text(json.dumps(cfg))
  if current() and alive(current()):os.kill(current(),signal.SIGTERM)
  try:parent.wait(timeout=10)
  except subprocess.TimeoutExpired:os.killpg(parent.pid,signal.SIGKILL);parent.wait()
 (base/'regression-results.json').write_text(json.dumps(results,indent=2))
