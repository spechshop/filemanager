#!/usr/bin/env python3
"""Local real-runtime regression: actual supervisor, middleware and bridges.

Runs in disposable copies with empty .env, synthetic tokens, loopback listeners
and Codex/GPT disabled. Never starts installers or contacts external services.
"""
import json
import os
from pathlib import Path
import shutil
import signal
import socket
import ssl
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
PHP = os.environ.get('SECURITY_PHP_BINARY', 'php')


def until(predicate, timeout=12):
    end = time.monotonic() + timeout
    while time.monotonic() < end:
        value = predicate()
        if value:
            return value
        time.sleep(0.05)
    raise AssertionError('Timed out waiting for local process lifecycle')


def processes():
    result = {}
    for entry in Path('/proc').glob('[0-9]*/stat'):
        try:
            fields = entry.read_text().rsplit(')', 1)[1].split()
            result[int(entry.parent.name)] = (int(fields[1]), fields[0])
        except (OSError, ValueError):
            pass
    return result


def descendants(pid):
    table = processes()
    found = {pid}
    while True:
        added = {p for p, (parent, _) in table.items() if parent in found} - found
        if not added:
            return found - {pid}
        found |= added


def free_port():
    with socket.socket() as listener:
        listener.bind(('127.0.0.1', 0))
        return listener.getsockname()[1]


class StartupTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='fm-startup-')
        self.root = Path(self.temp.name)
        self.child = None
        self.owned = set()
        self.log = (self.root / 'startup.log').open('w+')
        # Real deployment dependency is read-only; the loader sees the empty
        # fixture .env through cwd. Never copy live credentials into the test.
        for name in ['libspech', 'vendor', 'node_modules', 'js', 'css', 'img']:
            if (ROOT / name).exists():
                (self.root / name).symlink_to(ROOT / name, target_is_directory=True)
        shutil.copytree(ROOT / 'plugins', self.root / 'plugins')
        for name in ['server.php', 'middleware.php', 'pty.js', 'pty.php', 'lsp.js']:
            shutil.copy2(ROOT / name, self.root / name)
        (self.root / 'scripts/security').mkdir(parents=True)
        shutil.copy2(ROOT / 'scripts/security/bridge-auth.cjs', self.root / 'scripts/security/bridge-auth.cjs')
        (self.root / '.env').write_text('')
        (self.root / 'files').mkdir()
        (self.root / 'tokens.json').write_text(json.dumps({'fixture': {'expire': 9999999999}}))
        self.config = json.loads((ROOT / 'plugins/configInterface.json').read_text())
        self.ports = [free_port() for _ in range(3)]
        self.config.update(host='127.0.0.1', port=self.ports[0], ssl=True)
        self.config['serverSettings'].update(
            ssl_cert_file=str(ROOT / 'fullchain.pem'), ssl_key_file=str(ROOT / 'privkey.pem'),
            worker_num=1, max_wait_time=5)
        self.config['fileManager'].update(autoRestart=True, ptyBackend='node',
            services={'pty': True, 'lsp': True, 'gpt': False, 'codex': False})
        self.env = {**os.environ, 'FILEMANAGER_CONFIG_FILE': str(self.root / 'plugins/configInterface.json'),
            'FILEMANAGER_DATA_DIR': str(self.root / '.runtime'),
            'FILEMANAGER_TOKENS_FILE': str(self.root / 'tokens.json'),
            'FILEMANAGER_PTY_PORT': str(self.ports[1]), 'FILEMANAGER_LSP_PORT': str(self.ports[2]),
            'FILEMANAGER_BRIDGE_SECRET': 'synthetic-startup-secret-' * 3,
            'FILEMANAGER_ORIGIN': f'https://127.0.0.1:{self.ports[0]}',
            'FILEMANAGER_INITIAL_DIRECTORY': str(self.root / 'files'),
            'FILEMANAGER_ALLOWED_ROOTS': json.dumps([str(self.root / 'files')])}
        context = ssl._create_unverified_context()  # local fixture certificate
        self.http = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPSHandler(context=context))

    def save_config(self):
        self.config['allowObservable'] = []  # restart is explicit in these tests
        (self.root / 'plugins/configInterface.json').write_text(json.dumps(self.config))

    def start(self):
        self.save_config()
        self.child = subprocess.Popen([PHP, 'server.php'], cwd=self.root, env=self.env,
            stdout=self.log, stderr=subprocess.STDOUT, start_new_session=True)
        return self.ready()

    def ready(self, previous=None):
        address = self.root / '.runtime/server-address'
        def check():
            self.assertIsNone(self.child.poll(), self.output())
            if not address.exists():
                return False
            pid = int(address.read_text().split()[0])
            if pid == previous:
                return False
            try:
                with self.http.open(f'https://127.0.0.1:{self.ports[0]}/', timeout=0.5) as response:
                    return pid if response.status == 200 and b'<html' in response.read().lower() else False
            except (OSError, urllib.error.URLError):
                return False
        return until(check)

    def output(self):
        return (self.root / 'startup.log').read_text()

    def service_pid(self, script):
        for pid in descendants(self.child.pid):
            try:
                args = Path(f'/proc/{pid}/cmdline').read_bytes().split(b'\0')
                if str(self.root / script).encode() in args:
                    return pid
            except OSError:
                pass
        return None

    def assert_reaped(self, pids):
        until(lambda: not (set(processes()) & pids))

    def detached_terminal(self):
        # Create a real authenticated shell, then disconnect the client. The
        # session must persist until server shutdown and be reaped with it.
        script = """
const WebSocket = require('ws');
const ws = new WebSocket('ws://127.0.0.1:' + process.argv[1] + '/fixture-shell', {
  headers: {'X-FileManager-Bridge': process.env.FILEMANAGER_BRIDGE_SECRET}
});
ws.on('error', () => process.exit(1));
ws.on('open', () => {
  ws.send("printf '%s' $$ > shutdown-shell.pid\\n");
  setTimeout(() => ws.close(), 500);
});
ws.on('close', () => process.exit(0));
"""
        subprocess.run(['node', '-e', script, str(self.ports[1])], cwd=self.root,
            env=self.env, check=True, timeout=5, capture_output=True)
        marker = self.root / 'files/shutdown-shell.pid'
        until(lambda: marker.exists() and marker.read_text().strip())
        shell = int(marker.read_text())
        self.assertIn(shell, descendants(self.child.pid))
        self.owned.add(shell)

    def stop(self, sig=signal.SIGTERM):
        self.owned |= descendants(self.child.pid)
        self.child.send_signal(sig)
        self.assertEqual(self.child.wait(timeout=12), 0, self.output())
        self.assert_reaped(self.owned)
        self.assertNotIn('Fatal error', self.output())
        self.assertNotIn('API must be called', self.output())
        self.assertNotIn('WARNING', self.output())

    def test_restart_respawn_and_sigterm(self):
        middleware = self.start()
        lsp = until(lambda: self.service_pid('lsp.js'))
        until(lambda: self.service_pid('pty.js'))
        # Both bridges belong directly to the Swoole manager, not init/screen.
        manager = processes()[lsp][0]
        self.assertEqual(processes()[manager][0], middleware)
        self.assertEqual(manager, processes()[self.service_pid('pty.js')][0])
        os.kill(lsp, signal.SIGTERM)
        until(lambda: (pid := self.service_pid('lsp.js')) and pid != lsp)
        self.assert_reaped({lsp})
        old = descendants(self.child.pid)
        self.owned |= old
        os.kill(middleware, signal.SIGTERM)
        new = self.ready(previous=middleware)
        self.assertNotEqual(new, middleware)
        self.assert_reaped(old)
        self.assertEqual(self.output().count('Starting server...'), 2)
        until(lambda: self.service_pid('pty.js'))
        self.detached_terminal()
        self.stop()

    def test_php_backend_and_sigint(self):
        self.config['fileManager']['ptyBackend'] = 'php'
        self.start()
        until(lambda: self.service_pid('pty.php'))
        self.detached_terminal()
        self.stop(signal.SIGINT)

    def test_autorestart_disabled(self):
        self.config['fileManager']['autoRestart'] = False
        middleware = self.start()
        self.owned |= descendants(self.child.pid)
        os.kill(middleware, signal.SIGTERM)
        self.assertEqual(self.child.wait(timeout=12), 0, self.output())
        self.assert_reaped(self.owned)
        self.assertIn('Autorestart disabled', self.output())
        self.assertEqual(self.output().count('Starting server...'), 1)

    def test_explicit_restart_and_stop_during_backoff(self):
        self.config['fileManager']['autoRestart'] = False
        middleware = self.start()
        self.owned |= descendants(self.child.pid)
        marker = self.root / '.runtime/restart-request.json'
        marker.write_text(json.dumps({'requestedAt': int(time.time()), 'middlewarePid': middleware}))
        os.kill(middleware, signal.SIGTERM)
        middleware = self.ready(previous=middleware)
        self.assertIn('Explicit restart requested', self.output())
        self.config['fileManager']['autoRestart'] = True
        self.save_config()
        self.owned |= descendants(self.child.pid)
        os.kill(middleware, signal.SIGTERM)
        until(lambda: middleware not in processes())
        self.stop()
        self.assertEqual(self.output().count('Starting server...'), 2)

    def tearDown(self):
        if self.child and self.child.poll() is None:
            self.owned |= descendants(self.child.pid)
            self.child.terminate()
            try:
                self.child.wait(timeout=12)
            except subprocess.TimeoutExpired:
                os.killpg(self.child.pid, signal.SIGKILL)
                self.child.wait(timeout=5)
        for pid in self.owned & set(processes()):
            try:
                os.kill(pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
        self.log.close()
        self.temp.cleanup()


if __name__ == '__main__':
    subprocess.run([PHP, '-r', 'exit(extension_loaded("swoole") ? 0 : 1);'], check=True)
    unittest.main(verbosity=2)
