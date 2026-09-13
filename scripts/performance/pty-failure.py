import os
import pathlib,subprocess,shutil,time,os,signal
base=pathlib.Path(os.environ.get('FILEMANAGER_BENCH', '/tmp/filemanager-perf-reproduce'));src=pathlib.Path(os.environ['FILEMANAGER_REPO'])
for kind in ['node','php']:
 root=base/('pty-failure-'+kind);root.mkdir(exist_ok=True);(root/'files').mkdir(exist_ok=True)
 if not (root/'node_modules').exists():(root/'node_modules').symlink_to(base/'before/node_modules',target_is_directory=True)
 name='pty.js' if kind=='node' else 'pty.php';(root/name).write_text((src/name).read_text().replace('6060','16068'))
 log=open(base/(kind+'-pty-failure.log'),'w');server=subprocess.Popen(['node' if kind=='node' else 'php',name],cwd=root,stdout=log,stderr=log,start_new_session=True);time.sleep(2)
 try:
  subprocess.run(['node',str(base/'pty-failure.cjs'),str(root),kind],stdout=open(base/(kind+'-pty-failure.json'),'w'),check=True,timeout=30)
  subprocess.run(['python3',str(base/'sample.py'),str(root),'45',str(base/(kind+'-pty-failure-idle.json'))],stdout=open(base/(kind+'-pty-failure-idle.summary'),'w'),check=True)
 finally:
  os.killpg(server.pid,signal.SIGTERM)
  try:server.wait(timeout=5)
  except subprocess.TimeoutExpired:os.killpg(server.pid,signal.SIGKILL);server.wait()
 print(kind,flush=True)
