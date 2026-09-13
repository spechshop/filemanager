import os
import pathlib,subprocess,shutil,json,os,signal,time
base=pathlib.Path(os.environ.get('FILEMANAGER_BENCH', '/tmp/filemanager-perf-reproduce'));src=pathlib.Path(os.environ['FILEMANAGER_REPO']);dest=base/'http-after'
shutil.copytree(base/'after',dest,ignore=shutil.ignore_patterns('node_modules','vendor','.git'),dirs_exist_ok=True)
for n in ['node_modules','vendor']:
 if not (dest/n).exists():(dest/n).symlink_to(base/'before'/n,target_is_directory=True)
for n in ['middleware.php','plugins/Message/server/server.php']:
 s=(src/n).read_text()
 for a,b in [('6060','16060'),('3057','13057'),('3090','13090'),('3091','13091')]:s=s.replace(a,b)
 (dest/n).write_text(s)
shutil.copy2(base/'http-before/js/perf-large.js',dest/'js/perf-large.js')

def run(root,level,label,cases,proto='http1',worker=1):
 cfgp=root/'plugins/configInterface.json';cfg=json.loads(cfgp.read_text());cfg['port']=18083;cfg['fileManager']['autoRestart']=False;cfg['serverSettings']['http_compression_level']=level;cfg['serverSettings']['worker_num']=worker;cfgp.write_text(json.dumps(cfg))
 log=open(base/(label+'.server.log'),'w');server=subprocess.Popen(['php','middleware.php'],cwd=root,stdout=log,stderr=log,start_new_session=True);time.sleep(3)
 for case,url,encoding in cases:
  name=label+'-'+case
  sample=subprocess.Popen(['python3',str(base/'sample.py'),str(root),'30',str(base/(name+'.cpu.json'))],stdout=open(base/(name+'.summary'),'w'))
  subprocess.run(['node',str(base/'http.cjs'),'18083',url,'30','16',encoding,proto],stdout=open(base/(name+'.json'),'w'),check=True);sample.wait();print(name,flush=True)
 os.killpg(server.pid,signal.SIGTERM)
 try:server.wait(timeout=2)
 except subprocess.TimeoutExpired:os.killpg(server.pid,signal.SIGKILL);server.wait()
# Original JS probe was a 1-byte placeholder; measure the actual shipped bundle.
for level in [9,6,3]:run(base/'http-before',level,f'http-before-level{level}',[('js-real','/js/jquery.toast.min.js','gzip')])
run(dest,6,'http-after-level6',[(c,u,'gzip') for c,u in [('api','/checkToken?tokenBrowser=perf'),('html','/'),('js-real','/js/jquery.toast.min.js'),('css','/css/buildCurl.css'),('large','/js/perf-large.js')]])
run(dest,6,'http-after-identity',[('html','/','identity'),('large','/js/perf-large.js','identity')])
run(dest,6,'http-after-h2',[('html','/','gzip'),('api','/checkToken?tokenBrowser=perf','gzip')],proto='h2')
run(dest,6,'http-after-workers2',[('html','/','gzip'),('api','/checkToken?tokenBrowser=perf','gzip')],worker=2)
