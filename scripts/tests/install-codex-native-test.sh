#!/usr/bin/env bash

set -uo pipefail

TEST_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
HELPER="$TEST_DIR/../install-codex-native.sh"
TEST_TMP_ROOT="$(mktemp -d)"
FAILURES=0

cleanup_test_files() {
    rm -rf -- "$TEST_TMP_ROOT"
}
trap cleanup_test_files EXIT

new_fixture() {
    mktemp -d "$TEST_TMP_ROOT/case.XXXXXX"
}

pass() { printf '[teste][ok] %s\n' "$1"; }
fail_test() { printf '[teste][erro] %s\n' "$1" >&2; return 1; }

assert_eq() {
    [ "$1" = "$2" ] || fail_test "esperado '$2', recebido '$1'"
}

assert_contains() {
    grep -Fq -- "$2" "$1" || fail_test "'$2' nao encontrado em $1"
}

run_case() {
    local name="$1" function_name="$2"
    if ("$function_name"); then
        pass "$name"
    else
        fail_test "$name"
        FAILURES=$((FAILURES + 1))
    fi
}

quiet_callbacks() {
    log() { :; }
    ok() { :; }
    warn() { :; }
    json_state() { :; }
}

case_modern_system_toolchain() {
    source "$HELPER"
    quiet_callbacks
    local fixture calls fallback validated
    fixture="$(new_fixture)" || return 1
    calls="$fixture/npm-calls"
    fallback="$fixture/fallback"
    validated="$fixture/validated"
    PROJECT_ROOT="$fixture/project"
    RUNTIME_DIR="$PROJECT_ROOT/.runtime"
    NODE_BIN=/bin/true
    LAST_MESSAGE=""
    mkdir -p "$PROJECT_ROOT" "$RUNTIME_DIR"
    configure_native_toolchain_paths
    detect_node_gyp_python() { :; }
    run_npm() { printf 'system\n' >> "$calls"; return 0; }
    prepare_native_toolchain() { : > "$fallback"; return 1; }
    validate_node_pty() { : > "$validated"; return 0; }

    install_filemanager_node_dependencies || return 1
    assert_eq "$(wc -l < "$calls")" 1 || return 1
    [ ! -e "$fallback" ] || return 1
    [ -e "$validated" ] || return 1
}

case_python_priority() {
    source "$HELPER"
    quiet_callbacks
    local fixture fake_bin
    fixture="$(new_fixture)" || return 1
    fake_bin="$fixture/bin"
    mkdir -p "$fake_bin"
    printf '%s\n' '#!/bin/sh' "printf '3 6 8\\n'" > "$fake_bin/python3"
    printf '%s\n' '#!/bin/sh' "printf '3 12 4\\n'" > "$fake_bin/python3.12"
    printf '%s\n' '#!/bin/sh' "printf '3 8 19\\n'" > "$fake_bin/custom-python"
    chmod +x "$fake_bin/python3" "$fake_bin/python3.12" "$fake_bin/custom-python"

    PYTHON="$fake_bin/python3"
    PATH="$fake_bin"
    detect_node_gyp_python || return 1
    assert_eq "$PYTHON" "$fake_bin/python3.12" || return 1

    PYTHON="$fake_bin/custom-python"
    detect_node_gyp_python || return 1
    assert_eq "$PYTHON" "$fake_bin/custom-python"
}

case_old_compiler_fallback() {
    source "$HELPER"
    quiet_callbacks
    local fixture calls fallback retry_env
    fixture="$(new_fixture)" || return 1
    calls="$fixture/npm-calls"
    fallback="$fixture/fallback"
    retry_env="$fixture/retry-env"
    PROJECT_ROOT="$fixture/project"
    RUNTIME_DIR="$PROJECT_ROOT/.runtime"
    NODE_BIN=/bin/true
    LAST_MESSAGE=""
    mkdir -p "$PROJECT_ROOT" "$RUNTIME_DIR"
    configure_native_toolchain_paths
    unset CC CXX MAKE PYTHON
    detect_node_gyp_python() { :; }
    prepare_native_toolchain() {
        : > "$fallback"
        TOOLCHAIN_PYTHON="$RUNTIME_DIR/native-toolchain/bin/python"
        TOOLCHAIN_CC="$RUNTIME_DIR/native-toolchain/bin/x86_64-conda-linux-gnu-gcc"
        TOOLCHAIN_CXX="$RUNTIME_DIR/native-toolchain/bin/x86_64-conda-linux-gnu-g++"
        TOOLCHAIN_MAKE="$RUNTIME_DIR/native-toolchain/bin/make"
        TOOLCHAIN_BIN="$RUNTIME_DIR/native-toolchain/bin"
        return 0
    }
    run_npm() {
        printf 'call\n' >> "$calls"
        if [ -z "${CXX:-}" ]; then
            printf "g++: error: unrecognized command-line option '-std=gnu++20'\n" >&2
            return 1
        fi
        printf '%s|%s|%s|%s\n' "$PYTHON" "$CC" "$CXX" "$MAKE" > "$retry_env"
        return 0
    }
    validate_node_pty() { return 0; }

    install_filemanager_node_dependencies || return 1
    assert_eq "$(wc -l < "$calls")" 2 || return 1
    [ -e "$fallback" ] || return 1
    assert_contains "$retry_env" "$RUNTIME_DIR/native-toolchain/bin/python" || return 1
    assert_contains "$retry_env" "$RUNTIME_DIR/native-toolchain/bin/x86_64-conda-linux-gnu-g++" || return 1
    case "$(cat "$retry_env")" in
        "$RUNTIME_DIR"/*) ;;
        *) return 1 ;;
    esac
}

case_existing_toolchain_reused() {
    source "$HELPER"
    quiet_callbacks
    local fixture downloaded
    fixture="$(new_fixture)" || return 1
    downloaded="$fixture/downloaded"
    RUNTIME_DIR="$fixture/project/.runtime"
    LAST_MESSAGE=""
    mkdir -p "$RUNTIME_DIR"
    configure_native_toolchain_paths
    locate_native_toolchain() {
        TOOLCHAIN_GCC_VERSION=14.3.0
        TOOLCHAIN_PYTHON="$TOOLCHAIN_BIN/python"
        TOOLCHAIN_CC="$TOOLCHAIN_BIN/native-gcc"
        TOOLCHAIN_CXX="$TOOLCHAIN_BIN/native-g++"
        TOOLCHAIN_MAKE="$TOOLCHAIN_BIN/make"
        return 0
    }
    install_micromamba() { : > "$downloaded"; return 1; }

    prepare_native_toolchain || return 1
    prepare_native_toolchain || return 1
    [ ! -e "$downloaded" ]
}

case_network_failure_does_not_fallback() {
    source "$HELPER"
    quiet_callbacks
    local fixture calls fallback
    fixture="$(new_fixture)" || return 1
    calls="$fixture/npm-calls"
    fallback="$fixture/fallback"
    PROJECT_ROOT="$fixture/project"
    RUNTIME_DIR="$PROJECT_ROOT/.runtime"
    NODE_BIN=/bin/true
    LAST_MESSAGE=""
    mkdir -p "$PROJECT_ROOT" "$RUNTIME_DIR"
    configure_native_toolchain_paths
    detect_node_gyp_python() { :; }
    run_npm() {
        printf 'call\n' >> "$calls"
        printf 'npm error code ENOTFOUND\nnpm error request to https://registry.npmjs.org/node-pty failed\n' >&2
        return 1
    }
    prepare_native_toolchain() { : > "$fallback"; return 1; }
    validate_node_pty() { return 1; }

    if install_filemanager_node_dependencies; then
        return 1
    fi
    assert_eq "$(wc -l < "$calls")" 1 || return 1
    [ ! -e "$fallback" ] || return 1
    [[ "$LAST_MESSAGE" == *"não relacionado ao toolchain"* ]]
}

case_classifier_signatures() {
    source "$HELPER"
    local fixture log_file
    fixture="$(new_fixture)" || return 1
    log_file="$fixture/npm.log"

    printf "gyp ERR! find Python - could not find any Python installation to use\n" > "$log_file"
    npm_log_has_native_toolchain_failure "$log_file" || return 1
    printf "make: command not found\n" > "$log_file"
    npm_log_has_native_toolchain_failure "$log_file" || return 1
    printf "g++: error: unrecognized command-line option '-std=gnu++20'\n" > "$log_file"
    npm_log_has_native_toolchain_failure "$log_file" || return 1
    printf "npm error code ERESOLVE\nnpm error unable to resolve dependency tree\n" > "$log_file"
    ! npm_log_has_native_toolchain_failure "$log_file" || return 1
    printf "npm error code ETIMEDOUT\ngyp ERR! configure error\n" > "$log_file"
    ! npm_log_has_native_toolchain_failure "$log_file"
}

case_arm64_package_mapping() {
    source "$HELPER"
    local fixture fake_bin old_path
    fixture="$(new_fixture)" || return 1
    fake_bin="$fixture/bin"
    mkdir -p "$fake_bin"
    printf '%s\n' '#!/bin/sh' \
        'case "$1" in -s) printf "Linux\\n" ;; -m) printf "aarch64\\n" ;; esac' > "$fake_bin/uname"
    chmod +x "$fake_bin/uname"
    old_path="$PATH"
    PATH="$fake_bin:$old_path"
    LAST_MESSAGE=""

    native_platform_packages || return 1
    assert_eq "$MICROMAMBA_PLATFORM" linux-aarch64 || return 1
    assert_eq "$TOOLCHAIN_GCC_PACKAGE" gcc_linux-aarch64=14 || return 1
    assert_eq "$TOOLCHAIN_GXX_PACKAGE" gxx_linux-aarch64=14 || return 1
    assert_eq "$TOOLCHAIN_SYSROOT_PACKAGE" sysroot_linux-aarch64=2.17
}

case_node_pty_validation() {
    source "$HELPER"
    quiet_callbacks
    PROJECT_ROOT="$(cd -- "$TEST_DIR/../.." && pwd -P)"
    NODE_BIN="$(command -v node)"
    LAST_MESSAGE=""
    validate_node_pty || return 1
    NODE_BIN=/bin/false
    if validate_node_pty; then
        return 1
    fi
    [[ "$LAST_MESSAGE" == *"node-pty não pode ser carregado"* ]]
}

run_case "A: npm normal nao prepara micromamba" case_modern_system_toolchain
run_case "B: Python 3.12 tem prioridade sobre python3 antigo" case_python_priority
run_case "C/D: GCC antigo aciona fallback local sem root" case_old_compiler_fallback
run_case "E: toolchain existente e reutilizado" case_existing_toolchain_reused
run_case "F: erro de rede nao aciona fallback" case_network_failure_does_not_fallback
run_case "assinaturas do classificador" case_classifier_signatures
run_case "mapeamento Linux aarch64" case_arm64_package_mapping
run_case "G: validacao real do node-pty" case_node_pty_validation

if [ "$FAILURES" -ne 0 ]; then
    printf '[teste][erro] %s caso(s) falharam.\n' "$FAILURES" >&2
    exit 1
fi
printf '[teste][ok] Todos os casos passaram.\n'
