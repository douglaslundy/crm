# F3c2: contingência offline da NFC-e: plano de implementação

> **Para executores:** SUB-SKILL OBRIGATÓRIA: `superpowers:executing-plans` (modo Native). Passos com `- [ ]`.

**Objetivo:** NFC-e que perde a comunicação com a SEFAZ entra em contingência offline (`tpEmis=9`), é retransmitida depois (agendador + botão) e tem prazo com alerta.

**Arquitetura:** novo status `CONTINGENCIA`; `FalhaDeComunicacaoException` vinda do emissor; Actions `EntrarEmContingencia` e `RetransmitirNota`; `MontadorDeXml` remonta com `tpEmis=9`/`dhCont`/`xJust`; `ResumoFiscal` e o painel mostram prazos.

**Spec:** `docs/superpowers/specs/2026-10-05-f3c2-contingencia-nfce-design.md`. **Skill fiscal:** `br-fiscal-note-emission` (contingência só em falha real de comunicação).

## Global Constraints

- Contingência **só** em `FalhaDeComunicacaoException` e **só** para NFC-e; rejeição, schema e configuração nunca entram.
- Antes de entrar em contingência e antes de retransmitir: consultar a chave (nunca duplicar).
- Retransmitir reenvia o **mesmo XML assinado**; nunca remonta.
- Prazo e alerta vêm de `config/fiscal.php`; o limite é gravado na entrada.
- `declare(strict_types=1)`, TypeScript strict sem `any`, Global Scope e teste de isolamento em endpoint novo, schema só por migration.
- Backend: `php artisan test`, `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse --memory-limit=1G --no-progress`. Frontend: `npm run test`, `typecheck`, `lint`, `build`. Arquivos PHP grandes: criar com a ferramenta de escrita (heredoc com apóstrofos quebra o shell).
- Sem push. Um commit por tarefa. Testes que criam várias notas usam número e chave distintos.

## Review Focus

- Consulta prévia autorizada **não** entra em contingência (resposta perdida no caminho).
- Exceção que não é de comunicação em NFC-e vira ERRO, não contingência; NF-e com falha de comunicação vira ERRO.
- A chave nova tem `tpEmis` 9 nas posições 35–36? (`cUF(2) AAMM(4) CNPJ(14) mod(2) série(3) nNF(9) tpEmis(1) cNF(8) cDV(1)`: o `tpEmis` é o caractere da posição 35, índice 34).
- Retransmissão não remonta: o `xml_enviado` e a `chave` ficam idênticos após uma nova falha.
- Cota `DOCUMENTOS_MES` não conta CONTINGENCIA.

---

### Task 1: status, colunas e configuração

**Arquivos:** migration `backend/database/migrations/2026_10_05_000002_add_contingencia_as_notas.php`; modificar `Domain/Enums/StatusNota.php`, `Domain/Models/Nota.php`, `backend/config/fiscal.php`; teste `backend/tests/Feature/Fiscal/ContingenciaDadosTest.php`.

- [ ] **Passo 1: testes (RED).** `StatusNota::Contingencia->value === 'CONTINGENCIA'` e `->editavel() === false`; uma `Nota` salva com `status=Contingencia` e as colunas `contingencia_desde`, `contingencia_limite_em`, `ultima_retransmissao_em` (datas) e `contingencia_motivo` é relida com os valores; `config('fiscal.contingencia_prazo_horas') === 24` e `config('fiscal.contingencia_alerta_horas') === 6`; `ContadorDeDocumentosMes` **não** conta uma nota em CONTINGENCIA.
- [ ] **Passo 2: implementar.** Enum `case Contingencia = 'CONTINGENCIA';`. Migration com as quatro colunas nuláveis (`timestamp`, `timestamp`, `timestamp`, `text`) em `notas` e `down()` correspondente. `Nota`: `@property ?CarbonInterface` nos três campos e `?string $contingencia_motivo`, casts `datetime` para os três. `config/fiscal.php`: `'contingencia_prazo_horas' => (int) env('FISCAL_CONTINGENCIA_PRAZO_HORAS', 24)`, `'contingencia_alerta_horas' => (int) env('FISCAL_CONTINGENCIA_ALERTA_HORAS', 6)`.
- [ ] **Passo 3:** verde, suíte inteira (corrigir `match` exaustivos de `StatusNota` que o PHPStan acusar), Pint, PHPStan. **Passo 4: commit** `feat(fiscal): status CONTINGENCIA, colunas e configuração de prazo`.

### Task 2: comunicação, XML offline e contrato do motor

**Arquivos:** criar `Domain/Exceptions/FalhaDeComunicacaoException.php`, `Domain/Emissao/ContingenciaDeEmissao.php`; modificar `Infrastructure/NfePhp/MontadorDeXml.php`, `Infrastructure/NfePhp/NfePhpEmissor.php`, `Domain/Contracts/EmissorDeNfe.php`, `tests/Fixtures/EmissorFake.php`; testes `backend/tests/Unit/Fiscal/XmlContingenciaTest.php`.

**Produz:** `ContingenciaDeEmissao{ string $justificativa; CarbonImmutable $em }`; `MontadorDeXml::montar(NotaParaEmissao, ?string $cNF = null, ?ContingenciaDeEmissao $contingencia = null)`; `EmissorDeNfe::prepararContingencia(Emitente, NotaParaEmissao, string $justificativa, CarbonImmutable $em): XmlAssinado`; `EmissorFake::$falharEmConsultar` (?Throwable) e `$contingencias` (lista dos XMLs).

- [ ] **Passo 1: testes (RED).** `XmlContingenciaTest` (`AssinaNfeDeTeste`): NFC-e remontada com `ContingenciaDeEmissao` tem `<tpEmis>9</tpEmis>`, `<dhCont>` com offset `-03:00`, `<xJust>` com o texto, a chave com `9` no índice 34 (distinta da chave sem contingência) e, assinada com CSC (`assinar($xml, 65)`), tem `infNFeSupl/qrCode` e valida no XSD; NFC-e sem contingência continua `tpEmis=1` e sem `dhCont`; NF-e com contingência no montador lança `RuntimeException` (só a NFC-e remonta offline); justificativa com menos de 15 ou mais de 255 caracteres lança `InvalidArgumentException`.
- [ ] **Passo 2: implementar.** `FalhaDeComunicacaoException extends ErroDeNegocio` (status 503, código `SEFAZ_INDISPONIVEL`, mensagem "Falha de comunicação com a SEFAZ."; construtor aceita `?Throwable $causa` como `previous`). `ContingenciaDeEmissao` readonly com validação 15..255 no construtor. Montador: se `$contingencia !== null`, exigir `$nfce` (senão `RuntimeException`), `tpEmis => 9`, `dhCont => $contingencia->em->setTimezone('America/Sao_Paulo')->format('c')`, `xJust => $this->texto($contingencia->justificativa, 255)`. Emissor: `prepararContingencia` chama o montador com a contingência e assina (`signNFe`, modelo 65); `transmitir` e `consultarPorChave` envolvem as chamadas ao `Tools` em `try { … } catch (\NFePHP\Common\Exception\SoapException $e) { throw new FalhaDeComunicacaoException($e); }`. Contrato e fake: método novo (o fake devolve `XmlAssinado` com chave `'35'.str_pad(numero,42,'9')` e XML `<NFe>contingencia</NFe>` e registra a chamada; `consultarPorChave` lança `$falharEmConsultar` se definido).
- [ ] **Passo 3:** verde, suíte inteira, Pint, PHPStan. **Passo 4: commit** `feat(fiscal): XML offline da NFC-e (tpEmis 9) e falha de comunicação no emissor`.

### Task 3: `EntrarEmContingencia` e o job de emissão

**Arquivos:** criar `Application/Actions/EntrarEmContingencia.php`; modificar `Application/Jobs/EmitirNotaJob.php`; teste `backend/tests/Feature/Fiscal/EntradaEmContingenciaTest.php`.

**Produz:** `EntrarEmContingencia::executar(string $notaId): Nota`.

- [ ] **Passo 1: testes (RED).** Cenário NFC-e como em `FluxoNfceTest` (módulos NFE+NFCE, série NFCE, CSC), `EmissorFake`, fila síncrona, `Carbon::setTestNow`. Casos: (1) `falharEmTransmitir = new FalhaDeComunicacaoException` ao emitir NFC-e ⇒ nota `CONTINGENCIA`, `chave` diferente da original (`$fake->contingencias` registrou 1 chamada), `xml_enviado` do fake, `contingencia_desde = agora`, `contingencia_limite_em = agora + 24h`, `contingencia_motivo` preenchido, evento com a chave original; (2) a mesma falha em **NF-e** ⇒ `ERRO`, sem chamar `prepararContingencia`; (3) `falharEmTransmitir = new RuntimeException('x')` em NFC-e ⇒ `ERRO`, sem contingência; (4) com a chave original e `enfileirarConsulta(ResultadoDeConsulta::autorizada(...))` ⇒ `AUTORIZADA`, **sem** entrar em contingência; (5) consulta que também lança `FalhaDeComunicacaoException` ⇒ entra em contingência; (6) consulta `naoEncontrada` ⇒ entra; (7) prazo lido de `config` (mudar para 6 ⇒ limite +6h); (8) `DOCUMENTOS_MES` não conta a nota em contingência.
- [ ] **Passo 2: implementar.** `EmitirNotaJob::handle`: antes do `catch (Throwable)`, `catch (FalhaDeComunicacaoException $e)`: se `$nota->modelo === Nfce` ⇒ `app(EntrarEmContingencia::class)->executar($this->notaId)`; senão `report($e)` e `$aplicar->executar($this->notaId, ResultadoDeEmissao::erro('Falha técnica ao emitir: '.…))`. A Action: trava; só atua em `PROCESSANDO` e `NFCE`; se `chave !== null`, consulta (capturando `FalhaDeComunicacaoException`): `Autorizada`/`Cancelada` ⇒ `AplicarResultadoDaEmissao::aplicarConsulta` e retorna; monta `NotaParaEmissao::deNota`, `$agora = CarbonImmutable::now()`, `$xml = $emissor->prepararContingencia($emitente, $dto, self::JUSTIFICATIVA, $agora)`; grava (`forceFill`) status, chave, xml_enviado, `contingencia_*`, `cstat/motivo` nulos e `NotaEvento::registrar($nota, Processando, Contingencia, "Entrou em contingência offline (chave original {$chaveAntiga})")`. Se a própria `prepararContingencia` lançar, o erro cai no `catch (Throwable)` do job (ERRO) e é reportado.
- [ ] **Passo 3:** verde, suíte inteira, Pint, PHPStan. **Passo 4: commit** `feat(fiscal): entrada em contingência offline da NFC-e`.

### Task 4: retransmissão (Action, comando, agendador, endpoint)

**Arquivos:** criar `Application/Actions/RetransmitirNota.php`, `Console/RetransmitirContingenciaCommand.php`, `Domain/Exceptions/NotaNaoEmContingenciaException.php`; modificar `Http/Controllers/NotaController.php`, `Http/routes-app.php`, `Providers/FiscalServiceProvider.php`, `backend/routes/console.php`; testes `backend/tests/Feature/Fiscal/RetransmissaoTest.php`.

**Produz:** `RetransmitirNota::executar(string $notaId, ?Usuario $autor = null): Nota`; comando `fiscal:retransmitir-contingencia`; `POST /api/app/notas/{nota}/retransmitir` (202, `NotaStatusResource`).

- [ ] **Passo 1: testes (RED).** Notas em `CONTINGENCIA` criadas direto (forceFill: `modelo=Nfce`, `chave`, `xml_enviado`, `contingencia_*`). (1) retransmissão autorizada ⇒ `AUTORIZADA`, `xml_autorizado`, `protocolo`, `emitido_em`, evento; (2) rejeitada (`rejeitada('204','Duplicidade')`) ⇒ `REJEITADA` com cStat/motivo e evento "Rejeitada após contingência"; (3) falha de comunicação no `transmitir` ⇒ permanece `CONTINGENCIA`, `ultima_retransmissao_em` atualizado, `chave` e `xml_enviado` **idênticos** (não remonta: `contingencias` do fake continua vazio); (4) consulta prévia autorizada ⇒ `AUTORIZADA` sem chamar `transmitir`; consulta cancelada ⇒ `CANCELADA`; (5) idempotente: segunda chamada com a nota já autorizada não reenvia; (6) status diferente de CONTINGENCIA ⇒ `NotaNaoEmContingenciaException` (409 `NOTA_NAO_EM_CONTINGENCIA`); (7) endpoint: 202 e estado atualizado; papel que não emite (Leitura) 403; outro tenant 404; sem login 401; (8) comando percorre notas CONTINGENCIA de **todos** os tenants, restaura o contexto, e uma nota que lança exceção não impede as outras; (9) o agendador tem `fiscal:retransmitir-contingencia` de hora em hora (`Schedule`: `schedule:list` ou inspeção de `app(Schedule::class)->events()`).
- [ ] **Passo 2: implementar.** `RetransmitirNota`: `PoliticaDeNotas::garantirPodeEmitir($autor)` só quando há autor (o comando roda sem usuário); `DB::transaction` com `lockForUpdate`; status ≠ Contingencia ⇒ exceção; consulta (capturar `FalhaDeComunicacaoException` ⇒ segue para `transmitir`); `Autorizada`/`Cancelada` ⇒ aplica como `ConciliarNota` faz (reaproveitar `AplicarResultadoDaEmissao::aplicarConsulta` **estendido** para aceitar `Contingencia` como status de origem, com evento próprio); senão `NotaParaEmissao::deNota` + `$emissor->transmitir($emitente, $dto, new XmlAssinado($nota->chave, $nota->xml_enviado))`; resultado `autorizada` ⇒ AUTORIZADA; `rejeitada` ⇒ REJEITADA; `erro`/`processando` ⇒ continua; `FalhaDeComunicacaoException` ⇒ atualiza `ultima_retransmissao_em` e evento. Comando: mesmo molde de `ReconciliarNotasCommand`. Rota `Route::post('notas/{nota}/retransmitir', [NotaController::class, 'retransmitir'])->name('app.notas.retransmitir');` e método do controller (202). `routes/console.php`: `Schedule::command('fiscal:retransmitir-contingencia')->hourly()->withoutOverlapping()->onOneServer();`. Registrar o comando no provider.
- [ ] **Passo 3:** verde, suíte inteira, Pint, PHPStan. **Passo 4: commit** `feat(fiscal): retransmissão de notas em contingência`.

### Task 5: cupom da nota em contingência

**Arquivos:** modificar `Application/Actions/GerarDanfe.php`, `Domain/Contracts/GeradorDeDanfe.php`, `Infrastructure/Pdf/SpedDaGeradorDeDanfe.php`; testes em `GerarDanfeTest.php`, `GeradorDeDanfeTest.php`, `DanfeApiTest.php`.

**Produz:** `GeradorDeDanfe::gerar(string $xml, bool $cancelada, ModeloDocumento $modelo = Nfe, bool $contingencia = false)`.

- [ ] **Passo 1: testes (RED).** `GerarDanfe`: nota NFC-e `CONTINGENCIA` usa `xml_enviado` com `cancelada=false` e `contingencia=true`; nome `CUPOM-<n>.pdf`; **NF-e** nunca chega a CONTINGENCIA mas, se chegar, `NotaSemPdfException`; `CONTINGENCIA` sem `xml_enviado` ⇒ `NotaSemPdfException`. `GeradorDeDanfeTest` (XML real da NFC-e offline montada na Task 2 e assinada com CSC; ver `XmlAutorizadoDeTeste` para estender com `nfeProcContingenciaDeTeste()` **sem protocolo**): o PDF contém "CONTING" (texto da biblioteca para `tpEmis=9`) e a "via do estabelecimento" (duas vias): conferir o texto real extraído e ajustar só a string procurada (Ruling). API: `GET /notas/{id}/pdf` de nota em contingência ⇒ 200 `CUPOM-<n>.pdf`.
- [ ] **Passo 2: implementar.** Contrato e implementação: novo parâmetro; para NFC-e `Danfce` com `setOffLineDoublePrint($contingencia)`. `GerarDanfe`: `StatusNota::Contingencia => [$nota->xml_enviado, false]` e passa `contingencia = status === Contingencia`; o `makeVisible` inclui `xml_enviado`. Atualizar o duplo de `GerarDanfeTest` (nova assinatura).
- [ ] **Passo 3:** verde, suíte inteira, Pint, PHPStan. **Passo 4: commit** `feat(fiscal): cupom de duas vias para NFC-e em contingência`.

### Task 6: contingências no `ResumoFiscal`

**Arquivos:** modificar `Application/ResumoFiscal.php`; testes em `ResumoFiscalTest.php` e `DashboardFiscalApiTest.php`.

- [ ] **Passo 1: testes (RED).** Com `$agora` fixo e `config('fiscal.contingencia_alerta_horas')=6`: 3 notas em CONTINGENCIA do ambiente atual com limites em +10h, +3h e −1h ⇒ `contingencias.quantidade=3`, `em_alerta=1` (a de +3h; a vencida não conta em alerta), `vencidas=1`, `limite_mais_proximo` = o de −1h em ISO 8601; nenhuma nota ⇒ `{quantidade:0, em_alerta:0, vencidas:0, limite_mais_proximo:null}`; nota de outro ambiente e de outro tenant não entram; a API devolve o novo bloco.
- [ ] **Passo 2: implementar** `contingencias` em `executar` (uma consulta; filtros por `ambiente` atual e status `Contingencia`; comparar `contingencia_limite_em` com `$agora` convertido ao fuso do banco).
- [ ] **Passo 3:** verde, Pint, PHPStan. **Passo 4: commit** `feat(fiscal): contingências com prazo no resumo fiscal`.

### Task 7: frontend (status e detalhe)

**Arquivos:** modificar `frontend/features/notas/types.ts`, `api.ts`, `components/StatusDaNota.tsx`, `components/DetalheDaNota.tsx`; testes em `Notas.test.tsx`.

- [ ] **Passo 1: testes (RED).** `StatusDaNota status="CONTINGENCIA"` mostra "Em contingência"; `DetalheDaNota` de NFC-e em contingência mostra `Banner` com o motivo e o limite, o botão "Retransmitir agora" (só para quem emite) que chama `notasApi.retransmitir('n1')` e invalida a nota, e o link "Abrir cupom (PDF)"; não mostra "Cancelar NFC-e" nem "Emitir NFC-e"; a lista aceita o filtro `CONTINGENCIA`. Mock de `notasApi.retransmitir` em `vi.mock('../api')`.
- [ ] **Passo 2: implementar.** `StatusNota` inclui `'CONTINGENCIA'`; `ROTULO_STATUS`; `Nota` ganha `contingencia_limite_em: string | null` e `contingencia_motivo: string | null` (e o `NotaResource` do backend os devolve: acrescentar com teste na `NotaNfceApiTest` ou no `NotasApiTest`); `notasApi.retransmitir = (id) => api('/api/app/notas/${id}/retransmitir', { method: 'POST' })` no padrão do `emitir`; `TOM` do `StatusDaNota`: `CONTINGENCIA: { tom: 'alerta' }`; detalhe com o bloco descrito e tempo restante calculado a partir de `contingencia_limite_em` (função pura testada).
- [ ] **Passo 3:** `npm run test && typecheck && lint && build`. **Passo 4: commit** `feat(notas): NFC-e em contingência na tela`.

### Task 8: frontend (painel)

**Arquivos:** modificar `frontend/features/dashboard-fiscal/{types.ts,components/PainelFiscal.tsx}`; teste em `PainelFiscal.test.tsx`.

- [ ] **Passo 1: testes (RED).** Com `contingencias {quantidade:2, em_alerta:1, vencidas:0, limite_mais_proximo}`: cartão "Em contingência" com 2 e `Banner` âmbar "1 nota perto do prazo"; com `vencidas > 0`: `Banner` de perigo (`role="alert"`); sem contingências: cartão com 0 e sem banner extra.
- [ ] **Passo 2: implementar** o cartão (`StatCard`, tom `primario`/`perigo`) e os banners; atualizar a fixture `dados()` dos testes existentes com o novo bloco.
- [ ] **Passo 3:** testes completos, typecheck, lint, build. **Passo 4: commit** `feat(dashboard): contingências com prazo no painel fiscal`.

### Task 9: fechamento

- [ ] Suítes completas e build verdes. `TAREFAS.md` (F3c2 concluída, F3c3 pendente, pendências da spec §12), `PROGRESSO.md` (feito, decisões, **roteiro de homologação real** da §11), "Próxima tarefa" = F3c3, memória do projeto. Auto-revisão declarada no ledger. Commit `docs: F3c2 concluída`. Nenhum push sem confirmação.
