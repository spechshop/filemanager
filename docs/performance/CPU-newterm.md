# Investigação de CPU — `newterm`, 13/09/2026

O problema principal do painel parado era um ciclo de realimentação no WebSocket
de métricas. O scanner era uma segunda causa importante, especialmente sem
clientes e com muitas dependências. A compressão no nível 9 também desperdiçava
CPU por resposta. Foram encontrados ainda problemas de vida útil de listeners,
corrotinas e subprocessos, reproduzidos antes de corrigir.

Baseline: `ba648027bb22af83c2b7387dfa337311f9d71c1d`, branch `newterm`.
A alteração local preexistente `fileManager.services.codex=false` foi preservada;
os ensaios controlaram essa opção explicitamente. Nenhum serviço da instalação
em uso foi reiniciado ou publicado. O código corrigido precisa ser reiniciado
na instalação para substituir os processos que já estavam carregados.

## Ambiente e interpretação dos números

Linux Ubuntu, kernel `7.0.0-31-generic`, x86_64, Intel i5-13450HX, 16 CPUs lógicas;
PHP 8.5.10 ZTS, Swoole 6.2.2, Node 24.13.0, Chrome 153.0.8010.36,
strace 6.8 e sysstat 12.6.1. OPcache CLI/JIT desabilitados.
`libspech` local: `5a7fae100decfb212e34e40182008cbaae50f899`.
O ambiente não tem a extensão PHP inotify. Não foi adicionada dependência.

**100% de CPU significa um núcleo lógico**, somando usuário e kernel. Não é
porcentagem da máquina inteira. A coleta por PID usa deltas de `/proc/PID/stat`
com `CLK_TCK=100`, aproximadamente uma amostra por segundo. Zero significa
nenhum incremento detectado nessa resolução, não uma promessa de custo físico
absolutamente nulo. CPU média é a soma das médias dos PIDs do serviço; percentis
de CPU são calculados sobre as amostras simultâneas. Latências estão em ms.

Os serviços foram executados em cópias sob `/tmp/filemanager-perf`, com TLS,
tokens fictícios e portas locais 18080–18092. Baselines de idle: 180 s após
estabilização; painel/protocolo: 120 s; navegador: 20 s de aquecimento + 120 s;
browse/editor/terminal: 60 s; Codex: 90 s por estado; HTTP: 30 s por caso,
16 conexões persistentes. O idle prolongado usa 600 s com supervisor, middleware,
PTY e Codex nativo iniciados, sem clientes.

A máquina era compartilhada com IDE, navegador e a instalação original.
Algumas sondas independentes rodaram simultaneamente. Isso afeta throughput e
latência absoluta; não some CPU do gerador de carga à do servidor. Por essa
razão, a decisão de compressão também usa repetições alternadas no **mesmo código
original** e carga fixa. A atribuição por PID e os contadores demonstram as causas
independentemente da variação de frequência/carga do host.

`perf_event_paranoid=4` impediu perf; ptrace attach aos processos existentes
também foi negado. Foi usado strace em processos filhos controlados, pidstat,
contadores de função, `getrusage()` e Chrome DevTools Protocol. Tempos de espera
em `futex` não foram interpretados como CPU ocupada.

## Comparação dos cenários

O agregado abaixo inclui master/reactor, manager e worker. Terminal inclui
também Node e shells residentes; Codex inclui bridge, launcher e binário nativo.
O supervisor é apresentado separadamente. Não há cliente nos cenários idle.

| Cenário | CPU antes | CPU depois | Redução | p95 antes | p95 depois | Erros antes/depois |
|---|---:|---:|---:|---:|---:|---:|
| Idle, autoRestart=true, 180 s | 6,180% | 0,011% | 99,82% | — | — | 0/0 |
| Idle, autoRestart=false, 180 s | 0,155% | 0,006% | 96,43% | — | — | 0/0 |
| Painel real Chrome parado | 53,431% | 0,110% | 99,79% | — | — | 0/0 |
| Painel, protocolo antigo, 120 s | 85,900% | 0,099% | 99,88% | cadência | cadência | 0/0 |
| Scanner PHP, +90 mil ignorados, 180 s | 25,695% | 0,066% | 99,74% | — | — | 0/0 |
| Browse, 10/500/5.000 arquivos, 60 s | 56,744% | 31,691% | 44,15% | 231,31 | 87,40 | 0/0 |
| Editor/API abrir + formatar, 60 s | 9,961% | 0,531% | 94,67% | 3,30 | 1,28 | 0/0 |
| Editor real + 20 gravações | 37,749% | 1,102% | 97,08% | — | — | 0/0 |
| Terminal conectado parado, 60 s | 0,132% | 0,017% | 87,39% | — | — | 0/0 |
| Terminal ~10 linhas/s, 60 s | 1,251% | 0,646% | 48,34% | — | — | 0/0 |
| Oito terminais, 60 s | 0,298% | 0,067% | 77,63% | — | — | 0/0 |
| Terminais desconectados, 60 s | 0,116% | 0,017% | 85,64% | — | — | 0/0 |
| Codex desabilitado, 90 s | 0,088% | 0,000% | resolução de zero | — | — | 0/0 |
| Codex habilitado parado, 90 s | 0,276% | 0,177% | 35,90% | — | — | 0/0 |
| Codex conectado/health, 90 s | 0,099% | 0,022% | 77,72% | — | — | 0/0 |
| HTTP HTML, carga fixa 3 req/s, 60 s | 25,209% | 11,540% | 54,22% | 103,87 | 59,08 | 0/0 |

A latência de resposta do **protocolo antigo de métricas** passou de p95 0,466 ms
para 1.001,565 ms porque o servidor agora entrega no máximo uma atualização por
segundo. Isso é a cadência intencional das métricas, não latência de APIs/editor.
O painel novo espera 1 s antes de pedir outra amostra. Terminal, arquivos e
outros WebSockets não recebem esse limitador.

Browse respondeu 429 → 600 pedidos em 60 s, p50 22,68 → 8,92 e p99 290,04 →
100,78 ms. O editor/API respondeu 588 → 594 pedidos, p99 3,92 →
1,51 ms. O teste real abriu Monaco, alterou o modelo ativo, pressionou Ctrl+S
20 vezes e encontrou `PERF_EDITOR_SAVE_19` no arquivo salvo. Sua janela de CPU
inclui carregamento, painel parado e edição, não somente o handler de save.
O custo restante de browse grande continua relevante; não foi escondido.

## Atribuição por PID

Na captura inicial da instalação em uso, 180 amostras do pidstat mostraram:

| PID | Processo | CPU média |
|---:|---|---:|
| 139671 | `server.php`, supervisor | 1,850% |
| 147835 | `middleware.php`, master/reactor | 34,479% |
| 147875 | manager Swoole | 0,000% |
| 147877 | worker Swoole | 23,932% |
| 140024 | PTY Node | 0,006% |
| 149839 | `codex-agent.js` | 0,000% |
| 152077 | bridge LSP Node | 0,000% |
| 153709 | Intelephense | 0,000% |

LSP e Codex já tinham listeners separados apesar de flags locais desabilitadas:
desabilitar a inicialização não encerra um serviço já destacado. Eles não eram
a fonte de CPU nessa captura. Não foram mortos para fabricar um ganho.

Exemplos de pares controlados, com cada papel separado:

| Cenário/papel | PID antes | CPU antes | PID depois | CPU depois |
|---|---:|---:|---:|---:|
| panel, master/reactor | 170189 | 43,698% | 253438 | 0,033% |
| panel, manager | 170206 | 0,000% | 253455 | 0,000% |
| panel, worker | 170208 | 42,202% | 253457 | 0,066% |
| terminal-active, master/reactor | 170189 | 0,214% | 253475 | 0,066% |
| terminal-active, worker | 170208 | 0,148% | 253584 | 0,083% |
| terminal-active, PTY Node | 179891 | 0,378% | 253476 | 0,215% |
| terminal-active, shell | 179906 | 0,510% | 253782 | 0,282% |
| codex-idle, Codex bridge | 189878 | 0,011% | 257385 | 0,000% |
| codex-idle, Codex launcher | 189931 | 0,000% | 257438 | 0,000% |
| codex-idle, Codex native | 189938 | 0,199% | 257445 | 0,166% |

Todos os PIDs, médias, p95/p99 de CPU, RSS, FDs e threads de cada janela estão em
[cpu-summary.json](results/cpu-summary.json); as amostras individuais estão em
[samples.csv.gz](results/samples.csv.gz). Os `/usr/bin/sleep` muito curtos do
comando de terminal podem não aparecer no sampler de 1 s. As variações pequenas
do Codex ocioso não demonstram otimização do binário nativo; nenhuma foi feita.

O sampler inicialmente também capturou comandos de instrumentação cujo cwd era
a fixture. Eles foram mantidos em `earlier-round-samples.csv.gz` e excluídos do agregado
por papel; a rodada final foi repetida com as instâncias isoladas limpas. Os PIDs 197106/197123/197125 eram outra instância de diagnóstico em porta
efêmera 41203, sobrevivente à interrupção da sessão de ferramentas, e foram
excluídos explicitamente e encerrados. Não eram a instância medida na porta
18082. A exclusão está em [excluded-processes.json](results/excluded-processes.json).

## Prova das causas e correções

**1. WebSocket de métricas — causa principal do painel parado.**
Em `plugins/Request/modules/makeTable/preloadScripts.html`, `ws.onmessage`
enviava imediatamente outro pedido. `plugins/Message/server/server.php` respondia
sem cadência. Mesmo com os caches de CPU/memória/disco que já existiam em
`utilsFunction`, permaneciam milhares de frames TLS, callbacks, conversões JSON
e alterações do DOM por segundo. Não era necessário reler `/proc` em cada
mensagem para produzir o problema.

Chrome real: **551.036 → 119 mensagens em 120 s**, com **zero novos requests HTTP**
na janela parada e nenhuma exceção JS. O cliente que reproduz exatamente o
feedback antigo recebeu **565.449 → 120** respostas. A intervenção isolada no
protocolo elimina o tráfego contínuo; o servidor protege também abas antigas.
Existe no máximo uma resposta pendente por conexão, identidade da conexão é
verificada após a espera, e timers/referências são limpos ao desconectar.
CPU do renderer via CDP `ThreadTime`: aproximadamente **94,78% → 3,11%** durante
os 120 s; isso é uma medida do renderer, separada dos PIDs PHP.

**2. Scanner — principal causa em idle sem clientes.**
O `RecursiveTreeIterator(new RecursiveDirectoryIterator('.'))` em
`plugins/Start/server/server.php` percorria tudo antes dos `str_contains` de
exclusão. `autoRestart=false` reduziu o master de 6,180% para 0,155%, sem alteração
de código. A configuração original tinha `allowObservable=[]`, mas mesmo assim
pagava a travessia. Com PHP observado, havia hashing de todos os arquivos
observados a cada ciclo.

Microbenchmark com hooks Swoole, 10 execuções, médias das últimas nove:

| Fixture | Entradas antes/depois por scan | Diretórios depois | Hashes antes/depois estável | CPU ms antes/depois | Tempo ms antes/depois |
|---|---:|---:|---:|---:|---:|
| Pequena | 136 / 135 | 32 | 68 / 0 | 31,65 / 5,99 | 30,14 / 5,92 |
| +90 mil ignorados | 90.439 / 135 | 32 | 68 / 0 | 3.369,31 / 7,49 | 3.323,10 / 7,44 |
| Nova, extensões vazias, fixture grande | — / 0 | 0 | — / 0 | — / 0,002 | — / 0,004 |

A primeira execução antiga calculava 136 hashes: inserção e comparação do mesmo
arquivo. A fixture grande acrescenta 30 mil arquivos em cada um de `vendor`,
`node_modules` e `files`, em 100 subdiretórios por raiz. A contagem antiga inclui
entradas de arquivos **e** diretórios; a nova conta entradas efetivamente
inspecionadas depois de descartar os nomes ignorados. Não são contagens de
arquivos hashados.

No servidor real com essa carga de arquivos, o master passou de **25,695% para
0,066%**, com CPU p95 **107,30% → 0%** e p99 **108,07% → 1,99%**. O intervalo
continua **10.000 ms**. Agora `fileWatcher.php` observa somente `plugins` e
`libspech/plugins`, impede recursão em dependências e links de diretório, guarda
mtime/ctime/size/inode/device e só recalcula SHA-256 quando necessário.
Uma verificação adicional de metadados recentes evita perder saves de mesmo
tamanho no mesmo segundo. Detecta criação, remoção e rename; uma pasta
temporariamente ilegível conserva seu snapshot. Um único debounce de 250 ms
agrupa o lote encontrado, sem timer por arquivo.

Strace de três scans da fixture grande, incluindo startup do PHP em ambas:

| Syscall | Antes | Depois |
|---|---:|---:|
| `open` | 1.295 | 175 |
| `getdents64` | 6.633 | 192 |
| `stat` | 207 | 406 |
| `lstat` | 127 | 230 |
| `read` | 281.854 | 1.563 |

`stat/lstat` aumentam nesse recorte: a versão nova verifica metadados de forma
explícita. A redução vem de evitar a árvore ignorada e seus hooks, não de alegar
que toda syscall individual diminuiu. **`read` inclui notificações internas do
Swoole e pipes**, não somente conteúdo de arquivos. Essas contagens não são
comparáveis a tempo de CPU sem tracing; os tempos acima vêm de getrusage sem
strace. Hashes são contabilizados diretamente pela sonda PHP.

**3. Cache de um segundo — custo secundário, com hipótese parcialmente refutada.**
120 invocações instrumentadas da closure original: uma leitura de tokens,
uma listagem de páginas e oito chamadas `bufferPages::get()` em cada execução,
CPU média **0,386 ms/invocação**. Porém **zero templates preparados**: o `__DIR__`
passado era `plugins/Start/server`, e todas as oito chamadas retornavam `what?`.
Portanto não atribuímos o consumo observado a recompilar oito templates/s.
Além disso, globals do master não atualizam os globals dos workers. No PHP 8.5
medido, escrever no array devolvido por `cache::global()` tampouco alterava
`$GLOBALS` do próprio processo. A invalidação final usa atribuição direta a
`$GLOBALS`, validada com requests reais e WebSockets após troca de tokens.

O timer foi removido. Tokens e lista de rotas são atualizados sob demanda nos
workers, com cache de metadados e proteção para alterações rápidas. HTML e
imports continuam sendo renderizados a partir da fonte atual por request;
editar páginas/módulos não depende de reiniciar o processo.

Strace de filesystem em uma janela real de idle de 60 s, após aquecimento:

| Chamadas | Antes | Depois |
|---|---:|---:|
| `open` | 8.802 | 6 |
| `getdents64` | 26.472 | 0 |
| `lstat` | 482 | 5 |
| `access` | 60 | 0 |
| Total desse filtro | 35.816 | 11 |

Depois restam consultas da configuração a cada 10 s. O filtro é
`%file,getdents64`; **não inclui todas as chamadas `read`**. A remoção do timer
não exige Redis, outro serviço, nem um novo watcher de tokens/páginas.

**4. Supervisor e vida útil dos serviços.**
`plugins/Extension/plugins/terminal.php` tinha `Timer::tick(10)` para sondar os
pipes do middleware: cerca de 100 mudanças de contexto/s no pidstat e 1,85% de
CPU no supervisor. Agora `stream_select()` bloqueia no SO até dados ou EOF,
fecha cada pipe e executa `proc_close()` para colher o filho. `server.php` usa
argv diretamente, sem shell intermediário, e encaminha SIGTERM/SIGINT. O teste
isolado de SIGTERM do supervisor completou em **15,4 ms**, com filho encerrado.

`pty.js` anexava um listener de dados por conexão e nunca o retirava. Após 40
reconexões: **44 → 1 listeners**, **42 → 1 ocorrências** de um marcador no replay.
Agora existe uma assinatura por PTY; clientes apenas entram/saem de um conjunto.
Histórico, resize, shell e reconexão continuam funcionando. A saída e morte do
terminal limpam as referências sem duplicar a assinatura.

No relay Codex/LSP, o leitor encerrava no EOF, mas o escritor ficava suspenso em
`Channel::pop()`. Com 40 sessões autenticadas, matar o backend deixou **41
corrotinas/40 workers de bridge**; depois, **1/0**. A primeira mensagem após
reconectar falhava antes e funciona depois. FDs voltaram de 54 para 14 em ambas;
o vazamento era de corrotinas, não de FDs. A memória alocada PHP atingiu 14 MiB e
permaneceu como reserva do allocator; isso não foi chamado de leak de RSS.
Agora EOF fecha o canal e desperta o escritor, que fecha o socket e libera a
referência correspondente.

Os timers do master precisam ser limpos em `BeforeShutdown`. Nos workers,
relays/heartbeat ainda vivos precisam ser liberados em `WorkerExit`; o teste
intermediário liberava a porta mas registrava `worker exit timeout` e deadlock.
Foi rejeitado. A versão final encerra sockets/canais, cancela heartbeat e permite
saída limpa. O teste final rejeita explicitamente esses logs. O evento usado é
documentado na [referência oficial do Swoole](https://wiki.swoole.com/en/#/server/events?id=onworkerexit).

`codex-agent.js` anteriormente podia sair antes de colher o app-server. Um filho
falso que ignora SIGTERM sobreviveu ao bridge antigo: saída em **0,205 s**. A
correção espera os filhos e o servidor; após 3 s força somente os filhos ainda
vivos: saída em **3,223 s**, **nenhum filho sobrevivente**. Timers de retry e de
aprovação são cancelados. Nos ciclos com Codex real, nenhum neto precisou ser
adotado pelo subreaper de teste após a correção.

## HTTP, compressão e workers

Foi preservada compressão habilitada, TLS, HTTP/2 e um worker. Só o nível padrão
foi alterado de **9 para 6**, depois de medir.

Comparação alternada no código original, HTML idêntico, gzip, 16 conexões,
três janelas de 20 s por nível:

| Nível | req/s nas três janelas | p95 ms nas três janelas | CPU ms/resposta | Bytes/resposta |
|---|---|---|---|---:|
| 9 | 14,60 / 14,65 / 15,20 | 1.147 / 1.148 / 1.113 | 70,11 / 69,77 / 67,54 | 115.279 |
| 6 | 48,80 / 49,40 / 51,50 | 342 / 335 / 316 | 22,08 / 21,80 / 21,17 | 116.858 |

Nível 6 reduz aproximadamente **68,6% de CPU por resposta**, aumentando o HTML
comprimido em **1,37%**. Outra comparação consecutiva: nível 3 deu 89,7 req/s,
p95 186,08 ms, 132.144 bytes; nível 6 deu 50,1 req/s, p95 331,12 ms,
116.858 bytes. Nível 3 economiza ainda mais CPU, mas aumenta o payload em
**13,08%** frente a 6; escolhemos 6 como compromisso, mantendo a configuração
ajustável. Brotli também foi medido: nível 9 → 6 deu 40,4 → 87,7 req/s,
p95 405,13 → 201,19 ms, CPU 26,76 → 12,97 ms/resposta e bytes 100.444 → 103.415.

Carga concorrente, código/configuração antes versus depois, 30 s, 16 conexões:

| Recurso | req/s antes/depois | p50 ms antes/depois | p95 ms antes/depois | p99 ms antes/depois | CPU antes/depois | RSS máximo somado KiB antes/depois | Erros |
|---|---:|---:|---:|---:|---:|---:|---:|
| HTML `/` | 6,3 / 50,1 | 2.538,14 / 319,11 | 2.705,77 / 334,71 | 2.833,15 / 347,03 | 102,3% / 108,9% | 118.596 / 94.908 | 0/0 |
| API `checkToken` | 6.753,4 / 19.115,4 | 2,30 / 0,60 | 3,82 / 1,71 | 5,99 / 2,00 | 202,3% / 140,8% | 106.736 / 81.524 | 0/0 |
| CSS | 787,2 / 2.714,6 | 19,96 / 5,82 | 25,27 / 6,38 | 38,54 / 7,06 | 120,9% / 134,9% | 110.672 / 83.416 | 0/0 |
| JS distribuído | 157,3 / 402,3 | 101,68 / 39,41 | 105,01 / 41,75 | 108,35 / 44,22 | 105,8% / 111,6% | 107.764 / 84.048 | 0/0 |
| JS gerado, 2.148.890 bytes | 4,0 / 11,6 | 4.009,19 / 1.376,07 | 4.257,88 / 1.406,55 | 4.321,25 / 1.451,55 | 102,6% / 104,3% | 144.844 / 105.588 | 0/0 |

Sob saturação o servidor novo pode usar mais CPU porque termina mais trabalho.
A prova de economia com o mesmo trabalho é a carga fixa de 3 req/s: 179 respostas
em cada versão, CPU **25,209% → 11,540%**, p50 **88,86 → 44,54**, p95
**103,87 → 59,08** e p99 **108,32 → 65,44 ms**, zero erros. Nas cargas
saturadas, pedidos ainda pendentes ao corte estão registrados
em `pending`, separadamente; não foram inventadas latências para esses pedidos.

HTTP/2 com um worker: HTML 49,83 req/s e p95 330,60 ms; API 18.809 req/s e p95
1,86 ms. HTTP/1 correspondente: 49,93 e 18.249 req/s. Não apareceu evidência para
desabilitar HTTP/2. Dois workers: HTML 101,1 req/s, p95 162,85 ms e CPU 216,6%;
API 36.499,6 req/s. Aumenta capacidade usando outro núcleo; não é correção de
idle, portanto não alteramos o padrão. Sem compressão, HTML chegou a 419,4
req/s e o arquivo grande a 344,5 req/s, às custas de transferir todos os bytes.

Os primeiros ensaios de `/js/script.js` retornavam um placeholder de um byte;
foram mantidos nos dados, mas **não** usados como resultado representativo de
JS. A tabela usa `/js/jquery.toast.min.js`. A matriz inicial ocorreu sob mais
carga externa que as repetições: não atribua toda a diferença de throughput do
HTML exclusivamente à compressão. Os pares alternados isolam essa decisão.

## Regressão, falhas e idle prolongado

- Suíte existente: baseline **100/100**; versão final **115/115** (inclui novas
  verificações de sintaxe descobertas pelo runner).
- Watcher: **22 asserções**, incluindo save de mesmo tamanho no mesmo segundo,
  touch sem conteúdo novo, criar/apagar/renomear, lote, diretório novo/removido,
  permissão negada/recuperação, extensões vazias e links/árvores ignorados.
- Caches de request: **13 asserções**; criação/revogação de token, JSON inválido,
  escrita atômica, remoção do arquivo, criação/rename/remoção de páginas e caches
  estáveis. Mais **12 verificações HTTP** de tokens, páginas e imports no mesmo
  processo, e teste WebSocket de novo token e revogação de conexão já aberta.
  JSON inválido/escalar não derruba o worker: o decoder valida o tipo e
  `checkToken.php` usa lista vazia em falha. Testes em árvore descartável, sem
  tocar nos tokens reais.
- Instalador nativo: todos os casos existentes passaram, incluindo classificação
  de erros de rede, fallback de toolchain e validação real de node-pty.
- Node PTY e fallback PHP PTY: **12 asserções por backend**, 40 reconexões,
  replay único, shell morto, oito clientes com 100 linhas cada, fechamento
  abrupto e encerramento explícito. Idle posterior de 45 s: PHP 0,000% e Node
  próximo da resolução de zero; FDs estáveis.
- Backend PTY morto mantendo conexão: **45 s sem incremento de CPU** nos três
  PIDs PHP; backend reiniciado, comando respondido, sem término forçado.
- Relay Codex/LSP compartilhado: 40 conexões com backend falso; EOF, fechamento
  abrupto e primeira mensagem de reconexão verificados. LSP real estava
  desabilitado nos ensaios isolados; não se atribui ao Intelephense o resultado
  do mock. Codex real foi validado adicionalmente nos ciclos completos.
- Auto restart: **41 casos/ciclos**, sendo 30 restarts automáticos do supervisor,
  mais criar, modificar, 20 saves rápidos, vários arquivos, rename, remoção,
  ignorados em `files`, `vendor`, `node_modules`, `stubs`, e mudança sob HTTP +
  WebSocket. PIDs antigos desaparecem; quatro processos residentes no cenário
  sem auxiliares; supervisor com sete FDs; nenhuma porta perdida permanentemente.
- Conjunto completo: **30 ciclos adicionais** de start → PTY/health Codex →
  restart, com oito processos durante uso (três Swoole, PTY, Bash, bridge Codex,
  launcher e nativo). Um PTY e um bridge Codex, sem duplicação, histórico
  preservado, FDs retornando ao estado entre ciclos. Ao encerrar: **zero processos
  restantes, zero zombies, zero filhos adotados pelo subreaper**; logs sem
  `worker exit timeout`/deadlock.

Restart continua sendo uma interrupção de serviço, não um deploy sem downtime.
O supervisor conserva a pausa preexistente de 3 s. No teste que deliberadamente
altera PHP durante carga, houve **18.241 erros de transporte em 20 s** no cliente
em loop, concentrados na indisponibilidade, e o servidor voltou. Isso não é
incluído nos zeros de erro da carga normal. O cliente de métricas de diagnóstico
daquela janela não reconecta sozinho; a recuperação de sessão é verificada nos
ciclos PTY/Codex, e o frontend preserva sua reconexão automática.

Os resultados finais do idle de 600 s, por PID, incluindo p95/p99, RSS, FDs e
threads, estão no registro `long-idle` de `cpu-summary.json`. O encerramento final
é registrado em `long-idle-cleanup.json`. O teste é repetido depois das últimas
correções de shutdown e invalidação de cache. Consulte também a tabela numérica em
[idle-final.md](idle-final.md).

## Reprodução e dados

Os [comandos completos](../../scripts/performance/README.md) preparam a revisão
original por `git archive`, copiam as dependências locais, geram certificados e
fixtures e executam os mesmos clientes/sondas. Não exigem infraestrutura de
profiling. `prepare.py` também foi executado em um segundo diretório para
verificar o preparador. Os scripts de carga não devem apontar para a instalação
real; todos os exemplos usam portas e tokens de benchmark.

Arquivos em `results/`: amostras por segundo, sumário por PID, respostas/latências,
resultados de regressão, contadores de scanner/cache, strace e pidstat. Logs de
autenticação, chaves TLS, tokens reais, perfis Chrome e dependências não são
versionados. Sondas com cwd incorreto, token inválido ou sessão interrompida
foram rejeitadas e repetidas; não contam como medição válida. Os ensaios
intermediários de shutdown com deadlock também não contam como aprovação final.

## Arquivos alterados e limites restantes

Produção: `server.php`, `middleware.php`, `pty.js`, `codex-agent.js`,
`plugins/Start/server/{server,fileWatcher}.php`,
`plugins/Extension/plugins/terminal.php`, `plugins/Database/call/call.php`,
`plugins/Request/views/controller.php`, `plugins/Request/router/server.php`,
`plugins/Request/apps/checkToken.php`,
`plugins/OpenConnection/websocket/OpenConnection.php`,
`plugins/Message/server/server.php`,
`plugins/Request/modules/makeTable/preloadScripts.html` e
`plugins/configInterface.json` (somente nível de compressão no commit).
Testes: `scripts/tests/{file-watcher,request-cache}-test.php`.
Ferramentas e evidências: `scripts/performance/`, `docs/performance/`.

O watcher é polling limitado às raízes de código: custo proporcional ao código
observado, independente das dependências ignoradas. Detecta alteração no próximo
ciclo de 10 s, mais debounce e restart; não promete latência de inotify.
Diretórios symlinkados não são percorridos. Snapshots de timestamp em segundos
exigem rechecagem de arquivos recém-salvos; o guard de mesma-segunda cobre o
comportamento normal, mas não pretende detectar adulteração deliberada de
metadados por ferramentas externas. HTML ainda é renderizado por request,
compressão grande ainda pode saturar um worker e diretórios enormes ainda
custam CPU quando efetivamente navegados.

Sessões de terminal desconectadas continuam vivas por funcionalidade existente,
até fechamento explícito; não foram removidas para reduzir processos. GPT ficou
desabilitado; não foi exercitado como serviço ativo. Codex foi exercitado com
login local e conexão/health, sem inferência remota ou geração faturável.
Os resultados provam o problema e a melhoria no ambiente descrito; medições em
hardware/carga/dependências diferentes devem ser repetidas com os scripts.
