# Reprodução da investigação de CPU

Execute no Linux com as dependências **já instaladas do projeto**: PHP/Swoole,
Node, `ws`, `node-pty`, `libspech`, Composer, Python 3.12+, OpenSSL e strace.
O teste de navegador usa `/usr/bin/google-chrome`. O teste nativo de Codex usa
`.runtime/codex/bin/codex` e o login local existente, apenas `health`, sem iniciar
turnos de modelo. Os testes de falha do relay e de shutdown usam um backend falso.
Não há nova dependência de produção.

As ferramentas usam cópias locais, tokens fictícios e portas diferentes das do
FileManager em uso. `prepare.py` recusa diretório existente e portas ocupadas.
Os cenários devem rodar **sequencialmente**; vários reutilizam portas. Reserve
aproximadamente uma hora para a sequência inteira, conforme a máquina.

```bash
export FILEMANAGER_REPO="$(pwd)"
export FILEMANAGER_BENCH=/tmp/filemanager-perf-reproduce
python3 scripts/performance/prepare.py

# Baseline imutável ba648027 e versão do checkout atual: idle, painel, browse,
# editor/API, PTY, Codex, Chrome real. Janelas de 60–180 segundos.
python3 "$FILEMANAGER_BENCH/scenarios.py"
python3 "$FILEMANAGER_BENCH/browser-ui.py"
# Opcional: repetir somente a versão final em fixtures independentes concorrentes.
# Foi usado na rodada final; aumenta a carga externa do host.
# python3 scripts/performance/final-scenarios.py

# Compressão: 16 conexões, 30 s/caso; níveis 9/6/3 no código original.
python3 "$FILEMANAGER_BENCH/http-matrix.py"
python3 "$FILEMANAGER_BENCH/http-more.py"
python3 "$FILEMANAGER_BENCH/compression-repeat.py"
python3 "$FILEMANAGER_BENCH/fixed-http.py"

# 90 mil arquivos ignorados, medição do servidor real por 180 s/versão.
python3 "$FILEMANAGER_BENCH/large-runtime.py"
python3 "$FILEMANAGER_BENCH/fs-trace.py"

# Regressão/falhas: mudanças observadas, 30 restarts, 40 reconexões,
# backend morto, socket fechado, shell morto, filho que ignora SIGTERM.
python3 "$FILEMANAGER_BENCH/request-cache-http.py"
python3 "$FILEMANAGER_BENCH/bridge-failure.py"
python3 "$FILEMANAGER_BENCH/bridge-upgrade-failure.py"
python3 "$FILEMANAGER_BENCH/pty-failure.py"
python3 "$FILEMANAGER_BENCH/pty-backend-failure.py"
node "$FILEMANAGER_BENCH/pty-probe.cjs" "$FILEMANAGER_BENCH/before" before
node "$FILEMANAGER_BENCH/pty-probe.cjs" "$FILEMANAGER_BENCH/after" after
python3 "$FILEMANAGER_BENCH/codex-shutdown.py" before "$FILEMANAGER_BENCH/before"
python3 "$FILEMANAGER_BENCH/codex-shutdown.py" after "$FILEMANAGER_REPO"
python3 "$FILEMANAGER_BENCH/regression.py"
python3 "$FILEMANAGER_BENCH/stop-supervisor.py"
python3 "$FILEMANAGER_BENCH/fullstack.py"
python3 "$FILEMANAGER_BENCH/long-idle.py"

php run-tests.php
php scripts/tests/file-watcher-test.php
php scripts/tests/request-cache-test.php
bash scripts/tests/install-codex-native-test.sh
python3 scripts/performance/summarize.py "$FILEMANAGER_BENCH/results"
```

Instrumentação do scanner original, com os hooks de filesystem usados pelo
servidor. O **cwd do processo** precisa ser a fixture, não somente `chdir()` de
uma corrotina: as rotinas SPL e o cwd virtual do Swoole diferem.

```bash
for size in small large; do
    (
        cd "$FILEMANAGER_BENCH/scanner-$size"
        BENCH_HOOKS=1 php "$FILEMANAGER_BENCH/scanner.php" "$PWD" '["php"]' 10 > "$FILEMANAGER_BENCH/scanner-$size-hooked.jsonl"
        BENCH_HOOKS=1 php "$FILEMANAGER_BENCH/scanner.php" "$PWD" '[]' 10 > "$FILEMANAGER_BENCH/scanner-$size-empty.jsonl"
        php "$FILEMANAGER_BENCH/scanner-after.php" "$PWD" "$FILEMANAGER_REPO" php > "$FILEMANAGER_BENCH/scanner-after-$size-php.jsonl"
        php "$FILEMANAGER_BENCH/scanner-after.php" "$PWD" "$FILEMANAGER_REPO" empty > "$FILEMANAGER_BENCH/scanner-after-$size-empty.jsonl"
    )
done
(
    cd "$FILEMANAGER_BENCH/before"
    php "$FILEMANAGER_BENCH/cache-timer.php" "$PWD" > "$FILEMANAGER_BENCH/cache-timer.jsonl"
)
(
    cd "$FILEMANAGER_BENCH/scanner-large"
    strace -qq -f -c -e trace=%file,read,getdents64 -o "$FILEMANAGER_BENCH/scanner-large-before.syscalls" env BENCH_HOOKS=1 php "$FILEMANAGER_BENCH/scanner.php" "$PWD" '["php"]' 3
    strace -qq -f -c -e trace=%file,read,getdents64 -o "$FILEMANAGER_BENCH/scanner-large-after.syscalls" env BENCH_HOOKS=1 php "$FILEMANAGER_BENCH/scanner-after.php" "$PWD" "$FILEMANAGER_REPO" php 3
)
```

`sample.py ROOT SECONDS OUT.json` lê `/proc/PID/stat`, `fd` e `cmdline` uma vez
por segundo; filtra pelo cwd da fixture. Um núcleo = 100%, `utime+stime`, HZ do
sistema, RSS em KiB, contagem de threads e FDs. Processos com vida menor que a
amostragem podem escapar; não use essa ferramenta para atribuir CPU de comandos
efêmeros sem complementar com `pidstat`/strace. P95/p99 do JSON agregado são
percentis das amostras de CPU, não latência HTTP.

`http.cjs PORT PATH SECONDS CONCURRENCY [gzip|br|identity] [http1|h2]` registra
latências em ms, quantidade de respostas, bytes e erros. Pedidos pendentes no
corte da janela são informados separadamente. `fixed-http.cjs PORT SECONDS RPS`
permite comparar CPU com o mesmo trabalho, evitando interpretar CPU saturada
como regressão quando a versão nova responde mais pedidos.

`fullstack.py` mantém os auxiliares vivos entre restarts do middleware, valida
histórico PTY e health real do Codex, verifica PIDs/FDs e rejeita logs de término
forçado do worker. `long-idle.py` depende da fixture criada por esse teste e
mede 600 segundos sem clientes, encerrando também o supervisor no final.
`regression.py` provoca indisponibilidade deliberada durante restart; os erros
HTTP dessa janela devem ser analisados separadamente dos testes de carga normal.

O preparador foi executado também em `/tmp/filemanager-perf-reproducer-check`.
As medições publicadas foram feitas com os mesmos clientes/sondas durante a
investigação em `/tmp/filemanager-perf`, antes da organização destes drivers.
As cópias preservam o comportamento original; somente tokens, certificados,
portas, fixtures e configurações de cada cenário são controlados. Não execute
os scripts de falha apontando para um diretório de produção.
