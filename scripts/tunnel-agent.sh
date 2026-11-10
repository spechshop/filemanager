#!/usr/bin/env bash
# FILEMANAGER_TUNNEL_AGENT
# FILEMANAGER_EXEC_HELPER
if ! declare -F fm_exec_dir >/dev/null; then
    source "$(dirname -- "${BASH_SOURCE[0]}")/runtime-exec.sh" || exit 1
fi
# This entire function is received before execution when downloaded with curl | bash.
fm_tunnel_main() {
    # FILEMANAGER_CONFIG
    umask 077
    local FM_LAUNCH_DIR=$1
    local FM_WORK FM_BINARY FM_CHILD='' FM_INSTALL='' FM_NEXT_REPORT=0 FM_LAST_ACK=$SECONDS
    local FM_LAST_ONLINE=$SECONDS FM_FINAL_STATUS=offline FM_EXIT_CODE=0 FM_PUBLIC_URL=''
    # The worker has already parsed its private script; remove the credential copy.
    rm -f -- "$FM_LAUNCH_DIR/agent.sh"
    FM_WORK=$(mktemp -d "$FM_LAUNCH_DIR/work.XXXXXXXX") || return 1
    : > "$FM_WORK/cloudflared.log"
    printf 'header = "Authorization: Bearer %s"\n' "$FM_SECRET" > "$FM_WORK/auth.conf"

    fm_panel_request() {
        local code result
        result=$(curl --silent --show-error --connect-timeout 5 --max-time 8 --config "$FM_WORK/auth.conf" \
            --output "$FM_WORK/response" --write-out '%{http_code}' --request POST \
            --header 'Content-Type: application/json' --data-binary @"$FM_WORK/report.json" "$FM_ENDPOINT" 2> "$FM_WORK/request-error")
        code=$?
        if [ "$code" -eq 60 ] && [ -n "$FM_PANEL_PIN" ]; then
            result=$(curl --silent --show-error --connect-timeout 5 --max-time 8 --config "$FM_WORK/auth.conf" \
                --insecure --pinnedpubkey "$FM_PANEL_PIN" --output "$FM_WORK/response" --write-out '%{http_code}' --request POST \
                --header 'Content-Type: application/json' --data-binary @"$FM_WORK/report.json" "$FM_ENDPOINT" 2> "$FM_WORK/request-error")
            code=$?
        fi
        [ "$code" -eq 0 ] || return 2
        case "$result" in
            200) grep -Eq '"success"[[:space:]]*:[[:space:]]*true' "$FM_WORK/response" && return 0;;
            400|401|403|404|409|422) return 3;;
        esac
        return 2
    }

    fm_report() {
        printf '{"session":"%s","status":"%s","url":"%s","message":"%s"}' \
            "$FM_SESSION" "$1" "$2" "${3:-}" > "$FM_WORK/report.json"
        fm_panel_request
    }

    fm_connection_diagnostic() {
        local log="$FM_WORK/cloudflared.log"
        if grep -Eqi 'certificate|x509|tls.*(error|alert)' "$log"; then
            printf '%s' 'Falha TLS/certificado. Confira a cadeia de confiança e o nome do certificado.'
        elif grep -Eqi 'unauthorized|authentication|invalid token|forbidden' "$log"; then
            printf '%s' 'Falha de autenticação do túnel. Confira credenciais e autorização.'
        elif grep -Eqi 'no such host|lookup .*failed|DNS.*(error|fail)' "$log"; then
            printf '%s' 'Falha de DNS. Solicite ao provedor verificar a resolução dos destinos Cloudflare.'
        elif grep -Eqi 'connection refused|network is unreachable|i/o timeout|QUIC.*(fail|timeout)|dial tcp.*7844' "$log"; then
            printf '%s' 'Falha de conectividade após executar cloudflared. Saída TCP/UDP 7844 bloqueada ou destino indisponível; solicite diagnóstico ao provedor.'
        else
            printf '%s' 'cloudflared encerrou ou não conectou. Consulte o log para identificar a falha externa.'
        fi
    }

    fm_current_url() {
        awk '
            match($0, /https:\/\/[a-z0-9]+(-[a-z0-9]+)*\.trycloudflare\.com/) { url = substr($0, RSTART, RLENGTH) }
            /Registered tunnel connection/ { connected = 1 }
            /Unregistered tunnel connection|Connection terminated|failed to serve tunnel connection/ { connected = 0 }
            END { if (connected && url != "") print url }
        ' "$FM_WORK/cloudflared.log"
    }

    fm_keepalive() {
        local url state=starting code
        [ "$SECONDS" -ge "$FM_NEXT_REPORT" ] || return 0
        url=$(fm_current_url)
        if [ -n "$url" ]; then
            state=online
            FM_LAST_ONLINE=$SECONDS
            if [ "$url" != "$FM_PUBLIC_URL" ]; then printf 'Link do túnel: %s\n' "$url"; FM_PUBLIC_URL=$url; fi
        fi
        fm_report "$state" "$url"
        code=$?
        if [ "$code" -eq 0 ]; then
            FM_LAST_ACK=$SECONDS
        elif [ "$code" -eq 3 ]; then
            printf '%s\n' 'Túnel encerrado pelo painel. Gere um novo comando para reconectar.' >&2
            return 1
        elif [ $((SECONDS - FM_LAST_ACK)) -ge 25 ]; then
            printf '%s\n' 'Conexão com o painel perdida. Encerrando o túnel.' >&2
            return 1
        fi
        FM_NEXT_REPORT=$((SECONDS + 2))
        return 0
    }

    fm_cleanup() {
        local code=$?
        local attempt
        trap - EXIT INT TERM HUP
        if [ -n "$FM_CHILD" ]; then
            kill "$FM_CHILD" 2>/dev/null || true
            for attempt in 1 2 3 4 5; do
                kill -0 "$FM_CHILD" 2>/dev/null || break
                sleep 1
            done
            kill -KILL "$FM_CHILD" 2>/dev/null || true
            wait "$FM_CHILD" 2>/dev/null || true
        fi
        if [ -n "$FM_INSTALL" ]; then
            kill "$FM_INSTALL" 2>/dev/null || true
            wait "$FM_INSTALL" 2>/dev/null || true
        fi
        if [ "$FM_FINAL_STATUS" = error ]; then
            fm_report error '' "$(fm_connection_diagnostic)" || true
        else
            fm_report offline '' 'Conexão encerrada na máquina remota.' || true
        fi
        rm -rf -- "$FM_WORK"
        rm -f -- "$FM_LAUNCH_DIR/agent.pid"
        exit "$code"
    }
    trap fm_cleanup EXIT
    trap 'exit 130' INT
    trap 'exit 143' TERM HUP
    printf '%s\n' "$$" > "$FM_LAUNCH_DIR/agent.pid"

    fm_install_cloudflared() {
        local FM_ASSET FM_SHA256 digest member download='' code
        trap 'if [ -n "$download" ]; then kill "$download" 2>/dev/null; wait "$download" 2>/dev/null; fi; exit 143' INT TERM HUP
        case "$(uname -s):$(uname -m)" in
            # FILEMANAGER_PLATFORMS
            *) printf '%s\n' 'Sistema não suportado. Use Linux ou macOS, ou instale cloudflared no PATH.' >&2; return 1;;
        esac
        fm_verify_download() {
            if command -v sha256sum >/dev/null 2>&1; then
                digest=$(sha256sum "$FM_WORK/download") || return 1
                digest=${digest%% *}
            elif command -v shasum >/dev/null 2>&1; then
                digest=$(shasum -a 256 "$FM_WORK/download") || return 1
                digest=${digest%% *}
            else
                printf '%s\n' 'Não foi encontrado sha256sum ou shasum para verificar o download.' >&2
                return 1
            fi
            [ "$digest" = "$FM_SHA256" ]
        }
        printf '%s\n' 'Baixando cloudflared oficial pelo File Manager, sem sudo...'
        curl --fail --silent --show-error --connect-timeout 10 --max-time 180 --retry 4 --retry-delay 3 --retry-max-time 240 \
            --config "$FM_WORK/auth.conf" --max-filesize 104857600 --output "$FM_WORK/download" \
            "$FM_ENDPOINT?asset=$FM_ASSET&session=$FM_SESSION" 2> "$FM_WORK/download-error" &
        download=$!
        wait "$download"; code=$?
        download=''
        if [ "$code" -eq 60 ] && [ -n "$FM_PANEL_PIN" ]; then
            curl --fail --silent --show-error --connect-timeout 10 --max-time 180 --retry 4 --retry-delay 3 --retry-max-time 240 \
                --insecure --pinnedpubkey "$FM_PANEL_PIN" --config "$FM_WORK/auth.conf" \
                --max-filesize 104857600 --output "$FM_WORK/download" "$FM_ENDPOINT?asset=$FM_ASSET&session=$FM_SESSION" 2> "$FM_WORK/download-error" &
            download=$!
            wait "$download"; code=$?
            download=''
        fi
        # A certificate name mismatch can be recovered using the exact panel key.
        # Show curl diagnostics only if the final authenticated attempt also fails.
        if [ "$code" -ne 0 ]; then cat "$FM_WORK/download-error" >&2; fi
        if [ "$code" -ne 0 ] || ! fm_verify_download; then
            printf '%s\n' 'Download pelo File Manager indisponível. Tentando o GitHub com novas tentativas automáticas...'
            # Never send the File Manager credential to GitHub or its redirects.
            curl --fail --silent --show-error --location --proto '=https' --tlsv1.2 --connect-timeout 10 --max-time 60 \
                --retry 2 --retry-delay 2 --retry-max-time 90 --max-filesize 104857600 --output "$FM_WORK/download" \
                "https://github.com/cloudflare/cloudflared/releases/download/FILEMANAGER_CLOUDFLARED_VERSION/$FM_ASSET" &
            download=$!
            wait "$download"; code=$?
            download=''
            if [ "$code" -ne 0 ]; then
                printf '%s\n' 'Não foi possível baixar cloudflared pelo File Manager nem pelo GitHub. Verifique a conexão e gere um novo comando no painel.' >&2
                return 1
            fi
            if ! fm_verify_download; then
                printf '%s\n' 'A verificação SHA256 do cloudflared falhou.' >&2
                return 1
            fi
        fi
        case "$FM_ASSET" in
            *.tgz)
                member=$(tar -tzf "$FM_WORK/download" | grep -Ex '(\./)?cloudflared' | head -n 1)
                [ -n "$member" ] || return 1
                tar -xOzf "$FM_WORK/download" "$member" > "$FM_BINARY" || return 1;;
            *) mv "$FM_WORK/download" "$FM_BINARY" || return 1;;
        esac
        chmod 700 "$FM_BINARY"
    }

    if ! command -v curl >/dev/null 2>&1; then
        printf '%s\n' 'Este comando precisa de curl e Bash.' >&2
        exit 1
    fi
    fm_keepalive || exit 1
    if command -v cloudflared >/dev/null 2>&1; then
        FM_BINARY=$(fm_prepare_binary "$(command -v cloudflared)" "$FM_LAUNCH_DIR" --version) || { FM_FINAL_STATUS=error; exit 1; }
    else
        FM_BINARY="$FM_WORK/cloudflared"
        (trap - EXIT INT TERM HUP; fm_install_cloudflared) &
        FM_INSTALL=$!
        while kill -0 "$FM_INSTALL" 2>/dev/null; do
            fm_keepalive || exit 1
            sleep 1
        done
        if ! wait "$FM_INSTALL"; then
            FM_INSTALL=''
            FM_FINAL_STATUS=error
            exit 1
        fi
        FM_INSTALL=''
    fi

    "$FM_BINARY" --version >/dev/null 2>&1 || {
        printf '%s\n' 'cloudflared existe mas não executa: verifique permissões, noexec, arquitetura e bibliotecas.' >&2
        FM_FINAL_STATUS=error; exit 1;
    }
    printf '{}\n' > "$FM_WORK/config.yml"
    local arguments=(tunnel --config "$FM_WORK/config.yml" --no-autoupdate --url "$FM_TARGET"
        --protocol http2 --ha-connections 1 --metrics 127.0.0.1:0)
    if [ "$FM_NO_TLS_VERIFY" = true ]; then arguments+=(--no-tls-verify); fi
    printf 'Transmitindo o serviço %s desta máquina em segundo plano.\n' "$FM_TARGET"
    "$FM_BINARY" "${arguments[@]}" > "$FM_WORK/cloudflared.log" 2>&1 &
    FM_CHILD=$!
    FM_LAST_ONLINE=$SECONDS
    while kill -0 "$FM_CHILD" 2>/dev/null; do
        fm_keepalive || exit 1
        if [ $((SECONDS - FM_LAST_ONLINE)) -gt 180 ]; then
            printf '%s\n' 'cloudflared executou, mas não conectou. Confira DNS, TLS e saída TCP/UDP 7844 com o administrador da hospedagem.' >&2
            cat "$FM_WORK/cloudflared.log" >&2
            FM_FINAL_STATUS=error
            exit 1
        fi
        sleep 1
    done
    wait "$FM_CHILD" || FM_EXIT_CODE=$?
    FM_CHILD=''
    cat "$FM_WORK/cloudflared.log" >&2
    printf '%s\n' 'cloudflared encerrou. Gere um novo comando no painel para reconectar.' >&2
    FM_FINAL_STATUS=error
    [ "$FM_EXIT_CODE" -ne 0 ] && exit "$FM_EXIT_CODE"
    exit 1
}

fm_tunnel_start() {
    umask 077
    local launch_dir agent_pid
    if ! command -v nohup >/dev/null 2>&1; then
        printf '%s\n' 'Não foi encontrado nohup para iniciar o túnel em segundo plano.' >&2
        return 1
    fi
    launch_dir=$(fm_exec_dir "tunnel-${FM_SESSION:-remote}" 209715200) || return 1
    # Serialize the complete worker, including its configuration, before detaching.
    # All three standard streams must be detached from the curl | bash pipeline.
    if ! {
        printf '#!/usr/bin/env bash\n'
        fm_export_exec_helpers
        declare -f fm_tunnel_main
        printf 'fm_tunnel_main "$1"\n'
    } > "$launch_dir/agent.sh"; then
        rm -rf -- "$launch_dir"
        return 1
    fi
    nohup bash "$launch_dir/agent.sh" "$launch_dir" </dev/null >"$launch_dir/agent.log" 2>&1 &
    agent_pid=$!
    disown "$agent_pid" 2>/dev/null || true
    printf 'Túnel iniciado em segundo plano (PID %s). Você pode fechar este terminal ou a sessão SSH.\n' "$agent_pid"
    printf 'Log: %s/agent.log\n' "$launch_dir"
    printf '%s\n' 'Acompanhe o link e encerre a conexão na seção Túneis do File Manager.'
}
fm_tunnel_start
