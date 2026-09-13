import os
import pathlib,subprocess,time,os,signal,sys,shutil
base=pathlib.Path(os.environ.get('FILEMANAGER_BENCH', '/tmp/filemanager-perf-reproduce'));label=sys.argv[1];src=pathlib.Path(sys.argv[2]);root=base/('codex-shutdown-'+label);root.mkdir(exist_ok=True);(root/'files').mkdir(exist_ok=True)
if not (root/'node_modules').exists():(root/'node_modules').symlink_to(base/'before/node_modules',target_is_directory=True)
shutil.copy2(src/'codex-agent.js',root/'codex-agent.js')
fake=root/'fake-codex';fake.write_text('''#!/usr/bin/env node
if(process.argv[2]==='login')process.exit(0);
require('fs').writeFileSync('fake.pid',String(process.pid));
process.on('SIGTERM',()=>{});
require('readline').createInterface({input:process.stdin}).on('line',line=>{const o=JSON.parse(line);if(o.id)process.stdout.write(JSON.stringify({id:o.id,result:{}})+'\\n')});
setInterval(()=>{},1000000);
''');fake.chmod(0o755)
(root/'.env').write_text(f'CODEX_AGENT_PORT=13098\nCODEX_BIN={fake}\n')
log=open(base/(label+'-codex-shutdown.log'),'w');p=subprocess.Popen(['node','codex-agent.js'],cwd=root,stdout=log,stderr=log,start_new_session=True)
try:
 time.sleep(2);child=int((root/'fake.pid').read_text());start=time.monotonic();p.terminate();p.wait(timeout=8);time.sleep(.2)
 alive=pathlib.Path('/proc/'+str(child)).exists()
 print(__import__('json').dumps({'label':label,'agent_pid':p.pid,'child_pid':child,'exit_seconds':time.monotonic()-start,'child_survived':alive}),flush=True)
 if alive:os.kill(child,signal.SIGKILL)
finally:
 if p.poll() is None:os.killpg(p.pid,signal.SIGKILL);p.wait()
