"""Run after bridge-failure.py has prepared its instrumented, isolated fixture."""
import os
from pathlib import Path
import subprocess
import time

base=Path(os.environ['FILEMANAGER_BENCH']);src=Path(os.environ['FILEMANAGER_REPO'])
root=base/'after-bridge-failure'
text=(src/'plugins/Message/server/server.php').read_text().replace('3091','13096')
(root/'plugins/Message/server/server.php').write_text(text)
log=open(base/'bridge-upgrade-failure.log','w')
server=subprocess.Popen(['php','middleware.php'],cwd=root,stdout=log,stderr=log,start_new_session=True)
try:
    time.sleep(3)
    subprocess.run(['node',str(src/'scripts/performance/bridge-upgrade-failure.cjs'),str(root)],
                   stdout=open(base/'bridge-upgrade-failure.json','w'),check=True,timeout=10)
finally:
    server.terminate();server.wait(timeout=5)
