#!/usr/bin/env python3
"""Exercise the real Gitleaks binary; keep credentials inside disposable fixtures."""
import json
import os
from pathlib import Path
import re
import secrets
import subprocess
import tempfile
import tomllib
import unittest

from gitleaks_scan import BASELINE, CONFIG, ROOT, scan, validate_policy


def git(root, *args, env=None, strip=True):
    result = subprocess.run(['git', *args], cwd=root, env=env,
                            capture_output=True, text=True, timeout=30)
    if result.returncode:
        raise RuntimeError('Fixture Git command failed; output suppressed')
    return result.stdout.strip() if strip else result.stdout


def commit(root, message='Synthetic regression fixture', env=None):
    git(root, 'add', '.', env=env)
    git(root, '-c', 'core.hooksPath=/dev/null', '-c', 'commit.gpgsign=false',
        'commit', '--quiet', '--allow-empty', '-m', message, env=env)
    return git(root, 'rev-parse', 'HEAD')


def repository(root):
    root.mkdir()
    git(root, 'init', '--quiet', '--initial-branch=main')
    git(root, 'config', 'user.name', 'Gitleaks regression')
    git(root, 'config', 'user.email', 'gitleaks@example.invalid')
    commit(root)
    return root


def write(root, name, content):
    path = root / name
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(content)


class GitleaksRegression(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='fm-gitleaks-test-')
        self.addCleanup(self.temp.cleanup)
        self.directory = Path(self.temp.name)

    def assert_blocked(self, root, rule, commit_id):
        status, findings = scan(root)
        self.assertEqual(status, 1)
        self.assertTrue(any(f['RuleID'] == rule and f['Commit'] == commit_id for f in findings),
                        'Expected rule and commit were not detected')
        return findings

    def test_six_historical_findings_match_official_baseline(self):
        validate_policy()
        summary = json.loads((ROOT / 'docs/security/gitleaks-summary.json').read_text())
        baseline = json.loads(BASELINE.read_text())
        expected = {f"{f['commit']}:{f['file']}:{f['rule']}:{f['line']}"
                    for f in summary['locations']}
        self.assertEqual(len(baseline), 6)
        self.assertEqual(len(expected), 6)
        self.assertEqual({f['Fingerprint'] for f in baseline}, expected)
        self.assertTrue(all(f['Secret'] == f['Match'] == 'REDACTED' for f in baseline))
        status, raw = scan(ROOT, baseline=None)
        self.assertEqual(status, 1)
        self.assertEqual(len(raw), 6)
        self.assertEqual({f['Fingerprint'] for f in raw}, expected)
        status, remaining = scan(ROOT)
        self.assertEqual((status, len(remaining)), (0, 0))

    def test_new_credentials_are_blocked_by_custom_and_default_rules(self):
        fixtures = [
            ('database/tokens.lotus', json.dumps({secrets.token_hex(32): {'nameClient': 'fixture'}}),
             'filemanager-token-store'),
            ('plugins/Extension/plugins/libCurl.php',
             '<?php // CURLOPT_PROXYUSERPWD => "fixture:' + secrets.token_hex(20) + '"',
             'filemanager-proxy-credential'),
            ('settings.txt', 'github_token = "' + 'ghp_' + secrets.token_hex(18) + '"',
             'github-pat'),
        ]
        for index, (name, content, rule) in enumerate(fixtures):
            with self.subTest(rule=rule):
                root = repository(self.directory / str(index))
                self.assertEqual(scan(root)[0], 0)
                write(root, name, content)
                self.assert_blocked(root, rule, commit(root))

    def test_baseline_contains_no_historical_credential(self):
        rules = {rule['id']: re.compile(rule['regex'])
                 for rule in tomllib.loads(CONFIG.read_text())['rules']}
        serialized = BASELINE.read_text()
        for finding in json.loads(serialized):
            blob = git(ROOT, 'show', f"{finding['Commit']}:{finding['File']}", strip=False)
            matches = list(rules[finding['RuleID']].finditer(blob))
            self.assertTrue(matches, 'Historical credential must be exercised')
            self.assertTrue(all(match.group(1) not in serialized for match in matches),
                            'Baseline must not contain historical credentials in any field')

    def test_same_baselined_secret_is_blocked_in_a_later_commit(self):
        # Use synthetic secrets even in disposable commits. The real six-entry
        # baseline is checked separately against the unchanged project history.
        root = repository(self.directory / 'reintroduction')
        files = {
            'database/tokens.lotus': json.dumps({secrets.token_hex(32): {'nameClient': 'fixture'}}),
            'plugins/Extension/plugins/libCurl.php':
                '<?php // CURLOPT_PROXYUSERPWD => "fixture:' + secrets.token_hex(20) + '"',
            'settings.txt': 'github_token = "' + 'ghp_' + secrets.token_hex(18) + '"',
        }
        for name, content in files.items():
            write(root, name, content)
        first_commit = commit(root)
        status, historical = scan(root, baseline=None)
        self.assertEqual((status, len(historical)), (1, 3))
        self.assertEqual({f['RuleID'] for f in historical},
                         {'filemanager-token-store', 'filemanager-proxy-credential', 'github-pat'})
        baseline = self.directory / 'synthetic-baseline.json'
        baseline.write_text(json.dumps(historical))
        self.assertEqual(scan(root, baseline=baseline), (0, []))
        for name in files:
            (root / name).unlink()
        commit(root, 'Remove synthetic credentials')
        for name, content in files.items():
            write(root, name, content)
        metadata = historical[0]
        env = dict(os.environ, GIT_AUTHOR_NAME=metadata['Author'],
                   GIT_AUTHOR_EMAIL=metadata['Email'], GIT_AUTHOR_DATE=metadata['Date'])
        new_commit = commit(root, metadata['Message'], env=env)
        self.assertNotEqual(new_commit, first_commit)
        status, detected = scan(root, baseline=baseline)
        self.assertEqual((status, len(detected)), (1, 3))
        self.assertTrue(all(f['Commit'] == new_commit for f in detected))
        for finding in historical:
            self.assertTrue(any(
                all(f[key] == finding[key] for key in (
                    'RuleID', 'Description', 'File', 'StartLine', 'EndLine',
                    'StartColumn', 'EndColumn', 'Entropy', 'Author', 'Email', 'Date', 'Message',
                    'Secret', 'Match',
                )) for f in detected
            ), 'Same synthetic secret must retain baseline metadata except commit')

    def test_rule_changes_require_explicit_review(self):
        original = CONFIG.read_text()
        mutations = {
            'default rules disabled': original.replace('useDefault = true', 'useDefault = false'),
            'token regex narrowed': original.replace('{24,128}', '{128}'),
            'proxy rule removed': original[:original.index('[[rules]]', original.index('[[rules]]') + 1)],
            'description changed': original.replace('Authentication token', 'Legacy token'),
            'generic exception added': original + '\n[allowlist]\npaths = ["database/.*"]\n',
        }
        changed = self.directory / 'changed.toml'
        for label, text in mutations.items():
            with self.subTest(change=label):
                changed.write_text(text)
                with self.assertRaises(RuntimeError):
                    validate_policy(config=changed)

    def test_description_drift_is_not_silently_baselined(self):
        changed = self.directory / 'changed.toml'
        changed.write_text(CONFIG.read_text().replace('Authentication token', 'Legacy token'))
        status, findings = scan(ROOT, config=changed)
        self.assertEqual((status, len(findings)), (1, 5))
        self.assertTrue(all(f['RuleID'] == 'filemanager-token-store' for f in findings))

    def test_baseline_cannot_expand_or_hide_payloads_without_review(self):
        baseline = json.loads(BASELINE.read_text())
        changed = self.directory / 'baseline.json'
        for candidate in ([], baseline + [baseline[0]], [dict(f, Match='extra payload') for f in baseline]):
            changed.write_text(json.dumps(candidate))
            with self.assertRaises(RuntimeError):
                validate_policy(baseline=changed)

    def test_deleted_secret_in_old_commit_and_other_branch_is_still_blocked(self):
        root = repository(self.directory / 'history')
        initial = git(root, 'rev-parse', 'HEAD')
        name = 'database/tokens.lotus'
        write(root, name, json.dumps({secrets.token_hex(32): {'nameClient': 'fixture'}}))
        leaked = commit(root)
        (root / name).unlink()
        commit(root, 'Remove fixture')
        for _ in range(4):
            commit(root, 'Later clean commit')
        self.assert_blocked(root, 'filemanager-token-store', leaked)
        git(root, 'branch', 'historical-secret')
        git(root, 'switch', '--quiet', '-c', 'clean-branch', initial)
        self.assert_blocked(root, 'filemanager-token-store', leaked)

    def test_shallow_checkout_is_rejected(self):
        root = repository(self.directory / 'history')
        commit(root)
        shallow = self.directory / 'shallow'
        git(self.directory, 'clone', '--quiet', '--depth=1', root.as_uri(), str(shallow))
        with self.assertRaises(RuntimeError):
            scan(shallow)

    def test_workflow_runs_regressions_and_guarded_scan_with_full_checkout(self):
        workflow = (ROOT / '.github/workflows/security.yml').read_text()
        tooling = workflow.split('\n  tooling:\n')[1]
        self.assertIn('fetch-depth: 0', tooling)
        self.assertIn('go install github.com/zricethezav/gitleaks/v8@v8.28.0', tooling)
        self.assertIn('run: python3 scripts/security/gitleaks_regression.py', tooling)
        self.assertIn('run: python3 scripts/security/gitleaks_scan.py', tooling)


if __name__ == '__main__':
    unittest.main(verbosity=2)
