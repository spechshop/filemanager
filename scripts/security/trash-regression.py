#!/usr/bin/env python3
"""Recovery tests use private temporary data, never the application's real trash."""
import concurrent.futures
import importlib.util
import json
import os
import shutil
from pathlib import Path
import subprocess
import tempfile
import time
import unittest

spec = importlib.util.spec_from_file_location('broker', Path(__file__).with_name('fs-broker.py'))
broker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(broker)


class TrashTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='fm-trash-test-')
        self.base = Path(self.temp.name)
        self.root = self.base / 'workspace'
        self.root.mkdir()
        self.store = self.base / 'tokens.json'
        self.records = {owner: {'expire': int(time.time()) + 3600, 'allowedRoots': [str(self.root)]} for owner in ['alice', 'bob']}
        self.store.write_text(json.dumps(self.records))
        self.trash = self.base / 'private-trash'

    def tearDown(self):
        self.temp.cleanup()

    def request(self, owner='alice'):
        return dict(token=owner, tokenFile=str(self.store), base=str(self.base), trashDirectory=str(self.trash))

    def operation(self, action, *args, owner='alice', **kwargs):
        files = broker.Files(self.request(owner))
        trash = broker.Trash.__new__(broker.Trash)
        trash.fds = []
        try:
            trash.__init__(files)
            return getattr(trash, action)(*args, **kwargs)
        finally:
            trash.close()
            files.close()

    def test_files_and_folders_survive_restarts_and_restore_with_metadata(self):
        folder = self.root / 'nested'
        folder.mkdir()
        target = folder / 'hello.txt'
        target.write_bytes(b'original content\x00\xff')
        target.chmod(0o640)
        os.utime(target, (1700000000, 1700000000))
        record = self.operation('put', str(folder))
        self.assertFalse(folder.exists())
        listing = self.operation('list')
        self.assertEqual(listing[0]['originalPath'], str(folder))
        self.assertEqual(listing[0]['kind'], 'folder')
        self.operation('restore', record['id'])
        self.assertEqual(target.read_bytes(), b'original content\x00\xff')
        self.assertEqual(target.stat().st_mode & 0o777, 0o640)
        self.assertEqual(int(target.stat().st_mtime), 1700000000)
        self.assertEqual(self.operation('list'), [])

    def test_restore_conflict_preserves_both_files_and_handles_broken_links(self):
        path = self.root / 'notes.txt'
        path.write_text('old content')
        record = self.operation('put', str(path))
        path.write_text('new content')
        with self.assertRaises(broker.TrashError) as raised:
            self.operation('restore', record['id'])
        self.assertEqual(raised.exception.code, 'conflict')
        self.assertEqual(path.read_text(), 'new content')
        restored = self.operation('restore', record['id'], rename=True)
        self.assertEqual(Path(restored['restoredPath']).read_text(), 'old content')
        self.assertEqual(path.read_text(), 'new content')
        other = self.operation('put', restored['restoredPath'])
        os.symlink('/missing-target', restored['restoredPath'])
        with self.assertRaises(broker.TrashError):
            self.operation('restore', other['id'])
        self.assertTrue(Path(restored['restoredPath']).is_symlink())

    def test_missing_parent_is_recreated_and_escape_parent_is_denied(self):
        parent = self.root / 'parent'
        parent.mkdir()
        file = parent / 'item'
        file.write_text('recover')
        record = self.operation('put', str(file))
        parent.rmdir()
        self.operation('restore', record['id'])
        self.assertEqual(file.read_text(), 'recover')
        record = self.operation('put', str(file))
        parent.rmdir()
        outside = self.base / 'outside'
        outside.mkdir()
        os.symlink(outside, parent)
        with self.assertRaises(OSError):
            self.operation('restore', record['id'])
        self.assertFalse((outside / 'item').exists())
        self.assertEqual(len(self.operation('list')), 0)
        parent.unlink()
        self.operation('restore', record['id'])
        self.assertEqual(file.read_text(), 'recover')

    def test_owner_isolation_invalid_ids_and_revocation(self):
        file = self.root / 'private.txt'
        file.write_text('alice')
        record = self.operation('put', str(file))
        self.assertEqual(self.operation('list', owner='bob'), [])
        for action in ['restore', 'purge']:
            with self.assertRaises(broker.TrashError):
                self.operation(action, record['id'], owner='bob')
            with self.assertRaises(broker.TrashError):
                self.operation(action, '../' + record['id'])
        self.records['alice']['revoked'] = True
        self.store.write_text(json.dumps(self.records))
        with self.assertRaises(OSError):
            self.operation('list')
        self.assertTrue(self.trash.exists())

    def test_links_are_moved_and_purged_without_touching_the_target(self):
        outside = self.base / 'sentinel'
        outside.write_text('keep')
        link = self.root / 'link'
        os.symlink(outside, link)
        record = self.operation('put', str(link))
        self.assertFalse(link.is_symlink())
        self.assertEqual(outside.read_text(), 'keep')
        self.operation('restore', record['id'])
        self.assertEqual(os.readlink(link), str(outside))
        record = self.operation('put', str(link))
        self.operation('purge', record['id'])
        self.assertEqual(self.operation('list'), [])
        self.assertEqual(outside.read_text(), 'keep')

    def test_private_storage_symlinks_roots_and_ancestors_are_not_trashable(self):
        file = self.root / 'keep'
        file.write_text('keep')
        with self.assertRaises(OSError):
            self.operation('put', str(self.root))
        self.assertTrue(file.exists())
        outside = self.base / 'outside'
        outside.mkdir()
        shutil.rmtree(self.trash)
        os.symlink(outside, self.trash)
        with self.assertRaises(OSError):
            self.operation('put', str(file))
        self.assertEqual(list(outside.iterdir()), [])
        self.assertTrue(file.exists())
        self.trash.unlink()
        record = self.operation('put', str(file))
        files = broker.Files(self.request())
        try:
            with self.assertRaises(OSError):
                files.entries(str(self.trash))
        finally:
            files.close()
        self.operation('restore', record['id'])

    def test_simultaneous_restore_is_exclusive(self):
        file = self.root / 'once'
        file.write_text('single original')
        record = self.operation('put', str(file))
        command = {**self.request(), 'op': 'trash-restore', 'id': record['id']}
        def restore(_):
            process = subprocess.run(['/usr/bin/python3', '-I', str(Path(__file__).with_name('fs-broker.py'))], input=json.dumps(command).encode() + b'\n', capture_output=True)
            return json.loads(process.stdout)['result']
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as workers:
            results = list(workers.map(restore, range(2)))
        self.assertEqual(sum(result['success'] for result in results), 1)
        self.assertEqual(file.read_text(), 'single original')
        self.assertEqual(self.operation('list'), [])

    def test_cross_volume_roundtrip_preserves_bytes_and_symlinks(self):
        if not Path('/dev/shm').is_dir() or os.stat('/dev/shm').st_dev == self.root.stat().st_dev:
            self.skipTest('second writable filesystem unavailable')
        with tempfile.TemporaryDirectory(prefix='fm-trash-volume-', dir='/dev/shm') as volume:
            self.records['alice']['allowedRoots'].append(volume)
            self.store.write_text(json.dumps(self.records))
            folder = Path(volume) / 'folder'
            folder.mkdir()
            (folder / 'file').write_bytes(b'volume data')
            os.symlink('file', folder / 'link')
            record = self.operation('put', str(folder))
            self.assertFalse(folder.exists())
            self.operation('restore', record['id'])
            self.assertEqual((folder / 'file').read_bytes(), b'volume data')
            self.assertEqual(os.readlink(folder / 'link'), 'file')
            self.assertEqual(list(Path(volume).glob('.fm-trash-move-*')), [])


if __name__ == '__main__':
    unittest.main(verbosity=2)
