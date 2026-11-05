#!/usr/bin/env bash
# This entire function is received before execution when downloaded with curl | bash.
fm_tunnel_main() {
    # FILEMANAGER_CONFIG
    umask 077
    local FM_WORK FM_BINARY FM_CHILD='' FM_INSTALL='' FM_NEXT_REPORT=0 FM_LAST_ACK=$SECONDS
    local FM_LAST_ONLINE=$SECONDS FM_FINAL_STATUS=offline FM_EXIT_CODE=0 FM_PUBLIC_URL=''
    FM_WORK=$(mktemp -d "${TMPDIR:-/tmp}/filemanager-tunnel.XXXXXXXX") || return 1
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
            fm_report error '' 'Não foi possível manter o túnel. Consulte o terminal na máquina remota.' || true
        else
            fm_report offline '' 'Conexão encerrada na máquina remota.' || true
        fi
        rm -rf -- "$FM_WORK"
        exit "$code"
    }
    trap fm_cleanup EXIT
    trap 'exit 130' INT
    trap 'exit 143' TERM HUP

    fm_install_cloudflared() {
        local FM_ASSET FM_SHA256 digest member download=''
        trap 'if [ -n "$download" ]; then kill "$download" 2>/dev/null; wait "$download" 2>/dev/null; fi; exit 143' INT TERM HUP
        case "$(uname -s):$(uname -m)" in
            # FILEMANAGER_PLATFORMS
            *) printf '%s\n' 'Sistema não suportado. Use Linux ou macOS, ou instale cloudflared no PATH.' >&2; return 1;;
        esac
        printf '%s\n' 'Baixando cloudflared oficial, sem sudo...'
        curl --fail --silent --show-error --location --proto '=https' --tlsv1.2 --connect-timeout 15 --max-time 180 \
            --max-filesize 104857600 --output "$FM_WORK/download" \
            "https://github.com/cloudflare/cloudflared/releases/download/FILEMANAGER_CLOUDFLARED_VERSION/$FM_ASSET" &
        download=$!
        wait "$download" || return 1
        download=''
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
        if [ "$digest" != "$FM_SHA256" ]; then
            printf '%s\n' 'A verificação SHA256 do cloudflared falhou.' >&2
            return 1
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
        FM_BINARY=$(command -v cloudflared)
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

    printf '{}\n' > "$FM_WORK/config.yml"
    local arguments=(tunnel --config "$FM_WORK/config.yml" --no-autoupdate --url "$FM_TARGET"
        --protocol http2 --ha-connections 1 --metrics 127.0.0.1:0)
    if [ "$FM_NO_TLS_VERIFY" = true ]; then arguments+=(--no-tls-verify); fi
    printf 'Transmitindo o serviço %s desta máquina. Mantenha este terminal aberto; Ctrl+C encerra o túnel.\n' "$FM_TARGET"
    "$FM_BINARY" "${arguments[@]}" > "$FM_WORK/cloudflared.log" 2>&1 &
    FM_CHILD=$!
    FM_LAST_ONLINE=$SECONDS
    while kill -0 "$FM_CHILD" 2>/dev/null; do
        fm_keepalive || exit 1
        if [ $((SECONDS - FM_LAST_ONLINE)) -gt 180 ]; then
            printf '%s\n' 'Tempo de conexão esgotado. Verifique a rede e tente novamente.' >&2
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
fm_tunnel_main
