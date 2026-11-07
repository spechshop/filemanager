#!/usr/bin/env bash
# Parse the complete launcher before executing a script received through curl | bash.
fm_tunnel_start() {
    umask 077
    if ! command -v node >/dev/null 2>&1 || ! command -v nohup >/dev/null 2>&1; then
        printf '%s\n' 'O túnel direto precisa de Node.js 22 ou superior e nohup.' >&2
        return 1
    fi
    if ! node -e 'if (Number(process.versions.node.split(".")[0]) < 22) process.exit(1)'; then
        printf '%s\n' 'Atualize o Node.js para a versão 22 ou superior para usar o túnel direto.' >&2
        return 1
    fi
    local launch_dir agent_pid
    launch_dir=$(mktemp -d "${TMPDIR:-/tmp}/filemanager-tunnel.XXXXXXXX") || return 1
    if ! node -e 'require("node:fs").writeFileSync(process.argv[1], Buffer.from(process.argv[2], "base64"), {mode: 0o600})' \
        "$launch_dir/agent.cjs" 'FILEMANAGER_AGENT_BASE64'; then
        rm -rf -- "$launch_dir"
        return 1
    fi
    nohup node "$launch_dir/agent.cjs" </dev/null >"$launch_dir/agent.log" 2>&1 &
    agent_pid=$!
    disown "$agent_pid" 2>/dev/null || true
    printf 'Túnel direto iniciado em segundo plano (PID %s). Você pode fechar este terminal ou a sessão SSH.\n' "$agent_pid"
    printf 'Log: %s/agent.log\n' "$launch_dir"
    printf '%s\n' 'Acompanhe o link e encerre a conexão na seção Túneis do File Manager.'
}
fm_tunnel_start
