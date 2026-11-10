# Bash rcfile for FileManager terminals. Read with Bash, including on noexec.
# Keep the account's commands and preferences, adding fallbacks when needed.
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
    local command_path shell_path
    if command_path=$(/bin/bash "$FILEMANAGER_ROOT/scripts/terminal-command.sh" --prepare); then
        # Always rebuild from the original PATH: no stale /dev/shm entries.
        PATH="$command_path"
        export PATH
        shell_path="${PATH%%:*}/fm-npm-shell"
        if [ -x "$shell_path" ]; then
            if [ -z "${FILEMANAGER_NPM_SCRIPT_SHELL:-}" ]; then
                FILEMANAGER_ORIGINAL_NPM_SCRIPT_SHELL="${npm_config_script_shell-}"
            fi
            export npm_config_script_shell="$shell_path"
            FILEMANAGER_NPM_SCRIPT_SHELL="$shell_path"
        elif [ "${npm_config_script_shell:-}" = "${FILEMANAGER_NPM_SCRIPT_SHELL:-}" ]; then
            if [ -n "${FILEMANAGER_ORIGINAL_NPM_SCRIPT_SHELL:-}" ]; then
                export npm_config_script_shell="$FILEMANAGER_ORIGINAL_NPM_SCRIPT_SHELL"
            else
                unset npm_config_script_shell
            fi
            unset FILEMANAGER_NPM_SCRIPT_SHELL FILEMANAGER_ORIGINAL_NPM_SCRIPT_SHELL
        fi
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
