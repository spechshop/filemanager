import os
import pathlib,subprocess,shutil,json,time,os,signal
base=pathlib.Path(os.environ.get('FILEMANAGER_BENCH', '/tmp/filemanager-perf-reproduce'));source=pathlib.Path(os.environ['FILEMANAGER_REPO'])
for label in ['before','after']:
 root=base/(label+'-bridge-failure');shutil.copytree(base/label,root,ignore=shutil.ignore_patterns('node_modules','vendor','.git'),dirs_exist_ok=True)
 for n in ['node_modules','vendor']:
  if not (root/n).exists():(root/n).symlink_to(base/'before'/n,target_is_directory=True)
 if label=='after':
  for n in ['middleware.php','plugins/Message/server/server.php']:shutil.copy2(source/n,root/n)
 for n in ['middleware.php','plugins/Message/server/server.php']:
  p=root/n;s=p.read_text()
  for a,b in [('13091','13096'),('3091','13096'),('16060','16066'),('6060','16066')]:s=s.replace(a,b)
  if n=='middleware.php':
   s=s.replace("$server->on('Request', '\\plugins\\Request\\server::request');",'''$server->on('Request', static function($request,$response){
 if($request->server['path_info']==='/perf-stats') {
  $workers=0;foreach($GLOBALS['codexAgent']??[] as $session)if(!empty($session['worker']))$workers++;
  return $response->end(json_encode(['pid'=>getmypid(),'coroutines'=>Swoole\\Coroutine::stats()['coroutine_num'],'bridgeWorkers'=>$workers,'fds'=>count(scandir('/proc/self/fd'))-2,'memory'=>memory_get_usage(true)]));
 }
 return \\plugins\\Request\\server::request($request,$response);
});''')
  p.write_text(s)
 cfgp=root/'plugins/configInterface.json';cfg=json.loads(cfgp.read_text());cfg['port']=18086;cfg['fileManager']['autoRestart']=False;cfgp.write_text(json.dumps(cfg))
 (root/'database/tokens.lotus').write_text(json.dumps({**{'perf':{'expire':2000000000,'nameClient':'Benchmark'}},**{'perf'+str(i):{'expire':2000000000,'nameClient':'Benchmark'} for i in range(40)}}))
 log=open(base/(label+'-bridge-failure.log'),'w');p=subprocess.Popen(['php','middleware.php'],cwd=root,stdout=log,stderr=log,start_new_session=True);time.sleep(3)
 with open(base/(label+'-bridge-failure.json'),'w') as out:subprocess.run(['node',str(base/'bridge-failure.cjs'),str(root),label],stdout=out,check=True)
 os.killpg(p.pid,signal.SIGTERM)
 try:p.wait(timeout=3)
 except subprocess.TimeoutExpired:os.killpg(p.pid,signal.SIGKILL);p.wait()
 print(label,flush=True)
