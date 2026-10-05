<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Application\PoliticaDeNotas;
use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Emissao\NotaParaEmissao;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsulta;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissao;
use App\Modules\Fiscal\Domain\Emissao\XmlAssinado;
use App\Modules\Fiscal\Domain\Enums\SituacaoNaSefaz;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Exceptions\FalhaDeComunicacaoException;
use App\Modules\Fiscal\Domain\Exceptions\NotaNaoEmContingenciaException;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\Models\NotaEvento;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Transmite à SEFAZ uma NFC-e emitida em contingência. Consulta a chave antes e reenvia SEMPRE o mesmo XML
 * assinado: remontar mudaria a chave que já está impressa no cupom do consumidor.
 */
final class RetransmitirNota
{
    public function __construct(
        private readonly PoliticaDeNotas $politica,
        private readonly EmissorDeNfe $emissor,
        private readonly AplicarResultadoDaEmissao $aplicar,
    ) {}

    /** O agendador roda sem usuário; o botão da tela passa o autor, que precisa poder emitir. */
    public function executar(string $notaId, ?Usuario $autor = null): Nota
    {
        if ($autor !== null) {
            $this->politica->garantirPodeEmitir($autor);
        }
        $nota = Nota::query()->findOrFail($notaId);
        if ($nota->status !== StatusNota::Contingencia) {
            throw new NotaNaoEmContingenciaException;
        }

        // Um reenvio por vez por nota: o agendador e o botão não se atropelam.
        $retorno = Cache::lock("retransmitir-nota:{$notaId}", 120)->get(fn (): Nota => $this->retransmitir($nota, $autor));

        return $retorno instanceof Nota ? $retorno : $nota->refresh();
    }

    private function retransmitir(Nota $nota, ?Usuario $autor): Nota
    {
        $emitente = Emitente::query()->findOrFail($nota->emitente_id);

        $consulta = $this->consultar($emitente, $nota);
        if ($consulta !== null) {
            return $this->aplicar->aplicarConsulta($nota->id, $consulta);
        }

        $nota->makeVisible('xml_enviado');
        $dto = NotaParaEmissao::deNota($nota, $emitente, Tenant::query()->findOrFail($nota->tenant_id));
        try {
            $resultado = $this->emissor->transmitir($emitente, $dto, new XmlAssinado((string) $nota->chave, (string) $nota->xml_enviado));
        } catch (FalhaDeComunicacaoException) {
            return $this->registrarTentativa($nota->id, 'Retransmissão sem resposta da SEFAZ; a nota continua em contingência.', $autor);
        }

        return $this->gravar($nota->id, $resultado, $autor);
    }

    /** Só devolve a consulta quando a SEFAZ já tem a nota (autorizada ou cancelada). */
    private function consultar(Emitente $emitente, Nota $nota): ?ResultadoDeConsulta
    {
        try {
            $consulta = $this->emissor->consultarPorChave($emitente, (string) $nota->chave, $nota->xml_enviado, (string) $nota->ambiente);
        } catch (FalhaDeComunicacaoException) {
            return null;
        }

        return in_array($consulta->situacao, [SituacaoNaSefaz::Autorizada, SituacaoNaSefaz::Cancelada], true) ? $consulta : null;
    }

    private function registrarTentativa(string $notaId, string $detalhe, ?Usuario $autor): Nota
    {
        return DB::transaction(function () use ($notaId, $detalhe, $autor): Nota {
            $nota = Nota::query()->whereKey($notaId)->lockForUpdate()->firstOrFail();
            if ($nota->status === StatusNota::Contingencia) {
                $nota->forceFill(['ultima_retransmissao_em' => now()])->save();
                NotaEvento::registrar($nota, StatusNota::Contingencia, StatusNota::Contingencia, $detalhe, $autor?->id);
            }

            return $nota;
        });
    }

    private function gravar(string $notaId, ResultadoDeEmissao $r, ?Usuario $autor): Nota
    {
        return DB::transaction(function () use ($notaId, $r, $autor): Nota {
            $nota = Nota::query()->whereKey($notaId)->lockForUpdate()->firstOrFail();
            if ($nota->status !== StatusNota::Contingencia) {
                return $nota;
            }

            if ($r->status === StatusNota::Autorizada) {
                $nota->forceFill([
                    'status' => StatusNota::Autorizada, 'cstat' => $r->cstat, 'motivo' => $r->motivo, 'protocolo' => $r->protocolo,
                    'xml_autorizado' => $r->xmlAutorizado, 'emitido_em' => now(), 'ultima_retransmissao_em' => now(),
                ])->save();
                NotaEvento::registrar($nota, StatusNota::Contingencia, StatusNota::Autorizada, 'Autorizada após contingência', $autor?->id);
            } elseif ($r->status === StatusNota::Rejeitada) {
                $nota->forceFill(['status' => StatusNota::Rejeitada, 'cstat' => $r->cstat, 'motivo' => $r->motivo, 'ultima_retransmissao_em' => now()])->save();
                NotaEvento::registrar($nota, StatusNota::Contingencia, StatusNota::Rejeitada, "Rejeitada após contingência (cStat {$r->cstat}): o consumidor já recebeu o cupom", $autor?->id);
            } else {
                // Processando ou erro técnico: a nota continua em contingência para uma nova tentativa.
                $nota->forceFill(['ultima_retransmissao_em' => now()])->save();
                NotaEvento::registrar($nota, StatusNota::Contingencia, StatusNota::Contingencia, $r->mensagemErro ?? 'Retransmissão sem conclusão; nova tentativa depois.', $autor?->id);
            }

            return $nota;
        });
    }
}
