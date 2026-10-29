#!/usr/bin/env python3
"""Local, unprivileged descriptor IO. stdin: one JSON line then optional bytes.
stdout: one JSON line then optional bytes. Never accepts code or shell commands.
Linux openat2 deliberately has no pathname-based fallback.
"""
import ctypes
import fcntl
import hashlib
import re
import secrets
import errno
import json
import os
import stat
import sys
import time

BENEATH = 0x08
NO_MAGICLINKS = 0x02
NO_SYMLINKS = 0x04


class How(ctypes.Structure):
    _fields_ = [('flags', ctypes.c_ulonglong), ('mode', ctypes.c_ulonglong), ('resolve', ctypes.c_ulonglong)]


def openat2(fd, name, flags, mode=0, resolve=BENEATH | NO_MAGICLINKS):
    # SYS_openat2 is 437 on the supported x86_64/aarch64 Linux deployments.
    if os.uname().machine not in ('x86_64', 'aarch64'):
        raise PermissionError('unsupported secure filesystem architecture')
    libc = ctypes.CDLL(None, use_errno=True)
    how = How(flags | os.O_CLOEXEC, mode, resolve)
    result = libc.syscall(437, fd, os.fsencode(name), ctypes.byref(how), ctypes.sizeof(how))
    if result < 0:
        raise OSError(ctypes.get_errno(), 'secure filesystem resolution failed')
    return result


def within(path, root):
    return path == root or path.startswith(root.rstrip('/') + '/')


def expiration(value):
    if isinstance(value, bool) or not (isinstance(value, int) or (isinstance(value, str) and value.isascii() and value.isdigit() and len(value) <= 16)):
        return 0
    value = int(value)
    return value // 1000 if value > 9999999999 else value


class Files:
    def __init__(self, request):
        self.request = request
        self.token = request['token']
        self.token_file = request['tokenFile']
        self.base = request['base']
        self.roots = None
        self.fds = []
        self.authorize()
        self.rootfds = []
        slash = os.open('/', os.O_PATH | os.O_DIRECTORY | os.O_CLOEXEC)
        try:
            for root in self.roots:
                fd = openat2(slash, root.lstrip('/') or '.', os.O_PATH | os.O_DIRECTORY,
                             resolve=BENEATH | NO_MAGICLINKS | NO_SYMLINKS)
                self.fds.append(fd)
                self.rootfds.append((root, fd))
        finally:
            os.close(slash)

    def release(self, fd):
        self.fds.remove(fd)
        os.close(fd)

    def authorize(self):
        with open(self.token_file, encoding='utf8') as source:
            record = json.load(source).get(self.token)
        if not isinstance(record, dict) or record.get('revoked') or expiration(record.get('expire')) <= time.time():
            raise PermissionError('authorization expired')
        full = record.get('admin', 'allowedRoots' not in record and record.get('terminal', True) is True) is True
        scope = record['allowedRoots'] if 'allowedRoots' in record else (['/'] if full else self.request.get('fallbackRoots', []))
        if not isinstance(scope, list) or not scope or any(not isinstance(p, str) or not p.startswith('/') for p in scope):
            raise PermissionError('invalid filesystem scope')
        roots = sorted(set(os.path.realpath(p) for p in scope))
        if self.roots is not None and roots != self.roots:
            raise PermissionError('filesystem scope changed')
        self.roots = roots

    def normalize(self, path):
        if not isinstance(path, str) or not path or '://' in path or '\\' in path or any(ord(c) < 32 or ord(c) == 127 for c in path):
            raise PermissionError('invalid path')
        return os.path.normpath(path if path.startswith('/') else os.path.join(self.base, path))

    def locate(self, path, follow=True):
        self.authorize()
        path = self.normalize(path)
        # Canonicalization permits legitimate absolute links between allowed roots.
        # It is not the IO guard: openat2 below binds resolution to an open root.
        path = os.path.realpath(path) if follow else os.path.join(os.path.realpath(os.path.dirname(path)), os.path.basename(path))
        trash = self.request.get('trashDirectory')
        if trash and within(path, os.path.normpath(trash)):
            raise PermissionError('private recovery storage')
        for root, fd in sorted(self.rootfds, key=lambda item: -len(item[0])):
            if within(path, root):
                return root, fd, os.path.relpath(path, root), path
        raise PermissionError('path outside authorized roots')

    def open(self, path, flags=os.O_RDONLY, mode=0):
        _, rootfd, relative, _ = self.locate(path)
        fd = openat2(rootfd, relative, flags | os.O_NONBLOCK, mode)
        self.fds.append(fd)
        info = os.fstat(fd)
        if not (stat.S_ISREG(info.st_mode) or stat.S_ISDIR(info.st_mode)):
            raise PermissionError('unsupported file type')
        self.authorize()
        return fd

    def open_upload(self, path):
        directory = self.normalize(self.request['uploadDirectory'])
        path = self.normalize(path)
        if os.path.dirname(path) != directory:
            raise PermissionError('invalid upload provenance')
        slash = os.open('/', os.O_PATH | os.O_DIRECTORY)
        try:
            root = openat2(slash, directory.lstrip('/'), os.O_PATH | os.O_DIRECTORY, resolve=BENEATH | NO_MAGICLINKS | NO_SYMLINKS)
            self.fds.append(root)
        finally:
            os.close(slash)
        fd = openat2(root, os.path.basename(path), os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
        self.fds.append(fd)
        if not stat.S_ISREG(os.fstat(fd).st_mode):
            raise PermissionError('invalid upload type')
        self.authorize()
        return fd

    def parent(self, path):
        root, rootfd, relative, _ = self.locate(path, follow=False)
        if relative == '.' or self.normalize(path) in self.roots:
            raise PermissionError('cannot modify authorized root')
        fd = openat2(rootfd, os.path.dirname(relative) or '.', os.O_PATH | os.O_DIRECTORY)
        self.fds.append(fd)
        return fd, os.path.basename(relative)

    def mkdir(self, path):
        fd, name = self.parent(path)
        self.authorize()
        os.mkdir(name, 0o750, dir_fd=fd)

    def write(self, path, source):
        # Open without O_TRUNC first. No path is looked up again after authorization.
        fd = self.open(path, os.O_WRONLY | os.O_CREAT, 0o640)
        self.authorize()
        os.ftruncate(fd, 0)
        while True:
            block = source.read(262144)
            if not block:
                break
            self.authorize()
            view = memoryview(block)
            while view:
                view = view[os.write(fd, view):]

    def directory_size(self, fd, deadline, seen=None):
        if time.monotonic() > deadline:
            return None
        self.authorize()
        info = os.fstat(fd)
        seen = set() if seen is None else seen
        identity = (info.st_dev, info.st_ino)
        if identity in seen or len(seen) >= 128:
            return 0
        seen = seen | {identity}
        size = info.st_size
        for name in os.listdir(fd):
            if time.monotonic() > deadline:
                return None
            item = os.stat(name, dir_fd=fd, follow_symlinks=False)
            if stat.S_ISDIR(item.st_mode):
                child = openat2(fd, name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
                try:
                    amount = self.directory_size(child, deadline, seen)
                finally:
                    os.close(child)
                if amount is None:
                    return None
                size += amount
            else:
                # du-compatible metadata: count link bytes, never follow links.
                size += item.st_size
        return size

    def entries(self, path):
        fd = self.open(path, os.O_RDONLY | os.O_DIRECTORY)
        result = []
        deadline = time.monotonic() + self.request.get('directoryStatsSeconds', 0)
        for name in os.listdir(fd):
            try:
                item = os.stat(name, dir_fd=fd, follow_symlinks=False)
                link = stat.S_ISLNK(item.st_mode)
                if link:
                    # Metadata through a separately confined open, never unguarded stat.
                    child = self.open(os.path.join(path, name))
                    item = os.fstat(child)
                    self.release(child)
                if not (stat.S_ISREG(item.st_mode) or stat.S_ISDIR(item.st_mode)):
                    continue
                count = None
                size = item.st_size
                if stat.S_ISDIR(item.st_mode) and time.monotonic() <= deadline:
                    child = None
                    try:
                        child = self.open(os.path.join(path, name), os.O_RDONLY | os.O_DIRECTORY) if link else openat2(fd, name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
                        if link:
                            self.fds.remove(child)
                        count = len(os.listdir(child))
                        size = self.directory_size(child, deadline)
                    except OSError:
                        size = None
                    finally:
                        if child is not None:
                            os.close(child)
                result.append(dict(name=name, count=count, path=os.path.join(path.rstrip('/') or '/', name),
                                   directory=stat.S_ISDIR(item.st_mode), link=link, size=size,
                                   mtime=item.st_mtime, atime=item.st_atime, ctime=item.st_ctime,
                                   permissions=stat.filemode(item.st_mode)))
            except (OSError, ValueError):
                # Unreadable/broken/escaping individual links do not break navigation.
                result.append(dict(name=name, path=os.path.join(path, name), denied=True))
        return result

    def remove(self, path, seen=None):
        parent, name = self.parent(path)
        self.authorize()
        item = os.stat(name, dir_fd=parent, follow_symlinks=False)
        if stat.S_ISDIR(item.st_mode):
            fd = openat2(parent, name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
            self.fds.append(fd)
            for child in os.listdir(fd):
                # Descend using this descriptor, not the original mutable pathname.
                self.remove_at(fd, child)
            self.authorize()
            os.rmdir(name, dir_fd=parent)
        else:
            os.unlink(name, dir_fd=parent)

    def remove_at(self, parent, name):
        self.authorize()
        item = os.stat(name, dir_fd=parent, follow_symlinks=False)
        if stat.S_ISDIR(item.st_mode):
            fd = openat2(parent, name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
            try:
                for child in os.listdir(fd):
                    self.remove_at(fd, child)
            finally:
                os.close(fd)
            self.authorize()
            os.rmdir(name, dir_fd=parent)
        else:
            os.unlink(name, dir_fd=parent)

    def open_target(self, target, flags, mode=0):
        try:
            link = stat.S_ISLNK(os.stat(target[1], dir_fd=target[0], follow_symlinks=False).st_mode)
        except FileNotFoundError:
            link = False
        if link:
            fd = self.open(os.path.realpath('/proc/self/fd/' + str(target[0]) + '/' + target[1]), flags, mode)
            self.fds.remove(fd)
            return fd
        return openat2(target[0], target[1], flags | os.O_NOFOLLOW | os.O_NONBLOCK, mode)

    def copy_at(self, source, target, seen):
        self.authorize()
        info = os.fstat(source)
        if stat.S_ISDIR(info.st_mode):
            identity = (info.st_dev, info.st_ino)
            if identity in seen or len(seen) >= 128:
                raise PermissionError('recursive link')
            seen = seen | {identity}
            try:
                os.mkdir(target[1], 0o750, dir_fd=target[0])
            except FileExistsError:
                pass
            out = self.open_target(target, os.O_RDONLY | os.O_DIRECTORY)
            try:
                if within(os.readlink('/proc/self/fd/' + str(out)), os.readlink('/proc/self/fd/' + str(source))):
                    raise PermissionError('recursive destination')
                for name in os.listdir(source):
                    # Links in recursive copy cannot safely establish a new root;
                    # copy the link itself only when explicitly supported by policy.
                    if stat.S_ISLNK(os.stat(name, dir_fd=source, follow_symlinks=False).st_mode):
                        child = self.open(os.path.realpath('/proc/self/fd/' + str(source) + '/' + name))
                        self.fds.remove(child)
                    else:
                        child = openat2(source, name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
                    try:
                        self.copy_at(child, (out, name), seen)
                    finally:
                        os.close(child)
            finally:
                os.close(out)
        elif stat.S_ISREG(info.st_mode):
            out = self.open_target(target, os.O_WRONLY | os.O_CREAT, 0o640)
            try:
                if not stat.S_ISREG(os.fstat(out).st_mode):
                    raise PermissionError('unsupported destination')
                if (os.fstat(out).st_dev, os.fstat(out).st_ino) == (info.st_dev, info.st_ino):
                    raise PermissionError('source and destination are the same object')
                self.authorize()
                os.ftruncate(out, 0)
                while True:
                    block = os.read(source, 262144)
                    if not block:
                        break
                    self.authorize()
                    view = memoryview(block)
                    while view:
                        view = view[os.write(out, view):]
            finally:
                os.close(out)
        else:
            raise PermissionError('unsupported copy type')

    def copy(self, source, target):
        if within(self.locate(target)[3], self.locate(source)[3]):
            raise PermissionError('recursive copy')
        self.copy_at(self.open(source), self.parent(target), set())

    def move(self, source, target):
        src, name = self.parent(source)
        dst, new = self.parent(target)
        self.authorize()
        try:
            os.rename(name, new, src_dir_fd=src, dst_dir_fd=dst)
        except OSError as error:
            if error.errno != errno.EXDEV:
                raise
            self.copy(source, target)
            self.remove(source)

    def close(self):
        for fd in reversed(self.fds):
            os.close(fd)



class TrashError(Exception):
    def __init__(self, code):
        self.code = code


def rename_exclusive(source, name, target, new):
    libc = ctypes.CDLL(None, use_errno=True)
    if libc.renameat2(source, os.fsencode(name), target, os.fsencode(new), 1) != 0:
        raise OSError(ctypes.get_errno(), 'exclusive move failed')


class Trash:
    """Private per-owner recovery records; user paths always pass Files confinement."""
    def __init__(self, files):
        self.files = files
        self.fds = []
        path = files.normalize(files.request['trashDirectory'])
        slash = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
        self.fds.append(slash)
        parent = slash
        parts = path.strip('/').split('/')
        for part in parts:
            try:
                os.mkdir(part, 0o700, dir_fd=parent)
            except FileExistsError:
                pass
            parent = openat2(parent, part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW,
                             resolve=BENEATH | NO_MAGICLINKS | NO_SYMLINKS)
            self.fds.append(parent)
        self.storage_path = path
        info = os.fstat(parent)
        if info.st_uid != os.geteuid() or info.st_mode & 0o077:
            raise PermissionError('trash storage must be private')
        owner = hashlib.sha256(files.token.encode()).hexdigest()
        try:
            os.mkdir(owner, 0o700, dir_fd=parent)
        except FileExistsError:
            pass
        self.owner = openat2(parent, owner, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        self.fds.append(self.owner)
        owner_info = os.fstat(self.owner)
        if owner_info.st_uid != os.geteuid() or owner_info.st_mode & 0o077:
            raise PermissionError('trash owner storage must be private')
        self.lock = openat2(self.owner, '.lock', os.O_WRONLY | os.O_CREAT | os.O_NOFOLLOW, 0o600)
        self.fds.append(self.lock)
        deadline = time.monotonic() + 5
        while True:
            try:
                fcntl.flock(self.lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                break
            except BlockingIOError:
                if time.monotonic() > deadline:
                    raise TrashError('busy')
                time.sleep(0.01)
        files.authorize()

    def close(self):
        for fd in reversed(self.fds):
            os.close(fd)
        self.fds.clear()

    def item(self, identifier):
        if not isinstance(identifier, str) or re.fullmatch('[a-f0-9]{32}', identifier) is None:
            raise TrashError('not_found')
        try:
            fd = openat2(self.owner, identifier, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        except FileNotFoundError:
            raise TrashError('not_found')
        self.fds.append(fd)
        meta = openat2(fd, 'metadata.json', os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
        try:
            if not stat.S_ISREG(os.fstat(meta).st_mode) or os.fstat(meta).st_size > 65536:
                raise PermissionError('invalid recovery record')
            data = json.loads(os.read(meta, 65537))
        finally:
            os.close(meta)
        if not isinstance(data, dict) or data.get('id') != identifier or not isinstance(data.get('originalPath'), str) or not isinstance(data.get('deletedAt'), int):
            raise PermissionError('invalid recovery record')
        self.files.locate(data['originalPath'], follow=False)
        return fd, data

    def list(self):
        entries = []
        for identifier in os.listdir(self.owner):
            if re.fullmatch('[a-f0-9]{32}', identifier) is None:
                continue
            fd = None
            try:
                fd, data = self.item(identifier)
                os.stat('contents', dir_fd=fd, follow_symlinks=False)
                entries.append(data)
            except (OSError, ValueError, TrashError):
                # Preserve incomplete records for recovery; never erase evidence.
                continue
            finally:
                if fd is not None:
                    self.fds.remove(fd)
                    os.close(fd)
        return sorted(entries, key=lambda entry: entry['deletedAt'], reverse=True)

    def copy_entry(self, source, name, target, new, depth=0):
        self.files.authorize()
        if depth > 128:
            raise PermissionError('directory nesting limit')
        info = os.stat(name, dir_fd=source, follow_symlinks=False)
        if stat.S_ISLNK(info.st_mode):
            os.symlink(os.readlink(name, dir_fd=source), new, dir_fd=target)
        elif stat.S_ISDIR(info.st_mode):
            src = openat2(source, name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
            os.mkdir(new, 0o700, dir_fd=target)
            dst = openat2(target, new, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
            try:
                for child in os.listdir(src):
                    self.copy_entry(src, child, dst, child, depth + 1)
                os.fchmod(dst, stat.S_IMODE(info.st_mode))
                os.utime(dst, ns=(info.st_atime_ns, info.st_mtime_ns))
                os.fsync(dst)
            finally:
                os.close(src)
                os.close(dst)
        elif stat.S_ISREG(info.st_mode):
            src = openat2(source, name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
            dst = openat2(target, new, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
            try:
                if not stat.S_ISREG(os.fstat(src).st_mode):
                    raise PermissionError('unsupported trash type')
                while True:
                    self.files.authorize()
                    block = os.read(src, 262144)
                    if not block:
                        break
                    view = memoryview(block)
                    while view:
                        view = view[os.write(dst, view):]
                if os.fstat(src).st_mtime_ns != info.st_mtime_ns:
                    raise OSError('source changed during copy')
                os.fchmod(dst, stat.S_IMODE(info.st_mode))
                os.utime(dst, ns=(info.st_atime_ns, info.st_mtime_ns))
                os.fsync(dst)
            finally:
                os.close(src)
                os.close(dst)
        else:
            raise PermissionError('unsupported trash type')

    def transfer(self, source, name, target, new):
        self.files.authorize()
        try:
            rename_exclusive(source, name, target, new)
            return
        except OSError as error:
            if error.errno == errno.EEXIST:
                raise TrashError('conflict')
            if error.errno != errno.EXDEV:
                raise
        # Stage on the source volume so a pathname replacement cannot be removed.
        stage = '.fm-trash-move-' + secrets.token_hex(16)
        rename_exclusive(source, name, source, stage)
        temporary = '.pending-' + secrets.token_hex(16)
        try:
            self.copy_entry(source, stage, target, temporary)
            self.files.authorize()
            rename_exclusive(target, temporary, target, new)
        except Exception as error:
            try:
                self.files.remove_at(target, temporary)
            except OSError:
                pass
            try:
                rename_exclusive(source, stage, source, name)
            except OSError:
                raise TrashError('partial')
            if isinstance(error, OSError) and error.errno == errno.EEXIST:
                raise TrashError('conflict')
            raise
        try:
            self.files.remove_at(source, stage)
        except OSError:
            raise TrashError('partial')

    def put(self, path):
        parent, name = self.files.parent(path)
        original = self.files.locate(path, follow=False)[3]
        if within(self.storage_path, original) or within(original, self.storage_path):
            raise PermissionError('cannot trash recovery storage')
        info = os.stat(name, dir_fd=parent, follow_symlinks=False)
        if not (stat.S_ISREG(info.st_mode) or stat.S_ISDIR(info.st_mode) or stat.S_ISLNK(info.st_mode)):
            raise PermissionError('unsupported trash type')
        identifier = secrets.token_hex(16)
        os.mkdir(identifier, 0o700, dir_fd=self.owner)
        fd = openat2(self.owner, identifier, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        self.fds.append(fd)
        data = dict(id=identifier, name=name, originalPath=original, deletedAt=int(time.time()),
                    kind='link' if stat.S_ISLNK(info.st_mode) else 'folder' if stat.S_ISDIR(info.st_mode) else 'file',
                    size=None if stat.S_ISDIR(info.st_mode) else info.st_size)
        meta = openat2(fd, 'metadata.json', os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        with os.fdopen(meta, 'w', encoding='utf8') as output:
            json.dump(data, output)
            output.flush()
            os.fsync(output.fileno())
        os.fsync(fd)
        os.fsync(self.owner)
        self.transfer(parent, name, fd, 'contents')
        os.fsync(fd)
        return data

    def make_parents(self, path):
        try:
            fd = self.files.open(path, os.O_RDONLY | os.O_DIRECTORY)
            self.files.release(fd)
        except FileNotFoundError:
            self.make_parents(os.path.dirname(path))
            try:
                self.files.mkdir(path)
            except FileExistsError:
                pass

    def restore(self, identifier, rename=False):
        fd, data = self.item(identifier)
        original = data['originalPath']
        self.make_parents(os.path.dirname(original))
        parent, name = self.files.parent(original)
        os.stat('contents', dir_fd=fd, follow_symlinks=False)
        for attempt in range(1000):
            target = name if attempt == 0 else self.restored_name(name, attempt)
            try:
                self.transfer(fd, 'contents', parent, target)
                self.cleanup(identifier, fd)
                return dict(id=identifier, restoredPath=os.path.join(os.path.dirname(original), target))
            except TrashError as error:
                if error.code != 'conflict' or not rename:
                    raise
        raise TrashError('conflict')

    @staticmethod
    def restored_name(name, attempt):
        base, extension = os.path.splitext(name)
        suffix = ' (restaurado ' + str(attempt) + ')'
        limit = 255 - len((suffix + extension).encode())
        base = base.encode()[:max(1, limit)].decode('utf8', errors='ignore')
        return base + suffix + extension

    def cleanup(self, identifier, fd):
        os.unlink('metadata.json', dir_fd=fd)
        os.rmdir(identifier, dir_fd=self.owner)
        os.fsync(self.owner)

    def purge(self, identifier):
        fd, data = self.item(identifier)
        self.files.remove_at(fd, 'contents')
        self.cleanup(identifier, fd)
        return dict(id=identifier)



def reply(value):
    sys.stdout.buffer.write(json.dumps(value, ensure_ascii=True).encode() + b'\n')
    sys.stdout.buffer.flush()


def main():
    request = json.loads(sys.stdin.buffer.readline(1048576))
    files = None
    replied = False
    try:
        files = Files(request)
        op = request['op']
        path = request.get('path')
        if op in ('read', 'read-upload'):
            fd = files.open_upload(path) if op == 'read-upload' else files.open(path)
            info = os.fstat(fd)
            if not stat.S_ISREG(info.st_mode):
                raise PermissionError('not a regular file')
            offset = request.get('offset', 0)
            remaining = request.get('length')
            if not isinstance(offset, int) or offset < 0 or (remaining is not None and (not isinstance(remaining, int) or remaining < 0)):
                raise PermissionError('invalid range')
            os.lseek(fd, offset, os.SEEK_SET)
            reply({'ok': True, 'size': info.st_size})
            replied = True
            while True:
                files.authorize()
                block = os.read(fd, min(262144, remaining) if remaining is not None else 262144)
                if remaining is not None:
                    remaining -= len(block)
                if not block:
                    break
                sys.stdout.buffer.write(block)
            sys.stdout.buffer.flush()
        elif op == 'list':
            entries = files.entries(path)
            files.authorize()
            reply({'ok': True, 'entries': entries})
        elif op in ('trash-put', 'trash-list', 'trash-restore', 'trash-purge'):
            trash = Trash.__new__(Trash)
            trash.fds = []
            try:
                trash.__init__(files)
                if op == 'trash-put':
                    result = trash.put(path)
                elif op == 'trash-list':
                    result = {'entries': trash.list()}
                elif op == 'trash-restore':
                    result = trash.restore(request.get('id'), request.get('rename') is True)
                else:
                    result = trash.purge(request.get('id'))
                reply({'ok': True, 'result': {'success': True, **result}})
            except TrashError as error:
                reply({'ok': True, 'result': {'success': False, 'code': error.code}})
            except FileNotFoundError:
                reply({'ok': True, 'result': {'success': False, 'code': 'not_found'}})
            finally:
                trash.close()
        elif op == 'write':
            # Handshake permits streaming arbitrarily large uploads without buffering.
            # Validate and pin the target before accepting its bytes.
            fd = files.open(path, os.O_WRONLY | os.O_CREAT, 0o640)
            files.authorize()
            reply({'ok': True})
            replied = True
            os.ftruncate(fd, 0)
            while True:
                block = sys.stdin.buffer.read(262144)
                if not block:
                    break
                files.authorize()
                view = memoryview(block)
                while view:
                    view = view[os.write(fd, view):]
        elif op == 'upload':
            fd = files.open_upload(path)
            with os.fdopen(os.dup(fd), 'rb') as source:
                files.write(request['target'], source)
            reply({'ok': True})
        elif op == 'mkdir':
            files.mkdir(path)
            reply({'ok': True})
        elif op == 'symlink':
            parent, name = files.parent(path)
            target = request['target']
            files.locate(os.path.join(os.path.dirname(path), target))
            files.authorize()
            os.symlink(target, name, dir_fd=parent)
            reply({'ok': True})
        elif op == 'remove':
            files.remove(path)
            reply({'ok': True})
        elif op == 'copy':
            files.copy(path, request['target'])
            reply({'ok': True})
        elif op == 'move':
            files.move(path, request['target'])
            reply({'ok': True})
        else:
            raise PermissionError('unknown operation')
    except (OSError, ValueError, TypeError, KeyError):
        if not replied:
            reply({'ok': False})
        return 1
    finally:
        if files:
            files.close()
    return 0


if __name__ == '__main__':
    sys.exit(main())
