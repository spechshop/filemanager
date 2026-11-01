#!/usr/bin/env python3

import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


PROJECT_ROOT = Path(__file__).resolve().parents[2]


class InstallerBootstrapTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="fm-installer-bootstrap-", dir=PROJECT_ROOT)
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.cwd = self.root / "working directory"
        self.cwd.mkdir()
        self.fixture = self.root / "checkout"
        self.fixture.mkdir()
        for filename in ("server.php", "composer.json", "installer.sh"):
            (self.fixture / filename).touch()
        (self.fixture / "scripts").mkdir()
        shutil.copy2(PROJECT_ROOT / "scripts/secure-download.sh", self.fixture / "scripts/secure-download.sh")
        self.bin = self.root / "bin"
        self.bin.mkdir()
        git = self.bin / "git"
        git.write_text(r'''#!/usr/bin/env bash
printf '%s\n' "$*" >> "$TEST_GIT_LOG"
[ -z "${GIT_SSL_NO_VERIFY+x}" ] || exit 2
[ "$*" = '-c http.sslVerify=true -c http.followRedirects=false clone --branch newterm --single-branch https://github.com/spechshop/filemanager filemanager' ] || exit 2
[ "${TEST_GIT_FAIL:-0}" = 0 ] || exit 1
cp -R -- "$TEST_CHECKOUT" filemanager
''', encoding="utf-8")
        git.chmod(0o755)
        self.env = os.environ.copy()
        self.env.update({
            "PATH": str(self.bin) + os.pathsep + self.env["PATH"],
            "TEST_CHECKOUT": str(self.fixture),
            "TEST_GIT_LOG": str(self.root / "git.log"),
            "GIT_SSL_NO_VERIFY": "true",
        })
        self.env.pop("FILEMANAGER_SOURCE_SHA256", None)
        source = (PROJECT_ROOT / "installer.sh").read_text(encoding="utf-8")
        downloads = source[source.index("# 4) Download resiliente"):source.index("# 5) Atualização do sistema")]
        checkout = source[source.index("# 6) Obter o código"):source.index("# 7) Runtime Node")]
        self.script = r'''set +e
log() { printf '%s\n' "$*"; }
ok() { printf '%s\n' "$*"; }
warn() { printf '%s\n' "$*" >&2; }
err() { printf '%s\n' "$*" >&2; }
''' + downloads + checkout + '\nprintf "PROJECT_DIR=%s\\n" "$PWD"\n'

    def run_installer(self, standalone=False):
        if standalone:
            installer = self.root / "installer.sh"
            installer.write_text(self.script, encoding="utf-8")
            return subprocess.run(["bash", str(installer)], cwd=self.cwd, env=self.env,
                                  capture_output=True, text=True, timeout=10)
        return subprocess.run(["bash"], input=self.script, cwd=self.cwd, env=self.env,
                              capture_output=True, text=True, timeout=10)

    def assert_checkout(self, result, destination):
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("PROJECT_DIR=" + str(destination), result.stdout)
        self.assertTrue((destination / "scripts/secure-download.sh").is_file())

    def test_pipe_into_empty_directory_obtains_checkout(self):
        result = self.run_installer()
        self.assert_checkout(result, self.cwd / "filemanager")
        self.assertTrue((self.root / "git.log").is_file())
        self.assertEqual([item.name for item in self.cwd.iterdir()], ["filemanager"])

    def test_standalone_file_obtains_checkout_without_sibling_scripts(self):
        self.assert_checkout(self.run_installer(standalone=True), self.cwd / "filemanager")

    def test_pipe_reuses_current_checkout(self):
        shutil.copytree(self.fixture, self.cwd, dirs_exist_ok=True)
        sentinel = self.cwd / "operator-config"
        sentinel.write_text("preserve", encoding="utf-8")
        self.assert_checkout(self.run_installer(), self.cwd)
        self.assertEqual(sentinel.read_text(), "preserve")
        self.assertFalse((self.root / "git.log").exists())

    def test_pipe_reuses_child_checkout(self):
        destination = self.cwd / "filemanager"
        shutil.copytree(self.fixture, destination)
        self.assert_checkout(self.run_installer(), destination)
        self.assertFalse((self.root / "git.log").exists())

    def test_existing_unrelated_directory_is_preserved(self):
        destination = self.cwd / "filemanager"
        destination.mkdir()
        sentinel = destination / "operator-config"
        sentinel.write_text("preserve", encoding="utf-8")
        result = self.run_installer()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("não contém uma instalação válida", result.stderr)
        self.assertEqual(sentinel.read_text(), "preserve")
        self.assertFalse((self.root / "git.log").exists())

    def test_failed_clone_without_archive_digest_stops_installation(self):
        self.env["TEST_GIT_FAIL"] = "1"
        result = self.run_installer()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("SHA256 revisado", result.stderr)
        self.assertIn("Instalação interrompida", result.stderr)
        self.assertNotIn("PROJECT_DIR=", result.stdout)
        self.assertFalse((self.cwd / "filemanager").exists())


if __name__ == "__main__":
    unittest.main(verbosity=2)