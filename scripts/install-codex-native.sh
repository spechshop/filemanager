#!/usr/bin/env bash

# Suporte isolado para runtimes Linux antigos e addons Node.js nativos. Este
# arquivo é carregado por install-codex.sh antes da seleção do runtime Node.

configure_native_toolchain_paths() {
    MICROMAMBA_DIR="$RUNTIME_DIR/micromamba"
    MICROMAMBA_BIN="$MICROMAMBA_DIR/bin/micromamba"
    MAMBA_ROOT_PREFIX="$RUNTIME_DIR/mamba-root"
    TOOLCHAIN_DIR="$RUNTIME_DIR/native-toolchain"
    TOOLCHAIN_BIN="$TOOLCHAIN_DIR/bin"
    SYSTEM_NPM_INSTALL_LOG="$RUNTIME_DIR/npm-install-system.log"
    export MAMBA_ROOT_PREFIX
}

version_is_older_than() {
    local current="$1" required="$2" current_major current_minor required_major required_minor
    current_major="${current%%.*}"
    current_minor="${current#*.}"
    current_minor="${current_minor%%.*}"
    required_major="${required%%.*}"
    required_minor="${required#*.}"
    required_minor="${required_minor%%.*}"
    [[ "$current_major" =~ ^[0-9]+$ && "$current_minor" =~ ^[0-9]+$ ]] || return 1
    [[ "$required_major" =~ ^[0-9]+$ && "$required_minor" =~ ^[0-9]+$ ]] || return 1
    [ "$current_major" -lt "$required_major" ] \
        || { [ "$current_major" -eq "$required_major" ] && [ "$current_minor" -lt "$required_minor" ]; }
}

system_glibc_version() {
    local version
    if command -v getconf >/dev/null 2>&1; then
        version="$(getconf GNU_LIBC_VERSION 2>/dev/null | awk '{ print $2; exit }')"
        [[ "$version" =~ ^[0-9]+\.[0-9]+ ]] && { printf '%s\n' "$version"; return 0; }
    fi
    if command -v ldd >/dev/null 2>&1; then
        version="$(ldd --version 2>/dev/null | sed -n '1s/.* \([0-9][0-9]*\.[0-9][0-9]*\).*/\1/p')"
        [[ "$version" =~ ^[0-9]+\.[0-9]+ ]] && { printf '%s\n' "$version"; return 0; }
    fi
    return 1
}

select_compatible_node_version() {
    local index_file="$1" target_major="$2"
    awk -F '\t' -v prefix="v${target_major}." '
        index($1, prefix) == 1 && ("," $3 ",") ~ /,linux-x64-glibc-217,/ {
            print $1
            exit
        }
    ' "$index_file"
}

python_version_fields() {
    "$1" -c 'import sys; print(sys.version_info[0], sys.version_info[1], sys.version_info[2])' 2>/dev/null
}

absolute_executable_path() {
    local executable="$1" directory
    if [[ "$executable" == /* ]]; then
        printf '%s\n' "$executable"
        return 0
    fi
    if [[ "$executable" == */* ]]; then
        directory="$(cd -- "$(dirname -- "$executable")" 2>/dev/null && pwd -P)" || return 1
        printf '%s/%s\n' "$directory" "$(basename -- "$executable")"
        return 0
    fi
    command -v "$executable" 2>/dev/null
}

detect_node_gyp_python() {
    local configured_python="${PYTHON:-}" candidate resolved version
    local major minor patch
    local -a candidates=()

    [ -n "$configured_python" ] && candidates+=("$configured_python")
    candidates+=(python3.13 python3.12 python3.11 python3.10 python3.9 python3.8 python3)
    unset PYTHON

    for candidate in "${candidates[@]}"; do
        resolved=""
        if [[ "$candidate" == */* ]]; then
            [ -x "$candidate" ] && resolved="$candidate"
        else
            resolved="$(command -v "$candidate" 2>/dev/null || true)"
        fi
        [ -n "$resolved" ] && [ -x "$resolved" ] || continue
        resolved="$(absolute_executable_path "$resolved")" || continue

        version="$(python_version_fields "$resolved")"
        read -r major minor patch <<< "$version"
        [[ "$major" =~ ^[0-9]+$ && "$minor" =~ ^[0-9]+$ && "$patch" =~ ^[0-9]+$ ]] || continue
        if [ "$major" -gt 3 ] || { [ "$major" -eq 3 ] && [ "$minor" -ge 8 ]; }; then
            PYTHON="$resolved"
            export PYTHON
            log "Python $major.$minor encontrado em $PYTHON."
            return 0
        fi
    done

    warn "Nenhum Python 3.8 ou superior foi encontrado para o node-gyp."
    return 1
}

run_npm_install_logged() {
    local log_file="$1"
    mkdir -p "$(dirname -- "$log_file")" || return 1
    : > "$log_file" || return 1

    (
        local -a pipeline_status
        cd "$PROJECT_ROOT" || exit 1
        set +e
        run_npm install --no-audit --no-fund 2>&1 | tee "$log_file"
        pipeline_status=("${PIPESTATUS[@]}")
        [ "${pipeline_status[0]}" -ne 0 ] && exit "${pipeline_status[0]}"
        exit "${pipeline_status[1]}"
    )
}

npm_log_has_non_toolchain_failure() {
    local log_file="$1"

    LC_ALL=C grep -Eiq \
        '(EAI_AGAIN|ENOTFOUND|ETIMEDOUT|ERR_SOCKET_TIMEOUT|ECONNRESET|ECONNREFUSED|EHOSTUNREACH|ENETUNREACH|temporary failure in name resolution|could not resolve host|getaddrinfo|network request|network timeout|fetch failed|request to .* failed|registry.*(unavailable|timed out|timeout))' \
        "$log_file" && return 0

    LC_ALL=C grep -Eiq \
        '(npm (error|ERR!)[[:space:]]+(code[[:space:]]+)?(E404|ETARGET|ERESOLVE)|npm (error|ERR!)[[:space:]]+404|no matching version found|unable to resolve dependency tree|peer dependency conflict|[[:space:]]notarget[[:space:]])' \
        "$log_file"
}

npm_log_has_native_toolchain_failure() {
    local log_file="$1"
    [ -s "$log_file" ] || return 1

    # Erros de transporte e resolução de pacotes não melhoram com outro GCC.
    npm_log_has_non_toolchain_failure "$log_file" && return 1

    LC_ALL=C grep -Eiq \
        '(node-gyp|gyp ERR!|could not find any Python installation|no usable Python|Python is not set|this version of Python is not supported|requires Python[^0-9]*3\.[89]|python[^[:space:]]* (was not found|not found)|make: (command not found|not found)|spawn make ENOENT|no acceptable C compiler found|C compiler cannot create executables|C\+\+ compiler.*not found|g\+\+: (command not found|not found)|gcc: (command not found|not found)|unrecognized command.line option.*-std=gnu\+\+20|invalid value.*gnu\+\+20|-std=gnu\+\+20.*(unrecognized|unsupported)|C\+\+20.*(not supported|unsupported))' \
        "$log_file"
}

native_platform_packages() {
    local system machine
    system="$(uname -s 2>/dev/null | tr '[:upper:]' '[:lower:]')"
    machine="$(uname -m 2>/dev/null)"
    [ "$system" = "linux" ] || {
        LAST_MESSAGE="O fallback de toolchain nativo suporta Linux; sistema detectado: $system."
        return 1
    }

    case "$machine" in
        x86_64|amd64)
            MICROMAMBA_PLATFORM="linux-64"
            TOOLCHAIN_GCC_PACKAGE="gcc_linux-64=14"
            TOOLCHAIN_GXX_PACKAGE="gxx_linux-64=14"
            TOOLCHAIN_SYSROOT_PACKAGE="sysroot_linux-64=2.17"
            ;;
        aarch64|arm64)
            MICROMAMBA_PLATFORM="linux-aarch64"
            TOOLCHAIN_GCC_PACKAGE="gcc_linux-aarch64=14"
            TOOLCHAIN_GXX_PACKAGE="gxx_linux-aarch64=14"
            TOOLCHAIN_SYSROOT_PACKAGE="sysroot_linux-aarch64=2.17"
            ;;
        *)
            LAST_MESSAGE="Arquitetura $machine não suportada pelo fallback automático de toolchain."
            return 1
            ;;
    esac
}

install_micromamba() {
    local archive temp_dir extracted

    if [ -x "$MICROMAMBA_BIN" ] && "$MICROMAMBA_BIN" --version >/dev/null 2>&1; then
        log "Reutilizando micromamba em .runtime/micromamba."
        return 0
    fi
    command -v tar >/dev/null 2>&1 || {
        LAST_MESSAGE="O comando tar é necessário para instalar o micromamba."
        return 1
    }

    temp_dir="$(mktemp -d "$RUNTIME_DIR/micromamba-install.XXXXXX")" || {
        LAST_MESSAGE="Não foi possível criar o diretório temporário do micromamba."
        return 1
    }
    archive="$temp_dir/micromamba.tar.bz2"
    extracted="$temp_dir/bin/micromamba"
    log "Baixando micromamba oficial para $MICROMAMBA_PLATFORM..."
    if ! download "https://micro.mamba.pm/api/micromamba/$MICROMAMBA_PLATFORM/latest" "$archive" \
        || ! tar -xjf "$archive" -C "$temp_dir" bin/micromamba \
        || [ ! -x "$extracted" ]; then
        LAST_MESSAGE="Não foi possível baixar ou extrair o micromamba oficial."
        rm -rf -- "$temp_dir"
        return 1
    fi

    mkdir -p "$MICROMAMBA_DIR/bin" || {
        LAST_MESSAGE="Não foi possível criar .runtime/micromamba/bin."
        rm -rf -- "$temp_dir"
        return 1
    }
    if ! mv -f -- "$extracted" "$MICROMAMBA_BIN" \
        || ! chmod +x "$MICROMAMBA_BIN" \
        || ! "$MICROMAMBA_BIN" --version >/dev/null 2>&1; then
        LAST_MESSAGE="O binário do micromamba baixado não é válido."
        rm -rf -- "$temp_dir"
        return 1
    fi
    rm -rf -- "$temp_dir"
    ok "Micromamba instalado em .runtime/micromamba."
}

first_toolchain_executable() {
    local candidate
    for candidate in "$@"; do
        [ -x "$candidate" ] || continue
        printf '%s\n' "$candidate"
        return 0
    done
    return 1
}

locate_native_toolchain() {
    local python_version cc_version cxx_version make_version cc_major cxx_major
    local sysroot_metadata sysroot_package_name

    [ -d "$TOOLCHAIN_BIN" ] || return 1
    TOOLCHAIN_PYTHON="$(first_toolchain_executable \
        "$TOOLCHAIN_BIN/python" "$TOOLCHAIN_BIN/python3" "$TOOLCHAIN_BIN"/python3.*)" || return 1
    TOOLCHAIN_MAKE="$(first_toolchain_executable \
        "$TOOLCHAIN_BIN/make" "$TOOLCHAIN_BIN/gmake")" || return 1
    TOOLCHAIN_CC="$(first_toolchain_executable \
        "$TOOLCHAIN_BIN"/*-gcc "$TOOLCHAIN_BIN/gcc")" || return 1
    TOOLCHAIN_CXX="$(first_toolchain_executable \
        "$TOOLCHAIN_BIN"/*-g++ "$TOOLCHAIN_BIN/g++")" || return 1

    sysroot_package_name="${TOOLCHAIN_SYSROOT_PACKAGE%%=*}"
    sysroot_metadata=""
    for sysroot_metadata in "$TOOLCHAIN_DIR"/conda-meta/"$sysroot_package_name"-2.17-*.json; do
        [ -f "$sysroot_metadata" ] && break
        sysroot_metadata=""
    done
    [ -n "$sysroot_metadata" ] || return 1

    python_version="$(python_version_fields "$TOOLCHAIN_PYTHON")"
    [[ "$python_version" == "3 12 "* ]] || return 1
    cc_version="$($TOOLCHAIN_CC -dumpfullversion -dumpversion 2>/dev/null)" || return 1
    cxx_version="$($TOOLCHAIN_CXX -dumpfullversion -dumpversion 2>/dev/null)" || return 1
    cc_major="${cc_version%%.*}"
    cxx_major="${cxx_version%%.*}"
    case "$cc_major:$cxx_major" in *[!0-9:]*|:*|*:) return 1 ;; esac
    [ "$cc_major" -ge 14 ] && [ "$cxx_major" -ge 14 ] || return 1
    make_version="$($TOOLCHAIN_MAKE --version 2>/dev/null | sed -n '1p')"
    [ -n "$make_version" ] || return 1
    printf 'int main() { return 0; }\n' \
        | "$TOOLCHAIN_CXX" -std=gnu++20 -x c++ -fsyntax-only - >/dev/null 2>&1 \
        || return 1

    TOOLCHAIN_GCC_VERSION="$cxx_version"
    return 0
}

prepare_native_toolchain() {
    local action

    native_platform_packages || return 1
    if locate_native_toolchain; then
        ok "Reutilizando toolchain GCC $TOOLCHAIN_GCC_VERSION em .runtime/native-toolchain."
        return 0
    fi
    install_micromamba || return 1
    mkdir -p "$MAMBA_ROOT_PREFIX" || {
        LAST_MESSAGE="Não foi possível criar .runtime/mamba-root."
        return 1
    }

    if [ -f "$TOOLCHAIN_DIR/conda-meta/history" ]; then
        action="install"
        log "Atualizando o ambiente de compilação local incompleto..."
    else
        action="create"
        if [ -e "$TOOLCHAIN_DIR" ]; then
            case "$TOOLCHAIN_DIR" in
                "$RUNTIME_DIR"/native-toolchain) rm -rf -- "$TOOLCHAIN_DIR" ;;
                *) LAST_MESSAGE="Diretório de toolchain inesperado: $TOOLCHAIN_DIR"; return 1 ;;
            esac
        fi
        log "Criando o ambiente de compilação em .runtime/native-toolchain..."
    fi

    json_state running "Preparando o compilador C/C++ local..." null
    if ! "$MICROMAMBA_BIN" "$action" --yes --prefix "$TOOLCHAIN_DIR" \
        --override-channels --channel conda-forge --strict-channel-priority \
        python=3.12 make "$TOOLCHAIN_GCC_PACKAGE" "$TOOLCHAIN_GXX_PACKAGE" \
        "$TOOLCHAIN_SYSROOT_PACKAGE"; then
        LAST_MESSAGE="Falha ao preparar o toolchain C/C++ local com micromamba."
        return 1
    fi
    if ! locate_native_toolchain; then
        LAST_MESSAGE="O ambiente micromamba foi criado, mas Python, make, GCC ou G++ válidos não foram encontrados."
        return 1
    fi
    ok "Toolchain GCC $TOOLCHAIN_GCC_VERSION com sysroot glibc 2.17 preparado em .runtime/native-toolchain."
    log "Usando Python $TOOLCHAIN_PYTHON, CC $TOOLCHAIN_CC, CXX $TOOLCHAIN_CXX e make $TOOLCHAIN_MAKE."
}

run_npm_install_with_local_toolchain() {
    (
        cd "$PROJECT_ROOT" || exit 1
        export PYTHON="$TOOLCHAIN_PYTHON"
        export CC="$TOOLCHAIN_CC"
        export CXX="$TOOLCHAIN_CXX"
        export MAKE="$TOOLCHAIN_MAKE"
        export PATH="$TOOLCHAIN_BIN:${PATH:-}"
        run_npm install --no-audit --no-fund
    )
}

validate_node_pty() {
    if (cd "$PROJECT_ROOT" && "$NODE_BIN" -e \
        "const pty=require('node-pty'); if (typeof pty.spawn !== 'function') process.exit(1)"); then
        ok "node-pty carregado corretamente."
        return 0
    fi
    LAST_MESSAGE="npm install terminou, mas o addon nativo node-pty não pode ser carregado."
    return 1
}

install_filemanager_node_dependencies() {
    detect_node_gyp_python || true
    json_state running "Instalando as dependências do File Manager..." null
    log "Instalando dependências com toolchain do sistema..."

    if ! run_npm_install_logged "$SYSTEM_NPM_INSTALL_LOG"; then
        if ! npm_log_has_native_toolchain_failure "$SYSTEM_NPM_INSTALL_LOG"; then
            LAST_MESSAGE="npm install falhou por um erro não relacionado ao toolchain nativo. Consulte $SYSTEM_NPM_INSTALL_LOG."
            return 1
        fi
        warn "Build nativo falhou com o toolchain do sistema."
        log "Preparando fallback C/C++ via micromamba..."
        prepare_native_toolchain || return 1
        json_state running "Repetindo npm install com o compilador local..." null
        log "Repetindo npm install com toolchain local..."
        if ! run_npm_install_with_local_toolchain; then
            LAST_MESSAGE="npm install falhou também com o toolchain C/C++ local."
            return 1
        fi
    fi

    validate_node_pty
}
