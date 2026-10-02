<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Application\PoliticaDeNotas;
use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeInutilizacao;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use App\Modules\Fiscal\Domain\Justificativa;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\EmitenteSerie;
use App\Modules\Fiscal\Domain\Models\Inutilizacao;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Exceptions\AssinaturaSemEscritaException;
use Throwable;

/** Ação administrativa e rara: síncrona, e o resultado (inclusive recusa e erro) é sempre gravado. */
final class InutilizarNumeracao
{
    public function __construct(
        private readonly PoliticaDeNotas $politica,
        private readonly EntitlementService $planos,
        private readonly EmissorDeNfe $emissor,
    ) {}

    /** @param array<string, mixed> $dados */
    public function executar(array $dados, Usuario $autor): Inutilizacao
    {
        $this->politica->garantirPodeEmitir($autor);
        $tenant = $autor->tenant;
        if (! $tenant->situacao->permiteEscrita()) {
            throw AssinaturaSemEscritaException::para($tenant->situacao);
        }
        $this->planos->garantirModulo($tenant, Modulo::FiscalNfe);
        $justificativa = Justificativa::de((string) $dados['justificativa'])->texto;

        $numeroSerie = (string) $dados['serie'];
        $inicial = (int) $dados['numero_inicial'];
        $final = (int) $dados['numero_final'];
        if ($inicial < 1 || $inicial > $final) {
            throw new EmissaoBloqueadaException('FISCAL_FAIXA_INVALIDA', 'O número inicial deve ser maior que zero e não pode passar do final.');
        }

        $emitente = Emitente::query()->firstOrCreate([], []);
        $serie = EmitenteSerie::query()->where('emitente_id', $emitente->id)->where('modelo', ModeloDocumento::Nfe)->where('serie', $numeroSerie)->first();
        if ($serie === null) {
            throw new EmissaoBloqueadaException('FISCAL_SERIE_NAO_CONFIGURADA', "A série {$numeroSerie} da NF-e não está cadastrada em Configurações > Fiscal.");
        }
        if ($final >= $serie->proximo_numero) {
            throw new EmissaoBloqueadaException('FISCAL_FAIXA_NAO_ALOCADA', 'Só é possível inutilizar números já alocados (até o '.($serie->proximo_numero - 1).').');
        }

        $ambiente = $emitente->ambiente_fiscal->value;
        $conflito = Nota::query()
            ->where('serie', $numeroSerie)->where('ambiente', $ambiente)->whereBetween('numero', [$inicial, $final])
            ->whereIn('status', [StatusNota::Autorizada, StatusNota::Cancelando, StatusNota::Cancelada, StatusNota::Processando])
            ->orderBy('numero')->first();
        if ($conflito !== null) {
            throw new EmissaoBloqueadaException('FISCAL_FAIXA_COM_NOTA', "O número {$conflito->numero} pertence a uma NF-e {$conflito->status->value} nesta série e não pode ser inutilizado.");
        }

        try {
            $resultado = $this->emissor->inutilizar($emitente, (int) $numeroSerie, $inicial, $final, $justificativa, $ambiente);
        } catch (EmissaoBloqueadaException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            $resultado = ResultadoDeInutilizacao::erro('Falha técnica ao inutilizar: '.mb_substr($e->getMessage(), 0, 300));
        }

        return Inutilizacao::query()->create([
            'emitente_id' => $emitente->id, 'serie' => $numeroSerie, 'numero_inicial' => $inicial, 'numero_final' => $final,
            'justificativa' => $justificativa, 'ambiente' => $ambiente, 'status' => $resultado->status, 'cstat' => $resultado->cstat,
            'motivo' => $resultado->motivo, 'protocolo' => $resultado->protocolo, 'xml_resposta' => $resultado->xmlResposta,
            'usuario_id' => $autor->id,
        ]);
    }
}
