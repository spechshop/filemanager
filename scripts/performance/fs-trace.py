import os
import pathlib,shutil,subprocess,json,time,os,signal,collections
base=pathlib.Path(os.environ.get('FILEMANAGER_BENCH', '/tmp/filemanager-perf-reproduce'))
for label in ['before','after']:
 src=base/label;dest=base/(label+'-fs-trace')
 shutil.copytree(src,dest,ignore=shutil.ignore_patterns('.git','files'),dirs_exist_ok=True)
 (dest/'files').mkdir(exist_ok=True)
 cfgp=dest/'plugins/configInterface.json';cfg=json.loads(cfgp.read_text());cfg['port']=18085;cfg['fileManager']['autoRestart']=True;cfgp.write_text(json.dumps(cfg))
 prefix=base/(label+'-fs.strace')
 log=open(base/(label+'-fs-server.log'),'w');p=subprocess.Popen(['strace','-ff','-ttt','-e','trace=%file,getdents64','-o',str(prefix),'php','middleware.php'],cwd=dest,stdout=log,stderr=log,start_new_session=True)
 time.sleep(5);start=time.time();time.sleep(60);end=time.time()
 # All spawned processes belong to this new process group.
 os.killpg(p.pid,signal.SIGTERM)
 try:p.wait(timeout=2)
 except subprocess.TimeoutExpired:os.killpg(p.pid,signal.SIGKILL);p.wait()
 counts=collections.Counter();paths=collections.Counter()
 for path in base.glob(prefix.name+'.*'):
  for line in path.read_text(errors='replace').splitlines():
   fields=line.split(' ',1)
   try:t=float(fields[0])
   except ValueError:continue
   if not start<=t<=end:continue
   name=fields[1].split('(',1)[0];counts[name]+=1
   for target in ['tokens.lotus','configInterface.json','Request/pages','node_modules','vendor']:
    if target in line:paths[target]+=1
 (base/(label+'-fs-summary.json')).write_text(json.dumps({'seconds':end-start,'syscalls':counts,'path_mentions':paths},indent=2));print(label,dict(counts),flush=True)
