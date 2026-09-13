import os
import pathlib,subprocess,time,json,os,signal,shutil
b=pathlib.Path(os.environ.get('FILEMANAGER_BENCH', '/tmp/filemanager-perf-reproduce'));root=b/'regression';shutil.copy2(pathlib.Path(os.environ['FILEMANAGER_REPO'])/'server.php',root/'server.php')
p=root/'plugins/configInterface.json';cfg=json.loads(p.read_text());cfg['fileManager']['autoRestart']=True;p.write_text(json.dumps(cfg))
log=open(b/'stop-supervisor.log','w');p=subprocess.Popen(['php','server.php','--fix'],cwd=root,stdout=log,stderr=log,start_new_session=True);time.sleep(3)
child=int((root/'.runtime/server-address').read_text().split()[0]);start=time.monotonic();p.terminate()
try:p.wait(timeout=8);passed=not pathlib.Path('/proc/'+str(child)).exists()
except subprocess.TimeoutExpired:passed=False;os.killpg(p.pid,signal.SIGKILL);p.wait()
print(json.dumps({'parent':p.pid,'middleware':child,'passed':passed,'seconds':time.monotonic()-start}),flush=True)

assert passed, 'Supervisor or middleware did not exit'
