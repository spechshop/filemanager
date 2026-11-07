#!/usr/bin/env bash
# Parse the complete worker before executing a script received through curl | bash.
fm_tunnel_main() {
    # FILEMANAGER_CONFIG
    umask 077
    local FM_LAUNCH_DIR=$1 FM_WORK FM_NODE FM_CHILD='' FM_INSTALL='' FM_NEXT_REPORT=0 FM_LAST_ACK=$SECONDS
    local FM_FINAL_STATUS=offline code
    rm -f -- "$FM_LAUNCH_DIR/agent.sh"
    FM_WORK=$(mktemp -d "$FM_LAUNCH_DIR/work.XXXXXXXX") || return 1
    printf '%s\n' "$$" > "$FM_LAUNCH_DIR/agent.pid"
    printf 'header = "Authorization: Bearer %s"\n' "$FM_SECRET" > "$FM_WORK/auth.conf"

    fm_report() {
        local result code
        printf '{"session":"%s","status":"%s","url":"","message":"%s"}' \
            "$FM_SESSION" "$1" "${2:-}" > "$FM_WORK/report.json"
        local arguments=(--silent --show-error --connect-timeout 5 --max-time 8 --config "$FM_WORK/auth.conf"
            --output "$FM_WORK/response" --write-out '%{http_code}' --request POST
            --header 'Content-Type: application/json' --data-binary @"$FM_WORK/report.json")
        result=$(curl "${arguments[@]}" "$FM_ENDPOINT" 2> "$FM_WORK/request-error"); code=$?
        if [ "$code" -eq 60 ] && [ -n "$FM_PANEL_PIN" ]; then
            result=$(curl "${arguments[@]}" --insecure --pinnedpubkey "$FM_PANEL_PIN" "$FM_ENDPOINT" 2> "$FM_WORK/request-error"); code=$?
        fi
        [ "$code" -eq 0 ] || return 2
        case "$result" in
            200) grep -Eq '"success"[[:space:]]*:[[:space:]]*true' "$FM_WORK/response" && return 0;;
            400|401|403|404|409|422) return 3;;
        esac
        return 2
    }
    fm_keepalive() {
        local code
        [ "$SECONDS" -ge "$FM_NEXT_REPORT" ] || return 0
        fm_report starting
        code=$?
        if [ "$code" -eq 0 ]; then FM_LAST_ACK=$SECONDS
        elif [ "$code" -eq 3 ]; then
            printf '%s\n' 'Túnel encerrado pelo painel.' >&2; return 1
        elif [ $((SECONDS - FM_LAST_ACK)) -ge 25 ]; then
            printf '%s\n' 'Conexão com o painel perdida durante a preparação do túnel.' >&2; return 1
        fi
        FM_NEXT_REPORT=$((SECONDS + 2))
    }
    fm_cleanup() {
        local code=$? pid attempt
        trap - EXIT INT TERM
        for pid in "$FM_INSTALL" "$FM_CHILD"; do
            [ -n "$pid" ] || continue
            kill "$pid" 2>/dev/null || true
            for attempt in 1 2 3 4 5; do
                kill -0 "$pid" 2>/dev/null || break
                sleep 1
            done
            kill -KILL "$pid" 2>/dev/null || true
            wait "$pid" 2>/dev/null || true
        done
        if [ "$FM_FINAL_STATUS" = error ]; then
            fm_report error 'Não foi possível preparar o túnel direto. Consulte o log informado ao iniciar o comando.' || true
        else
            fm_report offline || true
        fi
        rm -rf -- "$FM_WORK"
        rm -f -- "$FM_LAUNCH_DIR/agent.pid"
        exit "$code"
    }
    trap fm_cleanup EXIT
    trap 'exit 130' INT
    trap 'exit 143' TERM
    trap '' HUP

    fm_node_compatible() {
        "$1" -e 'if (Number(process.versions.node.split(".")[0]) < 22) process.exit(1)' >/dev/null 2>&1
    }
    fm_install_node() {
        local FM_ASSET FM_SHA256 FM_NODE_URL platform variant digest code loader FM_DOWNLOAD=''
        trap 'if [ -n "$FM_DOWNLOAD" ]; then kill "$FM_DOWNLOAD" 2>/dev/null; wait "$FM_DOWNLOAD" 2>/dev/null; fi; exit 143' INT TERM
        platform="$(uname -s):$(uname -m)"
        variant=$platform
        fm_select_node() {
            case "$1" in
                # FILEMANAGER_NODE_PLATFORMS
                *) return 1;;
            esac
        }
        fm_verify_download() {
            if command -v sha256sum >/dev/null 2>&1; then
                digest=$(sha256sum "$FM_WORK/node.tar.gz") || return 1; digest=${digest%% *}
            elif command -v shasum >/dev/null 2>&1; then
                digest=$(shasum -a 256 "$FM_WORK/node.tar.gz") || return 1; digest=${digest%% *}
            elif command -v openssl >/dev/null 2>&1; then
                digest=$(openssl dgst -sha256 "$FM_WORK/node.tar.gz") || return 1; digest=${digest##* }
            else
                printf '%s\n' 'Nenhuma ferramenta SHA256 do sistema está disponível para verificar o runtime.' >&2; return 1
            fi
            [ "$digest" = "$FM_SHA256" ]
        }
        fm_download_node() {
            local arguments=(--fail --silent --show-error --connect-timeout 10 --max-time 180
                --retry 4 --retry-delay 3 --retry-max-time 240 --max-filesize 104857600 --output "$FM_WORK/node.tar.gz")
            printf '%s\n' 'Baixando Node.js privado pelo File Manager, automaticamente e sem sudo...'
            curl "${arguments[@]}" --config "$FM_WORK/auth.conf" \
                "$FM_ENDPOINT?asset=$FM_ASSET&session=$FM_SESSION" 2> "$FM_WORK/download-error" &
            FM_DOWNLOAD=$!; wait "$FM_DOWNLOAD"; code=$?; FM_DOWNLOAD=''
            if [ "$code" -eq 60 ] && [ -n "$FM_PANEL_PIN" ]; then
                curl "${arguments[@]}" --config "$FM_WORK/auth.conf" --insecure --pinnedpubkey "$FM_PANEL_PIN" \
                    "$FM_ENDPOINT?asset=$FM_ASSET&session=$FM_SESSION" 2> "$FM_WORK/download-error" &
                FM_DOWNLOAD=$!; wait "$FM_DOWNLOAD"; code=$?; FM_DOWNLOAD=''
            fi
            if [ "$code" -ne 0 ] || ! fm_verify_download; then
                printf '%s\n' 'Download pelo painel indisponível. Tentando a origem do runtime...'
                # File Manager credentials must never be sent to a runtime download host.
                curl "${arguments[@]}" --proto '=https' --tlsv1.2 "$FM_NODE_URL" 2> "$FM_WORK/download-error" &
                FM_DOWNLOAD=$!; wait "$FM_DOWNLOAD"; code=$?; FM_DOWNLOAD=''
                if [ "$code" -ne 0 ]; then cat "$FM_WORK/download-error" >&2; return 1; fi
                if ! fm_verify_download; then printf '%s\n' 'A verificação SHA256 do Node.js falhou.' >&2; return 1; fi
            fi
            # Extract only the executable; no npm, package manager or global installation.
            tar -xOzf "$FM_WORK/node.tar.gz" "${FM_ASSET%.tar.gz}/bin/node" > "$FM_NODE" || return 1
            chmod 700 "$FM_NODE" || return 1
            rm -f -- "$FM_WORK/node.tar.gz"
        }
        if ! fm_select_node "$variant"; then printf 'Sistema não suportado pelo runtime privado: %s\n' "$variant" >&2; return 1; fi
        fm_download_node || return 1
        if ! fm_node_compatible "$FM_NODE"; then
            for loader in /lib/ld-musl-*.so.1; do
                if [ -f "$loader" ] && fm_select_node "$platform:musl"; then
                    printf '%s\n' 'Preparando automaticamente o runtime para Linux musl...'
                    fm_download_node || return 1
                    fm_node_compatible "$FM_NODE" && return 0
                    break
                fi
            done
            if fm_select_node "$platform:compat"; then
                printf '%s\n' 'Preparando automaticamente o runtime para Linux com glibc antiga...'
                fm_download_node || return 1
                fm_node_compatible "$FM_NODE" && return 0
            fi
            printf '%s\n' 'O runtime privado não conseguiu executar neste sistema.' >&2; return 1
        fi
    }

    FM_FINAL_STATUS=error
    fm_keepalive || exit 1
    if command -v node >/dev/null 2>&1 && fm_node_compatible "$(command -v node)"; then
        FM_NODE=$(command -v node)
    else
        FM_NODE="$FM_WORK/node"
        (trap - EXIT INT TERM; fm_install_node) &
        FM_INSTALL=$!
        while kill -0 "$FM_INSTALL" 2>/dev/null; do fm_keepalive || exit 1; sleep 1; done
        wait "$FM_INSTALL"; code=$?; FM_INSTALL=''
        [ "$code" -eq 0 ] || exit 1
    fi
    "$FM_NODE" -e 'require("node:fs").writeFileSync(process.argv[1], Buffer.from(process.argv[2], "base64"), {mode: 0o600})' \
        "$FM_WORK/agent.cjs" 'FILEMANAGER_AGENT_BASE64' || exit 1
    fm_keepalive || exit 1
    FM_FINAL_STATUS=offline
    "$FM_NODE" "$FM_WORK/agent.cjs" &
    FM_CHILD=$!
    wait "$FM_CHILD"; code=$?; FM_CHILD=''
    exit "$code"
}
fm_tunnel_start() {
    umask 077
    local launch_dir agent_pid
    launch_dir=$(mktemp -d "${TMPDIR:-/tmp}/filemanager-tunnel.XXXXXXXX") || return 1
    if ! {
        printf '#!/usr/bin/env bash\n'
        declare -f fm_tunnel_main
        printf 'fm_tunnel_main "$1"\n'
    } > "$launch_dir/agent.sh"; then rm -rf -- "$launch_dir"; return 1; fi
    (trap '' HUP; exec bash "$launch_dir/agent.sh" "$launch_dir") </dev/null >"$launch_dir/agent.log" 2>&1 &
    agent_pid=$!
    disown "$agent_pid" 2>/dev/null || true
    printf 'Túnel direto iniciado em segundo plano (PID %s). Você pode fechar este terminal ou a sessão SSH.\n' "$agent_pid"
    printf 'Log: %s/agent.log\n' "$launch_dir"
    printf '%s\n' 'A preparação é automática. Acompanhe o link e encerre a conexão na seção Túneis do File Manager.'
}
fm_tunnel_start
