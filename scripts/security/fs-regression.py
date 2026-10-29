#!/usr/bin/env python3
"""Synthetic filesystem regressions. All objects are in a private temporary tree."""
import importlib.util
import io
import json
import os
from pathlib import Path
import tempfile
import time
import threading
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('broker', Path(__file__).with_name('fs-broker.py'))
broker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(broker)


class FilesystemTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='fm-descriptors-')
        self.base = Path(self.temp.name)
        self.a = self.base / 'projects'
        self.b = self.base / 'independent-logs'
        self.outside = self.base / 'outside'
        for directory in (self.a, self.b, self.outside):
            directory.mkdir()
            (directory / 'keep').write_text('sentinel')
        self.store = self.base / 'tokens.json'
        self.records = {'restricted': {'expire': int(time.time()) + 60, 'allowedRoots': [str(self.a), str(self.b)]},
                        'admin': {'expire': int(time.time()) + 60}}
        self.save()
        self.handles = []

    def save(self):
        self.store.write_text(json.dumps(self.records))

    def files(self, owner='restricted'):
        files = broker.Files(dict(token=owner, tokenFile=str(self.store), base=str(self.base)))
        self.handles.append(files)
        return files

    def tearDown(self):
        for handle in self.handles:
            handle.close()
        self.temp.cleanup()

    def test_admin_absolute_external_paths_and_root(self):
        files = self.files('admin')
        self.assertEqual(os.read(files.open(str(self.outside / 'keep')), 100), b'sentinel')
        files.write(str(self.outside / 'new'), io.BytesIO(b'authorized'))
        self.assertEqual((self.outside / 'new').read_bytes(), b'authorized')
        self.assertTrue(files.entries('/'))

    def test_multiple_independent_roots_and_compatible_links(self):
        files = self.files()
        os.symlink(self.b, self.a / 'logs')
        os.symlink('keep', self.a / 'local-link')
        for path in (self.a / 'keep', self.b / 'keep', self.a / 'logs/keep', self.a / 'local-link'):
            self.assertEqual(os.read(files.open(str(path)), 100), b'sentinel')
        files.write(str(self.a / 'logs/new'), io.BytesIO(b'logs'))
        self.assertEqual((self.b / 'new').read_bytes(), b'logs')
        files.copy(str(self.a / 'keep'), str(self.b / 'copied'))
        files.move(str(self.b / 'copied'), str(self.a / 'moved'))
        self.assertEqual((self.a / 'moved').read_bytes(), b'sentinel')
        files.remove(str(self.a / 'moved'))
        self.assertFalse((self.a / 'moved').exists())

    def test_copy_supports_legitimate_source_and_destination_links(self):
        files = self.files()
        os.symlink(self.b / 'keep', self.a / 'destination-link')
        (self.a / 'source').write_bytes(b'copy-through-authorized-link')
        files.copy(str(self.a / 'source'), str(self.a / 'destination-link'))
        self.assertEqual((self.b / 'keep').read_bytes(), b'copy-through-authorized-link')
        (self.a / 'tree').mkdir()
        os.symlink(self.b / 'keep', self.a / 'tree/linked-file')
        files.copy(str(self.a / 'tree'), str(self.b / 'copied-tree'))
        self.assertEqual((self.b / 'copied-tree/linked-file').read_bytes(), b'copy-through-authorized-link')

    def test_scope_escape_and_changed_scope_are_denied(self):
        files = self.files()
        os.symlink(self.outside, self.a / 'escape')
        for path in (self.outside / 'keep', self.a / '../outside/keep', self.a / 'escape/keep'):
            with self.assertRaises(OSError):
                files.open(str(path))
        self.records['restricted']['allowedRoots'] = [str(self.a)]
        self.save()
        with self.assertRaises(OSError):
            files.write(str(self.b / 'keep'), io.BytesIO(b'changed'))
        self.assertEqual((self.b / 'keep').read_bytes(), b'sentinel')
        self.assertEqual((self.outside / 'keep').read_bytes(), b'sentinel')

    def test_final_symlink_swap_at_actual_open_cannot_escape(self):
        files = self.files()
        native = broker.openat2
        def racing_open(fd, name, flags, mode=0, resolve=broker.BENEATH | broker.NO_MAGICLINKS):
            if name == 'keep':
                (self.a / 'keep').rename(self.a / 'original')
                os.symlink(self.outside / 'keep', self.a / 'keep')
            return native(fd, name, flags, mode, resolve)
        broker.openat2 = racing_open
        try:
            with self.assertRaises(OSError):
                files.write(str(self.a / 'keep'), io.BytesIO(b'changed'))
        finally:
            broker.openat2 = native
        self.assertEqual((self.outside / 'keep').read_bytes(), b'sentinel')
        self.assertEqual((self.a / 'original').read_bytes(), b'sentinel')

    def test_parent_swap_between_resolution_and_kernel_lookup(self):
        for operation in ('read', 'write', 'mkdir', 'move', 'remove', 'copy', 'list'):
            with self.subTest(operation=operation):
                files = self.files()
                sub = self.a / operation
                sub.mkdir()
                (sub / 'keep').write_text('sentinel')
                native = broker.openat2
                raced = False
                def racing_open(fd, name, flags, mode=0, resolve=broker.BENEATH | broker.NO_MAGICLINKS):
                    nonlocal raced
                    if not raced and (name == operation or name.startswith(operation + '/')):
                        raced = True
                        sub.rename(self.a / (operation + '-old'))
                        os.symlink(self.outside, sub)
                    return native(fd, name, flags, mode, resolve)
                broker.openat2 = racing_open
                try:
                    with self.assertRaises(OSError):
                        if operation == 'read': files.open(str(sub / 'keep'))
                        elif operation == 'write': files.write(str(sub / 'keep'), io.BytesIO(b'changed'))
                        elif operation == 'mkdir': files.mkdir(str(sub / 'new'))
                        elif operation == 'move': files.move(str(sub / 'keep'), str(self.b / 'moved'))
                        elif operation == 'remove': files.remove(str(sub / 'keep'))
                        elif operation == 'copy': files.copy(str(sub / 'keep'), str(self.b / 'copied'))
                        else: files.entries(str(sub))
                finally:
                    broker.openat2 = native
                self.assertTrue(raced)
                self.assertEqual((self.outside / 'keep').read_text(), 'sentinel')
                self.assertFalse((self.outside / 'new').exists())

    def test_open_file_remains_original_object_after_rename(self):
        files = self.files()
        fd = files.open(str(self.a / 'keep'))
        (self.a / 'keep').rename(self.a / 'original')
        os.symlink(self.outside / 'keep', self.a / 'keep')
        self.assertEqual(os.read(fd, 100), b'sentinel')
        self.assertEqual((self.outside / 'keep').read_text(), 'sentinel')

    def test_revocation_between_stream_chunks_stops_writes(self):
        files = self.files()
        owner = self
        class Input:
            calls = 0
            def read(self, _):
                self.calls += 1
                owner.records['restricted']['revoked'] = True
                owner.save()
                return b'not-authorized'
        with self.assertRaises(OSError):
            files.write(str(self.a / 'new'), Input())
        self.assertEqual((self.a / 'new').read_bytes(), b'')

    def test_real_concurrent_parent_replacements_never_write_external_sentinel(self):
        directory = self.a / 'racing'
        old = self.a / 'racing-old'
        directory.mkdir()
        (directory / 'keep').write_text('inside')
        stop = threading.Event()
        def replace():
            while not stop.is_set():
                try:
                    directory.rename(old)
                    os.symlink(self.outside, directory)
                    directory.unlink()
                    old.rename(directory)
                except OSError:
                    pass
        thread = threading.Thread(target=replace)
        files = self.files()
        thread.start()
        try:
            for _ in range(200):
                try:
                    files.write(str(directory / 'keep'), io.BytesIO(b'fixture'))
                except OSError:
                    pass
        finally:
            stop.set(); thread.join()
        self.assertEqual((self.outside / 'keep').read_text(), 'sentinel')

    def test_execute_only_parent_preserves_os_file_access(self):
        if os.geteuid() == 0:
            self.skipTest('root bypasses Unix directory permissions')
        self.a.chmod(0o100)
        try:
            files = self.files()
            self.assertEqual(os.read(files.open(str(self.a / 'keep')), 100), b'sentinel')
            with self.assertRaises(OSError):
                files.entries(str(self.a))
        finally:
            self.a.chmod(0o700)

    def upload_fixture(self):
        private = self.base / 'upload-tmp'
        private.mkdir()
        source = private / 'incoming'
        source.write_bytes(bytes(range(256)) * 4096)
        files = self.files()
        files.request['uploadDirectory'] = str(private)
        return files, source

    def test_upload_batch_contents_overwrite_empty_and_bounded_descriptors(self):
        files, source = self.upload_fixture()
        (self.a / 'uploaded').write_bytes(b'old' * 1000000)
        initial = len(files.fds)
        files.upload_many([{'source': str(source), 'target': str(self.a / 'uploaded')}] * 100)
        self.assertEqual((self.a / 'uploaded').read_bytes(), source.read_bytes())
        self.assertEqual(len(files.fds), initial)
        source.write_bytes(b'')
        files.upload_many([{'source': str(source), 'target': str(self.a / 'uploaded')}])
        self.assertEqual((self.a / 'uploaded').read_bytes(), b'')

    def test_upload_fallback_after_partial_kernel_copy(self):
        files, source = self.upload_fixture()
        native = os.copy_file_range
        calls = 0
        def copy(source_fd, target_fd, count):
            nonlocal calls
            calls += 1
            if calls == 1:
                return native(source_fd, target_fd, 123)
            raise OSError(broker.errno.EXDEV, 'different filesystem')
        with patch.object(os, 'copy_file_range', copy):
            files.upload(str(source), str(self.a / 'uploaded'))
        self.assertEqual((self.a / 'uploaded').read_bytes(), source.read_bytes())

    def test_upload_zero_copy_result_falls_back_without_losing_data(self):
        files, source = self.upload_fixture()
        with patch.object(os, 'copy_file_range', return_value=0):
            files.upload(str(source), str(self.a / 'uploaded'))
        self.assertEqual((self.a / 'uploaded').read_bytes(), source.read_bytes())

    def test_upload_revocation_between_kernel_chunks_and_batch_files(self):
        files, source = self.upload_fixture()
        native = os.copy_file_range
        def copy(source_fd, target_fd, count):
            result = native(source_fd, target_fd, count)
            self.records['restricted']['revoked'] = True
            self.save()
            return result
        initial = len(files.fds)
        with patch.object(os, 'copy_file_range', copy), self.assertRaises(PermissionError):
            files.upload_many([{'source': str(source), 'target': str(self.a / name)} for name in ('first', 'second')])
        self.assertEqual((self.a / 'first').stat().st_size, 262144)
        self.assertFalse((self.a / 'second').exists())
        self.assertEqual(len(files.fds), initial)

    def test_upload_rejects_outside_and_substituted_source(self):
        files, source = self.upload_fixture()
        with self.assertRaises(PermissionError):
            files.upload(str(source), str(self.outside / 'keep'))
        source.unlink()
        source.symlink_to(self.outside / 'keep')
        with self.assertRaises(OSError):
            files.upload(str(source), str(self.a / 'uploaded'))
        self.assertEqual((self.outside / 'keep').read_bytes(), b'sentinel')
        self.assertFalse((self.a / 'uploaded').exists())

    def test_upload_provenance_is_private_and_never_follows_substituted_links(self):
        private = self.base / 'upload-tmp'
        private.mkdir(mode=0o700)
        source = private / 'synthetic-upload'
        source.write_bytes(b'upload')
        files = self.files()
        files.request['uploadDirectory'] = str(private)
        fd = files.open_upload(str(source))
        source.rename(private / 'original')
        os.symlink(self.outside / 'keep', source)
        self.assertEqual(os.read(fd, 100), b'upload')
        with self.assertRaises(OSError):
            files.open_upload(str(source))
        source.unlink()
        private.rename(self.base / 'upload-old')
        os.symlink(self.outside, private)
        with self.assertRaises(OSError):
            files.open_upload(str(private / 'keep'))
        self.assertEqual((self.outside / 'keep').read_text(), 'sentinel')

    def test_cross_volume_move(self):
        if not Path('/dev/shm').is_dir() or os.stat('/dev/shm').st_dev == os.stat(self.a).st_dev:
            self.skipTest('second writable volume unavailable')
        with tempfile.TemporaryDirectory(prefix='fm-volume-', dir='/dev/shm') as volume:
            self.records['restricted']['allowedRoots'].append(volume)
            self.save()
            files = self.files()
            files.move(str(self.a / 'keep'), volume + '/moved')
            self.assertEqual(Path(volume + '/moved').read_bytes(), b'sentinel')
            self.assertFalse((self.a / 'keep').exists())


if __name__ == '__main__':
    unittest.main(verbosity=2)
