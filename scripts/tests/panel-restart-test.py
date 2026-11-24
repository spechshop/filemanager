#!/usr/bin/env python3
"""Isolated Swoole shutdown/restart contract; never touches the live service."""
import os
import shutil
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import unittest
from urllib.request import urlopen

ROOT = Path(__file__).resolve().parents[2]
PHP = os.environ.get('SECURITY_PHP_BINARY') or shutil.which('php') or 'php'


class PanelRestartTests(unittest.TestCase):
    def test_panel_never_uses_sigkill_for_restart(self):
        source = (ROOT / 'plugins/Request/apps/fileManagerSystem.php').read_text()
        self.assertIn('fileManagerRuntime::requestRestart()', source)
        self.assertIn('$server->shutdown()', source)
        self.assertNotIn('Process::kill(', source)
        self.assertNotIn('SIGKILL);', source)

    def test_swoole_worker_can_shutdown_master_after_response(self):
        with tempfile.TemporaryDirectory(prefix='fm-panel-shutdown-') as tmp:
            with socket.socket() as reservation:
                reservation.bind(('127.0.0.1', 0))
                port = reservation.getsockname()[1]
            marker = Path(tmp) / 'restart-marker'
            script = Path(tmp) / 'fixture.php'
            script.write_text(r'''<?php
$server = new Swoole\Http\Server('127.0.0.1', (int) getenv('TEST_PORT'), SWOOLE_PROCESS);
$server->set(['worker_num' => 1, 'log_file' => '/dev/null']);
$server->on('request', static function ($request, $response) use ($server) {
    $response->end('accepted');
    Swoole\Timer::after(150, static function () use ($server) {
        file_put_contents(getenv('TEST_MARKER'), 'restart requested');
        if ($server->shutdown() === false) error_log('shutdown rejected');
    });
});
$server->on('Start', static function () { echo "READY\n"; });
$server->on('Shutdown', static function () { echo "SHUTDOWN\n"; });
$server->start();
echo "STOPPED\n";
''')
            process = subprocess.Popen([PHP, str(script)], cwd=tmp,
                                       env={**os.environ, 'TEST_PORT': str(port), 'TEST_MARKER': str(marker)},
                                       stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
            try:
                response = None
                for _ in range(120):
                    if process.poll() is not None:
                        break
                    try:
                        with urlopen(f'http://127.0.0.1:{port}/', timeout=0.3) as stream:
                            response = stream.read().decode()
                        break
                    except (OSError, TimeoutError):
                        time.sleep(0.03)
                self.assertEqual(response, 'accepted')
                output, _ = process.communicate(timeout=10)
                self.assertEqual(process.returncode, 0, output)
                self.assertEqual(marker.read_text(), 'restart requested')
                self.assertIn('SHUTDOWN', output)
                self.assertIn('STOPPED', output)
            finally:
                if process.poll() is None:
                    process.terminate()
                    try:
                        process.wait(timeout=3)
                    except subprocess.TimeoutExpired:
                        process.kill()
                        process.wait(timeout=3)


if __name__ == '__main__':
    unittest.main(verbosity=2)
