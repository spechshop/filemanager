#!/usr/bin/env python3
"""Controller regressions use disposable processes and stub supervisors only."""
import os
from pathlib import Path
import shutil
import signal
import socket
import subprocess
import tempfile
import time
import unittest

ROOT = Path(__file__).resolve().parents[2]
PHP = os.environ.get('SECURITY_PHP_BINARY', '/usr/bin/php8.4')


def until(predicate, timeout=5):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        value = predicate()
        if value:
            return value
        time.sleep(0.03)
    raise AssertionError('fixture did not reach expected process state')


def alive(pid):
    try:
        return Path(f'/proc/{pid}/stat').read_text().rsplit(')', 1)[1].split()[0] not in ('Z', 'X')
    except FileNotFoundError:
        return False


class ControllerTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='fm-controller-')
        self.root = Path(self.temp.name) / 'instance with spaces'
        self.root.mkdir()
        shutil.copy2(ROOT / 'filemanagerctl', self.root / 'filemanagerctl')
        (self.root / 'pcg').symlink_to(PHP)
        (self.root / 'middleware.php').write_text('''<?php
file_put_contents(__DIR__ . '/middleware.pid', (string) getmypid());
$listener = stream_socket_server('tcp://127.0.0.1:0');
file_put_contents(__DIR__ . '/port', explode(':', stream_socket_get_name($listener, false))[1]);
while (true) usleep(100000);
''')
        (self.root / 'server.php').write_text('''<?php
file_put_contents(__DIR__ . '/server.pid', (string) getmypid());
$child = proc_open([PHP_BINARY, __DIR__ . '/middleware.php'], [['file','/dev/null','r'],['file','/dev/null','w'],['file','/dev/null','w']], $pipes);
while (true) usleep(100000);
''')
        (self.root / 'pty.js').write_text('setInterval(() => {}, 1000);')
        self.bin = Path(self.temp.name) / 'bin'
        self.bin.mkdir()
        self.script('systemctl', '''case " $* " in
  *" --user "*) exit 1 ;;
  *" LoadState "*) echo "${CTL_UNIT_STATE:-loaded}" ;;
  *" WorkingDirectory "*) echo "$CTL_APP_DIR" ;;
  *" ActiveState "*) echo "${CTL_SERVICE_STATE:-inactive}" ;;
  *) exit 1 ;;
esac
''')
        self.script('sudo', 'echo "unexpected sudo" >&2; exit 97\n')
        self.script('screen', '''if [ "$1" = "-dmS" ]; then
  shift 2
  nohup "$@" </dev/null >/dev/null 2>&1 &
  exit 0
fi
exit 1
''')
        self.env = {**os.environ, 'PATH': str(self.bin) + ':' + os.environ['PATH'], 'CTL_APP_DIR': str(self.root)}
        self.children = []
        self.owned = set()

    def script(self, name, source):
        target = self.bin / name
        target.write_text('#!/usr/bin/env bash\n' + source)
        target.chmod(0o755)

    def spawn(self, args, cwd=None):
        child = subprocess.Popen(args, cwd=cwd or self.root, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True)
        self.children.append(child)
        self.owned.add(child.pid)
        return child

    def ctl(self, action):
        return subprocess.run([str(self.root / 'filemanagerctl'), action], cwd='/', env=self.env, capture_output=True, text=True, timeout=15)

    def ready(self):
        until(lambda: (self.root / 'port').exists())
        port = int((self.root / 'port').read_text())
        with socket.create_connection(('127.0.0.1', port), timeout=1):
            pass
        self.owned.update(int(marker.read_text()) for marker in self.root.glob('*.pid'))
        return port

    def assert_stopped(self, result, port=None):
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn('FileManager desligado', result.stdout)
        until(lambda: not any(alive(pid) for pid in self.owned))
        if port:
            with self.assertRaises(OSError):
                socket.create_connection(('127.0.0.1', port), timeout=0.3)
        self.assertEqual(self.ctl('status').returncode, 1)

    def test_relative_supervisor_and_children_stop_without_sudo_for_inactive_service(self):
        self.spawn([PHP, 'server.php'])
        port = self.ready()
        status = self.ctl('status')
        self.assertEqual(status.returncode, 0, status.stdout + status.stderr)
        self.assertIn('background', status.stdout)
        self.assert_stopped(self.ctl('stop'), port)
        self.assert_stopped(self.ctl('stop'))

    def test_orphan_middleware_and_bridge_are_found_without_touching_other_instances_or_inline_code(self):
        other = Path(self.temp.name) / 'other'
        other.mkdir()
        shutil.copy2(self.root / 'server.php', other / 'server.php')
        shutil.copy2(self.root / 'middleware.php', other / 'middleware.php')
        unrelated = self.spawn([PHP, 'server.php'], cwd=other)
        until(lambda: (other / 'port').exists())
        mentioned = self.spawn([PHP, '-r', 'while (true) usleep(100000);', str(self.root / 'server.php')])
        ide = self.root / '.runtime/codex'
        ide.mkdir(parents=True)
        (ide / 'app-server.js').write_text('setInterval(() => {}, 1000);')
        ide_child = self.spawn(['node', str(ide / 'app-server.js')])
        untouched = {unrelated.pid, mentioned.pid, ide_child.pid}
        self.owned -= untouched
        self.spawn([PHP, '-n', '-d', 'memory_limit=32M', str(self.root / 'middleware.php')], cwd='/')
        self.spawn(['node', './pty.js'])
        port = self.ready()
        self.assert_stopped(self.ctl('stop'), port)
        for child in [unrelated, mentioned, ide_child]:
            self.assertIsNone(child.poll(), 'unrelated process was terminated')

    def test_orphan_bridge_does_not_block_start_and_restart_replaces_relative_supervisor(self):
        self.env['CTL_UNIT_STATE'] = 'not-found'
        bridge = self.spawn(['node', 'pty.js'])
        until(lambda: alive(bridge.pid))
        status = self.ctl('status')
        self.assertEqual(status.returncode, 1)
        self.assertIn('auxiliares', status.stderr)
        started = self.ctl('start')
        self.assertEqual(started.returncode, 0, started.stdout + started.stderr)
        until(lambda: not alive(bridge.pid))
        port = self.ready()
        self.assert_stopped(self.ctl('stop'), port)
        (self.root / 'port').unlink()
        for marker in self.root.glob('*.pid'):
            marker.unlink()
        relative = self.spawn([PHP, './server.php'])
        self.ready()
        restarted = self.ctl('restart')
        self.assertEqual(restarted.returncode, 0, restarted.stdout + restarted.stderr)
        until(lambda: not alive(relative.pid))
        self.ready()
        self.assert_stopped(self.ctl('stop'))

    def test_unstoppable_supervisor_is_not_reported_as_success(self):
        self.spawn([PHP, 'server.php'])
        self.ready()
        self.env['CTL_SERVICE_STATE'] = 'active'
        result = self.ctl('stop')
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn('[ok]', result.stdout)
        self.assertIn('supervisor', result.stderr)
        until(lambda: not any(alive(pid) for pid in self.owned))

    def test_force_stop_of_term_ignoring_process(self):
        check = subprocess.run([PHP, '-n', '-r', 'exit(function_exists("pcntl_signal") ? 0 : 1);'])
        if check.returncode:
            self.skipTest('pcntl unavailable')
        (self.root / 'middleware.php').write_text('''<?php
pcntl_async_signals(true);
pcntl_signal(SIGTERM, SIG_IGN);
file_put_contents(__DIR__ . '/middleware.pid', (string) getmypid());
while (true) usleep(100000);
''')
        self.spawn([PHP, 'middleware.php'])
        until(lambda: (self.root / 'middleware.pid').exists())
        self.assert_stopped(self.ctl('stop'))

    def tearDown(self):
        # Each explicit child has its own process group. No real installation
        # or unrelated service is ever signaled by this fixture cleanup.
        for child in self.children:
            try:
                os.killpg(child.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
        self.owned.update(int(marker.read_text()) for marker in self.root.glob('*.pid'))
        for pid in self.owned:
            if alive(pid):
                try:
                    os.kill(pid, signal.SIGKILL)
                except ProcessLookupError:
                    pass
        for child in self.children:
            child.wait(timeout=5)
        self.temp.cleanup()


if __name__ == '__main__':
    unittest.main(verbosity=2)
