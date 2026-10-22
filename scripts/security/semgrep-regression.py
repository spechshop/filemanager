#!/usr/bin/env python3
"""Prove the custom rules detect regressions, including the required auth guard."""
import json
from pathlib import Path
import subprocess
import tempfile

configuration = Path(__file__).resolve().parents[2] / '.semgrep/security.yml'
with tempfile.TemporaryDirectory(prefix='fm-semgrep-') as directory:
    root = Path(directory)
    sources = {
        'plugins/Request/apps/missing.php': '<?php namespace plugins\\Request; class Missing { public static function api($request, $response) { doWork(); } }',
        'plugins/Request/apps/good.php': '<?php namespace plugins\\Request; class Good { public static function api($request, $response) { if (!security::authorize($request, $response)) return true; doWork(); } }',
        'plugins/Request/apps/fallthrough.php': '<?php namespace plugins\\Request; class Bad { public static function api($request, $response) { security::verifyToken($request) ?: security::invalidToken($response); doWork(); } }',
        'plugins/Request/modules/unsafe.html': '<script>node.innerHTML = suggestionText;</script>',
        'js/unsafe.js': 'eval(request.body);',
    }
    for name, text in sources.items():
        file = root / name
        file.parent.mkdir(parents=True, exist_ok=True)
        file.write_text(text)
    result = subprocess.run(['semgrep', 'scan', '--config', str(configuration), '--json', '--metrics=off', '--disable-version-check', '.'], cwd=root, capture_output=True, text=True, timeout=60)
    if result.returncode:
        raise RuntimeError(result.stderr)
    report = json.loads(result.stdout)
    if report.get('errors'):
        raise RuntimeError(str(report['errors']))
    detected = {(item['path'], item['check_id'].split('.')[-1]) for item in report['results']}
    expected = {
        ('plugins/Request/apps/missing.php', 'filemanager-http-guard-required'),
        ('plugins/Request/apps/fallthrough.php', 'filemanager-http-guard-required'),
        ('plugins/Request/apps/fallthrough.php', 'filemanager-auth-fallthrough'),
        ('plugins/Request/modules/unsafe.html', 'filemanager-browser-html-data'),
        ('js/unsafe.js', 'filemanager-javascript-eval'),
    }
    if detected != expected:
        raise RuntimeError(f'Expected {expected}, got {detected}')
    print('PASS: all four custom rules detect unsafe fixtures; the authorized handler stays clean.')
