import os
import pathlib,subprocess,shutil,json,os,signal,time
base=pathlib.Path(os.environ.get('FILEMANAGER_BENCH', '/tmp/filemanager-perf-reproduce'));src=base/'before';dest=base/'http-before'
shutil.copytree(src,dest,ignore=shutil.ignore_patterns('node_modules','vendor','files'),dirs_exist_ok=True)
for n in ['node_modules','vendor']:
 if not (dest/n).exists():(dest/n).symlink_to(src/n,target_is_directory=True)
(dest/'files').mkdir(exist_ok=True)
# Deterministic 2 MiB moderately compressible JS fixture, same bytes at every level.
import random
rng=random.Random(731)
payload=''.join('const item%d=%s;\n'%(i,json.dumps(''.join(rng.choices('abcdefghijk0123456789 ',k=100)))) for i in range(18000))
(dest/'js/perf-large.js').write_text(payload)
cfgp=dest/'plugins/configInterface.json';cfg=json.loads(cfgp.read_text());cfg['port']=18083;cfg['fileManager']['autoRestart']=False
for level in [9,6,3]:
 cfg['serverSettings']['http_compression_level']=level;cfgp.write_text(json.dumps(cfg))
 log=open(base/f'http-level{level}.server.log','w');server=subprocess.Popen(['php','middleware.php'],cwd=dest,stdout=log,stderr=log,start_new_session=True);time.sleep(3)
 for case,url in [('api','/checkToken?tokenBrowser=perf'),('html','/'),('js-real','/js/jquery.toast.min.js'),('css','/css/buildCurl.css'),('large','/js/perf-large.js')]:
  name=f'http-before-level{level}-{case}'
  sample=subprocess.Popen(['python3',str(base/'sample.py'),str(dest),'30',str(base/(name+'.cpu.json'))],stdout=open(base/(name+'.summary'),'w'))
  subprocess.run(['node',str(base/'http.cjs'),'18083',url,'30','16'],stdout=open(base/(name+'.json'),'w'),check=True);sample.wait()
  print(name,flush=True)
 # Baseline shutdown needs SIGKILL because its master timers survive SIGTERM.
 os.killpg(server.pid,signal.SIGTERM)
 try:server.wait(timeout=2)
 except subprocess.TimeoutExpired:os.killpg(server.pid,signal.SIGKILL);server.wait()
