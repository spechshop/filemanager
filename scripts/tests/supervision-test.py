#!/usr/bin/env python3
"""Linux lifecycle integration tests. No host services, crontab or reboot touched.

Uses real PHP processes, flock, signals, process sessions and wall-clock delays.
Only systemctl/loginctl/crontab are fixtures. Boot IDs change in a disposable copy
of the controller, never through an environment override in production code.
"""
import concurrent.futures
import json
import os
from pathlib import Path
import shlex
import shutil
import signal
import subprocess
import tempfile
import time
import unittest

ROOT = Path(__file__).resolve().parents[2]
PHP = os.environ.get('SECURITY_PHP_BINARY') or shutil.which('php')
BOOT = Path('/proc/sys/kernel/random/boot_id').read_text().strip()


def until(predicate, timeout=10):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        value = predicate()
        if value:
            return value
        time.sleep(0.05)
    raise AssertionError('condition timed out')


def alive(pid):
    try:
        return Path(f'/proc/{pid}/stat').read_text().rsplit(')', 1)[1].split()[0] not in ('Z', 'X')
    except FileNotFoundError:
        return False


class Fixture:
    def __init__(self):
        self.temp = tempfile.TemporaryDirectory(prefix='fm-supervision-')
        # Exercise shell and cron quoting too.
        self.root = Path(self.temp.name) / "instance's 100% files"
        self.root.mkdir()
        self.state = self.root / '.runtime/supervision'
        self.bin = Path(self.temp.name) / 'bin'
        self.bin.mkdir()
        (self.root / 'boot-id').write_text(BOOT)
        controller = (ROOT / 'filemanagerctl').read_text().replace(
            'BOOT_ID="$(cat /proc/sys/kernel/random/boot_id)"',
            'BOOT_ID="$(cat "$APP_DIR/boot-id")"')
        (self.root / 'filemanagerctl').write_text(controller)
        (self.root / 'filemanagerctl').chmod(0o755)
        (self.root / 'scripts').mkdir(exist_ok=True)
        shutil.copy2(ROOT / 'scripts/runtime-exec.sh', self.root / 'scripts/runtime-exec.sh')
        for helper in ('install-php-runtime.sh', 'secure-download.sh', 'pcg-release.py'):
            shutil.copy2(ROOT / 'scripts' / helper, self.root / 'scripts' / helper)
        (self.root / 'pcg').symlink_to(PHP)
        (self.root / 'server.php').write_text('''<?php
file_put_contents(__DIR__ . '/starts', json_encode([getmypid(), microtime(true)]) . "\\n", FILE_APPEND | LOCK_EX);
file_put_contents(__DIR__ . '/server.pid', (string)getmypid());
$child = proc_open([PHP_BINARY, __DIR__ . '/middleware.php'], [['file','/dev/null','r'],['file','/dev/null','w'],['file','/dev/null','w']], $pipes);
while (true) usleep(100000);
''')
        (self.root / 'middleware.php').write_text('''<?php
file_put_contents(__DIR__ . '/child.pid', (string)getmypid());
while (true) usleep(100000);
''')
        self.script('systemctl', r'''printf '%s\n' "$*" >> "$FM_FIXTURE/systemctl.calls"
case "$*" in
 '--user show-environment') [ "${FM_USER_MANAGER:-0}" = 1 ]; exit $? ;;
esac
exit 1
''')
        self.script('loginctl', r'''case "$*" in
 *'show-user '*) echo "${FM_LINGER:-no}"; exit 0 ;;
 *) exit 1 ;;
esac
''')
        self.script('sudo', 'echo unexpected-sudo >> "$FM_FIXTURE/sudo.calls"; exit 97\n')
        self.script('screen', 'exit 1\n')
        self.script('crontab', r'''[ "${FM_CRON_DENIED:-0}" = 0 ] || { echo 'permission denied' >&2; exit 1; }
case "$1" in
 -l) if [ -f "$FM_FIXTURE/crontab" ]; then cat "$FM_FIXTURE/crontab"; else echo 'no crontab for fixture' >&2; exit 1; fi ;;
 -) cat > "$FM_FIXTURE/crontab" ;;
 *) cp -- "$1" "$FM_FIXTURE/crontab" ;;
esac
''')
        self.env = {**os.environ, 'PATH': str(self.bin) + ':' + os.environ['PATH'],
                    'HOME': str(self.root / 'home'), 'XDG_CONFIG_HOME': str(self.root / 'home/.config'),
                    'FM_FIXTURE': str(self.root)}
        (self.root / 'home').mkdir()

    def script(self, name, source):
        script = self.bin / name
        script.write_text('#!/usr/bin/env bash\n' + source)
        script.chmod(0o755)

    def ctl(self, *args, check=True):
        result = subprocess.run([str(self.root / 'filemanagerctl'), *args], env=self.env,
                                cwd='/', text=True, capture_output=True, timeout=25)
        if check and result.returncode:
            raise AssertionError(f'{args}: {result.stdout}\n{result.stderr}')
        return result

    def starts(self):
        path = self.root / 'starts'
        return [json.loads(line) for line in path.read_text().splitlines()] if path.exists() else []

    def pid(self):
        return int((self.root / 'server.pid').read_text())

    def watchdog(self):
        path = self.state / 'watchdog.pid'
        return int(path.read_text().split()[0]) if path.exists() else None

    def boot(self):
        (self.root / 'boot-id').write_text('fixture-next-boot')

    def run_cron(self, boot=False):
        cron = self.root / 'crontab'
        if not cron.exists():
            return
        for line in cron.read_text().splitlines():
            prefix = '@reboot ' if boot else '* * * * * '
            if line.startswith(prefix):
                command = line[len(prefix):].replace('\\%', '%')
                subprocess.run(['/bin/sh', '-c', command], env=self.env, cwd='/', check=True, timeout=10)

    def install(self):
        script = r'''set +e
log() { printf '%s\n' "$*"; }
ok() { printf '%s\n' "$*"; }
warn() { printf '%s\n' "$*" >&2; }
err() { printf '%s\n' "$*" >&2; }
IS_ROOT=0
SUDO=
FILEMANAGER_USER="$(id -un)"
CURRENT_DIR="$FM_FIXTURE"
CONTROL_SCRIPT="$CURRENT_DIR/filemanagerctl"
PHP_BIN="$CURRENT_DIR/pcg"
run_priv() { "$@"; }
source "$FM_REPO/scripts/install-supervision.sh"
'''
        result = subprocess.run(['bash', '-c', script], env={**self.env, 'FM_REPO': str(ROOT)},
                                cwd=self.root, capture_output=True, text=True, timeout=30)
        if result.returncode:
            raise AssertionError(result.stdout + result.stderr)
        return result

    def close(self):
        try:
            self.ctl('disable', check=False)
        finally:
            # Catch fixture processes even if an assertion fails mid-recovery.
            for record in self.starts():
                pid = record[0]
                try:
                    os.killpg(pid, signal.SIGKILL)
                except ProcessLookupError:
                    pass
            for name in ('watchdog.pid',):
                path = self.state / name
                if path.exists():
                    pid = int(path.read_text().split()[0])
                    if alive(pid):
                        os.kill(pid, signal.SIGKILL)
            self.temp.cleanup()


class SupervisionTests(unittest.TestCase):
    def setUp(self):
        self.f = Fixture()
        self.addCleanup(self.f.close)

    def test_start_with_old_env_and_stop_signal(self):
        f = self.f
        f.script('env', """case "$1" in --default-signal=*) echo 'env: unrecognized option --default-signal' >&2; exit 125 ;; esac
exec /usr/bin/env "$@"
""")
        f.ctl('start')
        self.assertTrue(alive(f.pid()))
        watchdog = f.watchdog()
        os.kill(watchdog, signal.SIGTERM)
        until(lambda: not alive(watchdog))

    def test_start_reports_launcher_failure_inline(self):
        f = self.f
        f.script('nohup', "echo 'nohup: execution denied by hosting policy' >&2; exit 126\n")
        result = f.ctl('start', check=False)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('nohup: execution denied by hosting policy', result.stderr)
        self.assertIn('status 126', result.stderr)
        self.assertIn('aplicação não produziu saída', result.stderr)
        self.assertNotIn('Consulte', result.stderr)
        self.assertFalse(f.starts())

    def test_start_reports_silent_launcher_sigkill(self):
        f = self.f
        f.script('nohup', 'kill -KILL $$\n')
        result = f.ctl('start', check=False)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('status 137', result.stderr)
        self.assertIn('Watchdog: não está executando', result.stderr)
        self.assertNotIn('Consulte', result.stderr)
        self.assertFalse(f.starts())

    def test_start_reports_pcg_failure_inline(self):
        f = self.f
        (f.root / 'scripts/runtime-exec.sh').write_text(
            "fm_php() { echo 'PCG fixture: execution denied' >&2; return 126; }\n")
        result = f.ctl('start', check=False)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('PCG fixture: execution denied', result.stderr)
        self.assertIn('aplicação PHP não chegou a iniciar', result.stderr)
        self.assertNotIn('Consulte', result.stderr)
        self.assertFalse(f.starts())

    def test_start_reports_php_fatal_inline(self):
        f = self.f
        (f.root / 'server.php').write_text("<?php fwrite(STDERR, 'Fatal error: missing application dependency\\n'); exit(42);")
        result = f.ctl('start', check=False)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('Fatal error: missing application dependency', result.stderr)
        self.assertIn('status 42', result.stderr)
        self.assertNotIn('FileManager iniciado.', result.stdout)

    def test_crash_restarts_in_about_two_seconds_and_cleans_children(self):
        f = self.f
        (f.root / 'worker.php').write_text("<?php file_put_contents(__DIR__ . '/worker.pid', (string)getmypid()); while(true) usleep(100000);")
        (f.root / 'middleware.php').write_text("<?php $worker = proc_open(['setsid', PHP_BINARY, __DIR__ . '/worker.php'], [['file','/dev/null','r'],['file','/dev/null','w'],['file','/dev/null','w']], $pipes); file_put_contents(__DIR__ . '/child.pid', (string)getmypid()); while(true) usleep(100000);")
        f.ctl('enable')
        until(lambda: (f.root / 'worker.pid').exists())
        worker = int((f.root / 'worker.pid').read_text())
        until(lambda: (f.root / 'child.pid').exists())
        pid, child = f.pid(), int((f.root / 'child.pid').read_text())
        killed = time.time()
        os.kill(pid, signal.SIGKILL)
        until(lambda: len(f.starts()) == 2)
        delay = f.starts()[1][1] - killed
        self.assertGreaterEqual(delay, 1.0)
        self.assertLess(delay, 4.5)
        until(lambda: not alive(child))
        until(lambda: not alive(worker))
        self.assertTrue(alive(f.pid()))
        self.assertIn('terminou inesperadamente', (f.state / 'events.log').read_text())
        print(f'\nRECOVERY PHP SIGKILL: {delay:.3f}s', flush=True)

    def test_unexpected_successful_php_exit_is_also_restarted(self):
        f = self.f
        script = f.root / 'server.php'
        script.write_text(script.read_text().replace('while (true) usleep(100000);',
            "while (true) { if (file_exists(__DIR__ . '/exit-zero')) { unlink(__DIR__ . '/exit-zero'); exit(0); } usleep(100000); }"))
        f.ctl('enable')
        (f.root / 'exit-zero').touch()
        until(lambda: len(f.starts()) == 2)
        self.assertIn('status 0', (f.state / 'events.log').read_text())
        self.assertTrue(alive(f.pid()))

    def test_simultaneous_start_and_restart_have_one_managed_instance(self):
        f = self.f
        with concurrent.futures.ThreadPoolExecutor(4) as pool:
            results = list(pool.map(lambda _: f.ctl('start'), range(4)))
        self.assertTrue(all(result.returncode == 0 for result in results))
        self.assertEqual(len(f.starts()), 1)
        initial = f.pid()
        supervisor = f.watchdog()
        f.ctl('restart')
        self.assertEqual(len(f.starts()), 2)
        self.assertFalse(alive(initial))
        self.assertFalse(alive(supervisor))
        self.assertNotEqual(f.pid(), initial)
        self.assertTrue(alive(f.watchdog()))
        time.sleep(3)
        self.assertEqual(len(f.starts()), 2)

    def test_killed_watchdog_is_recovered_by_cron_without_duplicating_php(self):
        f = self.f
        f.ctl('enable')
        server, supervisor = f.pid(), f.watchdog()
        os.kill(supervisor, signal.SIGKILL)
        until(lambda: not alive(supervisor))
        f.run_cron()
        until(lambda: f.watchdog() != supervisor and alive(f.watchdog()))
        time.sleep(2)
        self.assertEqual(f.pid(), server)
        self.assertEqual(len(f.starts()), 1)
        os.kill(server, signal.SIGKILL)
        until(lambda: len(f.starts()) == 2)

    def test_boot_clears_stop_but_never_clears_disable(self):
        f = self.f
        f.ctl('enable')
        f.ctl('stop')
        f.run_cron(boot=True)
        time.sleep(0.2)
        self.assertEqual(len(f.starts()), 1)
        f.boot()
        f.run_cron(boot=True)
        until(lambda: len(f.starts()) == 2)
        f.ctl('disable')
        self.assertNotIn('# filemanager:', (f.root / 'crontab').read_text())
        (f.root / 'boot-id').write_text('third-boot')
        # Even a stale cron invocation cannot override persistent disable.
        f.ctl('__tick')
        time.sleep(2.2)
        self.assertEqual(len(f.starts()), 2)
        self.assertFalse(alive(f.pid()))
        f.ctl('enable')
        self.assertEqual(len(f.starts()), 3)
        self.assertEqual((f.root / 'crontab').read_text().count('# filemanager:'), 2)

    def test_installer_without_sudo_migrates_cron_and_is_idempotent(self):
        f = self.f
        unrelated = "* * * * * echo unrelated\n@reboot php /other/server.php\n# backup " + str(f.root / 'server.php') + '\n'
        old = f"@reboot cd '{f.root}' && '{PHP}' '{f.root}/server.php' >> /dev/null 2>&1\n"
        (f.root / 'crontab').write_text(unrelated + old + old)
        f.install()
        self.assertTrue(alive(f.pid()))
        f.install()
        cron = (f.root / 'crontab').read_text()
        self.assertTrue(cron.startswith(unrelated))
        self.assertNotIn(old, cron)
        self.assertEqual(cron.count('# filemanager:'), 2)
        self.assertEqual((f.state / 'php').read_text().strip(), str(f.root / 'pcg'))
        self.assertFalse((f.root / 'sudo.calls').exists())
        self.assertEqual(sum(alive(record[0]) for record in f.starts()), 1)
        f.ctl('stop')
        count = len(f.starts())
        f.install()
        f.run_cron()
        time.sleep(2.2)
        self.assertEqual(len(f.starts()), count, 'installer overrode stop')
        f.ctl('disable')
        f.install()
        f.boot()
        f.ctl('__tick')
        time.sleep(2.2)
        self.assertEqual(len(f.starts()), count, 'installer overrode disable')
        self.assertNotIn('# filemanager:', (f.root / 'crontab').read_text())

    def test_supervision_only_upgrade_preserves_application_data(self):
        f = self.f
        shutil.copy2(ROOT / 'installer.sh', f.root / 'installer.sh')
        (f.root / 'scripts').mkdir(exist_ok=True)
        shutil.copy2(ROOT / 'scripts/install-supervision.sh', f.root / 'scripts/install-supervision.sh')
        # Even a root test runner must never reach real /etc/systemd writes.
        if os.getuid() == 0:
            f.script('id', 'if [ "$*" = -u ]; then echo 60000; else exec /usr/bin/id "$@"; fi\n')
        # This lifecycle test must not query remote releases.
        f.env['FILEMANAGER_PHP_URL'] = 'file://' + str(Path(PHP).resolve())
        f.env['FILEMANAGER_PHP_SHA256'] = __import__('hashlib').sha256(Path(PHP).read_bytes()).hexdigest()
        # Avoid account HOME resolution reaching the test runner's real home.
        f.script('getent', 'printf "%s:x:%s:%s::%s:/bin/bash\\n" "$(id -un)" "$(id -u)" "$(id -g)" "$FM_FIXTURE/home"\n')
        for name in ('apt-get', 'curl', 'wget', 'composer', 'npm'):
            f.script(name, 'echo unexpected >> "$FM_FIXTURE/unexpected-install"; exit 97\n')
        preserved = {}
        for name in ('config.json', 'sessions.json', 'tokens.json', 'data.txt'):
            preserved[name] = ('existing-' + name).encode()
            (f.root / name).write_bytes(preserved[name])
        result = subprocess.run(['bash', str(f.root / 'installer.sh'), '--supervision-only'],
                                cwd='/', env=f.env, capture_output=True, text=True, timeout=30)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertTrue(alive(f.pid()))
        self.assertFalse((f.root / 'unexpected-install').exists())
        for name, contents in preserved.items():
            self.assertEqual((f.root / name).read_bytes(), contents)

    def test_user_manager_without_linger_uses_persistent_cron(self):
        f = self.f
        f.env.update(FM_USER_MANAGER='1', FM_LINGER='no')
        f.install()
        self.assertEqual((f.state / 'backend').read_text().strip(), 'watchdog')
        self.assertIn('Linger indisponível', f.install().stderr)
        self.assertEqual((f.root / 'crontab').read_text().count('# filemanager:'), 2)

    def test_unavailable_cron_preserves_unrelated_entries_and_uses_background(self):
        f = self.f
        original = '0 * * * * echo keep-me\n'
        (f.root / 'crontab').write_text(original)
        # A failed read must never overwrite the existing crontab.
        f.env['FM_CRON_DENIED'] = '1'
        result = f.ctl('enable', check=False)
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual((f.root / 'crontab').read_text(), original)
        f.ctl('start')
        self.assertTrue(alive(f.pid()))
        self.assertIn('Autostart: desativado', f.ctl('status').stdout)
        f.env.pop('FM_CRON_DENIED')

    def test_installer_falls_back_when_cron_rejects_the_account(self):
        f = self.f
        f.env['FM_CRON_DENIED'] = '1'
        result = f.install()
        self.assertIn('Sem autostart persistente', result.stderr)
        self.assertEqual((f.state / 'backend').read_text().strip(), 'background')
        self.assertTrue(alive(f.pid()))
        self.assertIn('Autostart: desativado', f.ctl('status').stdout)
        # A second installation must not preserve the internal migration stop.
        f.install()
        self.assertTrue(alive(f.pid()))
        self.assertNotEqual((f.state / 'autostart').read_text().strip(), 'disabled')
        f.env.pop('FM_CRON_DENIED')

    def test_stop_and_disable_during_crash_backoff_cancel_recovery(self):
        f = self.f
        for command in ('stop', 'disable'):
            f.ctl('enable')
            count = len(f.starts())
            os.kill(f.pid(), signal.SIGKILL)
            with concurrent.futures.ThreadPoolExecutor(4) as pool:
                tasks = [pool.submit(f.ctl, '__tick') for _ in range(3)]
                f.ctl(command)
                for task in tasks:
                    task.result()
            time.sleep(3)
            f.ctl('__tick')
            self.assertEqual(len(f.starts()), count)
            self.assertFalse(alive(f.pid()))
            self.assertFalse(f.watchdog() and alive(f.watchdog()))

    def test_user_without_writable_home_can_enable_watchdog(self):
        f = self.f
        f.env['HOME'] = '/proc/filemanager-no-home'
        f.ctl('enable')
        self.assertTrue(alive(f.pid()))
        self.assertEqual((f.root / 'crontab').read_text().count('# filemanager:'), 2)
        f.ctl('stop')
        f.run_cron()
        self.assertFalse(alive(f.pid()))

    def test_root_watchdog_commands_use_the_installation_account(self):
        if os.getuid() == 0:
            self.skipTest('fixture requires a non-root owner distinct from simulated root')
        f = self.f
        f.ctl('enable')
        f.script('id', 'if [ "${FM_AS_ROOT:-0}" = 1 ] && [ "$*" = -u ]; then echo 0; else exec /usr/bin/id "$@"; fi\n')
        f.script('runuser', 'printf "%s\\n" "$*" >> "$FM_FIXTURE/runuser.calls"\nshift 3\nexport FM_AS_ROOT=0\nexec "$@"\n')
        f.env['FM_AS_ROOT'] = '1'
        self.assertIn('Autostart: ativado', f.ctl('status').stdout)
        f.ctl('disable')
        self.assertFalse(alive(f.pid()))
        self.assertNotIn('# filemanager:', (f.root / 'crontab').read_text())
        self.assertIn('-u ' + subprocess.check_output(['/usr/bin/id', '-un'], text=True).strip(),
                      (f.root / 'runuser.calls').read_text())
        f.env.pop('FM_AS_ROOT')

    def test_stale_reused_main_pid_never_signals_an_unrelated_session(self):
        f = self.f
        foreign = subprocess.Popen([PHP, '-r', 'while(true) usleep(100000);'],
                                   start_new_session=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        try:
            f.ctl('__configure', str(f.root / 'pcg'), 'filemanager.service', 'background')
            ticks = Path(f'/proc/{foreign.pid}/stat').read_text().rsplit(')', 1)[1].split()[19]
            (f.state / 'main.pid').write_text(f'{foreign.pid} {int(ticks) - 1} {BOOT}\n')
            f.ctl('start')
            self.assertIsNone(foreign.poll())
            f.ctl('stop')
            self.assertIsNone(foreign.poll())
        finally:
            foreign.kill()
            foreign.wait(timeout=5)

    def test_logs_are_bounded(self):
        f = self.f
        payload = 'log-data' * 1024 + '\n'
        result = subprocess.run([str(f.root / 'filemanagerctl'), '__log'], input=payload * 400,
                                env=f.env, text=True, capture_output=True, timeout=20)
        self.assertEqual(result.returncode, 0, result.stderr)
        for path in (f.root / 'filemanager.log', f.root / 'filemanager.log.1'):
            self.assertTrue(path.exists())
            self.assertLessEqual(path.stat().st_size, 1048576 + 4097)

    def test_stop_and_disable_remain_off_for_two_real_minutes(self):
        f = self.f
        other = Fixture()
        self.addCleanup(other.close)
        for fixture in (f, other):
            fixture.ctl('enable')
        f.ctl('stop')
        other.ctl('disable')
        self.assertIn('Autostart: ativado', f.ctl('status', check=False).stdout)
        self.assertIn('Parada administrativa: sim', f.ctl('status', check=False).stdout)
        started = time.monotonic()
        for deadline in (0, 60, 120):
            while time.monotonic() - started < deadline:
                time.sleep(min(0.5, deadline - (time.monotonic() - started)))
            for fixture in (f, other):
                fixture.run_cron()
                fixture.ctl('__tick')  # also simulate an already queued stale cron job
                self.assertEqual(len(fixture.starts()), 1)
                self.assertFalse(alive(fixture.pid()))
                self.assertFalse(fixture.watchdog() and alive(fixture.watchdog()))
        print(f'\nADMINISTRATIVE STOP/DISABLE: both stayed off for {time.monotonic() - started:.2f}s with cron ticks', flush=True)
        f.ctl('start')
        self.assertEqual(len(f.starts()), 2)
        other.boot()
        other.ctl('__tick')
        self.assertEqual(len(other.starts()), 1)
        other.ctl('enable')
        self.assertEqual(len(other.starts()), 2)


# Contract fixture for controller/systemd interaction. It does not reimplement
# systemd's restart engine: generated units are also verified by systemd-analyze.
SYSTEMCTL_FIXTURE = r'''#!/usr/bin/env python3
import os, pathlib, signal, subprocess, sys
root = pathlib.Path(os.environ['FM_FIXTURE'])
args = sys.argv[1:]
with (root / 'systemctl.calls').open('a') as log:
    log.write(' '.join(args) + '\n')
user = '--user' in args
args = [arg for arg in args if arg != '--user']
kind = 'user' if user else 'system'
if args == ['show-environment']:
    sys.exit(0 if user else 1)
name = next((arg for arg in args if arg.endswith('.service')), 'filemanager.service')
unit = root / 'home/.config/systemd/user' / name if user else root / 'system.service'
loaded = unit.exists() and os.environ.get('FM_UNIT_KIND') == kind
if args[0] == 'daemon-reload':
    sys.exit(0)
if user and args[0] == 'start' and os.environ.get('FM_FAIL_USER_START') == '1':
    sys.exit(1)
if not loaded:
    if args[0] == 'show' and 'LoadState' in args:
        print('not-found')
        sys.exit(0)
    sys.exit(1)
enabled = root / ('enabled-' + kind)
active = root / ('active-' + kind)
if args[0] == 'show':
    prop = args[args.index('-p') + 1]
    print({'LoadState': 'loaded', 'WorkingDirectory': os.environ.get('FM_UNIT_DIRECTORY', str(root)), 'ActiveState': 'active' if active.exists() else 'inactive'}.get(prop, ''))
elif args[0] == 'is-enabled':
    sys.exit(0 if enabled.exists() else 1)
elif args[0] == 'is-active':
    sys.exit(0 if active.exists() else 1)
elif args[0] == 'enable':
    enabled.touch()
elif args[0] in ('disable', 'stop', 'start', 'restart'):
    if args[0] == 'disable':
        enabled.unlink(missing_ok=True)
    if args[0] in ('stop', 'restart') or '--now' in args:
        if active.exists():
            try:
                os.killpg(int(active.read_text()), signal.SIGTERM)
            except ProcessLookupError:
                pass
        active.unlink(missing_ok=True)
    if args[0] in ('start', 'restart') and not active.exists():
        p = subprocess.Popen([str(root / 'pcg'), str(root / 'server.php')], cwd=root,
                             stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
                             stderr=subprocess.DEVNULL, start_new_session=True)
        active.write_text(str(p.pid))
else:
    sys.exit(1)
'''


class SystemdTests(unittest.TestCase):
    def setUp(self):
        self.f = Fixture()
        self.addCleanup(self.f.close)
        (self.f.bin / 'systemctl').write_text(SYSTEMCTL_FIXTURE)

    def test_user_linger_selects_systemd_and_removes_cron(self):
        f = self.f
        f.env.update(FM_LINGER='yes', FM_UNIT_KIND='user')
        f.install()
        self.assertEqual((f.state / 'backend').read_text().strip(), 'user')
        # The systemctl fixture starts PHP asynchronously; wait for PHP to
        # publish its PID before asserting liveness.
        until(lambda: (f.root / 'server.pid').exists()
              and (f.root / 'server.pid').read_text().strip())
        self.assertTrue(alive(f.pid()))
        self.assertFalse(f.watchdog())
        self.assertNotIn('# filemanager:', (f.root / 'crontab').read_text())
        unit = next((f.root / 'home/.config/systemd/user').glob('*.service'))
        content = unit.read_text()
        for directive in ('Restart=always', 'RestartSec=2', 'KillMode=control-group', 'TimeoutStopSec=15', 'StartLimitIntervalSec=0'):
            self.assertIn(directive, content)
        f.ctl('disable')
        self.assertFalse((f.root / 'enabled-user').exists())
        self.assertFalse(alive(f.pid()))
        f.boot()
        f.ctl('__tick')
        self.assertEqual(len(f.starts()), 1)

    def test_failed_user_unit_is_disabled_before_cron_fallback(self):
        f = self.f
        f.env.update(FM_LINGER='yes', FM_UNIT_KIND='user', FM_FAIL_USER_START='1')
        f.install()
        self.assertEqual((f.state / 'backend').read_text().strip(), 'watchdog')
        self.assertFalse((f.root / 'enabled-user').exists())
        self.assertFalse((f.root / 'active-user').exists())
        self.assertTrue(alive(f.pid()))
        self.assertTrue(alive(f.watchdog()))
        self.assertEqual(len(f.starts()), 1)
        self.assertEqual((f.root / 'crontab').read_text().count('# filemanager:'), 2)

    def test_system_administrative_commands_and_disable_order(self):
        f = self.f
        f.env['FM_UNIT_KIND'] = 'system'
        alias = f.root.parent / 'installation alias'
        alias.symlink_to(f.root, target_is_directory=True)
        f.env['FM_UNIT_DIRECTORY'] = str(alias)
        (f.root / 'system.service').touch()
        # run_systemctl uses sudo -n systemctl; preserve only its arguments.
        f.script('sudo', '[ "$1" = -n ] && shift\nexec "$@"\n')
        f.ctl('enable')
        pid = f.pid()
        f.ctl('start')
        self.assertEqual(len(f.starts()), 1)
        f.ctl('restart')
        self.assertEqual(len(f.starts()), 2)
        self.assertFalse(alive(pid))
        f.ctl('stop')
        self.assertTrue((f.root / 'enabled-system').exists())
        f.ctl('__tick')
        self.assertEqual(len(f.starts()), 2)
        f.ctl('start')
        (f.root / 'systemctl.calls').write_text('')
        f.ctl('disable')
        calls = (f.root / 'systemctl.calls').read_text().splitlines()
        self.assertLess(calls.index('disable filemanager.service'), calls.index('stop filemanager.service'))
        self.assertFalse((f.root / 'enabled-system').exists())
        self.assertFalse(alive(f.pid()))
        self.assertFalse(f.watchdog())
        f.boot()
        f.ctl('__tick')
        self.assertEqual(len(f.starts()), 3)

    def test_generated_system_and_user_units_pass_systemd_verification(self):
        if not shutil.which('systemd-analyze'):
            self.skipTest('systemd-analyze unavailable')
        f = self.f
        source = (ROOT / 'scripts/install-supervision.sh').read_text().rsplit('\nconfigure_supervision\n', 1)[0]
        for kind in ('system', 'user'):
            unit = f.root / ('filemanager-' + kind + '.service')
            script = source + '\n' + '\n'.join([
                'CURRENT_DIR=' + shlex.quote(str(f.root)),
                'PHP_BIN=' + shlex.quote(str(f.root / 'pcg')),
                'FILEMANAGER_USER=' + shlex.quote(subprocess.check_output(['id', '-un'], text=True).strip()),
                'run_priv() { "$@"; }',
                'supervision_write_unit ' + kind + ' ' + shlex.quote(str(unit))])
            result = subprocess.run(['bash', '-c', script], capture_output=True, text=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            checked = subprocess.run(['systemd-analyze', 'verify', '--man=no', str(unit)], capture_output=True, text=True)
            self.assertEqual(checked.returncode, 0, checked.stderr)


if __name__ == '__main__':
    unittest.main(verbosity=2)
