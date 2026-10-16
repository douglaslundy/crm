# Resultado dos testes de carga: AAAA-MM-DD

- **Data e hora:**
- **Commit implantado:**
- **Carga dos outros projetos na VPS no momento:**
- **Versão do k6:** `grafana/k6:0.54.0`
- **Empresas semeadas:** 50

## Metas

| Cenário | Meta | Medido | Passou |
|---|---|---|---|
| Leitura | p95 < 800 ms | | |
| Escrita do CRM | p95 < 1,5 s | | |
| Aceite público | p95 < 1,5 s (429 tolerado) | | |
| Erros 5xx (todos) | < 1% | | |
| Emissão simulada | 95% autorizam em até 60 s a 20/s | | |
| API pública normal | p95 < 800 ms | | |
| API pública abusiva | só 200 e 429, `Retry-After` no 429 | | |
| Isolamento (checks) | 100% | | |

## Estresse

- **Ponto em que as metas deixaram de valer (usuários virtuais):**
- **Primeiro recurso saturado (PHP-FPM, worker, banco, CPU):**
- **O teste abortou sozinho?**

## Gargalos e correções

| Gargalo | Causa | Correção | Entrou na F8d ou virou tarefa |
|---|---|---|---|

## O que não foi medido

