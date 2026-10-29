#!/usr/bin/env python3
"""Local TLS fixtures only: no installer executes and no production peer is used."""
import hashlib
import http.server
import os
import re
from pathlib import Path
import ssl
import subprocess
import tempfile
import threading
import unittest

ROOT = Path(__file__).resolve().parents[2]


class DownloadTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory(prefix='fm-download-')
        cls.base = Path(cls.temp.name)
        cls.body = b'synthetic artifact, never executed\n'
        cls.digest = hashlib.sha256(cls.body).hexdigest()
        cls.cert, cls.key = cls.base / 'cert.pem', cls.base / 'key.pem'
        subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1', '-keyout', str(cls.key), '-out', str(cls.cert), '-subj', '/CN=localhost', '-addext', 'subjectAltName=DNS:localhost'], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        class Handler(http.server.BaseHTTPRequestHandler):
            def do_GET(self):
                if self.path == '/redirect':
                    self.send_response(302)
                    self.send_header('Location', 'http://localhost/unsafe')
                    self.end_headers()
                else:
                    self.send_response(200)
                    self.end_headers()
                    self.wfile.write(cls.body)
            def log_message(self, *_): pass
        cls.server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.load_cert_chain(cls.cert, cls.key)
        cls.server.socket = context.wrap_socket(cls.server.socket, server_side=True)
        cls.thread = threading.Thread(target=cls.server.serve_forever, daemon=True)
        cls.thread.start()
        cls.url = 'https://localhost:' + str(cls.server.server_address[1])

    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown(); cls.server.server_close(); cls.thread.join()
        cls.temp.cleanup()

    def download(self, url, digest, trust=False, environment=None):
        output = self.base / self._testMethodName
        env = {**os.environ, 'CURL_CA_BUNDLE': str(self.cert) if trust else '/etc/ssl/certs/ca-certificates.crt'}
        env.update(environment or {})
        result = subprocess.run(['bash', '-c', 'source "$1"; secure_download "$2" "$3" "$4"', 'fixture', str(ROOT / 'scripts/secure-download.sh'), url, str(output), digest], env=env, capture_output=True, timeout=15)
        return result, output

    def test_all_production_installers_keep_tls_verification_enabled(self):
        for filename in ['installer.sh', 'install-tmp.sh', 'scripts/install-codex.sh', 'scripts/install-codex-native.sh', 'scripts/secure-download.sh']:
            source = (ROOT / filename).read_text()
            self.assertNotRegex(source, r'curl[^\n]*\s-k(?:\s|[a-z])|--insecure|--no-check-certificate|sslVerify=false|--ssl-verify false')
        source = (ROOT / 'installer.sh').read_text()
        self.assertIn('http.sslVerify=true', source)
        self.assertIn('GIT_SSL_NO_VERIFY', source)
        self.assertNotRegex(source, r'(?:curl|wget)[^\n]*\|\s*(?:sh|bash)')

    def test_untrusted_certificate_is_rejected(self):
        result, output = self.download(self.url + '/artifact', self.digest)
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(output.exists())

    def test_insecure_curl_configuration_cannot_disable_certificate_validation(self):
        directory = self.base / 'curl-home'
        directory.mkdir()
        (directory / '.curlrc').write_text('insecure\n')
        result, output = self.download(self.url + '/artifact', self.digest, environment={'CURL_HOME': str(directory)})
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(output.exists())

    def test_valid_chain_and_digest_are_accepted(self):
        result, output = self.download(self.url + '/artifact', self.digest, True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(output.read_bytes(), self.body)

    def test_corrupt_artifact_is_removed(self):
        result, output = self.download(self.url + '/artifact', '0' * 64, True)
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(output.exists())

    def test_https_redirect_cannot_downgrade_to_http(self):
        result, output = self.download(self.url + '/redirect', self.digest, True)
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(output.exists())

    def test_local_archive_requires_explicit_integrity(self):
        fixture = self.base / 'local-fixture'
        fixture.write_bytes(self.body)
        result, output = self.download(fixture.as_uri(), '')
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(output.exists())


if __name__ == '__main__': unittest.main(verbosity=2)
