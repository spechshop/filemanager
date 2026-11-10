#!/usr/bin/env python3
import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location('pcg_release', Path(__file__).resolve().parents[1] / 'pcg-release.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class ReleaseTests(unittest.TestCase):
    def setUp(self):
        self.asset = dict(name='pcg729-linux-x86_64-zts-php8.5.10-v1.1.7',
                          digest='sha256:' + 'a' * 64, size=137825672)
        self.asset['browser_download_url'] = 'https://github.com/spechshop/pcg729/releases/download/v1.1.7/' + self.asset['name']
        self.release = dict(tag_name='v1.1.7', draft=False, prerelease=False, assets=[self.asset])

    def test_current_versioned_asset(self):
        self.assertEqual(module.select(self.release, 'x86_64'),
                         (self.asset['browser_download_url'], 'a' * 64, '137825672', 'v1.1.7'))

    def test_reject_missing_digest(self):
        self.asset['digest'] = None
        with self.assertRaises(ValueError): module.select(self.release, 'x86_64')

    def test_reject_wrong_architecture(self):
        with self.assertRaises(ValueError): module.select(self.release, 'aarch64')

    def test_reject_unofficial_url(self):
        self.asset['browser_download_url'] = 'https://example.org/php'
        with self.assertRaises(ValueError): module.select(self.release, 'x86_64')

    def test_reject_ambiguous_assets(self):
        self.release['assets'].append(self.asset.copy())
        with self.assertRaises(ValueError): module.select(self.release, 'x86_64')

    def test_reject_draft(self):
        self.release['draft'] = True
        with self.assertRaises(ValueError): module.select(self.release, 'x86_64')


if __name__ == '__main__':
    unittest.main(verbosity=2)
