# Runbook: testes de carga (F8d)

Mede o staging com 50 empresas e 100 usuários simultâneos (metas) e depois até 3x (estresse). Nada toca SEFAZ ou ADN: a emissão usa o emissor simulado. **Só no staging. Nunca em produção.**

Spec: `docs/superpowers/specs/2026-10-16-f8d-testes-de-carga-design.md`. Scripts: `carga/k6/`.

## 0. Antes de começar

- A VPS é compartilhada com outros projetos. Rode fora do horário deles.
- Abra um segundo terminal com `docker stats` e decida o limite de CPU/memória em que você cancela à mão.
- Confirme `APP_AMBIENTE=staging` em `~/crm-plataforma/src/deploy/.env` (`grep APP_AMBIENTE .env`). Se faltar, acrescente a linha. Sem ela, o emissor simulado e a semeadura **se recusam a rodar** (é a proteção).
- O `.env` do staging precisa de `INSTALL_DEV=1` (a semeadura usa as factories e o Faker).

## 1. Atualizar o staging

Reimplante como em `deploy/README.md` (`git archive` + `bash src/deploy/deploy.sh`, sem apagar `src/deploy/.env`). Depois, dentro de `deploy/`:

```bash
docker compose -f docker-compose.yml -f docker-compose.carga.yml config -q && echo OK
```

## 2. Ligar o emissor simulado

```bash
docker compose -f docker-compose.yml -f docker-compose.carga.yml up -d app worker
docker compose exec app php artisan tinker --execute="echo get_class(app(App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe::class));"
```

Tem que imprimir `App\Modules\Fiscal\Infrastructure\Simulado\EmissorDeNfeSimulado`. Se imprimir `NfePhpEmissor`, pare: o overlay não pegou.

## 3. Semear

```bash
docker compose exec app php artisan carga:semear --empresas=50
mkdir -p ../carga/dados ../carga/saida
docker compose exec -T app cat storage/app/carga/contas.json > ../carga/dados/contas.json
```

O arquivo tem chaves de API das empresas fictícias: não vai para o git (`carga/.gitignore` já ignora `carga/dados/`). Contas de login: `carga<N>.u1@carga.local` (admin) e `carga<N>.u2@carga.local` (vendedor), senha `Senha123`.

## 4. Rodar os cenários, um por vez

```bash
C="docker compose -f docker-compose.yml -f docker-compose.carga.yml --profile carga run --rm k6"
$C run --summary-export=/saida/leitura.json     /scripts/leitura.js
$C run --summary-export=/saida/crm-escrita.json /scripts/crm-escrita.js
$C run --summary-export=/saida/emissao.json     /scripts/emissao.js
$C run --summary-export=/saida/api-publica.json /scripts/api-publica.js
```

Entre um e outro, espere a fila esvaziar: `docker compose exec app php artisan queue:monitor default` (tamanho 0). O k6 termina com código diferente de zero se algum limiar falhar.

Metas (os limiares estão dentro de cada script): leitura p95 < 800 ms; escrita CRM p95 < 1,5 s; 5xx < 1%; 95% das notas acompanhadas autorizam em até 60 s com 20 emissões/s; API pública só 200/429, nunca 5xx.

Observações dos cenários:
- `crm-escrita.js` chama o aceite público da proposta. A rota tem `throttle:60,1` por IP e o k6 sai de um IP só, então 429 ali é esperado e tolerado.
- `api-publica.js` usa a conta 0 de propósito acima do limite (900/min contra 300/min) e confere o 429.
- Se o check `enviar devolve o link` falhar em `crm-escrita.js`, a resposta de `POST /api/app/crm/propostas/{id}/enviar` mudou: ver `PropostasController::um`.

## 5. Estresse (opcional, só depois dos quatro passarem)

```bash
$C run --summary-export=/saida/estresse.json /scripts/estresse.js
```

Sobe até 300 usuários virtuais. Aborta sozinho se o p95 passar de 5 s ou os erros 5xx de 10%. Vigie o `docker stats` e cancele com Ctrl+C se a VPS passar do seu limite.

## 6. Limpar e voltar ao normal

```bash
docker compose exec app php artisan carga:limpar
rm ../carga/dados/contas.json
docker compose up -d app worker      # sem o overlay: o emissor simulado desliga
docker compose exec app php artisan tinker --execute="echo get_class(app(App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe::class));"
```

O último comando tem que imprimir `App\Modules\Fiscal\Infrastructure\NfePhp\NfePhpEmissor`.

## 7. Como ler um resultado vermelho

| Sintoma | Onde olhar primeiro |
|---|---|
| Leitura lenta | Consulta lenta ou N+1: `docker compose logs app --tail=200`; ligar log de queries lentas no PostgreSQL |
| Escrita do CRM lenta | Locks do funil/etapas (`lockForUpdate` ao mover e criar negócio) |
| Fila não drena | Poucos workers: `docker compose up -d --scale worker=3` e repetir |
| 5xx | `docker compose logs app --tail=200` e o log do Laravel em `storage/logs` |
| 429 inesperado | Limite de requisições do plano de carga (300/min) ou throttle da rota pública (60/min por IP) |
| Login falhando | Origem fora de `SANCTUM_STATEFUL_DOMAINS`: passar `CARGA_ORIGEM=http://<host>:3200` |

## 8. Registrar

Copie `docs/carga/_modelo-de-resultado.md` para `docs/carga/AAAA-MM-DD-resultado.md`, preencha e leve os JSONs de `carga/saida/` para a próxima sessão: a análise de gargalos e as correções saem dali.

## O que isto não mede

SEFAZ e ADN reais; latência de internet; HTTPS do Caddy (o staging é HTTP); o navegador (só a API é medida); NFS-e e NFC-e (o emissor simulado cobre só NF-e).
