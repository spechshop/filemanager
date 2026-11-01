#!/usr/bin/env python3

import json
import os
import re
import shlex
import shutil
import subprocess
import tempfile
import time
from pathlib import Path


PROJECT_ROOT = Path(__file__).resolve().parents[2]
INSTALLER = PROJECT_ROOT / "installer.sh"
PHP_BIN = shutil.which("php")


def require(condition, message):
    if not condition:
        raise AssertionError(message)


def extract_between(source, start_marker, end_marker):
    start = source.index(start_marker)
    end = source.index(end_marker, start) + len(end_marker)
    return source[start:end]


def run_generation(function, root, token_file=None):
    env = os.environ.copy()
    env["TEST_INSTALLER_ROOT"] = str(PROJECT_ROOT)
    env["TEST_PHP_BIN"] = PHP_BIN
    if token_file is None:
        env.pop("FILEMANAGER_TOKENS_FILE", None)
    else:
        env["FILEMANAGER_TOKENS_FILE"] = str(token_file)
    script = """CURRENT_DIR="$TEST_INSTALLER_ROOT"
PHP_BIN="$TEST_PHP_BIN"
""" + function + "\ngenerate_installer_token\n"
    return subprocess.run(["bash", "-c", script], env=env, capture_output=True, text=True)


def check_policy(token_file, token):
    env = os.environ.copy()
    env["FILEMANAGER_TOKENS_FILE"] = str(token_file)
    env["TEST_TOKEN"] = token
    code = r'''require getenv("FILEMANAGER_INSTALLER_ROOT") . "/plugins/Request/components/securityPolicy.php";
$token = getenv("TEST_TOKEN");
if (!\plugins\Request\securityPolicy::validToken($token)
    || !\plugins\Request\securityPolicy::adminAllowed($token)
    || !\plugins\Request\securityPolicy::terminalAllowed($token)) exit(1);
'''
    env["FILEMANAGER_INSTALLER_ROOT"] = str(PROJECT_ROOT)
    result = subprocess.run([PHP_BIN, "-r", code], env=env, capture_output=True, text=True)
    require(result.returncode == 0, "The generated token failed securityPolicy validation")


def run_summary(summary, token):
    script = """log() { printf '%s\\n' "$*"; }
ok() { printf '%s\\n' "$*"; }
warn() { printf '%s\\n' "$*"; }
FREE_RAM_OK=1
CONTROL_SCRIPT=
""" + f"INSTALLER_TOKEN={shlex.quote(token)}\n" + summary
    return subprocess.run(["bash", "-c", script], capture_output=True, text=True).stdout


def main():
    require(PHP_BIN is not None, "PHP CLI is required for this isolated test")
    source = INSTALLER.read_text(encoding="utf-8")
    function = extract_between(source, "# BEGIN installer token generation", "# END installer token generation")
    summary = source[source.index('ok "Instalação concluída!"'):]

    with tempfile.TemporaryDirectory(prefix="fm-installer-token-") as temporary:
        root = Path(temporary) / "project"
        root.mkdir()

        token_file = root / "database" / "tokens.lotus"
        created = run_generation(function, root, token_file)
        require(created.returncode == 0, "Initial token generation failed: " + created.stderr)
        token = created.stdout
        require(re.fullmatch(r"[a-f0-9]{64}", token) is not None, "Generated token is not 64 hexadecimal characters")
        records = json.loads(token_file.read_text(encoding="utf-8"))
        require(token in records, "Generated token was not persisted")
        record = records[token]
        require(record.get("admin") is True and record.get("terminal") is True, "Generated token permissions are incorrect")
        require(abs(record["expire"] - (int(time.time()) + 365 * 24 * 60 * 60)) <= 5, "Generated token validity is not 365 days")
        require(token_file.stat().st_mode & 0o077 == 0, "Token store permissions are not private")
        check_policy(token_file, token)

        repeated = run_generation(function, root, token_file)
        require(repeated.returncode == 0, "Repeated token generation failed: " + repeated.stderr)
        second_token = repeated.stdout
        repeated_records = json.loads(token_file.read_text(encoding="utf-8"))
        require(second_token != token and token in repeated_records and second_token in repeated_records, "Repeated installation did not preserve both tokens")
        require(repeated_records[token] == record, "Repeated installation modified the existing token")

        corrupt_file = root / "database" / "corrupt.lotus"
        corrupt_file.write_text("{invalid", encoding="utf-8")
        corrupt = run_generation(function, root, corrupt_file)
        require(corrupt.returncode != 0 and corrupt.stdout == "", "Invalid token storage unexpectedly generated or announced a token")
        require(corrupt_file.read_text(encoding="utf-8") == "{invalid", "Invalid token storage was overwritten")

        unavailable_file = Path("/proc/filemanager-installer-token-test/tokens.lotus")
        unavailable = run_generation(function, root, unavailable_file)
        require(unavailable.returncode != 0 and unavailable.stdout == "", "A failed storage write unexpectedly announced a token")

        successful_summary = run_summary(summary, token)
        require(token in successful_summary and "Acesse o sistema em:" in successful_summary and "365 dias" in successful_summary, "Final summary does not show URL, token, and validity")
        failed_summary = run_summary(summary, "")
        require("Token administrativo" not in failed_summary and "Não foi possível gerar o token administrativo" in failed_summary, "Final summary announced a token after generation failure")

    print("PASS installer token: creation, preservation, corruption, write failure, policy permissions, private storage, and summary")


if __name__ == "__main__":
    main()