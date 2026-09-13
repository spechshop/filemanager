import json
import os
from pathlib import Path
import shutil
import subprocess

base = Path(os.environ['FILEMANAGER_BENCH'])
src = Path(os.environ['FILEMANAGER_REPO'])
root = base / 'pty-backend-failure'
shutil.copytree(base / 'after', root, dirs_exist_ok=True,
                ignore=shutil.ignore_patterns('node_modules', 'vendor', '.git', 'files'))
for name in ['node_modules', 'vendor']:
    if not (root/name).exists(): (root/name).symlink_to(base/'before'/name, target_is_directory=True)
(root/'files').mkdir(exist_ok=True)
for name in ['middleware.php', 'pty.js', 'plugins/Message/server/server.php']:
    text = (src/name).read_text()
    for old, new in [('6060', '16071'), ('3091', '13071'), ('3057', '13072'), ('3090', '13073')]:
        text = text.replace(old, new)
    (root/name).write_text(text)
p = root/'plugins/configInterface.json'
cfg = json.loads(p.read_text()); cfg['port'] = 18091
cfg['fileManager']['autoRestart'] = False
cfg['fileManager']['services'] = dict.fromkeys(['pty', 'codex', 'lsp', 'gpt'], False)
p.write_text(json.dumps(cfg))
subprocess.run(['node', str(base/'pty-backend-failure.cjs'), str(root)],
               stdout=open(base/'pty-backend-failure.json', 'w'), check=True)
log = (base/'pty-backend-failure.log').read_text()
assert 'FATAL ERROR' not in log and 'worker exit timeout' not in log
