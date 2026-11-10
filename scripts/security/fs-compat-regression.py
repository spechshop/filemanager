#!/usr/bin/env python3
"""Run the descriptor/race suites using the Linux 5.4-compatible implementation."""
import ctypes
import errno
import importlib.util
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch


def load(filename):
    spec = importlib.util.spec_from_file_location(filename, Path(__file__).with_name(filename))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


broker = load('fs-broker.py')


class DispatchTests(unittest.TestCase):
    def test_enosys_selects_descriptor_fallback(self):
        with tempfile.TemporaryDirectory() as directory:
            Path(directory, 'proof').write_text('safe')
            root = os.open(directory, os.O_PATH | os.O_DIRECTORY)
            try:
                def unsupported(*args):
                    ctypes.set_errno(errno.ENOSYS)
                    return -1
                broker._openat2_available = True
                with patch.object(broker.ctypes, 'CDLL') as library:
                    library.return_value.syscall.side_effect = unsupported
                    fd = broker.openat2(root, 'proof', os.O_RDONLY)
                    try: self.assertEqual(os.read(fd, 4), b'safe')
                    finally: os.close(fd)
                    self.assertFalse(broker._openat2_available)
            finally: os.close(root)

    def test_unsupported_and_escape_paths_are_rejected(self):
        root = os.open('/', os.O_PATH | os.O_DIRECTORY)
        try:
            for name in ['/etc/passwd', '../etc/passwd', 'tmp/../../etc/passwd']:
                with self.assertRaises(OSError): broker.openat_compat(root, name, os.O_RDONLY)
        finally: os.close(root)

    def test_permission_errors_do_not_trigger_fallback(self):
        broker._openat2_available = True
        with patch.object(broker.ctypes, 'CDLL') as library:
            def denied(*args):
                ctypes.set_errno(errno.EACCES)
                return -1
            library.return_value.syscall.side_effect = denied
            with patch.object(broker, 'openat_compat') as fallback:
                with self.assertRaises(PermissionError):
                    broker.openat2(-1, 'proof', os.O_RDONLY)
                fallback.assert_not_called()
                self.assertTrue(broker._openat2_available)


if __name__ == '__main__':
    suites = [unittest.defaultTestLoader.loadTestsFromTestCase(DispatchTests)]
    for filename in ('fs-regression.py', 'trash-regression.py'):
        module = load(filename)
        module.broker._openat2_available = False
        suites.append(unittest.defaultTestLoader.loadTestsFromModule(module))
    result = unittest.TextTestRunner(verbosity=2).run(unittest.TestSuite(suites))
    raise SystemExit(not result.wasSuccessful())
