"""Verify live worker invalidation, including rendered imports, without autorestart."""
import json
import os
from pathlib import Path
import shutil
import ssl
import subprocess
import time
import urllib.request

base = Path(os.environ['FILEMANAGER_BENCH']); src = Path(os.environ['FILEMANAGER_REPO'])
root = base/'request-cache-http'
shutil.copytree(base/'after', root, dirs_exist_ok=True,
                ignore=shutil.ignore_patterns('node_modules', 'vendor', 'files', '.git'))
for name in ['node_modules', 'vendor']:
    if not (root/name).exists(): (root/name).symlink_to(base/'before'/name, target_is_directory=True)
for name in ['middleware.php', 'plugins/Message/server/server.php', 'plugins/Database/call/call.php', 'plugins/Request/apps/checkToken.php', 'plugins/Request/router/server.php', 'plugins/Request/views/controller.php', 'plugins/OpenConnection/websocket/OpenConnection.php']:
    shutil.copy2(src/name, root/name)
(root/'files').mkdir(exist_ok=True)
p = root/'plugins/configInterface.json'; cfg = json.loads(p.read_text())
cfg['port'] = 18095; cfg['fileManager']['autoRestart'] = False
cfg['fileManager']['services'] = dict.fromkeys(['pty', 'codex', 'lsp', 'gpt'], False)
p.write_text(json.dumps(cfg)); checks=[]
def get(path):
    with urllib.request.urlopen('https://127.0.0.1:18095'+path,
                                context=ssl._create_unverified_context(), timeout=3) as response:
        globals()['last_response'] = response.read().decode()
        return globals()['last_response']
def check(ok, name):
    assert ok, (name, globals().get('last_response'))
    checks.append(name)
for name in ['perf-cache.html','perf-renamed.html']:
    (root/'plugins/Request/pages'/name).unlink(missing_ok=True)
log=open(base/'request-cache-http.log','w')
server=subprocess.Popen(['php','middleware.php'],cwd=root,stdout=log,stderr=log,start_new_session=True)
try:
    time.sleep(3)
    tokens=root/'database/tokens.lotus'
    tokens.write_text(json.dumps({'perf':{'expire':2000000000,'nameClient':'test'}}))
    check(json.loads(get('/checkToken?tokenBrowser=perf'))['success'], 'initial token')
    tokens.write_text(json.dumps({'next':{'expire':2000000000,'nameClient':'test'}}))
    check(not json.loads(get('/checkToken?tokenBrowser=perf'))['success'], 'revoke existing token in worker')
    check(json.loads(get('/checkToken?tokenBrowser=next'))['success'], 'new token in worker')
    tokens.write_text('{')
    check(not json.loads(get('/checkToken?tokenBrowser=next'))['success'], 'malformed token data fails closed')
    tokens.write_text(json.dumps({'next':{'expire':2000000000,'nameClient':'test'}}))
    check(json.loads(get('/checkToken?tokenBrowser=next'))['success'], 'token recovery')
    page=root/'plugins/Request/pages/perf-cache.html'
    module=root/'plugins/Request/modules/perf-cache.html'
    module.write_text('MODULE_A');page.write_text('PAGE_A @import(perf-cache)')
    check(get('/perf-cache')=='PAGE_A MODULE_A', 'create route with import')
    module.write_text('MODULE_B')
    check(get('/perf-cache')=='PAGE_A MODULE_B', 'module edit without restart')
    page.write_text('PAGE_B @import(perf-cache)')
    check(get('/perf-cache')=='PAGE_B MODULE_B', 'page edit without restart')
    page.rename(page.with_name('perf-renamed.html'))
    check(get('/perf-renamed')=='PAGE_B MODULE_B', 'rename route in worker')
    check('PAGE_B' not in get('/perf-cache'), 'old route removed')
    page.with_name('perf-renamed.html').unlink()
    check('PAGE_B' not in get('/perf-renamed'), 'delete route in worker')
    subprocess.run(['node', str(src/'scripts/performance/request-cache-ws.cjs'), str(root)],
                   stdout=open(base/'request-cache-ws.json','w'), check=True)
    check(server.poll() is None, 'same master throughout')
finally:
    server.terminate();server.wait(timeout=5)
(base/'request-cache-http.json').write_text(json.dumps({'passed':True,'checks':checks,'pid':server.pid},indent=2))
print(f'{len(checks)} HTTP cache checks passed')
