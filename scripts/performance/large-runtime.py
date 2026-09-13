import os
import pathlib,subprocess,shutil,json,time,os,signal
base=pathlib.Path(os.environ.get('FILEMANAGER_BENCH', '/tmp/filemanager-perf-reproduce'));source=pathlib.Path(os.environ['FILEMANAGER_REPO']);root=base/'large-runtime'
shutil.copytree(base/'before',root,ignore=shutil.ignore_patterns('node_modules','vendor','.git','files'),dirs_exist_ok=True)
for n in ['node_modules','vendor']:shutil.copytree(base/'before'/n,root/n,dirs_exist_ok=True)
for name in ['node_modules','vendor','files']:
 shutil.copytree(base/'scanner-large'/name,root/name/'perf-ignored',dirs_exist_ok=True)
cfgp=root/'plugins/configInterface.json';cfg=json.loads(cfgp.read_text());cfg['port']=18089;cfg['allowObservable']=['php'];cfg['fileManager']['autoRestart']=True;cfg['fileManager']['services']={s:False for s in ['pty','lsp','gpt','codex']};cfgp.write_text(json.dumps(cfg))
for label in ['before','after']:
 if label=='after':
  for name in ['plugins/Start/server/server.php','plugins/Start/server/fileWatcher.php','middleware.php']:
   s=(source/name).read_text()
   if name=='middleware.php':
    for a,b in [('6060','16069'),('3057','13059'),('3090','13099'),('3091','13099')]:s=s.replace(a,b)
   (root/name).write_text(s)
 log=open(base/(label+'-large-runtime.log'),'w');p=subprocess.Popen(['php','middleware.php'],cwd=root,stdout=log,stderr=log,start_new_session=True);time.sleep(15)
 subprocess.run(['python3',str(base/'sample.py'),str(root),'180',str(base/(label+'-large-runtime.json'))],stdout=open(base/(label+'-large-runtime.summary'),'w'),check=True)
 os.killpg(p.pid,signal.SIGTERM)
 try:p.wait(timeout=2)
 except subprocess.TimeoutExpired:os.killpg(p.pid,signal.SIGKILL);p.wait()
 print(label,flush=True)
