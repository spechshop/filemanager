# Bash rcfile for FileManager terminals. Read with Bash, including on noexec.
# Keep the account's interactive preferences, then apply the managed runtime.
if [[ $- = *i* ]] && [ -r "$HOME/.bashrc" ]; then
    source "$HOME/.bashrc"
fi
FILEMANAGER_ROOT="$(cd -- "${BASH_SOURCE[0]%/*}/.." && pwd -P)"
export FILEMANAGER_ROOT
# Optional per-installation customizations, never rewritten by the installer.
[ ! -r "$FILEMANAGER_ROOT/.filemanager-profile" ] || source "$FILEMANAGER_ROOT/.filemanager-profile"
export FILEMANAGER_TERMINAL_BASE_PATH="${FILEMANAGER_TERMINAL_BASE_PATH:-$PATH}"
for fm_terminal_system_bin in /usr/local/bin /usr/bin /bin /usr/local/sbin /usr/sbin /sbin; do
    case ":$FILEMANAGER_TERMINAL_BASE_PATH:" in
        *":$fm_terminal_system_bin:"*) ;;
        *) FILEMANAGER_TERMINAL_BASE_PATH="${FILEMANAGER_TERMINAL_BASE_PATH:+$FILEMANAGER_TERMINAL_BASE_PATH:}$fm_terminal_system_bin" ;;
    esac
done
unset fm_terminal_system_bin
export npm_config_prefix="$FILEMANAGER_ROOT/.runtime/npm-global"
export npm_config_cache="$FILEMANAGER_ROOT/.runtime/npm-cache"

filemanager_refresh_commands() {
    local bin
    if bin=$(/bin/bash "$FILEMANAGER_ROOT/scripts/terminal-command.sh" --prepare); then
        # Always rebuild from the original PATH: no stale /dev/shm entries.
        PATH="$bin:$FILEMANAGER_TERMINAL_BASE_PATH"
        export PATH
        export npm_config_script_shell="$bin/fm-npm-shell"
        hash -r
    else
        printf 'Não foi possível preparar os comandos do terminal FileManager.\n' >&2
    fi
}
filemanager_refresh_commands
# Commands installed by npm become available at the very next prompt; an
# erased temporary launcher directory is recreated by the same hook.
if declare -p PROMPT_COMMAND 2>/dev/null | /bin/grep -q 'declare -a'; then
    PROMPT_COMMAND+=(filemanager_refresh_commands)
else
    PROMPT_COMMAND="${PROMPT_COMMAND:+$PROMPT_COMMAND; }filemanager_refresh_commands"
fi
