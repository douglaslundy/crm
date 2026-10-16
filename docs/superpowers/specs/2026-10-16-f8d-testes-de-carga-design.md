# F8d: testes de carga (design)

Data: 2026-10-16. Última fase antes do go-live (F8a auditoria, F8b pendências, F8c backup já concluídas).

## Objetivo

Saber se o sistema aguenta o uso esperado no primeiro ano e onde ele quebra primeiro. Dois resultados:

1. **Metas passa/falha** para o uso esperado.
2. **Estresse** para achar o limite e os gargalos.

Uso esperado (decisão do usuário): 50 empresas, 100 usuários simultâneos.

## Decisões do usuário

- Ferramenta: **k6 em container na VPS** do staging. Não há Docker na máquina de desenvolvimento; a VPS tem.
- Cenários: login e leitura; escrita do CRM; emissão simulada na fila; API pública.
- Emissão: **emissor de carga** ligado por variável de ambiente e bloqueado em produção. Nunca SEFAZ ou ADN reais.
- Entregamos scripts, comando de semeadura e runbook. **Quem executa na VPS é o usuário.**

## Estrutura

- `carga/k6/`: `leitura.js`, `crm-escrita.js`, `emissao.js`, `api-publica.js`, `estresse.js` e `lib/` (login, cabeçalhos, limiares).
- `carga/docker-compose.carga.yml`: serviço `k6` na rede do projeto Compose `crm`. Não expõe portas.
- `docs/carga/runbook-testes-de-carga.md`: como semear, rodar, interpretar e limpar.
- `docs/carga/AAAA-MM-DD-resultado.md`: números, gargalos e correções (preenchido após a execução do usuário).

## Dados de carga

Comando Artisan `carga:semear` (`backend/app/Console`):

- Recusa rodar com `APP_ENV=production`.
- Cria 50 empresas fictícias (CNPJ gerado, nunca real), cerca de 100 usuários (dono e vendedores), clientes, catálogo, funil e negócios em volume realista por empresa, mais uma série e um emitente de homologação por empresa.
- Cria um token de API por empresa e grava a lista (usuários e tokens) em um arquivo JSON fora do repositório, que o k6 lê.
- Idempotente: marca as empresas de carga por um prefixo no nome; `carga:limpar` remove só elas.
- Nenhum certificado A1 e nenhum dado real. Segredos criptografados como no resto do sistema.

## Emissor de carga

- Classe em `Fiscal/Infrastructure` que implementa os contratos de emissor existentes, devolve autorização sintética com latência configurável (`FISCAL_EMISSOR_SIMULADO_LATENCIA_MS`).
- Ligada só quando `FISCAL_EMISSOR_SIMULADO=1`.
- `FiscalServiceProvider` **aborta o boot** com exceção clara se a flag estiver ligada e `APP_ENV=production`. Teste automatizado cobre os dois ramos.
- A emissão passa pelas Actions, pela fila e pelo worker reais. Só a chamada final ao órgão fiscal é trocada. Regra tributária continua fora do emissor, como nos providers reais.
- O emissor de carga não grava XML como se fosse real: o XML sintético é marcado como simulado e não é válido contra o XSD.

## Metas (limiares do k6)

Cenário de capacidade: 100 usuários virtuais, rampa de 2 min, platô de 10 min.

| Cenário | Meta |
|---|---|
| Leitura (login, painel, listas, kanban) | p95 < 800 ms |
| Escrita do CRM (criar/mover negócio, atividade, proposta, aceite público) | p95 < 1,5 s |
| Erros HTTP 5xx e falhas de rede | < 1% |
| Fila de emissão simulada | drena 20 notas/s sem backlog crescente nos últimos 5 min |
| API pública | respeita o limite de taxa: excesso vira 429, nunca 5xx |

Os números são o ponto de partida; o resultado real pode pedir ajuste, registrado no relatório com o motivo.

Cada cenário ainda checa **isolamento**: um usuário de uma empresa nunca recebe dado de outra (checagem por amostragem nas respostas).

## Estresse

- Rampa até 3x a carga de capacidade.
- **Teto de segurança**, porque a VPS é compartilhada com outros projetos: o runbook manda rodar fora do horário deles, e o teste aborta (`abortOnFail`) se o p95 passar de 5 s ou os erros passarem de 10%. O runbook pede ao usuário vigiar CPU e memória da VPS (`docker stats`) e cancelar à mão se passar do limite que ele definir.
- Saída: ponto em que as metas deixam de valer e o primeiro recurso saturado (PHP-FPM, worker, banco, CPU).

## Correções

- Correção simples e de baixo risco achada pela carga (índice, N+1, parâmetro de worker) entra na F8d, com teste.
- Correção grande vira tarefa em `TAREFAS.md`, sem bloquear o relatório.

## Fora do escopo

SEFAZ e ADN reais, latência da internet, PostgreSQL gerenciado, teste de carga do frontend no navegador (só a API é medida), escala horizontal.

## Riscos e não verificado

- Não há Docker nem PostgreSQL na máquina de desenvolvimento: scripts k6 e `docker-compose.carga.yml` só rodam de verdade na VPS. Aqui validamos sintaxe (`k6 inspect` não existe local; revisão do código) e os testes PHP do comando e do emissor.
- A VPS é compartilhada: números dependem da carga dos outros projetos no momento. O relatório registra data e hora.
- Staging usa HTTP e `INSTALL_DEV=1`; a produção com Caddy e TLS terá custo um pouco diferente.

## Testes automatizados (backend)

- `carga:semear` cria o volume esperado, recusa produção, é idempotente; `carga:limpar` remove só as empresas de carga.
- Teste de isolamento: dados semeados de uma empresa não aparecem para outra.
- Emissor de carga: autoriza pelo fluxo real da fila; provider recusa subir em produção com a flag.
