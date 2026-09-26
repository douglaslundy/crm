# ADR 0004: Planos, situação da assinatura e limites

- **Status:** aceito (F1, 2026-09-27)

## Contexto

O admin cria planos com preço, módulos e limites. Cada empresa tem um plano e uma situação de assinatura. Os módulos (fiscal, CRM, API) precisam consultar o que o plano permite sem conhecer uns aos outros.

## Decisão

- **Limites fail-closed.** Cada limite é uma linha em `plano_limites`. `-1` = ilimitado. **Recurso sem linha vale 0**, então esquecer de configurar bloqueia em vez de liberar.
- **`EntitlementService`** (módulo Platform):
  - É o único ponto que responde "tem o módulo?", "qual o limite?" e "cabe mais um?".
  - O uso vem de **contadores registrados pelo módulo dono do recurso** (`ContadorDeUso`). A Identity registra `USUARIOS`, e cada fase registra os seus.
  - Pedir o uso de um recurso sem contador lança exceção. Nunca devolve 0 em silêncio.
- **A checagem fica nas Actions**, não no controller, para valer igual no frontend e na API pública. Onde há corrida (convite, reativação), a Action trava a linha do tenant (`lockForUpdate`) antes de contar.
- **Situação é máquina de estados explícita** (`SituacaoAssinatura::podeIrPara`):
  - Toda mudança passa por `MudarSituacaoDaEmpresa`, que audita com o motivo.
  - O middleware `VerificarSituacaoDoTenant` bloqueia a escrita em `PENDENTE`, `SUSPENSA` e `CANCELADA`.
  - A leitura continua liberada para o cliente baixar seus dados.
- **O "hoje" do negócio** usa `config('app.fuso_negocio')` (`America/Sao_Paulo`). O banco continua em UTC.
- **Erros de negócio** estendem `ErroDeNegocio` e viram JSON `{message, codigo}` num único ponto.
- **Auditoria** com `spatie/laravel-activitylog`, com morphs em uuid, cobrindo planos, situação, troca de plano e usuários.

## Consequências

- Editar um plano muda o que todas as empresas nele podem fazer. O admin vê quantas empresas usam o plano antes de salvar. Versionar planos fica para a F5, se for preciso.
- Um módulo novo precisa registrar o contador do seu recurso. Se não registrar, a primeira checagem falha de forma barulhenta.
