#!/usr/bin/env python3
"""Run the official baseline scan with reviewed policy and complete Git history."""
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[2]
CONFIG = ROOT / '.gitleaks.toml'
BASELINE = ROOT / '.gitleaks-baseline.json'
# Deliberate policy changes require review and rerunning the regression suite.
# Also protect files that Gitleaks itself skips (its config and baseline).
CONFIG_SHA256 = '76adb2015bb09618d6fa0371b175f4209a08fa1ad62bf659f488ef756b797279'
BASELINE_SHA256 = 'f8095f9731874c344361cc233fe7eb34ee538f95ef2972718a35320d85c1eb9c'
LOG_OPTS = '--all --full-history --diff-filter=tuxdb'


def validate_policy(config=CONFIG, baseline=BASELINE):
    for path, digest in ((config, CONFIG_SHA256), (baseline, BASELINE_SHA256)):
        if hashlib.sha256(path.read_bytes()).hexdigest() != digest:
            raise RuntimeError('Gitleaks policy changed; explicit review and regression validation required')


def scan(source, config=CONFIG, baseline=BASELINE):
    shallow = subprocess.run(
        ['git', 'rev-parse', '--is-shallow-repository'], cwd=source,
        capture_output=True, text=True, timeout=30,
    )
    if shallow.returncode != 0 or shallow.stdout.strip() != 'false':
        raise RuntimeError('A complete Git checkout is required (fetch-depth: 0)')
    with tempfile.TemporaryDirectory(prefix='fm-gitleaks-report-') as directory:
        report = Path(directory) / 'redacted.json'
        command = [
            'gitleaks', 'git', '--redact=100', '--no-banner',
            '--config', str(config), f'--log-opts={LOG_OPTS}',
            '--report-format=json', '--report-path', str(report),
        ]
        if baseline is not None:
            command += ['--baseline-path', str(baseline)]
        result = subprocess.run(command, cwd=source, capture_output=True, timeout=120)
        # Never relay subprocess output: even commit messages may contain secrets.
        if result.returncode not in (0, 1) or not report.is_file():
            raise RuntimeError('Gitleaks failed to produce a report')
        findings = json.loads(report.read_text())
        if not isinstance(findings, list) or result.returncode != int(bool(findings)):
            raise RuntimeError('Gitleaks exit status and report disagree')
        if any(f.get('Secret') != 'REDACTED' for f in findings):
            raise RuntimeError('Gitleaks report is not fully redacted')
        return result.returncode, findings


def main():
    try:
        validate_policy()
        status, findings = scan(ROOT)
        print(f'Gitleaks full-history scan: {len(findings)} findings outside the reviewed baseline.')
        return status
    except (OSError, ValueError, RuntimeError, subprocess.SubprocessError):
        print('FAIL: Gitleaks could not validate the policy or complete the scan.', file=sys.stderr)
        return 2


if __name__ == '__main__':
    sys.exit(main())
