# F3b3a: emissão da NFC-e: plano de implementação

> **Para executores:** SUB-SKILL OBRIGATÓRIA: `superpowers:executing-plans` (modo Native). Passos com `- [ ]`.

**Objetivo:** emitir NFC-e (modelo 65) pelo mesmo fluxo da NF-e, com destinatário opcional, CSC do ambiente e QR Code no XML.

**Arquitetura:** `notas.modelo` (`NFE`/`NFCE`) em vez de entidade nova. O Preparador (regras) e os Resolvers (CFOP) decidem; `NotaParaEmissao` carrega o modelo e o destinatário opcional; `MontadorDeXml`/`NfePhpEmissor` só traduzem formato. Consulta e cancelamento derivam o modelo da chave (posições 21–22); só `inutilizar` ganha parâmetro.

**Stack:** Laravel 12 / PHP 8.2, `sped-nfe` 5.2.8, PHPUnit, Next/vitest/zod.

**Spec:** `docs/superpowers/specs/2026-10-05-f3b3a-nfce-emissao-design.md`. **Skill fiscal:** `br-fiscal-note-emission` (ler antes de qualquer código fiscal). **Referência:** `docs/referencia-fiscal/04-nfephp-nfce.md`.

## Global Constraints

- **Nunca chutar valor fiscal:** dado ausente bloqueia com mensagem acionável; `=== null`, nunca `empty()`/`?? 0`.
- `declare(strict_types=1)`; TypeScript strict, sem `any`; mudanças de schema só por migration.
- Regra tributária fora do motor (`MontadorDeXml`/`NfePhpEmissor` só traduzem).
- Frontend e API chamam as mesmas Actions. Todo endpoint novo/alterado tem teste de isolamento por tenant.
- NFC-e: `mod=65`, `idDest=1`, `indFinal=1`, `indIEDest=9`, `tpImp=4`; sem `dest` quando sem cliente/documento; `dest` só com CPF/CNPJ e `xNome`, sem endereço; sem CSC do ambiente bloqueia **antes** de alocar número.
- NFC-e interestadual é vedada (bloqueia com `FISCAL_NFCE_INTERESTADUAL`); sem CFOP 6108.
- PDF/cupom, cancelamento específico e contingência offline estão **fora**.
- Comandos em `backend/`: `php artisan test`, `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse --memory-limit=1G`. Frontend em `frontend/`: `npm run test`, `typecheck`, `lint`, `build`.
- Sem push. Commits locais na `master`, um por tarefa. Testes que criam várias notas usam números/chaves distintos (índice único).

## Review Focus

- NF-e e NFC-e com o **mesmo número e série** no mesmo tenant não colidem (índice único com `modelo`).
- NFC-e sem cliente e NFC-e com cliente **sem CPF** emitem sem o grupo `dest`.
- Cliente de outra UF na NFC-e bloqueia; cliente sem endereço **não** bloqueia a NFC-e (mas bloqueia a NF-e).
- Sem CSC do ambiente: bloqueio **antes** de consumir número (`serie.proximo_numero` não anda).
- Tenant sem `FISCAL_NFCE` não emite NFC-e; tenant só com `FISCAL_NFCE` não emite NF-e.
- Consulta/cancelamento de NFC-e usam o modelo 65 (derivado da chave), não 55.
- Rascunho de NF-e sem `cliente_id` continua recusado (422).

---

### Task 1: migration, enum e modelo da nota

**Arquivos:**
- Criar: `backend/database/migrations/2026_10_05_000001_add_modelo_as_notas.php`
- Modificar: `backend/app/Modules/Fiscal/Domain/Models/Nota.php`, `Domain/Models/Inutilizacao.php`, `backend/database/factories/NotaFactory.php` (só se precisar de `modelo`)
- Criar: `backend/tests/Feature/Fiscal/ModeloDaNotaTest.php`

**Produz:** coluna `notas.modelo` (cast `ModeloDocumento`, padrão `NFE`); `notas.cliente_id` nulável; índice único `(tenant_id, modelo, ambiente, serie, numero)`; `inutilizacoes.modelo`.

- [ ] **Passo 1: teste (RED).** `ModeloDaNotaTest` (`RefreshDatabase`, `CenarioDeNota`): (a) `Nota::factory()->create([...])` sem `modelo` tem `modelo === ModeloDocumento::Nfe`; (b) duas notas do mesmo tenant com `ambiente=HOMOLOGACAO`, `serie='1'`, `numero=5`, uma `Nfe` e uma `Nfce` (chaves distintas) salvam sem violar índice; (c) duas `Nfe` com os mesmos números violam (`UniqueConstraintViolationException`); (d) uma nota com `cliente_id = null` e `modelo = Nfce` salva. Esperado: FAIL.
- [ ] **Passo 2: migration.** Seguir o padrão de `2026_10_03_000001_add_cancelamento_e_inutilizacoes.php` (ler antes). Em `up()`:

```php
Schema::table('notas', function (Blueprint $table): void {
    $table->dropUnique(['tenant_id', 'ambiente', 'serie', 'numero']);
    $table->string('modelo', 4)->default('NFE')->after('emitente_id');
    $table->foreignUuid('cliente_id')->nullable()->change();
    $table->unique(['tenant_id', 'modelo', 'ambiente', 'serie', 'numero']);
});
Schema::table('inutilizacoes', function (Blueprint $table): void {
    $table->string('modelo', 4)->default('NFE')->after('emitente_id');
});
```

  `down()` desfaz na ordem inversa. Se o `change()` do `foreignUuid` não preservar a FK em SQLite/Postgres, recriar a coluna como `$table->uuid('cliente_id')->nullable()->change()` e registrar Ruling (a FK é garantida pelo Global Scope e pelas validações).
- [ ] **Passo 3: Model.** `Nota`: `@property ModeloDocumento $modelo`, `@property ?string $cliente_id`, `'modelo' => ModeloDocumento::class` nos casts e `'modelo' => 'NFE'` em `$attributes`; `modelo` em `$fillable` se a lista existir. `Inutilizacao`: mesmo cast e `modelo` em `$fillable`.
- [ ] **Passo 4: verde.** `php artisan test tests/Feature/Fiscal/ModeloDaNotaTest.php`, depois a suíte inteira (todas as 455 seguem verdes), Pint, PHPStan.
- [ ] **Passo 5: commit** `feat(fiscal): modelo na nota (NFE/NFCE), cliente opcional e índice por modelo`.

---

### Task 2: `CfopConsumidorResolver`

**Arquivos:** criar `backend/app/Modules/Fiscal/Domain/Resolvers/CfopConsumidorResolver.php` e `backend/tests/Unit/Fiscal/CfopConsumidorResolverTest.php` (usar a pasta de testes unitários onde os outros resolvers são testados: `grep -rl CfopSaidaResolver backend/tests`).

**Produz:** `CfopConsumidorResolver::resolver(string $ufEmitente, ?string $ufDestino, TributacaoIcms $tributacao): string`.

- [ ] **Passo 1: teste (RED).** Normal + mesma UF (ou `ufDestino === null`, venda sem identificação) → `5102`; ST + mesma UF → `5405`; UF diferente (qualquer tributação) → lança `EmissaoBloqueadaException` com código `FISCAL_NFCE_INTERESTADUAL`; UF inválida (`'XX'`) → lança (a `TabelaIbge::codigoDaUf` já lança). Esperado: FAIL.
- [ ] **Passo 2: implementar.**

```php
final class CfopConsumidorResolver
{
    public function resolver(string $ufEmitente, ?string $ufDestino, TributacaoIcms $tributacao): string
    {
        TabelaIbge::codigoDaUf($ufEmitente);
        if ($ufDestino !== null) {
            TabelaIbge::codigoDaUf($ufDestino);
            if (strtoupper($ufEmitente) !== strtoupper($ufDestino)) {
                throw new EmissaoBloqueadaException('FISCAL_NFCE_INTERESTADUAL', 'A NFC-e não pode ser emitida para outra UF. Use NF-e.');
            }
        }

        return match ($tributacao) {
            TributacaoIcms::Normal => '5102',
            TributacaoIcms::St => '5405',
        };
    }
}
```

  Conferir o construtor de `EmissaoBloqueadaException($codigo, $mensagem)` (visto em `PreparadorDaNota`).
- [ ] **Passo 3:** testes verdes, Pint, PHPStan. **Passo 4: commit** `feat(fiscal): CfopConsumidorResolver`.

---

### Task 3: `NotaParaEmissao` com modelo e destinatário opcional

**Arquivos:** modificar `Domain/Emissao/NotaParaEmissao.php`; testes em `backend/tests/Feature/Fiscal/NotaParaEmissaoTest.php` (criar, ou estender o existente: `grep -rl NotaParaEmissao backend/tests`).

**Produz:** `NotaParaEmissao::$modelo` (`ModeloDocumento`) e `$destinatario` (`?DestinatarioParaEmissao`); `idDest` = 1 para NFC-e.

- [ ] **Passo 1: teste (RED).** `deNota` de uma NFC-e com `destinatario = ['tipo'=>'PF','documento'=>'52998224725','nome'=>'Maria Teste']` (snapshot enxuto, sem endereço) produz DTO com `modelo = Nfce`, `idDest = 1`, destinatário com `documento` e `nome` e demais campos `null`; NFC-e com `destinatario = null` produz `destinatario === null`; NF-e continua exigindo snapshot completo (nota `Nfe` com `destinatario = null` lança `LogicException`). Esperado: FAIL.
- [ ] **Passo 2: implementar.** Em `NotaParaEmissao`: inserir `public ModeloDocumento $modelo` como segundo parâmetro do construtor (depois de `notaId`) e `public ?DestinatarioParaEmissao $destinatario`. `DestinatarioParaEmissao` passa a ter `indIeDest` e `indFinal` com valores de NFC-e (9 e 1) quando o snapshot é enxuto; os campos de endereço já são aceitos como `?string`? Se o construtor os declara não-nulos, torná-los `?string` e ajustar `MontadorDeXml` na Task 6 (o montador só os lê para NF-e). Em `deNota`:

```php
$modelo = $nota->modelo;
if ($modelo === ModeloDocumento::Nfe) {
    $d = $nota->destinatario ?? throw new LogicException('Nota sem snapshot do destinatário: prepare antes de emitir.');
    $destinatario = new DestinatarioParaEmissao(/* como hoje */);
    $idDest = strtoupper((string) $emitente->uf) === $d['uf'] ? 1 : 2;
} else {
    $d = $nota->destinatario; // null = venda sem identificação
    $destinatario = $d === null ? null : new DestinatarioParaEmissao($d['tipo'], $d['documento'], $d['nome'], 9, 1, null, null, null, null, null, null, null, null, null);
    $idDest = 1;
}
```

  Atualizar **todas** as chamadas de `new NotaParaEmissao(...)` (testes e `AssinaNfeDeTeste::notaDeTeste`: passar `ModeloDocumento::Nfe`; `grep -rn "new NotaParaEmissao" backend`).
- [ ] **Passo 3:** testes verdes + suíte inteira (corrige os pontos de chamada), Pint, PHPStan. **Passo 4: commit** `feat(fiscal): NotaParaEmissao com modelo e destinatário opcional`.

---

### Task 4: regras da NFC-e no Preparador, Verificador e IniciarEmissao

**Arquivos:** modificar `Application/Emissao/PreparadorDaNota.php`, `Application/Emissao/VerificadorDoEmitente.php`, `Application/Actions/IniciarEmissao.php`; criar `backend/tests/Feature/Fiscal/EmissaoNfceTest.php`.

**Consome:** `CfopConsumidorResolver` (Task 2), `Nota::$modelo` (Task 1). **Produz:** `VerificadorDoEmitente::garantirApto(Emitente, Tenant, ModeloDocumento $modelo = ModeloDocumento::Nfe)`.

- [ ] **Passo 1: testes (RED).** `EmissaoNfceTest` (`CenarioDeNota`, `EmissorFake`, `RefreshDatabase`). O cenário padrão tem plano só com `FiscalNfe`: neste teste, recriar o tenant com `Plano::factory()->comModulos(Modulo::FiscalNfe, Modulo::FiscalNfce)` (ver `CenarioDeNota::montarCenario`; se preciso, extrair um parâmetro `$modulos` no trait, com Ruling) e criar `EmitenteSerie` `Nfce` série `'1'` `proximo_numero 1` e CSC `csc_id_homologacao = '000001'` + `csc_token_homologacao_encrypted = Crypt::encryptString('TOKEN-DE-TESTE')`. Casos (cada um cria a nota `Nfce` com `Nota::factory()`+itens/pagamento pelo mesmo helper usado em `IniciarEmissaoTest`):
  1. NFC-e **sem cliente**: `IniciarEmissao::executar` ⇒ status `PROCESSANDO`, `nota->destinatario === null`, `serie`/`numero` da série NFCE, itens com `cfop` `5102`.
  2. NFC-e com cliente PF com CPF e **sem endereço**: não bloqueia; snapshot `{tipo:'PF', documento, nome}`.
  3. NFC-e com cliente sem CPF/CNPJ: snapshot `null` (venda sem identificação).
  4. NFC-e com cliente de UF diferente do emitente: `EmissaoBloqueadaException` `FISCAL_NFCE_INTERESTADUAL`; `proximo_numero` da série NFCE **não** anda.
  5. Sem CSC do ambiente (`csc_id_homologacao = null`): `EmissaoBloqueadaException` `FISCAL_CSC_AUSENTE`, número não consumido.
  6. Tenant sem módulo `FISCAL_NFCE`: exceção de entitlement (a mesma de `IniciarEmissaoTest` para módulo ausente).
  7. Sem série NFCE cadastrada: `FISCAL_SERIE_NAO_CONFIGURADA` com "NFC-e" na mensagem.
  8. NF-e com cliente sem endereço continua bloqueando (`FISCAL_DESTINATARIO_INCOMPLETO`) e usa a série NFE (regressão).
  Esperado: FAIL.
- [ ] **Passo 2: implementar.**
  - `VerificadorDoEmitente::garantirApto(..., ModeloDocumento $modelo = ModeloDocumento::Nfe)`: a checagem de série usa `$modelo` e a mensagem diz "NFC-e" ou "NF-e" (`$modelo === Nfce ? 'NFC-e' : 'NF-e'`). Novo método `garantirCsc(Emitente $emitente, string $ambiente): array{id: string, token: string}` que lê `csc_id_*`/`csc_token_*_encrypted` do ambiente, decifra com `Crypt::decryptString` e lança `FISCAL_CSC_AUSENTE` ("Cadastre o CSC de homologação|produção em Configurações > Fiscal.") quando id ou token for `null`/vazio. O token **não** é guardado em lugar nenhum; o emissor o decifra de novo na hora (Task 6), então aqui `garantirCsc` retorna `void` (só valida): trocar a assinatura para `garantirCsc(Emitente, string $ambiente): void`.
  - `IniciarEmissao`: módulo = `$nota->modelo === Nfce ? Modulo::FiscalNfce : Modulo::FiscalNfe`; chamar `garantirApto($emitente, $tenant, $nota->modelo)` e, para NFC-e, `garantirCsc($emitente, $emitente->ambiente_fiscal->value)` **antes** de `preparar` e da alocação do número; a série vem de `where('modelo', $nota->modelo)`.
  - `PreparadorDaNota::preparar`: ramificar por `$nota->modelo`. NF-e: como hoje. NFC-e: `cliente_id === null` ⇒ `$destinatario = null`; senão carrega o cliente e `$documento = digits(cpf_cnpj)`; documento vazio ⇒ `null`; senão snapshot `['tipo'=>…, 'documento'=>…, 'nome'=>…, 'uf'=>strtoupper((string) $c->uf) ?: null]`. CFOP: `CfopConsumidorResolver::resolver($emitente->uf, $destinatario['uf'] ?? null, $tributacao)` (injetar no construtor). O resto (itens, rateio, pagamentos) é comum. `consumidor_final` não é lido na NFC-e. O snapshot grava `destinatario` com `null` permitido (a coluna é JSON nulável).
- [ ] **Passo 3: verde.** `php artisan test tests/Feature/Fiscal/EmissaoNfceTest.php`, depois suíte inteira, Pint, PHPStan.
- [ ] **Passo 4: commit** `feat(fiscal): regras da NFC-e (CSC, destinatário opcional, CFOP, série por modelo)`.

---

### Task 5: rascunho de NFC-e pela API e Resource

**Arquivos:** modificar `Http/Requests/SalvarNotaRequest.php`, `Application/Actions/CriarRascunhoDeNota.php`, `Application/Actions/AtualizarRascunhoDeNota.php` (se tocar `cliente_id`), `Http/Resources/NotaResource.php`, `Http/Controllers/NotaController.php` (só se o `store` mapear campos); criar `backend/tests/Feature/Fiscal/NotaNfceApiTest.php`.

**Produz:** `POST/PUT /api/app/notas` com `modelo` (`NFE` padrão) e `cliente_id` condicional; `NotaResource` com `modelo`.

- [ ] **Passo 1: testes (RED).** `NotaNfceApiTest`: (1) `POST` com `modelo=NFCE` e **sem** `cliente_id` ⇒ 201, `data.modelo = 'NFCE'`, `data.cliente = null`; (2) `POST` com `modelo=NFE` sem `cliente_id` ⇒ 422 com erro em `cliente_id`; (3) `POST` sem `modelo` ⇒ 201 e `data.modelo = 'NFE'` (compatibilidade); (4) `modelo=NFCE` em tenant **sem** `FISCAL_NFCE` ⇒ 403/422 com o código de entitlement já usado (copiar o assert de `NotasApiTest` para módulo ausente; se hoje o rascunho não verifica módulo, o rascunho de NFC-e passa a verificar `garantirModulo(FiscalNfce)` na Action, com Ruling); (5) `modelo` inválido (`'XYZ'`) ⇒ 422; (6) isolamento: usuário de outro tenant recebe 404 em `GET` da NFC-e; (7) `GET /api/app/notas?modelo=NFCE` filtra (se o `index` aceitar filtro; senão omitir e registrar Ruling). Esperado: FAIL.
- [ ] **Passo 2: implementar.** `SalvarNotaRequest::rules`: `'modelo' => ['nullable', Rule::in(['NFE', 'NFCE'])]` e `'cliente_id' => [Rule::requiredIf(fn () => ($this->input('modelo') ?? 'NFE') !== 'NFCE'), 'nullable', 'uuid', Rule::exists(...)]`. `CriarRascunhoDeNota`: `$nota->modelo = ModeloDocumento::from($dados['modelo'] ?? 'NFE')`; `$nota->cliente_id = $dados['cliente_id'] ?? null`; `preencher` usa `$dados['cliente_id'] ?? null`; para NFC-e chama `$this->planos->garantirModulo($tenant, Modulo::FiscalNfce)` (injetar `EntitlementService`). O `modelo` de uma nota existente **não muda** em `AtualizarRascunhoDeNota` (ignorar o campo). `NotaResource`: `'modelo' => $this->modelo->value` e `cliente` nulo-seguro (`whenLoaded('cliente', fn () => $this->cliente === null ? null : [...])`).
- [ ] **Passo 3: verde.** Testes novos, suíte inteira, Pint, PHPStan. **Passo 4: commit** `feat(fiscal): rascunho de NFC-e pela API`.

---

### Task 6: motor modelo 65 (XML real, QR Code, inutilização)

**Arquivos:** modificar `Infrastructure/NfePhp/MontadorDeXml.php`, `Infrastructure/NfePhp/NfePhpEmissor.php`, `Domain/Contracts/EmissorDeNfe.php`, `Application/Actions/InutilizarNumeracao.php`, `Http/Requests/InutilizarRequest` (o que valida a inutilização: `ls Http/Requests`), `tests/Fixtures/EmissorFake.php`; criar `backend/tests/Feature/Fiscal/XmlNfceTest.php`.

**Consome:** `NotaParaEmissao` (Task 3), CSC (Task 4). **Produz:** XML 65 assinado com `infNFeSupl/qrCode`; `EmissorDeNfe::inutilizar(Emitente, ModeloDocumento $modelo, int $serie, ...)`.

- [ ] **Passo 1: testes (RED).** `XmlNfceTest` com `AssinaNfeDeTeste` (estender `notaDeTeste` com parâmetros `?ModeloDocumento $modelo`, `bool $comDestinatario`; ver o trait). Para o QR Code real é preciso `CSC`/`CSCid` no `Tools`: o teste monta via `NfePhpEmissor` com um `CertificadoStore` fake que devolve `certificadoDeTeste()` (ver como `NfePhpEmissor` é construído em `EmitirNotaJobTest`/smoke) **ou**, mais simples, exercita `MontadorDeXml::montar` (que não assina) e um teste separado do emissor só se a montagem do `Tools` permitir CSC fake. Casos no XML montado: (1) NFC-e sem destinatário: `mod=65`, `tpImp=4`, `idDest=1`, `indFinal=1`, sem `<dest>` e sem `<enderDest>`; (2) NFC-e identificada: `<dest>` com `CPF` e `xNome`, **sem** `<enderDest>` e sem `indIEDest` (a lib força 9; conferir no XML e ajustar a asserção ao que a Make gerar, com Ruling); (3) `serie`/`nNF` do modelo; (4) NF-e (regressão): `mod=55`, `tpImp=1`, `<dest>`+`<enderDest>` como hoje; (5) o emissor chama `->model(65)` para NFC-e (verificar por `consultarPorChave` com chave de posições 21–22 = `65`, via spy do `Tools` ou aceitar cobertura pelo teste (6)); (6) `inutilizar` com `ModeloDocumento::Nfce` registra `modelo` na `Inutilizacao`. Esperado: FAIL.
- [ ] **Passo 2: implementar.**
  - `MontadorDeXml::montar`: `$nfce = $nota->modelo === ModeloDocumento::Nfce;` `ide`: `'mod' => $nfce ? 65 : 55`, `'tpImp' => $nfce ? 4 : 1`, `'indFinal' => $nfce ? 1 : $nota->destinatario->indFinal`. Bloco `dest`: se `$nota->destinatario === null` não chama `tagdest`/`tagenderDest`; se NFC-e com destinatário, só `CPF|CNPJ` + `xNome` (`tagdest`, sem `tagenderDest`, sem `indIEDest`/`IE`/`email`); NF-e como hoje. Mensagem de erro "Falha ao montar o XML da NF-e" passa a usar "NF-e"/"NFC-e".
  - `NfePhpEmissor`: `tools(Emitente, string $razao, string $cnpj, string $ambiente, int $modelo = 55)`; quando `65`, ler o CSC do ambiente (`csc_id_*` e `Crypt::decryptString(csc_token_*_encrypted)`; se ausente, `EmissaoBloqueadaException('FISCAL_CSC_AUSENTE', …)`) e acrescentar `'CSC' => $token, 'CSCid' => $id` ao JSON; `$tools->model($modelo)`. `preparar`/`transmitir` usam `$nota->modelo`. `consultarPorChave` e `cancelar` derivam `(int) substr($chave, 20, 2)` (55 ou 65; outro valor lança `InvalidArgumentException`) e passam a `toolsDoTenant($emitente, $ambiente, $modelo)`. `inutilizar` ganha `ModeloDocumento $modelo` como 2º parâmetro e o usa.
  - `EmissorDeNfe` (contrato) e `EmissorFake`: a nova assinatura de `inutilizar`; o fake registra `modelo` em `$this->chamadas`.
  - `InutilizarNumeracao`: lê `modelo` de `$dados` (`ModeloDocumento::from($dados['modelo'] ?? 'NFE')`, só NFE/NFCE), exige o módulo correspondente, usa o modelo na busca da série, na mensagem e na busca de notas em conflito (`where('modelo', $modelo)`), e grava `modelo` na `Inutilizacao`. O `Request` aceita `modelo` (`Rule::in(['NFE','NFCE'])`, nulável). `InutilizacaoResource` devolve `modelo`.
- [ ] **Passo 3: verde.** Testes novos, `InutilizarNumeracaoTest`/`InutilizacoesApiTest` (atualizar para a nova assinatura), suíte inteira, Pint, PHPStan.
- [ ] **Passo 4: commit** `feat(fiscal): motor modelo 65 com CSC e QR Code; inutilização por modelo`.

---

### Task 7: fluxo completo com o fake e reconciliação

**Arquivos:** criar `backend/tests/Feature/Fiscal/FluxoNfceTest.php`.

- [ ] **Passo 1: testes (devem passar sem código novo, ou revelar lacunas).** Com `EmissorFake` e o cenário NFC-e da Task 4: (1) `POST /notas` (NFC-e sem cliente) → `POST /notas/{id}/emitir` → `202` → `GET` mostra `AUTORIZADA`, `modelo NFCE`, série/número do modelo; (2) emissão da NFC-e **não** consome a numeração da NF-e (as duas séries andam independentes); (3) NFC-e com `cStat 110` (denegada, via `ResultadoDeEmissao::rejeitada('110', 'Uso Denegado')`) fica `REJEITADA`/`ERRO` com o motivo — conferir o que `AplicarResultadoDaEmissao` faz com 110/301/302 e, se hoje tratar como rejeitada, **aceitar** e documentar no teste; (4) `fiscal:reconciliar-processando` resolve uma NFC-e `PROCESSANDO` (o fake responde a `consultarPorChave`); (5) a cota `DOCUMENTOS_MES` conta a NFC-e autorizada em produção (`ContadorDeDocumentosMes`).
- [ ] **Passo 2:** se algum caso falhar por lacuna real (por exemplo o job lê série fixa), corrigir com RED→GREEN e Ruling. **Passo 3:** suíte inteira, Pint, PHPStan. **Passo 4: commit** `test(fiscal): fluxo completo e reconciliação da NFC-e`.

---

### Task 8: frontend

**Arquivos:** modificar `frontend/features/notas/types.ts`, `schemas.ts`, `components/NotaForm.tsx`, `components/ListaDeNotas.tsx`, `components/DetalheDaNota.tsx`, `components/FormInutilizacao.tsx`; testes em `components/Notas.test.tsx`.

- [ ] **Passo 1: testes (RED).** (a) `NotaForm` com o módulo `FISCAL_NFCE` no plano mostra o seletor "Modelo" (NF-e / NFC-e); sem o módulo, não mostra; (b) escolhendo NFC-e, o campo "Cliente" não é obrigatório (opção "Consumidor não identificado"), some "Consumidor final?" e o envio manda `modelo: 'NFCE'` e `cliente_id: null`; (c) na NF-e, o cliente continua obrigatório e o payload leva `modelo: 'NFE'`; (d) `DetalheDaNota` e `ListaDeNotas` mostram "NFC-e" quando `modelo === 'NFCE'`; (e) `FormInutilizacao` envia `modelo` escolhido. Como o `NotaForm` descobre os módulos: ler `features/assinatura/hooks/useAssinatura.ts` (`modulos: Modulo[]`) e mockar o hook como o teste atual mocka `useUsuario`. Esperado: FAIL.
- [ ] **Passo 2: implementar.** `types.ts`: `Nota.modelo: 'NFE' | 'NFCE'`, `NotaPayload.modelo` e `cliente_id: string | null`; `schemas.ts`: `modelo: z.enum(['NFE','NFCE'])` em `notaSchema`, `cliente_id` vira `z.string()` com `superRefine` que exige uuid só quando `modelo === 'NFE'`; `paraPayload` envia `cliente_id: d.cliente_id === '' ? null : d.cliente_id`. `NotaForm`: seletor "Modelo" (usa `SelectNativo` e `Campo`), cliente com `<option value="">Consumidor não identificado</option>` na NFC-e, `clienteEhPj`/consumidor final só para NF-e. Lista e detalhe: `Pilula` ou texto "NFC-e"/"NF-e" ao lado do número; o detalhe da NFC-e não mostra "Abrir DANFE (PDF)" (a F3b3b o liga). `FormInutilizacao`: `SelectNativo` "Modelo" (mesma regra do módulo).
- [ ] **Passo 3: verde.** `npm run test`, `typecheck`, `lint`, `build`. **Passo 4: commit** `feat(notas): NFC-e na tela de notas`.

---

### Task 9: fechamento

- [ ] **Passo 1:** backend `php artisan test`, Pint, PHPStan; frontend `npm run test && typecheck && lint && build`. Tudo verde.
- [ ] **Passo 2:** `TAREFAS.md` (F3b3a concluída; F3b3b e F3c pendentes, mais as pendências da spec §9), `PROGRESSO.md` (feito, decisões não óbvias: `modelo` na nota, índice com modelo, modelo derivado da chave, NFC-e interestadual bloqueia, denegada como ERRO; roteiro de homologação real da NFC-e com A1 e CSC de teste; "Próxima tarefa" = F3b3b: brainstorming/spec do cupom 80 mm e do cancelamento da NFC-e; reescrever "Contexto necessário" para ela).
- [ ] **Passo 3:** revisão final do branch (revisor novo no modelo mais capaz, se o ambiente permitir; senão auto-revisão declarada no ledger). Critical/Important com teste RED→GREEN.
- [ ] **Passo 4:** commit `docs: F3b3a concluída`. Nenhum push sem confirmação. Recomendar `/compact`.
