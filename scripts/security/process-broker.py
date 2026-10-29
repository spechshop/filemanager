#!/usr/bin/env python3
"""Unprivileged supervisor for finite local tools; fixed argv comes from PHP.

No network listener and no shell. A private process group binds descendants to
the authorization/timeout lifecycle, including after the direct child exits.
"""
import base64
import ctypes
import json
import os
import selectors
import signal
import subprocess
import sys
import time


def authorized(job):
    try:
        with open(job['tokenFile'], encoding='utf-8') as stream:
            entry = json.load(stream).get(job['token'])
        if not isinstance(entry, dict) or entry.get('revoked'):
            return False
        expiry = entry.get('expire')
        if isinstance(expiry, bool) or not (isinstance(expiry, int) or
                isinstance(expiry, str) and expiry.isascii() and expiry.isdigit() and len(expiry) <= 16):
            return False
        expiry = int(expiry)
        if (expiry // 1000 if expiry > 9999999999 else expiry) <= time.time():
            return False
        admin = entry.get('admin', 'allowedRoots' not in entry and entry.get('terminal', True) is True)
        return not job['admin'] or admin is True
    except (OSError, ValueError, TypeError, AttributeError):
        return False


def stop_group(process):
    # The direct child stays unreaped until its entire group has been signalled.
    try:
        os.killpg(process.pid, signal.SIGTERM)
    except ProcessLookupError:
        pass
    deadline = time.monotonic() + 0.2
    while time.monotonic() < deadline:
        try:
            os.killpg(process.pid, 0)
        except ProcessLookupError:
            break
        time.sleep(0.01)
    try:
        os.killpg(process.pid, signal.SIGKILL)
    except ProcessLookupError:
        pass


def run(job):
    if not authorized(job):
        raise PermissionError('revoked')
    maximum = int(job['bytes'])
    deadline = time.monotonic() + float(job['seconds'])
    if maximum <= 0 or deadline <= time.monotonic():
        raise ValueError('limits')
    output = bytearray()
    with subprocess.Popen(job['argv'], stdin=subprocess.DEVNULL,
                          stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                          cwd=job.get('cwd'), env=job.get('env'),
                          start_new_session=True) as process:
        try:
            os.set_blocking(process.stdout.fileno(), False)
            with selectors.DefaultSelector() as selector:
                selector.register(process.stdout, selectors.EVENT_READ)
                while True:
                    if not authorized(job):
                        raise PermissionError('revoked')
                    if time.monotonic() >= deadline:
                        raise TimeoutError('tool timeout')
                    # WNOWAIT retains the PID, preventing group-ID reuse at cleanup.
                    exited = os.waitid(os.P_PID, process.pid, os.WEXITED | os.WNOHANG | os.WNOWAIT)
                    events = selector.select(0.05)
                    for _, _mask in events:
                        block = os.read(process.stdout.fileno(), min(65536, maximum + 1 - len(output)))
                        output.extend(block)
                        if len(output) > maximum:
                            raise ValueError('output limit')
                        if not block:
                            selector.unregister(process.stdout)
                    if exited is not None and not events:
                        break
                    if exited is not None and not selector.get_map():
                        break
            if not authorized(job):
                raise PermissionError('revoked')
        finally:
            stop_group(process)
            status = process.wait()
            reap_children()
    return {'ok': True, 'exit': status, 'output': base64.b64encode(output).decode('ascii')}


def reap_children():
    # Linux subreaper owns orphaned grandchildren; do not leave zombies to PID 1.
    reap_deadline = time.monotonic() + 0.5
    while time.monotonic() < reap_deadline:
        try:
            child, _ = os.waitpid(-1, os.WNOHANG)
        except ChildProcessError:
            break
        if child == 0:
            time.sleep(0.01)


class Cancelled(Exception):
    pass


def interrupted(_number, _frame):
    raise Cancelled('supervisor stopped')


def main():
    signal.signal(signal.SIGTERM, interrupted)
    signal.signal(signal.SIGINT, interrupted)
    parent = os.getppid()
    # Worker exit also cancels tools, without relying on PHP finally blocks.
    libc = ctypes.CDLL(None, use_errno=True)
    if libc.prctl(1, signal.SIGTERM, 0, 0, 0) != 0 or libc.prctl(36, 1, 0, 0, 0) != 0:
        return 1
    if os.getppid() != parent:
        return 1
    try:
        job = json.loads(sys.stdin.buffer.readline(2 * 1024 * 1024))
        result = run(job)
    except (OSError, ValueError, TypeError, KeyError, PermissionError, TimeoutError, Cancelled):
        result = {'ok': False}
    print(json.dumps(result), flush=True)
    return 0 if result['ok'] else 1


if __name__ == '__main__':
    sys.exit(main())
