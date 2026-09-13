import os
import pathlib,subprocess,time,json,os,signal,shutil,ctypes
b=pathlib.Path(os.environ.get('FILEMANAGER_BENCH', '/tmp/filemanager-perf-reproduce'));root=b/'fullstack';src=pathlib.Path(os.environ['FILEMANAGER_REPO'])
for n in ['codex-agent.js','server.php']:shutil.copy2(src/n,root/n)
ctypes.CDLL(None).prctl(36,1,0,0,0)
logs=[];processes=[]
try:
 for args,name in [(['node','pty.js'],'pty'),(['node','codex-agent.js'],'codex'),(['php','server.php','--fix'],'supervisor')]:
  log=open(b/f'long-idle-{name}.log','w');logs.append(log);processes.append(subprocess.Popen(args,cwd=root,stdout=log,stderr=log,start_new_session=True));time.sleep(2)
 time.sleep(14)
 # Confirm real HTTP and Codex startup, then keep the stack completely idle.
 subprocess.run(['curl','-fkSs','https://127.0.0.1:18087/checkToken?tokenBrowser=perf'],stdout=open(b/'long-idle-health.json','w'),check=True)
 assert 'inicializado' in (b/'long-idle-codex.log').read_text()
 print(json.dumps({'phase':'sampling','pids':[p.pid for p in processes],'seconds':600}),flush=True)
 subprocess.run(['python3',str(b/'sample.py'),str(root),'600',str(b/'long-idle.json')],stdout=open(b/'long-idle.summary','w'),check=True)
 print('measurement complete',flush=True)
finally:
 # Exercise stopping the supervisor itself, not only its middleware.
 for p in reversed(processes):
  if p.poll() is None:
   p.terminate()
   try:p.wait(timeout=10)
   except subprocess.TimeoutExpired:os.killpg(p.pid,signal.SIGKILL);p.wait()
 time.sleep(.5);adopted=[]
 while True:
  try:
   pid,status=os.waitpid(-1,os.WNOHANG)
   if pid==0:break
   adopted.append([pid,status])
  except ChildProcessError:break
 remaining=[]
 for p in pathlib.Path('/proc').iterdir():
  if not p.name.isdigit():continue
  try:
   cwd=os.readlink(p/'cwd')
   if cwd==str(root) or cwd.startswith(str(root)+'/'):remaining.append(int(p.name))
  except OSError:pass
 (b/'long-idle-cleanup.json').write_text(json.dumps({'adopted':adopted,'remaining':remaining}))
 print(json.dumps({'cleanup':remaining,'adopted':adopted}),flush=True)
