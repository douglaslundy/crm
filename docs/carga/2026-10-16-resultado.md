# Resultado dos testes de carga: 2026-10-16

- **Data e hora:** 16/10/2026, 17:38 a 18:30 (UTC da VPS)
- **Commit implantado:** 5cc4998 (scripts k6 com `noCookiesReset` copiados à mão depois)
- **Carga dos outros projetos na VPS no momento:** não medida. A VPS (4 núcleos, 7,4 GB) é compartilhada e o k6 rodou nela mesma, dividindo CPU. Load médio chegou a 24.
- **Versão do k6:** `grafana/k6:0.54.0`
- **Empresas semeadas:** 50 (removidas ao final com `carga:limpar`)

## Metas

| Cenário | Meta | Medido | Passou |
|---|---|---|---|
| Leitura (100 usuários, 12 min) | p95 < 800 ms | p95 1,27 s, mediana 875 ms, máx 2,64 s | **Não** |
| Escrita do CRM (50 usuários, 12,5 min) | p95 < 1,5 s | p95 572 ms | Sim |
| Aceite público | p95 < 1,5 s (429 tolerado) | p95 260 ms | Sim |
| Erros 5xx (todos) | < 1% | 0% nos quatro cenários | Sim |
| Emissão simulada | 95% autorizam em até 60 s a 20/s | p95 63 s; só 14,7 emissões/s sustentadas (2712 iterações descartadas) | **Não** |
| API pública normal | p95 < 800 ms | p95 157 ms | Sim |
| API pública abusiva | só 200 e 429, `Retry-After` no 429 | 100% dos checks | Sim |
| Isolamento (checks) | 100% | 100% (leitura 63658/63658) | Sim |

## Estresse

Não rodado (VPS compartilhada). Só vale depois de repetir leitura e emissão em ambiente com mais folga.

## Gargalos e correções

| Gargalo | Causa | Correção | Entrou na F8d ou virou tarefa |
|---|---|---|---|
| Leitura p95 1,27 s | Não é consulta lenta: ocioso, cada rota responde em 21 a 50 ms (`auth/me` 21, `clientes` 23, `notas` 30, `crm/funis` 30, `crm/agenda` 35, `dashboard/fiscal` 44, `crm/resumo` 44, `crm/negocios` 50). Com 100 usuários (~60 req/s) a CPU de 4 núcleos compartilhados saturou (crm-app ~158%, crm-db ~128%, load 24), com k6 na mesma máquina | Nenhuma no código. Repetir com k6 em outra máquina; avaliar mais núcleos | Tarefa em `TAREFAS.md` |
| Emissão: fila não acompanha 20/s | Um único worker (`crm-worker-1`); a fila chegou a 4279 jobs e levou ~15 min para drenar | `docker compose up -d --scale worker=3` e repetir | Tarefa em `TAREFAS.md` |

## O que não foi medido

SEFAZ e ADN reais; HTTPS do Caddy; navegador; NFS-e e NFC-e (o emissor simulado cobre só NF-e); estresse até 300 usuários. O cenário de API pública rodou com a fila de emissão ainda drenando (1778 jobs ao início).
Observação: o relatório da leitura saiu só em texto (`leitura.txt`); a pasta `saida` não tinha permissão de escrita nesse primeiro cenário.
