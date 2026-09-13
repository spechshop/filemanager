import os
import pathlib,subprocess,time,os,signal,json
b=pathlib.Path(os.environ.get('FILEMANAGER_BENCH', '/tmp/filemanager-perf-reproduce'))
# Separate browser data directories and CDP ports from the panel measurement.
p=b/'browser-ui.cjs';s=p.read_text().replace('19222','19223').replace('chrome-profile','chrome-ui-profile');p.write_text(s)
for label,port in [('before',18080),('after',18088)]:
 root=b/label
 if label=='after':
  import shutil
  dest=b/'ui-after';shutil.copytree(root,dest,ignore=shutil.ignore_patterns('node_modules','vendor','.git'),dirs_exist_ok=True)
  for n in ['node_modules','vendor']:
   if not (dest/n).exists():(dest/n).symlink_to(b/'before'/n,target_is_directory=True)
  root=dest
 cfgp=root/'plugins/configInterface.json';cfg=json.loads(cfgp.read_text());cfg['port']=port;cfg['fileManager']['autoRestart']=False;cfg['fileManager']['services']['codex']=False;cfgp.write_text(json.dumps(cfg))
 (root/'files/edit.php').write_text('<?php\n$x = 1;\n')
 log=open(b/f'{label}-ui-server.log','w');server=subprocess.Popen(['php','middleware.php'],cwd=root,stdout=log,stderr=log,start_new_session=True);time.sleep(3)
 sampler=subprocess.Popen(['python3',str(b/'sample.py'),str(root),'120',str(b/f'{label}-editor-ui-cpu.json')],stdout=open(b/f'{label}-editor-ui.summary','w'))
 try:subprocess.run(['node',str(b/'browser-ui.cjs'),str(root),'30'],env={**os.environ,'BENCH_PORT':str(port),'BROWSER_EDIT':'1'},stdout=open(b/f'{label}-editor-ui.jsonl','w'),stderr=subprocess.STDOUT,check=True,timeout=170)
 finally:
  sampler.wait();os.killpg(server.pid,signal.SIGTERM)
  try:server.wait(timeout=2)
  except subprocess.TimeoutExpired:os.killpg(server.pid,signal.SIGKILL);server.wait()
 print(label,flush=True)
