# Dependências npm e regressões LSP

Investigação: 22/10/2026 UTC (21/10 em America/Porto_Velho). Base: `security/audit-regression-ci`, `f3dc77125c8bfa3dac67fcf785d23dd0712d40a2`. Branch: `security/npm-lsp-advisories`.

**Correção parcial; aprovação bloqueada.** A árvore instalada passa de 12 alertas (8 altos, 4 moderados) para 8 (4 altos, 4 moderados). O critério de zero alertas/8 jobs verdes não foi atingido. O job `Composer and npm advisories (blocking)` permanece falhando, sem exceções ou redução de severidade. PR #12 deve permanecer aberto para revisão e homologação. Nenhum deploy, acesso à VPS ou merge foi realizado.

## Causas e versões das 12 ocorrências

Os 12 nomes são entradas da auditoria, não 12 falhas independentes: três advisories se propagam aos consumidores. Todos pertencem à árvore de produção de `intelephense`; não são dependências exclusivamente de testes.

| Pacote | Antes | Depois | Causa/cadeia e resultado |
|---|---|---|---|
| `basic-ftp` | 5.3.1 | **6.2.3** | Parser de listagem Unix com custo quadrático de CPU, GHSA-c475-qrg2-pj4r; introduzido por `get-uri`. Correção upstream desde 6.2.1; aplicado 6.2.3, incluindo correções posteriores. Sai da auditoria da árvore instalada. |
| `get-uri` | 6.0.5 | 6.0.5 | Dependência `basic-ftp ^5.0.2` mantinha versão vulnerável; sai da auditoria pelo override restrito a `get-uri@6.0.5`. |
| `pac-proxy-agent` | 7.2.0 | 7.2.0 | Herda o alerta de `get-uri`; sai da auditoria. |
| `proxy-agent` | 6.5.0 | 6.5.0 | Dependência direta do Intelephense, herda `pac-proxy-agent`; sai da auditoria. |
| `braces` | 3.0.3 | 3.0.3 | Recursão sem limite de profundidade nos walkers da AST; padrões aninhados esgotam a pilha, GHSA-vfj7-8cjw-p6xm. Nenhuma versão oficial corrigida publicada. **Bloqueado.** |
| `micromatch` | 4.0.8 | 4.0.8 | Direto do Intelephense e transitivo de `fast-glob`; depende de `braces ^3.0.3`. **Bloqueado.** |
| `fast-glob` | 3.3.3 | 3.3.3 | Direto do Intelephense; depende de `micromatch`. **Bloqueado.** |
| `intelephense` | 1.18.5 | 1.18.5 | Dependência direta; agrega alertas de globbing, telemetria e, antes, proxy/FTP. Continua alto por `braces`. 1.18.5 segue sendo `latest`. **Bloqueado.** |
| `@opentelemetry/core` | 1.30.1 | 1.30.1 | Baggage de entrada sem limites de tamanho/entradas, GHSA-8988-4f7v-96qf; vem de `applicationinsights` e SDK 1.x. Correção oficial em 2.8.0; não há backport 1.x publicado. **Bloqueado.** |
| `@opentelemetry/resources` | 1.30.1 | 1.30.1 | `sdk-trace-base` → `resources` → `core 1.30.1`. **Bloqueado.** |
| `@opentelemetry/sdk-trace-base` | 1.30.1 | 1.30.1 | `applicationinsights` → SDK → `core`/`resources` vulneráveis. **Bloqueado.** |
| `applicationinsights` | 2.9.8 | 2.9.8 | Versão exata exigida por Intelephense; exige OpenTelemetry 1.x. A versão 3.17.0 disponível migra para outra major do SDK e tem diferenças de comportamento documentadas. Não aplicado override sem comprovação de equivalência. **Bloqueado.** |

Há também cópias não vulneráveis de OpenTelemetry 2.9.0/2.12.0 sob o SDK Azure; elas não substituem a cópia 1.30.1. A [árvore completa dos pacotes envolvidos](dependency-tree.txt) mostra essa coexistência.

Fontes: [advisory FTP](https://github.com/advisories/GHSA-c475-qrg2-pj4r), [advisory braces](https://github.com/advisories/GHSA-vfj7-8cjw-p6xm), [advisory OpenTelemetry](https://github.com/advisories/GHSA-8988-4f7v-96qf), consultas `npm view` ao registry público e manifests do lockfile.

## Decisões de compatibilidade

Foi adicionado somente `overrides["get-uri@6.0.5"].basic-ftp = "6.2.3"`. O diff do lock altera exclusivamente versão, URL e integridade de `basic-ftp`. O override prévio de `protobufjs ^8.8.1` foi preservado. Intelephense, node-pty, ws e jsdom não mudaram de versão.

A [mudança incompatível de FTP 6.0](https://github.com/patrickjuchli/basic-ftp/releases/tag/v6.0.0) proíbe por padrão hosts separados para transferência passiva, protegendo contra FTP bounce. Não reativamos esse comportamento. As APIs usadas por `get-uri` (`Client`, `access`, `lastMod`, `list`, `downloadTo`, `close`) foram verificadas com um servidor FTP local real. Os testes cobrem download, cache, erro de arquivo ausente, fallback LIST com listagem MLSD e PAC FTP até uma requisição HTTP real através de `proxy-agent`. Uma listagem Unix adversarial de aproximadamente 128 KiB é processada em subprocesso com prazo de 3 segundos, preservando a entrada válida. FTPS com certificados e FTP com hosts de dados separados não são cobertos por essa fixture; este último comportamento é deliberadamente restringido pela correção upstream. [Release 6.2.3](https://github.com/patrickjuchli/basic-ftp/releases/tag/v6.2.3).

Um override isolado de `core` 1.x para 2.x não é compatível. A inspeção das APIs exportadas mostrou remoção de `getEnv`, `getEnvWithoutDefaults`, `TracesSamplerValues` e outras usadas pelo SDK 1.30.1. Em subprocesso descartável, redirecionar apenas a resolução de `@opentelemetry/core` para a cópia 2.12.0 já instalada fez `BasicTracerProvider` falhar com `TypeError: Cannot read properties of undefined (reading 'AlwaysOn')`. Nenhuma alteração desse experimento foi aplicada à árvore instalada. Migrar todas as bibliotecas para 2.x também exige adaptação de APIs como `Resource`; isso não é uma atualização transitiva compatível. [Guia oficial 2.x](https://github.com/open-telemetry/opentelemetry-js/blob/main/doc/upgrade-to-2.x.md).

Substituir `applicationinsights` por 3.17.0 exige validação de telemetria/exportação/configuração e integração do fornecedor, além dos testes de editor. O [README oficial](https://github.com/microsoft/ApplicationInsights-node.js/blob/main/README.md) documenta limitações e mudanças em 3.x. Não removemos nem desabilitamos telemetria para ocultar o problema.

## Por que o downgrade não resolve

`npm audit` sugere 1.8.2 ao buscar um candidato fora do range direto que evite algumas cadeias transitivas. O campo `isSemVerMajor` sinaliza atualização fora do range; não prova que seja uma versão maior, segura ou funcionalmente equivalente. A sugestão não é garantia de auditoria limpa da nova árvore.

Foi feita uma resolução descartável de `intelephense: "1.8.2"`, mantendo as demais dependências e overrides do projeto, sem instalar/executar o servidor antigo: `npm install --package-lock-only`, seguido de `npm audit --json`. Resultado: **10 alertas, incluindo 2 críticos**, com `request`, `form-data`, `qs`, `tough-cookie`, `uuid`, `semver` e a mesma cadeia `braces`. Ver [auditoria do downgrade](audit-downgrade.json). Isso rejeita objetivamente a sugestão. O [changelog](https://github.com/bmewburn/vscode-intelephense/blob/master/CHANGELOG.md) registra ainda correções de vazamento de memória, diagnósticos e recursos PHP posteriores à linha antiga, que não devem ser perdidos.

## Dependências instaladas versus código carregado

`intelephense`, `node-pty` e `ws` são dependências de produção. `jsdom` e seus transitivos são exclusivamente de desenvolvimento para regressões DOM. Os 12 alertas pertencem à árvore de produção; nenhuma flag `omit` foi usada.

A integração existente permanece: Monaco → WebSocket Swoole (`plugins/Message/server/server.php`) → bridge autenticado em loopback (`lsp.js`) → processo Intelephense por conexão. O editor usa `initialize`, `initialized`, documentos, completion, hover e definition; a injeção da raiz permite indexar workspace/vendor.

**Limitação importante:** o servidor distribuído em `lib/intelephense.js` é um bundle. Um preload apenas nas fixtures capturou `require.cache` no encerramento; os módulos externos `braces`, `micromatch`, `fast-glob`, `applicationinsights`, `@opentelemetry/core` e `basic-ftp` não foram carregados separadamente no percurso LSP exercitado. Isso não demonstra ausência de código equivalente incorporado ao bundle, nem ausência de carregamento em outros percursos. O override corrige o pacote externo auditado e testado; **não é evidência de que o bundle proprietário tenha recebido essa correção**. É necessária uma distribuição corrigida e confirmação do fornecedor sobre as bibliotecas incorporadas. O bundle não foi modificado.

## Funcionalidades e ciclo de vida

`lsp-integration.cjs` executa Intelephense 1.18.5 real, sem mock do servidor, em workspace temporário. Verifica:

- `initialize` com raiz nula (igual ao editor), capacidades e `initialized`;
- indexação de outro arquivo do workspace; definição local e entre arquivos;
- autocomplete de método, hover e atualização de hover após edição;
- diagnóstico PHP para sintaxe inválida e remoção do diagnóstico após correção;
- `didOpen`, `didChange`, `didClose`, `shutdown`, `exit` e framing com texto Unicode;
- três ciclos completos, reconexão, PID encerrado e lista de filhos vazia;
- falha abrupta, revogação de sessão, reconnect negado, acesso sem credencial, erro de spawn, binário ausente, processo que ignora SIGTERM e encerramento do bridge;
- com `SECURITY_LSP_SWOOLE=1`, duas sessões reais passando pelo relay PHP/Swoole, initialize/hover/shutdown e reap dos processos.

Foram corrigidas lacunas de lifecycle do bridge: eventos `error` do spawn/stdin passam a fechar a sessão, SIGTERM/SIGINT encerram os filhos e um filho resistente recebe SIGKILL após 1,5 s. A porta de teste pode ser configurada via `FILEMANAGER_LSP_PORT`; o padrão segue 3057 e o bind segue 127.0.0.1. Autenticação, segredo, validação de sessão e políticas não foram modificados.

A medição usa `/proc` no Linux. Cada ciclo registra RSS, CPU acumulada e CPU em um intervalo ocioso de um segundo. A suíte exige RSS abaixo de 512 MiB, CPU ociosa abaixo de 500 ms, dispersão de RSS menor que 128 MiB e ausência de filhos ao final. Esses limites são guardas de regressão em uma fixture pequena, não dimensionamento de um workspace de produção. Não se afirma cobertura de funcionalidades premium que exigem licença nem homologação visual completa do editor.

## Validação e evidências

[Resultados locais](local-results.txt) e JSONs de auditoria preservam a diferença antes/depois. `npm ci` foi executado em instalação limpa antes e depois; `npm ls --all` após a instalação retornou zero, sem dependências inválidas. A instalação inicialmente existente divergia do lock (ws/protobufjs e ausência de jsdom), por isso não foi usada como evidência de compatibilidade.

Comandos reproduzíveis:

```sh
npm ci
npm audit --package-lock-only
npm run test:security:dependencies
npm run test:security:lsp
npm run test:security:ui
npm run test:security:threads
npm run test:security:pty
SECURITY_LSP_SWOOLE=1 npm run test:security:lsp
SECURITY_PTY_BACKEND=php npm run test:security:pty
node --test scripts/security/swoole-integration.cjs
```

A auditoria deve retornar **exit 1** enquanto os oito alertas permanecerem. Os testes PHP com doubles exigem PHP convencional sem Swoole compilado: `/usr/bin/php8.3 -n scripts/security/http-regression.php` e `ws-regression.php` passaram (85 e 18 assertivas). O PHP padrão local tem Swoole embutido mesmo com `-n`, causando colisões com os doubles; não se modificou esse runtime. Seu runtime-check também falha porque PHP 8.5.10 está abaixo do mínimo 8.5.12 exigido pelo projeto. O CI instala as versões e extensões necessárias para os checks de PHP 8.3/8.4/8.5. Essas limitações locais não foram convertidas em passes.

O workflow mantém os oito jobs e o comando de auditoria bloqueante intactos. Foram acrescentados testes de dependências/LSP ao job Node e LSP via relay ao job Swoole. Os resultados finais e os links de execução serão registrados no PR desta branch; a execução de referência anterior é [39715665326](https://github.com/spechshop/filemanager/actions/runs/39715665326), 7/8 aprovados.

## Alternativas e condição de desbloqueio

1. **Preferida:** release oficial do Intelephense com `braces` corrigido/substituído, telemetria migrada e bundle reconstruído; repetir instalação, auditoria e toda a suíte. Ainda não publicada na consulta efetuada.
2. Manter fork revisado de `braces` com limites de profundidade e testes diferenciais da API completa; solicitar ao fornecedor integração/rebuild. Exige manutenção e análise de semântica. Um alias para picomatch ou outra biblioteca não reproduz automaticamente parse/compile/expand e pode quebrar indexação/exclusões. Renomear um pacote ou aplicar patch sem rastrear o advisory não atende ao critério de segurança.
3. Migração coordenada de Application Insights/OpenTelemetry, validando recursos de telemetria e o bundle com o fornecedor. Override isolado de core foi comprovadamente rejeitado por incompatibilidade.
4. Avaliar outro LSP apenas com decisão explícita de produto e uma matriz de paridade (PHP, indexação vendor/Swoole, completion, diagnósticos, hover, definition, reconexão e licenciamento). Não foi substituído o servidor nem removida qualquer funcionalidade nesta alteração.

Até essa decisão/correção, manter o PR sem merge e o check de advisories falhando. A aprovação dos demais testes não autoriza implantação.
