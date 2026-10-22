# Auditoria de segurança do FileManager

Data: 22/10/2026. Base: `newterm`, commit `894e52c`. Branch: `security/audit-regression-ci`.

**As correções e os testes não determinam o vetor do incidente anterior.** Não foram fornecidos logs forenses, imagem da VPS, histórico de sessões ou perfil/extensões do navegador. A existência de XSS, falhas de autorização ou avisos do PHP não comprova qual deles foi explorado.

**Esta alteração não representa uma liberação para produção:** oito achados do npm permanecem reais e foram aceitos temporariamente pelo proprietário para esta etapa, com correção acompanhada na [issue #15](https://github.com/spechshop/filemanager/issues/15). O runtime local também permanece anterior aos patches publicados em 21/10. As seis ocorrências históricas de credenciais receberam o tratamento específico documentado abaixo, após confirmação de revogação pelo proprietário. Não houve acesso SSH, implantação, merge na `newterm` ou alteração de uma VPS. Instalações de ferramentas, dependências de validação e processos de teste ficaram no ambiente de desenvolvimento e em árvores temporárias.

## Superfície investigada antes das correções

O [inventário HTTP](route-inventory.md) registra os 39 handlers e suas guardas originais. Também foram examinados dispatcher, arquivos estáticos, templates, consumidores de HTML/JavaScript, armazenamento de tokens, terminais, buscas, LSP, bridge de agente, execução de processos, sessões, arquivos compactados e scripts de inicialização.

A página inicial e as páginas de interface continuam públicas; elas não autenticam operações. Todas as 39 APIs passam pela mesma guarda no dispatcher **e** na entrada do handler. Os únicos arquivos públicos são os assets canônicos em `css/`, `js/` e `img/`. Arquivos do workspace, configuração, banco de tokens, locks e metadados de sessões não são assets públicos. Não há autenticação por cookies: as credenciais são bearer tokens explícitos. Cookies adicionados por proxies/serviços externos precisam ser avaliados na implantação.

## Achados e correções

A severidade considera a capacidade técnica da falha, não comprovação de exploração.

| ID / severidade | Arquivo, função e causa | Correção e evidência |
|---|---|---|
| S01 — Crítica | `deleteFile::api`, `freeRam::api`: `invalidToken()` finalizava a resposta, mas a expressão ternária permitia continuar até `rm -rf`/`sudo`. `chat4o::api` estava sem guarda; `phpParser::api` e `phpParserBackup::api` também não autenticavam. | Guarda comum `security::authorize()` com retorno imediato, também em `appController::call`. Testes invocam diretamente todos os 39 handlers sem credencial, observam chamadas de processos e preservação dos sentinelas. O subconjunto original tinha 17 falhas em 18 verificações; o mesmo subconjunto corrigido tem zero. |
| S02 — Crítica | Leitura, download, upload, criação, renomeação, cópia, exclusão, busca, stubs e extração aceitavam caminhos arbitrários. `checkRoute::check` montava nomes de assets a partir da URL, expondo JSON de configuração/sessões e permitindo traversal. | `securityPolicy::path/roots/tree/filename` valida componentes, caminho canônico, fronteira de diretório, escopo opcional do token, wrappers, caracteres de controle e symlinks. Validações cobrem todos os membros de lotes antes do primeiro efeito. Exclusão e cópia usam funções de filesystem. A raiz não pode ser excluída. Assets têm allowlist de diretórios e validação do destino real. Testes verificam conteúdo, existência, lixeira, origem de upload e ausência de disclosure. |
| S03 — Crítica | `plugins/Message/server/server.php::message` preferia `object.token` à identidade da conexão e alterava os FDs associados. Terminal/LSP verificavam existência da chave sem expiração. A abertura em `/` dispensava autenticação. Separar tokens pelo primeiro hífen também identificava incorretamente usuários. | Identidade fixada na abertura; payload deve corresponder exatamente ao socket. Token completo, origem, expiração e revogação são verificados. Chaves ambíguas são rejeitadas. Timers revogam conexões ociosas; mensagens e saída dos bridges revalidam o vínculo. A identidade é conferida **após** leituras que podem suspender corrotinas, protegendo reutilização de FD. Regressões antes: 15/18 falhas; depois: 0/18, incluindo troca de sessão durante IO. |
| S04 — Crítica | `pty.js` e `pty.php`: qualquer cliente que alcançasse loopback podia escolher um identificador e criar/reconectar um shell. Loopback isoladamente não autentica o bridge. Logs incluíam identificadores e comandos. | Segredo de serviço no header, rejeição de Origin no backend, token proprietário válido, checagem por frame/saída e timers de revogação. Backend Node nega antes do upgrade; backend Swoole fecha antes de criar o PTY. Ambos encerram sessões revogadas, inclusive desconectadas. Segredo criado com modo 0600; não vai ao navegador ou ao ambiente do shell. Testes de rede reais cobrem os dois backends e comandos-sentinela temporários. |
| S05 — Alta | Tabela/árvore de arquivos, breadcrumbs, upload, sugestões, chat, boas-vindas, notificações e token em localStorage chegavam a `innerHTML`, `.html()` e atributos de JavaScript sem codificação contextual. Permitiriam exibir avisos falsos e executar código na origem do painel. | Texto usa `textContent`/`.text()`. `FMUI.html/argument` separa contexto HTML de literais JS em templates existentes. Markdown do chat escapa a entrada antes de formatar. Notificações escapam título/corpo. Credenciais deixam de ser impressas no console. Testes DOM com múltiplos payloads, eventos de clique e chamadas reais dos renderizadores falham no código antigo e passam no atual. Isso não atribui o aviso anterior a esses pontos. |
| S06 — Crítica | `importGit::api`: regex aceitava caracteres de substituição de shell e o URL era concatenado ao comando. Parsers montavam código PHP executável com nomes fornecidos pelo cliente, mesmo quando o comando externo usava `escapeshellarg`. `dirCurrent` era concatenado a um `cd` entre aspas duplas. | Clone usa argv, HTTPS e hosts explícitos, sem opções fornecidas pelo cliente, transports locais ou redirects; destino canônico validado. Parsers usam a biblioteca em processo, sem gerar PHP para `php -r`. Diretório inicial de terminal é canônico e usa `escapeshellarg`. Testes de Git observam ausência de subprocesso para payloads de injeção, URLs locais, credenciais e hosts indevidos. Nomes com metacaracteres são tratados como dados, com criação/exclusão legítimas preservadas. |
| S07 — Alta | `database/tokens.lotus` estava versionado. `manage-tokens.php::generateToken` usava `md5(uniqid(rand()))`, sem gerador criptográfico. Escritas não eram atômicas. | Banco retirado do índice e ignorado, mantendo o arquivo local. Novas credenciais usam `random_bytes(32)` na CLI e `crypto.getRandomValues` no navegador, substituindo fingerprints como padrão de autenticação; salvamento atômico com modo 0600. Validação relê o armazenamento atual e considera `revoked`, removendo cache de autorização. Regra própria Gitleaks encontrou **cinco ocorrências históricas de tokens e uma credencial de proxy em comentário**. Posteriormente, o proprietário confirmou expressamente que todas essas credenciais já haviam sido revogadas há bastante tempo. A baseline exata descrita abaixo registra somente essas seis ocorrências; o agente não verificou a revogação diretamente nos serviços. |
| S08 — Alta | `codex-agent.js::performAction`: `thread.list` devolvia histórico compartilhado e `thread.resume` permitia reivindicar qualquer conversa conhecida. O mapa de donos existia somente por conexão. `chat4o` também compartilhava um arquivo de histórico. | Dono de conversa persistido como hash do token autenticado em arquivo privado; listagem é filtrada e resume valida o dono antes do RPC. Retomadas por outro usuário/revogadas não alteram o registro nem emitem RPC. Chat legado tem histórico por proprietário. Duas regressões verificam persistência, reconexão, isolamento e ausência de efeitos. Conversas antigas sem dono exigem associação offline pelo operador; não há reivindicação automática por um cliente. |
| S09 — Alta | Extração por `unzip/tar/unrar/7z` recebia diretório de destino sem validar todos os membros/links. Compactação e cópia poderiam seguir links de arquivos. | ZIP/TAR são lidos como dados; XZ e 7z/RAR só emitem dados por stdout, sem receber um diretório para extrair. Todos os membros são validados antes da primeira escrita; links não são materializados. Limites: 10 mil entradas e 512 MiB declarados/descomprimidos. Testes incluem entrada maliciosa tardia, traversal, symlink, hardlink, symlink pré-existente e operações válidas ZIP/TAR/TAR.XZ/7z/RAR. |
| S10 — Alta | `codex.php`, `utilsFunction::simplePost` e `libCurl::{get,put,post}` desabilitavam validação TLS. `getPreview` podia entregar protocolos/formatos de rede ao ffmpeg e usar temporário previsível. | TLS validado nas conexões externas; temporário exclusivo para preview e restrição de protocolos/demuxers do ffmpeg. Erros não devolvem saída de processos. O preview ainda exige ffmpeg atualizado e teste com a coleção real de mídias na homologação. |
| S11 — Média | CORS `*`, ausência de proteção de framing/referrer e verificações de origem. Não havia política comum de negação nem logs seguros. | CORS wildcard removido; validação de Origin/Fetch Metadata, `nosniff`, `Referrer-Policy: no-referrer`, `X-Frame-Options: DENY` e CSP para objetos/base/framing. Eventos registram apenas rótulos controlados, canal e horário; nunca tokens, comandos, URLs, nomes de arquivos ou conteúdo. |
| S12 — Alta | Lock npm original apontava 15 pacotes vulneráveis. Composer incluía pacote abandonado; constraints não limitavam vários majors. | `ws` atualizado, override de `protobufjs` no mesmo major e atualizações compatíveis do lock. Pacote abandonado substituído por `composer/class-map-generator`. `basic-ftp` foi atualizado de 5.3.1 para 6.2.3 por override restrito a `get-uri@6.0.5`, removendo quatro entradas da auditoria e preservando a integração FTP/PAC. **Permanecem 8 alertas npm** (4 altos, 4 moderados) na árvore do Intelephense. Eles não foram corrigidos; estão aceitos temporariamente por uma baseline exata e acompanhados na issue #15. |

## Execução e resultados locais

Os resultados textuais estão nesta pasta; são saídas dos testes, não uma afirmação de ausência de vulnerabilidades.

| Check | Resultado |
|---|---|
| HTTP, PHP convencional com doubles e handlers reais | [85 verificações, zero falhas](after-http.txt). Inclui todos os 39 handlers, credenciais ausentes/inválidas/expiradas/revogadas, escopos, lotes, upload/download, metacaracteres, origem e sentinelas. |
| WebSocket, política real e transporte simulado | [18 verificações, zero falhas](after-ws.txt); [antes: 15 falhas](before-ws.txt). Inclui FD reutilizado durante a leitura da credencial. |
| Arquivos compactados | [14 verificações, zero falhas](after-archive.txt), com PHP Zip/Phar e decodificadores reais 7z/XZ. |
| Interface DOM | [7 testes, zero falhas/zero skips](after-ui.txt); [os mesmos renderizadores antigos falham](before-ui.txt). JSDOM 30.1.3; não equivale a uma certificação de todos os navegadores. |
| Conversas do bridge | [2 testes, zero falhas](after-threads.txt); inclui handlers reais de resume/list e observação de RPC. Nenhuma conta/modelo externo é usado nos testes. |
| PTY real Node e PHP/Swoole | [2 testes, zero falhas/zero skips](after-pty.txt), portas aleatórias, shells e arquivos somente na fixture temporária. |
| HTTP/WebSocket reais Swoole | [1 teste, zero falhas/zero skips](after-swoole.txt), roteador real, multipart válido/negado e zero conexões ao PTY nos casos negados. Não inicializa middleware/serviços da instalação. |
| Regressões existentes | 22 verificações do file watcher e 13 do armazenamento/rotas passaram. Testes isolados de HOME, instalador temporário e helper de toolchain também passaram. |
| Sintaxe | Todos os PHP versionados; validado com PHP convencional 8.3.35. Lint não executa os arquivos. |
| PHPStan 2.3.1, nível 5 | Passa com **51 diagnósticos preexistentes** em baseline exata por mensagem/arquivo/contagem, todos reproduzidos na base. Novas classes de política/filesystem não têm exceções. As antigas exclusões genéricas foram removidas. Stubs documentam as APIs do libspech fornecido na implantação; não simulam que essa biblioteca foi auditada integralmente. |
| Semgrep 1.156.0 | Rulesets PHP, JavaScript e OWASP mais cinco regras próprias. Scan bloqueante, sem métricas, sem erros de parsing na superfície selecionada. Autoteste comprova detecção e caso permitido; regras próprias também detectam omissões/fallthrough/HTML no código original. Scripts Shell são analisados pelo ShellCheck; probes antigos de performance com TLS de laboratório e relatórios são excluídos do Semgrep, não do controle de versão. |
| Gitleaks, compilado do tag v8.28.0 | Árvore versionada atual limpa. Histórico completo: **5 ocorrências de tokens (4 tokens distintos) e 1 credencial de proxy comentada**. Após confirmação de revogação pelo proprietário, a baseline oficial reconhece exatamente essas seis ocorrências. A suíte verifica que credenciais novas e reinserções das antigas continuam bloqueantes. Uma varredura ampla também vê material privado ignorado da instalação e falsos positivos em vendor; isso não representa novos segredos versionados. Nenhum segredo real foi incluído no relatório. |
| Composer 2.9.5 | [Audit do lock: zero advisories e zero pacotes abandonados](composer-audit.json). Validação do manifesto passa, com aviso de licença não especificada já existente; esta tarefa não escolhe uma licença para o projeto. |
| npm 11.17.0 / Node 24.19.0 | [Antes da auditoria: 15 alertas](npm-before.json); após a primeira atualização: [12 alertas](npm-after.json); após `basic-ftp` 6.2.3: [8 alertas](npm-lsp/audit-after.json), 4 altos e 4 moderados. O CI executa o scanner real e aceita temporariamente somente esses oito pares de pacote, severidade e advisories. |
| ShellCheck 0.9.0 / actionlint 1.7.7 | Scripts versionados e workflow validados. Ajustados export e avisos de variáveis consumidas por helpers carregados dinamicamente. |

## Reprodução

Em um checkout de desenvolvimento, com Node 24, PHP convencional, Zip/Phar, 7z e xz:

```sh
composer install --no-plugins --no-scripts --ignore-platform-req=ext-swoole
npm ci --ignore-scripts
npm rebuild node-pty
python3 scripts/security/php-lint.py
vendor/bin/phpstan analyse --no-progress
php -n scripts/security/http-regression.php
php -n scripts/security/ws-regression.php
php scripts/security/archive-regression.php
npm run test:security:ui
npm run test:security:threads
npm run test:security:pty
npm run test:security:dependencies
npm run test:security:lsp
node --test scripts/security/npm-audit-policy.test.cjs
```

Novos identificadores do navegador usam 32 bytes aleatórios. Credenciais já registradas são preservadas por compatibilidade e precisam de rotação se eram fingerprints ou apareceram no histórico.

Doubles de Swoole exigem PHP convencional. `-n` não desliga extensões compiladas estaticamente: por exemplo, neste ambiente foi usado `/usr/bin/php8.3 -n`. A suíte falha explicitamente se o runtime não permite esse isolamento.

Os testes adicionais exigem uma CLI **realmente compilada com Swoole**:

```sh
SECURITY_PTY_BACKEND=php SECURITY_PHP_BINARY=/caminho/php-swoole npm run test:security:pty
SECURITY_PHP_BINARY=/caminho/php-swoole node --test scripts/security/swoole-integration.cjs
```

Para comprovar a falha antiga e a correção do mesmo subconjunto, sem executar comandos destrutivos reais:

```sh
PHP_PLAIN=/usr/bin/php8.3 bash scripts/security/reproduce-before.sh
```

Esse script extrai o commit original para `mktemp`, exige exatamente 17 falhas em 18 verificações antes e zero depois, e remove a fixture ao terminar. Os testes completos são sempre executados sem `SECURITY_BASELINE_SUBSET` no CI. `SECURITY_SOURCE` permite repetir os testes DOM/WebSocket contra o código original extraído, usando os doubles/encoders de teste atuais.

Semgrep: `python3 scripts/security/semgrep-regression.py` e o comando exato de scan está em [security.yml](../../.github/workflows/security.yml). Gitleaks: `python3 scripts/security/gitleaks_regression.py` e `python3 scripts/security/gitleaks_scan.py`, com o binário v8.28.0 no PATH e Python 3.11+. Falhas do scanner ou das regressões continuam bloqueantes.

## Baseline de credenciais históricas revogadas

O proprietário confirmou expressamente que todas as credenciais dos seis achados de [gitleaks-summary.json](gitleaks-summary.json) foram revogadas há bastante tempo. São cinco ocorrências de quatro tokens distintos em `database/tokens.lotus` e uma credencial de proxy em comentário em `plugins/Extension/plugins/libCurl.php`. Essa informação foi fornecida pelo proprietário; **o agente não consultou os serviços para verificar a revogação**.

[.gitleaks-baseline.json](../../.gitleaks-baseline.json) usa o [mecanismo oficial `--baseline-path`](https://github.com/gitleaks/gitleaks/tree/v8.28.0#creating-a-baseline), sem exceções por caminho, regex, tipo de credencial ou commit inteiro. Foi gerada com Gitleaks v8.28.0, `--redact=100` e conferência exata dos seis identificadores do inventário. `Secret` e `Match` contêm somente `REDACTED`; nenhum valor de credencial foi versionado no novo relatório. Os fingerprints são `commit:arquivo:regra:linha`.

Na [implementação v8.28.0](https://github.com/gitleaks/gitleaks/blob/v8.28.0/detect/baseline.go), o campo `Fingerprint` é informativo: a comparação oficial usa commit, arquivo, regra, descrição, linhas/colunas, entropia e metadados do commit. Com redação ativa, não compara o texto de `Secret`/`Match`. Por isso a baseline conserva os metadados completos, além dos fingerprints; uma lista só de fingerprints não seria uma baseline válida para esta finalidade. Outro commit não corresponde à ocorrência aceita, mesmo que repita o segredo no mesmo arquivo e linha.

O [executor](../../scripts/security/gitleaks_scan.py) usa a baseline oficial, redação integral e `--log-opts='--all --full-history --diff-filter=tuxdb'`, preservando o filtro padrão da versão fixada. O checkout do job tem `fetch-depth: 0`; clones rasos são recusados. Todas as refs disponíveis são examinadas, sem restringir ao diff do PR, a uma data ou aos commits posteriores à baseline. A configuração [.gitleaks.toml](../../.gitleaks.toml) permanece integralmente inalterada, com regras padrão e ambas as regras próprias.

Hashes SHA-256 fixam os bytes revisados da configuração e da baseline. Qualquer alteração exige revisão explícita, incluindo mudanças aparentemente cosméticas. Isso também protege os dois arquivos que o próprio scanner deixa de analisar por serem sua configuração/baseline. Uma atualização de regras, baseline ou versão do scanner deve revisar a semântica oficial, executar a suíte completa e justificar a atualização dos hashes; não se deve regenerar a baseline a partir de todos os achados novos. O executor não atualiza hashes nem baseline automaticamente.

A [suíte de regressão](../../scripts/security/gitleaks_regression.py) executa o binário real em repositórios temporários. A [evidência local](after-gitleaks.txt) registra dez testes passando e zero achados fora da baseline no scan final. A suíte verifica:

- Sem baseline, o histórico real falha com exatamente os seis achados do inventário; com baseline, retorna zero achados e exit 0.
- Novos tokens FileManager, credencial de proxy comentada e token sintético GitHub são bloqueados (exit 1), inclusive nos dois caminhos históricos.
- Segredos sintéticos das duas regras próprias e de uma regra padrão são aceitos em uma baseline temporária, removidos e reinseridos identicamente em outro commit. A reinserção falha (exit 1), preservando os demais metadados usados na comparação oficial. Isso exercita o mecanismo de reutilização de segredo antigo sem criar commits com credenciais reais; a baseline real de seis achados é validada separadamente contra o histórico existente. Fixtures e relatórios redigidos são removidos ao terminar.
- Mudanças nas regras padrão, regex, descrição, remoção da regra de proxy e inclusão de exceção genérica são recusadas pela guarda de integridade. Uma descrição modificada também faz o scanner oficial voltar a apontar os cinco tokens, em vez de absorvê-los silenciosamente.
- Expansão/alteração da baseline é recusada, e nenhum campo do JSON contém as credenciais históricas.
- Um segredo removido antes de quatro commits limpos continua detectado; também é detectado quando existe apenas em outra branch. Checkout raso é recusado.

Limitações: a revogação é uma declaração do proprietário; os segredos continuam acessíveis em commits e clones antigos. Gitleaks é um detector baseado em regras, não uma prova de ausência de segredos nem um teste de validade junto aos provedores. O scan cobre o histórico alcançável pelas refs disponíveis ao checkout, não refs não buscadas ou objetos inacessíveis. Alterações intencionais nos scripts de CI e hashes precisam de revisão/proteção de branch: um teste no próprio repositório não impede alguém autorizado de modificar simultaneamente o controle e o teste. Os oito alertas do `npm audit` permanecem conhecidos, sem correção, sob a aceitação temporária detalhada abaixo. Não houve mudança de autenticação, implantação, acesso à VPS, reescrita de histórico ou merge na `newterm`.

## Baseline temporária do npm

O arquivo [npm-audit-accepted-risks.json](npm-audit-accepted-risks.json) registra exatamente oito pacotes, a severidade de cada entrada e o conjunto de advisories raiz propagado até ela. A política executa `npm audit --package-lock-only --json` sem `continue-on-error`; o exit 1 esperado para vulnerabilidades só é aceito depois que o JSON é validado. Erro de rede/registry, JSON ausente ou inválido, exit code inesperado, contagens inconsistentes, novo pacote, novo advisory ou mudança de severidade fazem o job falhar. A remoção de um achado conhecido é permitida.

Quando os oito achados permanecem, o resumo do GitHub Actions mostra um aviso explícito com 4 altos, 4 moderados e o link da [issue #15](https://github.com/spechshop/filemanager/issues/15). A suíte da política prova que pacote novo, advisory novo, severidade alterada, erro de execução e relatório inconsistente são rejeitados. Essa política registra aceitação de risco; não converte os achados em vulnerabilidades corrigidas nem afirma auditoria com zero alertas.

## CI e atualização contínua

[O workflow](../../.github/workflows/security.yml) executa em PRs para `newterm` e `security/audit-regression-ci`, pushes em `newterm`/`security/**`, semanalmente e por disparo manual. Há jobs independentes para PHP 8.3/8.4/8.5 convencional, Node/PTY, Swoole real, auditorias de dependências, Semgrep e ferramentas. Falta de extensão, decoder, arquivo de lock ou execução de um teste necessário é erro, não aprovação por skip.

Ações são fixadas em SHA; o token tem somente `contents: read`, checkout sem persistência de credenciais, sem secrets da implantação, sem `pull_request_target` e sem auto-merge/deploy. Testes de PR executam em runners descartáveis. Dependabot acompanha Actions/Composer/npm semanalmente, com cooldown de sete dias para atualizações de versão. O agendamento e Dependabot passam a vigorar ao integrar os arquivos na branch padrão (`newterm`). Recomenda-se exigir esses checks na proteção da branch; as configurações administrativas do repositório não foram alteradas.

## Evidência da execução no GitHub

[A execução anterior do PR para o código `f3dc771`](https://github.com/spechshop/filemanager/actions/runs/39715665326) aprovou sete dos oito jobs: as três versões de PHP, Node/DOM/conversas/PTY, Swoole real, Semgrep e ferramentas. O job de dependências falhou pelos 12 alertas então presentes. O PR #14 reduziu a auditoria a oito alertas e acrescentou regressões reais de FTP/PAC, LSP, Swoole e lifecycle; sua integração no PR [#12](https://github.com/spechshop/filemanager/pull/12) preserva essas evidências e aplica a política temporária descrita acima.

Também foi repetido `npm ci` a partir do lock em um checkout temporário limpo; os testes DOM, conversas, ambos os PTYs e HTTP/WebSocket Swoole passaram sem depender de node_modules/libspech da instalação local. A verificação final encontrou zero processos remanescentes nas fixtures e zero segredos na árvore versionada atual.

## PHP, Swoole e implantação segura

O README dizia PHP 8.1+, mas o lock já exigia pelo menos PHP 8.2. Agora o suporte declarado começa em **PHP 8.3 com patches de segurança atuais**, testado no CI em 8.3–8.5. PHP 8.1 encerrou o suporte; a situação atual está na [tabela oficial de suporte](https://www.php.net/supported-versions.php).

O [aviso oficial de 21/10/2026](https://www.php.net/archive/2026.php#2026-10-21-5) publica PHP 8.3.36, 8.4.27 e 8.5.12. O [inventário local](runtime-local.json) identifica PHP 8.5.10, Swoole 6.3.0RC1 e OpenSSL próprio: **não satisfaz o patamar de patches desse aviso**. A aplicabilidade exata ao build/SAPI/extensões deve ser confirmada com os mantenedores; isso não comprova exploração, nem justifica ignorar a atualização. O CI inclui `runtime-check.php`, e a CLI de implantação pode executar `php scripts/security/runtime-check.php --require-swoole`.

A compilação estática não é substituída por `apt upgrade`. Recompilar em builder isolado com fontes verificadas, incluir correções e CA bundle, testar a combinação exata de PHP/Swoole/OpenSSL e só então substituir o artefato por um procedimento de implantação separado. Nenhum build/instalação foi executado numa VPS de produção. O [canal de avisos do Swoole](https://github.com/swoole/swoole-src/security/advisories) deve ser acompanhado, além do PHP e das bibliotecas estáticas; não há atualização automática dessas partes pelo Composer.

Antes de implantar, o operador precisa:

1. Preservar evidências do incidente e confirmar sessões encerradas. O proprietário confirmou a revogação prévia das credenciais das seis ocorrências inventariadas; o agente não verificou essa revogação diretamente nos serviços. Qualquer exposição adicional exige tratamento próprio, sem ampliação automática da baseline. Remover um arquivo do commit não remove cópias do histórico/clones.
2. Corrigir os oito alertas npm aceitos temporariamente na issue #15, remover a baseline depois da correção, atualizar o runtime e manter os checks bloqueantes. Não liberar implantação a partir dos resultados locais de um runtime anterior ao patch.
3. Executar com usuário dedicado sem root; código/configuração, banco de tokens e `.runtime` privados e fora dos workspaces graváveis. Novos tokens são criptográficos e o banco deve ter modo 0600. Limitar o helper sudo ao comando fixo necessário.
4. Exportar `FILEMANAGER_ORIGIN` com a origem HTTPS externa exata e `FILEMANAGER_ALLOWED_ROOTS` como JSON de diretórios dedicados. O PHP não carrega automaticamente `.env`; configure o ambiente do serviço. Não autorizar `/`, a aplicação ou seus pais como workspace. Um token pode restringir `allowedRoots`; tokens com esse escopo não recebem terminal/admin por padrão. `terminal: true` concede privilégios de shell equivalentes ao usuário Unix, não uma sandbox de filesystem.
5. Manter PTY/LSP/agente em loopback e atrás do bridge autenticado, sem proxy público dessas portas. Preservar o segredo 0600 e não inseri-lo em páginas, URLs ou logs. Reiniciar os bridges na implantação para eliminar processos antigos sem a nova guarda.
6. Configurar TLS, validação do Host/origem no proxy, logs com query strings/identificadores WebSocket suprimidos e retenção segura. Tokens legados em URL/localStorage continuam sendo bearer credentials; não os copiar para tickets/logs. Preferir `Authorization: Bearer` em clientes HTTP novos.
7. Associar offline as conversas antigas a proprietários confirmados no registro privado `.runtime/codex-thread-owners.json` (`threadId` → SHA-256 do token). A rotação de um token também exige migrar a associação de suas conversas. Não disponibilizar uma opção de autoapropriação no navegador.
8. Testar autenticação, reconexão, download/streaming longo, revogação, recursos multimídia e restarts em homologação com a configuração real e com múltiplos workers, se utilizados. Aplicar quotas de upload, limites de corpo/conexões/processos/CPU/memória e manter ffmpeg/7z/xz atualizados.

## Limitações e riscos residuais

- As credenciais legadas mantêm o workspace compartilhado de administração. Isolamento de arquivos entre usuários exige `allowedRoots` explícitos por token; a implementação não inventa propriedade para arquivos existentes.
- Shell remoto e modos privilegiados do agente são funcionalidades administrativas: podem acessar tudo que o usuário Unix alcança. Restringir caminhos HTTP não transforma esse shell em uma sandbox; não compartilhar credenciais administrativas com usuários de menor confiança.
- Arquivos são revalidados e links rejeitados, mas PHP portátil não fornece uma transação `openat/O_NOFOLLOW` para todos esses fluxos. Um processo local com permissão concorrente de alterar diretórios (incluindo um shell já autorizado) ainda pode provocar corridas de filesystem. A separação de permissões Unix é parte necessária do modelo de segurança.
- Revogação é conferida por mensagem/transferência e por timers de 0,5–1 segundo. Ela interrompe canais/PTY; não desfaz operações anteriormente autorizadas, nem garante eliminar da máquina um daemon deliberadamente iniciado antes da revogação. Falhas de disco e revogações no meio de lotes já autorizados podem deixar trabalho parcial; negações no preflight são testadas sem efeitos.
- Restam scripts inline, dependências CDN nem todas protegidas por SRI, extensões do navegador e localStorage. A CSP adicionada restringe objetos/base/framing; **não é uma CSP estrita de scripts**. Homologação com navegador real, migração de scripts inline e fixação/SRI dos assets externos são trabalhos posteriores. A suíte DOM cobre os sinks identificados, não prova ausência universal de XSS.
- O CI testa um servidor Swoole isolado com código real e ambos os PTYs. Não inicia o supervisor completo, instaladores/reparadores, autenticação real do provedor de IA, proxy TLS de produção ou o build estático de produção. Esses componentes exigem homologação separada, sem segredos em PRs não confiáveis.
- O Semgrep cobre as linguagens/sinks descritos e tem um autoteste; não substitui revisão manual. A baseline PHPStan registra dívida anterior, não aprovação dessa dívida. A aprovação do job npm significa correspondência exata com os oito riscos aceitos temporariamente; não significa zero vulnerabilidades.
