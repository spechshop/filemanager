#!/usr/bin/env python3
"""Lint the versioned source, never vendor, mutable files/ or local nested checkouts."""
import os
import subprocess
import sys

files = subprocess.check_output(['git', 'ls-files', '-z', '*.php']).decode().split('\0')
failed = []
for file in filter(None, files):
    if not os.path.isfile(file):
        continue
    result = subprocess.run(['php', '-n', '-l', os.path.abspath(file)], capture_output=True, text=True, timeout=15)
    if result.returncode:
        failed.append(result.stdout + result.stderr)
print('\n'.join(failed) if failed else f'PHP syntax: {sum(bool(f) for f in files)} tracked files checked')
sys.exit(bool(failed))
