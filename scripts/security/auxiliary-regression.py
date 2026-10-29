#!/usr/bin/env python3
"""Synthetic finite-tool tests. Only private local fixtures and inert arguments."""
import base64
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import time
import unittest

BROKER = Path(__file__).with_name('process-broker.py')


class AuxiliaryTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix='fm-auxiliary-')
        self.root = Path(self.directory.name)
        self.tokens = self.root / 'tokens.json'
        self.write_token({'expire': int(time.time()) + 60})

    def tearDown(self):
        self.directory.cleanup()

    def write_token(self, entry):
        self.tokens.write_text(json.dumps({'fixture-owner': entry}))

    def job(self, arguments):
        return dict(argv=arguments, token='fixture-owner', admin=False,
                    tokenFile=str(self.tokens), cwd=str(self.root), env=None,
                    seconds=3, bytes=4096)

    def run_tool(self, job):
        result = subprocess.run([sys.executable, '-I', str(BROKER)],
                                input=json.dumps(job), text=True,
                                capture_output=True, timeout=5)
        return result.returncode, json.loads(result.stdout)

    def test_literal_arguments_nonzero_status_and_output(self):
        literal = 'quotes " and $() remain text; no shell'
        status, result = self.run_tool(self.job([sys.executable, '-c',
            'import sys; print(sys.argv[1]); sys.exit(7)', literal]))
        self.assertEqual(status, 0)
        self.assertEqual(result['exit'], 7)
        self.assertEqual(base64.b64decode(result['output']).decode(), literal + '\n')

    def test_revoked_and_non_admin_tokens_never_start_tool(self):
        arguments = [sys.executable, '-c', 'from pathlib import Path; Path("started").touch()']
        self.write_token({'expire': int(time.time()) + 60, 'admin': False})
        job = self.job(arguments)
        job['admin'] = True
        self.assertEqual(self.run_tool(job)[0], 1)
        self.write_token({'expire': int(time.time()) + 60, 'revoked': True})
        self.assertEqual(self.run_tool(self.job(arguments))[0], 1)
        self.assertFalse((self.root / 'started').exists())

    def test_output_limit_and_timeout_are_enforced(self):
        job = self.job([sys.executable, '-c', 'print("x" * 8192)'])
        self.assertEqual(self.run_tool(job)[0], 1)
        job = self.job([sys.executable, '-c', 'import time; time.sleep(10)'])
        job['seconds'] = 0.1
        started = time.monotonic()
        self.assertEqual(self.run_tool(job)[0], 1)
        self.assertLess(time.monotonic() - started, 2)

    def start_family(self, admin=False, wrapper=False):
        child = 'import time,signal; from pathlib import Path; signal.signal(signal.SIGTERM,signal.SIG_IGN); time.sleep(2); Path("late-effect").touch()'
        parent = ('import subprocess,sys,time; from pathlib import Path; '
                  'p=subprocess.Popen([sys.executable,"-c",sys.argv[1]]); '
                  'Path("child-pid").write_text(str(p.pid)); time.sleep(10)')
        job = self.job([sys.executable, '-c', parent, child])
        job['admin'] = admin
        command = [sys.executable, '-I', str(BROKER)]
        if wrapper:
            command = [sys.executable, '-c',
                'import subprocess,sys; p=subprocess.Popen(sys.argv[1:],stdin=sys.stdin); p.wait()', *command]
        process = subprocess.Popen(command,
                                   stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                                   stderr=subprocess.PIPE, text=True)
        process.stdin.write(json.dumps(job) + '\n')
        process.stdin.flush()
        deadline = time.monotonic() + 2
        while not (self.root / 'child-pid').exists():
            if time.monotonic() > deadline:
                process.kill(); process.wait()
                self.fail('Synthetic process family did not start')
            time.sleep(0.01)
        return process, int((self.root / 'child-pid').read_text())

    def assert_stopped(self, pid):
        status = Path('/proc') / str(pid) / 'status'
        deadline = time.monotonic() + 1
        while status.exists() and '\nState:\tZ' not in status.read_text() and time.monotonic() < deadline:
            time.sleep(0.01)
        self.assertTrue(not status.exists() or '\nState:\tZ' in status.read_text())
        self.assertFalse((self.root / 'late-effect').exists())

    def test_capability_removed_during_tool_kills_descendants(self):
        process, child = self.start_family(admin=True)
        self.write_token({'expire': int(time.time()) + 60, 'admin': False})
        process.communicate(timeout=3)
        self.assertEqual(process.returncode, 1)
        self.assert_stopped(child)

    def test_revocation_during_tool_kills_descendants(self):
        process, child = self.start_family()
        self.tokens.write_text('{}')
        process.communicate(timeout=3)
        self.assertEqual(process.returncode, 1)
        self.assert_stopped(child)

    def test_worker_death_cancels_supervisor_and_descendants(self):
        process, child = self.start_family(wrapper=True)
        process.terminate()
        process.communicate(timeout=3)
        self.assertLess(process.returncode, 0)
        self.assert_stopped(child)

    def test_stopping_supervisor_kills_descendants(self):
        process, child = self.start_family()
        process.terminate()
        process.communicate(timeout=3)
        self.assertEqual(process.returncode, 1)
        self.assert_stopped(child)


if __name__ == '__main__':
    unittest.main(verbosity=2)
