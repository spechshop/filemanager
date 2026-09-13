"""Create isolated baseline/final fixtures. Refuses to overwrite an existing run."""
import io
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tarfile

repo = Path(os.environ.get('FILEMANAGER_REPO', Path(__file__).resolve().parents[2])).resolve()
base = Path(os.environ.get('FILEMANAGER_BENCH', '/tmp/filemanager-perf-reproduce')).resolve()
baseline = 'ba648027bb22af83c2b7387dfa337311f9d71c1d'
if base.exists():
    raise SystemExit(f'Refusing existing output directory: {base}')
for name in ['libspech', 'vendor', 'node_modules']:
    if not (repo / name).is_dir():
        raise SystemExit(f'Missing existing project dependency: {name}')
for port in [18080, 18082, 18083, 18084, 18085, 18086, 18087, 18088, 18089, 18091,
             16060, 16063, 16066, 16067, 16068, 16069, 16071, 13091, 13094,
             13096, 13097, 13098, 13099, 19222, 19223]:
    with socket.socket() as sock:
        try:
            sock.bind(('127.0.0.1', port))
        except OSError as error:
            raise SystemExit(f'Benchmark port {port} unavailable: {error}')
base.mkdir(parents=True)
for script in (repo / 'scripts/performance').iterdir():
    if script.is_file():
        shutil.copy2(script, base / script.name)
archive = subprocess.check_output(['git', 'archive', baseline], cwd=repo)
before = base / 'before'
before.mkdir()
with tarfile.open(fileobj=io.BytesIO(archive)) as tar:
    tar.extractall(before, filter='data')
tracked = subprocess.check_output(['git', 'ls-files', '-z'], cwd=repo).decode().split('\0')
for label, port in [('before', 18080), ('after', 18082)]:
    root = base / label
    root.mkdir(exist_ok=True)
    if label == 'after':
        for name in tracked + ['plugins/Start/server/fileWatcher.php']:
            if name and (repo / name).is_file():
                dest = root / name
                dest.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(repo / name, dest)
    # Real directory copies matter: symlinking dependencies hides the old scan cost.
    for name in ['libspech', 'vendor', 'node_modules']:
        shutil.copytree(repo / name, root / name, dirs_exist_ok=True,
                        ignore=shutil.ignore_patterns('.git'))
    (root / '.runtime').mkdir(exist_ok=True)
    (root / 'files').mkdir(exist_ok=True)
    (root / 'database').mkdir(exist_ok=True)
    (root / 'database/tokens.lotus').write_text(json.dumps({'perf': {'expire': 2000000000, 'nameClient': 'Benchmark'}}))
    (root / '.env').write_text(f'CODEX_AGENT_PORT=13091\nCODEX_BIN={repo}/.runtime/codex/bin/codex\n')
    # Disposable TLS certificate, never a copied private key or account token.
    subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes',
                    '-keyout', str(root / 'privkey.pem'), '-out', str(root / 'fullchain.pem'),
                    '-days', '2', '-subj', '/CN=localhost'], check=True, capture_output=True)
    for name in ['middleware.php', 'pty.js', 'pty.php', 'plugins/Message/server/server.php']:
        p = root / name
        text = p.read_text()
        for old, new in [('6060', '16060'), ('3057', '13057'), ('3090', '13090'), ('3091', '13091')]:
            text = text.replace(old, new)
        p.write_text(text)
    config_path = root / 'plugins/configInterface.json'
    config = json.loads(config_path.read_text())
    config.update(host='127.0.0.1', port=port, ssl='.')
    config['fileManager']['autoRestart'] = True
    config['fileManager']['services'] = dict.fromkeys(['pty', 'lsp', 'gpt', 'codex'], False)
    config_path.write_text(json.dumps(config, indent=4))
    for size, count in [('small', 10), ('medium', 500), ('large', 5000)]:
        directory = root / 'files' / size
        directory.mkdir()
        for i in range(count):
            (directory / f'f{i:05}.txt').write_text('benchmark\n')
    (root / 'files/edit.php').write_text('<?php echo 1;\n')
for size in ['small', 'large']:
    root = base / ('scanner-' + size)
    shutil.copytree(before / 'plugins', root / 'plugins')
    if size == 'large':
        for name in ['vendor', 'node_modules', 'files']:
            for directory in range(100):
                dest = root / name / f'd{directory:03}'
                dest.mkdir(parents=True)
                for file in range(300):
                    (dest / f'f{file:04}.php').write_text('<?php // ignored fixture\n')
print(json.dumps({'directory': str(base), 'baseline': baseline, 'repo': str(repo)}))
