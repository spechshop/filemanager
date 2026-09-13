import os
import pathlib,subprocess,time,json,os,signal
b=pathlib.Path(os.environ.get('FILEMANAGER_BENCH', '/tmp/filemanager-perf-reproduce'));root=b/'http-before'
for iteration,encoding,level in [(i,'gzip',level) for i in range(3) for level in ([9,6] if i%2==0 else [6,9])]+[(0,'br',9),(0,'br',6)]:
 p=root/'plugins/configInterface.json';cfg=json.loads(p.read_text());cfg['port']=18083;cfg['serverSettings']['http_compression_level']=level;cfg['serverSettings']['worker_num']=1;p.write_text(json.dumps(cfg))
 name=f'compression-{encoding}-{iteration}-level{level}';log=open(b/(name+'.log'),'w');server=subprocess.Popen(['php','middleware.php'],cwd=root,stdout=log,stderr=log,start_new_session=True);time.sleep(3)
 sample=subprocess.Popen(['python3',str(b/'sample.py'),str(root),'20',str(b/(name+'.cpu.json'))],stdout=open(b/(name+'.summary'),'w'))
 subprocess.run(['node',str(b/'http.cjs'),'18083','/','20','16',encoding],stdout=open(b/(name+'.json'),'w'),check=True);sample.wait()
 os.killpg(server.pid,signal.SIGTERM)
 try:server.wait(timeout=1)
 except subprocess.TimeoutExpired:os.killpg(server.pid,signal.SIGKILL);server.wait()
 print(name,flush=True)
