"""Sequential idle, normal use, PTY, Codex and browser measurements for both revisions."""
import json
import os
from pathlib import Path
import signal
import subprocess
import time

base = Path(os.environ['FILEMANAGER_BENCH'])
def stop(process):
    if process and process.poll() is None:
        os.killpg(process.pid, signal.SIGTERM)
        try:
            process.wait(timeout=5)
        except subprocess.TimeoutExpired:
            os.killpg(process.pid, signal.SIGKILL)
            process.wait()

for label, port in [('before', 18080), ('after', 18082)]:
    root = base / label
    cfg_path = root / 'plugins/configInterface.json'
    cfg = json.loads(cfg_path.read_text())
    cfg['port'] = port
    cfg['fileManager']['autoRestart'] = True
    cfg['fileManager']['services'] = dict.fromkeys(['pty', 'codex', 'lsp', 'gpt'], False)
    cfg_path.write_text(json.dumps(cfg))
    log = open(base / (label + '-server.log'), 'w')
    server = subprocess.Popen(['php', 'middleware.php'], cwd=root, stdout=log, stderr=log, start_new_session=True)
    pty = codex = None
    env = {**os.environ, 'BENCH_PORT': str(port)}
    def sample(name, seconds, client=None):
        sampler = subprocess.Popen(['python3', str(base/'sample.py'), str(root), str(seconds), str(base/f'{label}-{name}.json')], stdout=open(base/f'{label}-{name}.summary', 'w'))
        if client:
            run = subprocess.Popen(['node', str(base/'client.cjs'), str(root), client, str(seconds)], env=env, stdout=open(base/f'{label}-{name}.client', 'w'))
        assert sampler.wait() == 0
        if client:
            assert run.wait() == 0, name
        print(label, name, flush=True)
    try:
        time.sleep(15)
        assert server.poll() is None
        sample('idle', 180)
        sample('panel', 120, 'panel')
        sample('browse', 60, 'browse')
        sample('editor', 60, 'editor')
        cfg['fileManager']['autoRestart'] = False
        cfg_path.write_text(json.dumps(cfg)); time.sleep(12)
        sample('idle-no-autorestart', 180)
        pty = subprocess.Popen(['node', 'pty.js'], cwd=root, stdout=open(base/f'{label}-pty.log', 'w'), stderr=subprocess.STDOUT, start_new_session=True)
        time.sleep(3)
        for case in ['terminal-idle', 'terminal-active', 'terminal-multi']:
            sample(case, 60, case)
        sample('terminal-disconnected', 60)
        # Explicitly close persistent sessions before stopping the old PTY service.
        subprocess.run(['node', '-e', "const W=require('./node_modules/ws');for(let i=0;i<8;i++){const w=new W('ws://127.0.0.1:16060/perf-t'+i);w.on('open',()=>w.send('closeXtermHandlerCommand'))}setTimeout(()=>process.exit(),1000)"], cwd=root, check=True)
        stop(pty)
        sample('codex-disabled', 90)
        cfg['fileManager']['services']['codex'] = True
        cfg_path.write_text(json.dumps(cfg))
        codex = subprocess.Popen(['node', 'codex-agent.js'], cwd=root, stdout=open(base/f'{label}-codex.log', 'w'), stderr=subprocess.STDOUT, start_new_session=True)
        time.sleep(10)
        assert 'inicializado' in (base/f'{label}-codex.log').read_text(), 'Native Codex must already be installed/authenticated'
        sample('codex-idle', 90)
        sample('codex-active', 90, 'codex-active')
        stop(codex)
        cfg['fileManager']['services']['codex'] = False
        cfg['fileManager']['autoRestart'] = True
        cfg_path.write_text(json.dumps(cfg)); time.sleep(12)
        sampler = subprocess.Popen(['python3', str(base/'sample.py'), str(root), '145', str(base/f'{label}-browser-cpu.json')], stdout=open(base/f'{label}-browser.summary', 'w'))
        subprocess.run(['node', str(base/'browser.cjs'), str(root), '120'], env=env, stdout=open(base/f'{label}-browser.jsonl', 'w'), check=True)
        assert sampler.wait() == 0
    finally:
        stop(codex); stop(pty); stop(server)
