# Idle final de 600 segundos

Janela medida: 600,921 s, CPU média agregada **0,0432% de um núcleo**, p95 **0,000%**, p99 **0,994%**. Sem navegador, terminal conectado ou cliente Codex. Auto restart ligado.

| PID | Papel | Amostras | CPU média % | CPU p95 % | CPU p99 % | FDs min–max | Threads min–max | RSS KiB min–max |
|---:|---|---:|---:|---:|---:|---|---|---|
| 291952 | PTY Node | 595 | 0,0017 | 0,000 | 0,000 | 22–22 | 7–7 | 51956–51956 |
| 291959 | Codex bridge | 595 | 0,0033 | 0,000 | 0,000 | 25–25 | 7–7 | 52352–52352 |
| 292029 | Codex launcher | 595 | 0,0017 | 0,000 | 0,000 | 20–20 | 7–7 | 47724–47724 |
| 292055 | Codex native | 595 | 0,0232 | 0,000 | 0,988 | 29–34 | 26–28 | 81248–85352 |
| 292370 | supervisor | 595 | 0,0000 | 0,000 | 0,000 | 7–7 | 1–1 | 33252–33252 |
| 292415 | master/reactor | 595 | 0,0133 | 0,000 | 0,989 | 14–14 | 18–18 | 37020–37160 |
| 292512 | manager | 595 | 0,0000 | 0,000 | 0,000 | 9–9 | 1–1 | 19116–19116 |
| 292514 | worker | 595 | 0,0000 | 0,000 | 0,000 | 12–12 | 1–1 | 23104–23104 |

Ao terminar e encerrar supervisor/auxiliares: **zero processos restantes, zero filhos adotados pelo subreaper**. O conjunto mantém oito processos durante a janela; a soma de RSS inclui páginas compartilhadas, portanto não é PSS. Zero CPU significa nenhum tick detectado com a resolução de 10 ms do `/proc`, não ausência absoluta de trabalho.

O ensaio de filesystem separado, após aquecimento, contou 11 chamadas do filtro `%file,getdents64` em 60 s (antes: 35.816). Não se extrapolou essa contagem para a janela de 600 s: são medições diferentes.
