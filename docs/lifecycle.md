# Supervisão e ciclo de vida

O controlador funciona diretamente no checkout (`./filemanagerctl`) e através
do link instalado no PATH. Cada instalação recebe um identificador derivado do
caminho físico do checkout. Nenhum comando usa `killall php` ou busca genérica
por nomes de aplicações PHP.

## Atualizar uma instalação existente

Atualize os arquivos do checkout e execute:

```bash
bash ./installer.sh --supervision-only
./filemanagerctl status
```

Essa opção atualiza apenas a supervisão. Não instala pacotes, baixa runtimes,
recria tokens nem altera configurações, sessões ou dados da aplicação. Pode
interromper brevemente um servidor em execução durante a migração. Preserva
`disable` e uma parada administrativa feita no boot atual. O instalador completo
utiliza o mesmo mecanismo de migração.

Execute como a conta da instalação. Para uma instalação pertencente a
`www-data`, um administrador pode usar:

```bash
sudo -u www-data /bin/bash /caminho/filemanager/installer.sh --supervision-only
```

Para instalar um serviço de sistema que execute como essa conta, use
`FILEMANAGER_SERVICE_USER=www-data` com o instalador executado por root/sudo.
Uma atualização como root preserva a conta registrada no diretório de estado.

## Seleção do supervisor

1. **systemd do sistema**, quando há privilégios. Novas unidades usam
   `filemanager-<id>.service`; uma unidade antiga `filemanager.service` só é
   reutilizada se seu `WorkingDirectory` corresponder a este checkout.
2. **systemd do usuário**, quando o gerenciador está acessível e
   `loginctl show-user <conta> -p Linger --value` confirma `yes`. O instalador
   tenta habilitar linger sem interação. Sem linger, passa ao cron, mesmo que
   o serviço do usuário funcione durante uma sessão de login.
3. **cron e watchdog**, sem exigir sudo. Há exatamente duas entradas marcadas
   com `# filemanager:<id>`: `@reboot` e uma chamada por minuto. Ambas executam
   o controlador local por caminho absoluto, com escape de espaços, aspas e `%`.
4. **nohup/setsid e watchdog**, para o boot atual quando não há mecanismo
   persistente disponível. Não há garantia de autostart nesse último caso.

As unidades systemd usam `Restart=always`, `RestartSec=2`,
`StartLimitIntervalSec=0`, `KillMode=control-group` e `TimeoutStopSec=15`.
O executável `/usr/bin/env` substitui a si próprio pelo PHP selecionado, sem shell
intermediário; isso também admite caminhos de runtime com espaços. Paradas
solicitadas ao systemd não acionam `Restart=always`. `filemanagerctl disable`
desabilita a unidade **antes** de pará-la, inclusive quando o comando parte de
um terminal do painel e será encerrado pelo próprio cgroup. O comando nativo
`systemctl disable` apenas remove o autostart; para parar também, use
`filemanagerctl disable` ou `systemctl disable --now`.

A migração desabilita supervisores antigos antes de escolher o novo. Remove
somente os marcadores desta instalação e os antigos lançadores PHP `@reboot`
que apontam exatamente para seu `server.php`. Entradas de outros aplicativos
são preservadas. Uma falha ao ler o crontab não é tratada como crontab vazio.
Sem acesso ao cron, o instalador avisa que não conseguiu verificar/remover
as entradas anteriores e oferece somente a execução em background neste boot.
O comando `disable` continua retornando erro se não conseguir remover essas
entradas. Se um supervisor anterior não puder ser encerrado, a atualização informa a
falha em vez de iniciar outro em paralelo.

## Estado e concorrência

O estado fica em `.runtime/supervision/`, privado da conta da instalação.
Arquivos de estado são dados; o shell nunca os executa com `source`.

| Arquivo | Finalidade |
| --- | --- |
| `autostart` | `enabled` ou `disabled`, persistente entre boots |
| `stopped-boot` | ID de `/proc/sys/kernel/random/boot_id` da parada administrativa |
| `run-boot` | Permissão de execução manual neste boot, mesmo com autostart desabilitado |
| `backend`, `unit`, `php` | Supervisor escolhido, nome da unidade e runtime absoluto |
| `main.pid`, `watchdog.pid` | PID, tempo de criação no `/proc` e ID do boot |
| `lifecycle.lock` | Serializa alterações administrativas e decisões do watchdog |
| `watchdog.lock` | Bloqueio exclusivo durante toda a vida do watchdog |
| `waiter.pid`, `recovery.pid` | Espera por PHP adotado e temporizador de recuperação |
| `restart-request` | Reinício pendente de um comando executado dentro do cgroup |

Um bloqueio adicional por usuário serializa as edições de cron entre instalações.
Os bloqueios não são herdados pelo PHP, pelo leitor de logs ou pelas esperas de
longa duração. PIDs antigos só são aceitos quando o tempo de criação e o boot
coincidem, evitando sinalizar um PID reutilizado.

O watchdog normalmente usa `wait` para aguardar o PHP sem polling. Após falha,
encerra os processos restantes da execução anterior e aguarda um temporizador
de 2 segundos antes de criar a nova instância. Processos que demoram a encerrar
podem prolongar esse intervalo. O PHP recebe uma sessão própria e uma variável
`FILEMANAGER_MANAGED_INSTANCE`, herdada pelos filhos. Isso permite localizar
inclusive workers que usam `setsid` e ficam órfãos. Na migração, também são
reconhecidos os argumentos exatos dos scripts desta instalação.

Se o watchdog morrer, o próximo tick do cron o recupera, normalmente em até um
minuto. O novo watchdog adota o PHP que ainda estiver vivo. Usa `pidwait`/pidfd
quando disponível; em distribuições antigas, verifica somente esse PID uma vez
por segundo. Não há uma segunda instância PHP durante essa adoção.

| Comando | Resultado |
| --- | --- |
| `start` | Limpa a parada deste boot e inicia com supervisão; preserva a escolha de autostart |
| `stop` | Grava a parada deste boot antes de encerrar supervisor, PHP e processos gerenciados |
| `restart` | Para e inicia de forma serializada; não altera o autostart |
| `disable` | Grava a desativação persistente, remove/desabilita autostart e para tudo |
| `enable` | Configura autostart, libera supervisão e inicia |
| `status` | Mostra PHP/PID, supervisor/PID, supervisão, autostart, parada administrativa e logs |

`stop` deixa as entradas cron instaladas, mas qualquer chamada no mesmo boot é
inócua. No próximo boot, o ID muda e o autostart habilitado volta a valer.
`disable` remove as entradas e mantém o estado persistente: até uma chamada
cron antiga já enfileirada não pode iniciar o servidor depois de outro boot.
`start` após `disable` permite execução supervisionada somente no boot atual;
use `enable` para restaurar a inicialização automática e a recuperação externa
do watchdog pelo cron.

Um reinício solicitado de dentro de um cgroup systemd é executado por uma
unidade transitória externa. O pedido tem um identificador cancelável: um
`stop`, `disable` ou outro comando posterior cancela o pedido anterior.
Para o backend watchdog, comandos root são encaminhados à conta proprietária,
usando também o crontab dessa conta.

## Logs e limites sem sudo

- Saída do PHP no watchdog: `filemanager.log`, mais `filemanager.log.1`.
- Eventos de supervisão: `.runtime/supervision/events.log`, mais `.1`.
- Cada arquivo é limitado a aproximadamente 1 MiB, com uma cópia anterior.
  O leitor divide linhas muito grandes em blocos de até 4096 bytes.
- No systemd, consulte também `journalctl -u <unidade>` ou
  `journalctl --user -u <unidade>`. A retenção do journal segue a política do host.
- Cron não gera saída periódica nem mensagens por e-mail.

Sem sudo, a conta precisa poder escrever no checkout/estado e ter acesso ao
`crontab`; o daemon cron precisa estar instalado, em execução e aceitar essa
conta. Isso inclui `www-data`, que pode ser bloqueada por `cron.allow` ou
`cron.deny`. O cron também precisa acessar a home definida para essa conta;
ela não precisa ser gravável. O controlador/watchdog usa o estado no checkout.
Sem cron e sem linger, o watchdog protege o PHP durante este boot, mas não pode
se recuperar de sua própria morte nem prometer inicialização após reboot.
Restrições do provedor que eliminem todos os processos da conta também exigem
um mecanismo externo permitido pelo provedor. Processos que removam
explicitamente sua identificação e se desliguem da árvore/sessão deixam de ser
rastreáveis pelo watchdog shell; systemd oferece o isolamento completo por
cgroup. Serviços de sistema continuam sujeitos às permissões administrativas
do systemctl; falhas de permissão são reportadas.

## Testes automatizados

```bash
python3 scripts/tests/supervision-test.py
python3 scripts/tests/filemanagerctl-test.py
python3 scripts/tests/installer-bootstrap-test.py
python3 scripts/tests/installer-token-test.py
bash scripts/tests/installer-home-test.sh
```

A suíte nova utiliza PHP, sinais, `flock`, sessões de processos e relógio reais.
Somente systemctl/loginctl/crontab são substituídos por fixtures. Uma cópia
isolada do controlador lê um ID de boot de teste: nenhum reboot real ou
crontab/serviço do host é alterado. As unidades geradas também são verificadas
pelo `systemd-analyze verify`. O teste de parada mantém duas instalações
respectivamente em `stop` e `disable` por **120 segundos reais**, executando
chamadas cron intermediárias e verificando ausência de servidor e watchdog.
A execução completa leva alguns minutos e faz parte do workflow `Lifecycle`.

A suíte cobre falha do PHP e de seus workers, recuperação do watchdog sem
PHP duplicado, partidas concorrentes, restart único, cancelamento de recuperação
por comandos administrativos, boot habilitado/desabilitado, migração de cron,
idempotência, linger, falta de home gravável, runtime persistido, rotação de
logs e atualização sem reinstalação.

## Validar um reboot verdadeiro

Em uma VPS de teste, registre o `boot_id`, o resultado de `status`, o supervisor
e seu crontab/unidade. Execute `enable`, depois `stop`, e confirme que continua
parado após pelo menos dois minutos. Reinicie a VPS por um canal administrativo
independente; ao reconectar, o `boot_id` deve ser diferente e `status` deve
mostrar o PHP ativo, sem parada administrativa do boot anterior.

Depois execute `disable` e faça um segundo reboot. O PHP e o watchdog devem
continuar ausentes; o autostart deve aparecer desativado. Termine com `enable`
e confirme a volta do serviço. Para testar falha real, use o PID principal
mostrado por `status`, envie `kill -KILL <PID>` e confira o novo PID e o evento
de recuperação. No backend cron, repita com o PID do watchdog e aguarde o
próximo minuto. Essas validações exigem uma VPS de teste; a suíte automatizada
não reinicia esta máquina.

Os resultados reais desta alteração estão registrados em
[Validação da supervisão](lifecycle-validation.md).
