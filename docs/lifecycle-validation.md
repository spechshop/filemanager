# Validação da supervisão — 2026-11-07

Ambiente: Ubuntu Linux, usuário sem root (UID 1000), Bash, PHP CLI 8.4.26
para as fixtures de supervisão, PHP 8.5.10 com Swoole para os testes de startup
existentes e Node.js 24.22.1. Nenhum reboot, serviço systemd ou crontab real
foi alterado pelos testes.

| Verificação | Resultado real |
| --- | --- |
| `python3 scripts/tests/supervision-test.py` | 20/20 aprovados em 239.775s |
| Recuperação após SIGKILL do PHP | 2.261s; worker antigo, inclusive em outra sessão, encerrado |
| `stop` e `disable`, com chamadas cron | Ambos desligados por 120.16s reais |
| Boot isolado depois de `stop` | Iniciou novamente com autostart habilitado |
| Boot isolado depois de `disable` | Continuou desligado, inclusive com chamada cron obsoleta |
| Recuperação de watchdog morto | Cron recuperou a supervisão e adotou o PHP sem duplicação |
| Quatro `start` concorrentes | Uma instância principal |
| Restart administrativo | Uma substituição do PHP e do supervisor |
| Falha no systemd de usuário | Unidade desabilitada antes de escolher cron/watchdog |
| Unidades systemd geradas | Sistema e usuário aprovados pelo `systemd-analyze verify` |
| Suítes existentes em `scripts/tests` | 23/23 aprovadas |
| `php run-tests.php` em cópia dos arquivos versionados | 135/135 verificações aprovadas |
| `python3 scripts/security/php-lint.py` | 113 arquivos PHP versionados aprovados |
| ShellCheck dos três scripts de implementação | Aprovado com `--severity=warning` |
| Workflow Lifecycle | YAML analisado e estrutura verificada |
| `git diff --check` | Aprovado |
| Processos vivos restantes das fixtures ao final | 0 |

Os testes do controlador (5 casos), bootstrap (6 casos), token e home também
foram repetidos após os ajustes finais. A suíte nova cobre preservação de dados
na atualização `--supervision-only`, saída inesperada com código zero, limpeza
de cron legado, instalação repetida, permissões de cron, falta de home gravável,
encaminhamento de comandos root à conta da instalação, PID obsoleto e rotação
dos logs. O teste root usa uma fixture de identidade/runuser, sem trocar a conta
real do processo de teste.

As interações de systemd são fixtures contratuais com processos PHP reais;
a validação das unidades usa o parser real do systemd. A política interna de
reinício do daemon systemd e um reboot físico devem ser validados na VPS de
teste conforme [o procedimento documentado](lifecycle.md#validar-um-reboot-verdadeiro).
O cron é uma fixture que executa as linhas instaladas por um shell real.

## Saída da execução completa

```text
test_boot_clears_stop_but_never_clears_disable (__main__.SupervisionTests.test_boot_clears_stop_but_never_clears_disable) ... ok
test_crash_restarts_in_about_two_seconds_and_cleans_children (__main__.SupervisionTests.test_crash_restarts_in_about_two_seconds_and_cleans_children) ...
RECOVERY PHP SIGKILL: 2.261s
ok
test_installer_falls_back_when_cron_rejects_the_account (__main__.SupervisionTests.test_installer_falls_back_when_cron_rejects_the_account) ... ok
test_installer_without_sudo_migrates_cron_and_is_idempotent (__main__.SupervisionTests.test_installer_without_sudo_migrates_cron_and_is_idempotent) ... unrelated
ok
test_killed_watchdog_is_recovered_by_cron_without_duplicating_php (__main__.SupervisionTests.test_killed_watchdog_is_recovered_by_cron_without_duplicating_php) ... ok
test_logs_are_bounded (__main__.SupervisionTests.test_logs_are_bounded) ... ok
test_root_watchdog_commands_use_the_installation_account (__main__.SupervisionTests.test_root_watchdog_commands_use_the_installation_account) ... ok
test_simultaneous_start_and_restart_have_one_managed_instance (__main__.SupervisionTests.test_simultaneous_start_and_restart_have_one_managed_instance) ... ok
test_stale_reused_main_pid_never_signals_an_unrelated_session (__main__.SupervisionTests.test_stale_reused_main_pid_never_signals_an_unrelated_session) ... ok
test_stop_and_disable_during_crash_backoff_cancel_recovery (__main__.SupervisionTests.test_stop_and_disable_during_crash_backoff_cancel_recovery) ... ok
test_stop_and_disable_remain_off_for_two_real_minutes (__main__.SupervisionTests.test_stop_and_disable_remain_off_for_two_real_minutes) ...
ADMINISTRATIVE STOP/DISABLE: both stayed off for 120.16s with cron ticks
ok
test_supervision_only_upgrade_preserves_application_data (__main__.SupervisionTests.test_supervision_only_upgrade_preserves_application_data) ... ok
test_unavailable_cron_preserves_unrelated_entries_and_uses_background (__main__.SupervisionTests.test_unavailable_cron_preserves_unrelated_entries_and_uses_background) ... ok
test_unexpected_successful_php_exit_is_also_restarted (__main__.SupervisionTests.test_unexpected_successful_php_exit_is_also_restarted) ... ok
test_user_manager_without_linger_uses_persistent_cron (__main__.SupervisionTests.test_user_manager_without_linger_uses_persistent_cron) ... ok
test_user_without_writable_home_can_enable_watchdog (__main__.SupervisionTests.test_user_without_writable_home_can_enable_watchdog) ... ok
test_failed_user_unit_is_disabled_before_cron_fallback (__main__.SystemdTests.test_failed_user_unit_is_disabled_before_cron_fallback) ... ok
test_generated_system_and_user_units_pass_systemd_verification (__main__.SystemdTests.test_generated_system_and_user_units_pass_systemd_verification) ... ok
test_system_administrative_commands_and_disable_order (__main__.SystemdTests.test_system_administrative_commands_and_disable_order) ... ok
test_user_linger_selects_systemd_and_removes_cron (__main__.SystemdTests.test_user_linger_selects_systemd_and_removes_cron) ... ok

----------------------------------------------------------------------
Ran 20 tests in 239.775s

OK
```

## Suítes existentes executadas

| Suíte | Exit code | Duração (s) |
| --- | --- | --- |
| `editor-save-test.cjs` | 0 | 0.11 |
| `file-list-loading-test.cjs` | 0 | 0.77 |
| `media-viewer-test.cjs` | 0 | 0.72 |
| `search-ui-test.cjs` | 0 | 0.57 |
| `trash-ui-test.cjs` | 0 | 0.62 |
| `tunnel-agent-test.cjs` | 0 | 20.83 |
| `tunnel-direct-bootstrap-test.cjs` | 0 | 20.18 |
| `tunnels-direct-integration-test.cjs` | 0 | 43.12 |
| `tunnels-integration-test.cjs` | 0 | 19.67 |
| `tunnels-ui-test.cjs` | 0 | 0.77 |
| `upload-ui-test.cjs` | 0 | 0.62 |
| `file-watcher-test.php` | 0 | 0.06 |
| `request-cache-test.php` | 0 | 4.08 |
| `search-files-test.php` | 0 | 0.42 |
| `terminal-supervisor-test.php` | 0 | 0.31 |
| `tunnels-test.php` | 0 | 0.07 |
| `filemanagerctl-test.py` | 0 | 21.63 |
| `install-codex-native-test.sh` | 0 | 0.21 |
| `install-tmp-test.sh` | 0 | 0.11 |
| `installer-bootstrap-test.py` | 0 | 0.11 |
| `installer-home-test.sh` | 0 | 0.06 |
| `installer-token-test.py` | 0 | 0.21 |
| `startup-swoole-test.py` | 0 | 17.87 |

## Ressalvas anteriores à alteração

O comando `php run-tests.php` diretamente neste workspace encontrou um arquivo
ignorado preexistente, `.cache/phpstan/resultCache.php`, com sintaxe inválida:
161/162 verificações passaram. Esse cache não foi modificado. A repetição em
uma cópia dos arquivos versionados, com as dependências existentes e sem caches
locais, passou em 135/135 verificações.

O ShellCheck global também encontrou dois avisos SC2034 anteriores nos scripts
inalterados `scripts/tunnel-agent.sh` e `scripts/tunnel-direct-agent.sh` (variável
`attempt` não utilizada). Os arquivos de implementação desta entrega passam
no ShellCheck. Não foram executadas auditorias remotas de dependências nem
novamente toda a suíte de segurança; as 23 suítes funcionais existentes foram
executadas, incluindo os testes de startup com Swoole real.
