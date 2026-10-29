#!/usr/bin/env python3
"""Run isolated regressions and existing blocking gates; never start the installation."""
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[2]
RESULTS = ROOT / 'docs/security/hardening-results'
RESULTS.mkdir(exist_ok=True)
PLAIN = os.environ.get('PHP_PLAIN', '/usr/bin/php8.3')
SWOOLE = os.environ.get('SECURITY_PHP_BINARY', 'php')
ENV = os.environ.copy()
checks = []
tools_directory = os.environ.get('SECURITY_TOOLS_DIRECTORY')
if tools_directory:
    ENV['PATH'] = os.pathsep.join([str(Path(tools_directory) / 'bin'), str(Path(tools_directory) / 'python/bin'), ENV['PATH']])
    ENV['PYTHONPATH'] = str(Path(tools_directory) / 'python')


def run(name, command, extra=None, accepted=(0,)):
    started = time.monotonic()
    output = RESULTS / (name + '.txt')
    try:
        with output.open('w') as log:
            result = subprocess.run(command, cwd=ROOT, env={**ENV, **(extra or {})}, stdout=log, stderr=subprocess.STDOUT, timeout=240)
        status = result.returncode
    except (OSError, subprocess.TimeoutExpired) as error:
        output.write_text(type(error).__name__ + '\n')
        status = 127
    checks.append(dict(name=name, command=command, exitCode=status, accepted=list(accepted), passed=status in accepted, seconds=round(time.monotonic()-started, 2), log=output.name))
    (RESULTS / 'summary.json').write_text(json.dumps(checks, indent=2) + '\n')
    print(('PASS ' if status in accepted else 'BLOCKED ') + name + ' (exit ' + str(status) + ')', flush=True)
    return status


run('php-syntax', ['python3', 'scripts/security/php-lint.py'])
run('phpstan', [PLAIN, 'vendor/bin/phpstan', 'analyse', '--no-progress', '--memory-limit=1G'])
for name, script, flags in [
    ('autoload', 'autoload-regression.php', ['-n']), ('http', 'http-regression.php', ['-n']), ('websocket', 'ws-regression.php', ['-n']),
    ('metadata', 'metadata-regression.php', []), ('tokens', 'tokens-regression.php', []), ('token-manager', 'token-manager-regression.php', []),
    ('git-publication', 'git-publication-regression.php', []), ('parser', 'parser-regression.php', []),
    ('process', 'process-regression.php', ['-n']), ('temporaries', 'temporary-regression.php', ['-n']),
]:
    run(name, [PLAIN, *flags, 'scripts/security/' + script])
run('filesystem', ['python3', 'scripts/security/fs-regression.py'])
run('auxiliary', ['python3', 'scripts/security/auxiliary-regression.py'])
run('auxiliary-quotas', [SWOOLE, 'scripts/security/auxiliary-quotas.php'])
run('downloads', ['python3', 'scripts/security/download-regression.py'])
run('archives', [SWOOLE, 'scripts/security/archive-regression.php'])
run('file-watcher', [PLAIN, 'scripts/tests/file-watcher-test.php'])
run('request-cache', [PLAIN, 'scripts/tests/request-cache-test.php'])
for name in ['install-codex-native', 'installer-home', 'install-tmp']:
    run(name, ['bash', 'scripts/tests/' + name + '-test.sh'])
for name, files in [
    ('ui', ['ui-regression.cjs', 'token-manager-ui.cjs']), ('threads', ['thread-regression.cjs']),
    ('codex', ['codex-integration.cjs']), ('transport-limits', ['auth-transport-regression.cjs', 'limits-regression.cjs']),
    ('dependency-compatibility', ['dependency-regression.cjs']), ('npm-policy-regression', ['npm-audit-policy.test.cjs']),
]:
    run(name, ['node', '--test', *['scripts/security/' + file for file in files]])
run('pty-node-php', ['node', '--test', 'scripts/security/pty-integration.cjs'], {'SECURITY_PTY_BACKEND': 'node,php', 'SECURITY_PHP_BINARY': SWOOLE})
run('swoole-http-websocket', ['node', '--test', 'scripts/security/swoole-integration.cjs'], {'SECURITY_PHP_BINARY': SWOOLE})
run('lsp-and-swoole-relay', ['node', '--test', 'scripts/security/lsp-integration.cjs'], {'SECURITY_PHP_BINARY': SWOOLE, 'SECURITY_LSP_SWOOLE': '1'})
run('composer-validate', ['composer', 'validate', '--no-check-publish'])
run('composer-audit', ['composer', 'audit', '--locked', '--format=json'])
audit_status = run('npm-live-audit', ['npm', 'audit', '--package-lock-only', '--json'], accepted=(0,1))
run('npm-live-policy', ['node', 'scripts/security/npm-audit-policy.cjs', str(RESULTS / 'npm-live-audit.txt'), 'docs/security/npm-audit-accepted-risks.json'], {'NPM_AUDIT_EXIT_CODE': str(audit_status)})
run('runtime-plain', [PLAIN, 'scripts/security/runtime-check.php'])
run('runtime-swoole', [SWOOLE, 'scripts/security/runtime-check.php', '--require-swoole'])
# Compile inline scripts without evaluating any page, command, network resource or user input.
failures = []
compiled = 0
for file in (ROOT / 'plugins/Request').rglob('*.html'):
    for index, match in enumerate(re.finditer(r'<script\b([^>]*)>(.*?)</script>', file.read_text(), re.S | re.I)):
        attributes, source = match[1], match[2]
        if 'src=' in attributes or 'application/json' in attributes or not source.strip():
            continue
        kind = 'module' if 'module' in attributes else 'commonjs'
        result = subprocess.run(['node', '--check', '--input-type=' + kind], input=source, text=True, capture_output=True, timeout=15)
        compiled += 1
        if result.returncode:
            failures.append(str(file.relative_to(ROOT)) + ':' + str(index))
(RESULTS / 'inline-js.txt').write_text(json.dumps(dict(compiled=compiled, failures=failures), indent=2) + '\n')
checks.append(dict(name='inline-js', exitCode=int(bool(failures)), passed=not failures, log='inline-js.txt'))
run('semgrep-regression', ['python3', 'scripts/security/semgrep-regression.py'])
run('semgrep', ['semgrep', 'scan', '--error', '--strict', '--metrics=off', '--disable-version-check', '--config', '.semgrep/security.yml', '--config', 'p/php', '--config', 'p/javascript', '--config', 'p/owasp-top-ten', '--exclude', 'vendor', '--exclude', 'node_modules', '--exclude', 'libspech', '--exclude', 'docs', '--exclude', 'scripts/performance', '--exclude', '*.sh', '--exclude', 'filemanagerctl'])
versioned = subprocess.check_output(['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z'], cwd=ROOT).decode().split('\0')
shell = [file for file in versioned if file.endswith('.sh') or file == 'filemanagerctl']
run('shellcheck', ['shellcheck', '--severity=warning', *shell])
run('actionlint', ['actionlint'])
run('gitleaks-regression', ['python3', 'scripts/security/gitleaks_regression.py'])
run('gitleaks-history', ['python3', 'scripts/security/gitleaks_scan.py'])
# Scan only the reviewable source tree. Never read ignored .env, databases or incident artifacts.
with tempfile.TemporaryDirectory(prefix='fm-reviewable-source-') as directory:
    for name in filter(None, versioned):
        source, destination = ROOT / name, Path(directory) / name
        if not source.is_file() or source.is_symlink():
            continue
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(source, destination)
    run('gitleaks-working-tree', ['gitleaks', 'dir', directory, '--redact=100', '--no-banner', '--config', str(ROOT / '.gitleaks.toml')])
run('diff-check', ['git', 'diff', '--check'])
(RESULTS / 'summary.json').write_text(json.dumps(checks, indent=2) + '\n')
failed = [check['name'] for check in checks if not check['passed']]
print(json.dumps({'checks': len(checks), 'passed': len(checks) - len(failed), 'blocked': failed}), flush=True)
raise SystemExit(bool(failed))
