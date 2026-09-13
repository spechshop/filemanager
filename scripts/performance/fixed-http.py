import os
import pathlib,subprocess,json,signal,os,time
b=pathlib.Path(os.environ.get('FILEMANAGER_BENCH', '/tmp/filemanager-perf-reproduce'))
assert (b/'http-after-workers2-api.json').exists(), 'Run http-more.py first'
time.sleep(3)
for label,level in [('before',9),('after',6)]:
 root=b/('http-'+label);p=root/'plugins/configInterface.json';cfg=json.loads(p.read_text());cfg['serverSettings']['http_compression_level']=level;cfg['serverSettings']['worker_num']=1;p.write_text(json.dumps(cfg))
 log=open(b/f'fixed-{label}.server.log','w');server=subprocess.Popen(['php','middleware.php'],cwd=root,stdout=log,stderr=log,start_new_session=True);time.sleep(3)
 sample=subprocess.Popen(['python3',str(b/'sample.py'),str(root),'60',str(b/f'fixed-{label}.cpu.json')],stdout=open(b/f'fixed-{label}.summary','w'))
 subprocess.run(['node',str(b/'fixed-http.cjs'),'18083','60','3'],stdout=open(b/f'fixed-{label}.json','w'),check=True);sample.wait()
 os.killpg(server.pid,signal.SIGTERM)
 try:server.wait(timeout=2)
 except subprocess.TimeoutExpired:os.killpg(server.pid,signal.SIGKILL);server.wait()
 print(label,flush=True)
