#!/usr/bin/env python3
"""Offline tests of install handoff. Never starts or stops real services."""
import os
import pathlib
import subprocess
import tempfile
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]
HELPER = ROOT / 'scripts/install-detach.sh'


class HandoffTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='fm-install-handoff-')
        self.addCleanup(self.temp.cleanup)
        self.root = pathlib.Path(self.temp.name)
        self.project = self.root / 'filemanager'
        self.project.mkdir()
        (self.project / '.runtime/supervision').mkdir(parents=True)
        self.installer = self.project / 'installer.sh'
        self.installer.write_text('#!/bin/bash\necho example\n')
        self.bin = self.root / 'bin'
        self.bin.mkdir()
        systemctl = self.bin / 'systemctl'
        systemctl.write_text('''#!/bin/bash
case "$*" in
 *"show filemanager.service -p WorkingDirectory --value"*) printf '%s\\n' "$FM_TEST_PROJECT" ;;
 *"show filemanager.service -p ControlGroup --value"*) printf '%s\\n' /system.slice/filemanager.service ;;
 *) exit 1 ;;
esac
''')
        systemctl.chmod(0o755)
        systemd_run = self.bin / 'systemd-run'
        systemd_run.write_text('''#!/bin/bash
printf '%s\\n' "$@" > "$FM_TEST_CALL"
[ "${FM_TEST_FAIL:-0}" = 0 ] || exit 45
''')
        systemd_run.chmod(0o755)
        self.env = dict(os.environ, PATH=str(self.bin) + ':' + os.environ.get('PATH', ''),
                        FM_TEST_PROJECT=str(self.project), FM_TEST_CALL=str(self.root / 'call.txt'))
        self.env.pop('FILEMANAGER_INSTALL_DETACHED', None)

    def dispatch(self, cgroup, *params, overrides=None):
        args = ['bash', '-c', '''source "$1"; shift
fm_installer_dispatch "$1" "$2" "${@:3}"
code=$?
echo "RETURN=$code"
''', 'test', str(HELPER), str(self.installer), cgroup, *params]
        return subprocess.run(args, env={**self.env, **(overrides or {})},
                              capture_output=True, text=True, timeout=5)

    def test_detaches_inside_owned_service(self):
        result = self.dispatch('/system.slice/filemanager.service',
                               '--supervision-only')
        self.assertIn('RETURN=0', result.stdout + result.stderr)
        self.assertTrue((self.root / 'call.txt').exists())
        args = (self.root / 'call.txt').read_text().splitlines()
        self.assertIn('--setenv=FILEMANAGER_INSTALL_DETACHED=1', args)
        self.assertIn('--supervision-only', args)
        self.assertEqual(args[-2:], [str(self.installer), '--supervision-only'])
        self.assertTrue(any(x.startswith('--unit=filemanager-install-') for x in args))
        self.assertEqual(args[args.index('--collect') + 1], '--unit=' + next(
            x.split('=', 1)[1] for x in args if x.startswith('--unit=')))
        self.assertIn('Instalação agendada fora do FileManager.', result.stdout)

    def test_installer_entrypoint_dispatches_before_mutations(self):
        # Run only the real installer bootstrap, not installation or services.
        source = (ROOT / 'installer.sh').read_text()
        bootstrap = source[:source.index('# An existing checkout can upgrade supervision')]
        (self.project / 'scripts').mkdir()
        (self.project / 'scripts/install-detach.sh').write_text(HELPER.read_text())
        self.installer.write_text(bootstrap + '\necho SHOULD_NOT_RUN_AFTER_HANDOFF\n')
        result = subprocess.run(['bash', str(self.installer)], env=self.env,
                                capture_output=True, text=True, timeout=5)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('Instalação agendada fora do FileManager.', result.stdout)
        self.assertNotIn('SHOULD_NOT_RUN_AFTER_HANDOFF', result.stdout)
        self.assertTrue((self.root / 'call.txt').is_file())

    def test_bash_syntax_is_valid(self):
        for script in [ROOT / 'installer.sh', HELPER]:
            result = subprocess.run(['bash', '-n', str(script)], capture_output=True, text=True)
            self.assertEqual(result.returncode, 0, result.stderr)

    def test_non_service_process_runs_normally(self):
        result = self.dispatch('/user.slice/user-1000.slice')
        self.assertIn('RETURN=2', result.stdout)
        self.assertFalse((self.root / 'call.txt').exists())

    def test_foreign_checkout_does_not_handoff(self):
        self.env['FM_TEST_PROJECT'] = str(self.root)
        result = self.dispatch('/system.slice/filemanager.service')
        self.assertIn('RETURN=2', result.stdout)
        self.assertFalse((self.root / 'call.txt').exists())

    def test_failure_cannot_fall_through_to_destructive_installer(self):
        result = self.dispatch('/system.slice/filemanager.service', overrides={'FM_TEST_FAIL': '1'})
        self.assertIn('RETURN=1', result.stdout)
        self.assertIn('serviço preservado', result.stderr)

    def test_detached_unit_does_not_relaunch_itself(self):
        result = self.dispatch('/system.slice/filemanager.service',
                               overrides={'FILEMANAGER_INSTALL_DETACHED': '1'})
        self.assertIn('RETURN=2', result.stdout)
        self.assertFalse((self.root / 'call.txt').exists())


if __name__ == '__main__':
    unittest.main(verbosity=2)
