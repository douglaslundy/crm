<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Application\Jobs\EmitirNotaJob;
use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsulta;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissao;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Enums\SituacaoNaSefaz;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\EmitenteSerie;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\Models\NotaEvento;
use Illuminate\Support\Facades\DB;

/** Única porta de saída de uma emissão: toda mudança de status vinda da SEFAZ passa por aqui, com lock. */
final class AplicarResultadoDaEmissao
{
    public function __construct(private readonly EmissorDeNfe $emissor) {}

    public function executar(string $notaId, ResultadoDeEmissao $r): Nota
    {
        $redespachar = false;
        $nota = DB::transaction(function () use ($notaId, $r, &$redespachar): Nota {
            $nota = Nota::query()->whereKey($notaId)->lockForUpdate()->firstOrFail();
            if ($nota->status !== StatusNota::Processando) {
                return $nota;
            }

            if ($r->status === StatusNota::Rejeitada && $r->cstat === '539') {
                $redespachar = $this->tratarNumeroOcupado($nota, $r);

                return $nota;
            }

            $anterior = $nota->status;
            $campos = ['status' => $r->status, 'cstat' => $r->cstat, 'motivo' => $r->motivo];
            if ($r->status === StatusNota::Autorizada) {
                $campos += ['protocolo' => $r->protocolo, 'xml_autorizado' => $r->xmlAutorizado, 'emitido_em' => now()];
            }
            if ($r->status === StatusNota::Erro) {
                $campos['mensagem_erro'] = $r->mensagemErro;
            }
            $nota->forceFill($campos)->save();

            if ($r->status !== StatusNota::Processando) {
                NotaEvento::registrar($nota, $anterior, $r->status, $r->mensagemErro ?? ($r->cstat !== null ? "cStat {$r->cstat}: {$r->motivo}" : null));
            }

            return $nota;
        });

        if ($redespachar) {
            EmitirNotaJob::dispatch($nota->id, $nota->tenant_id);
        }

        return $nota->refresh();
    }

    /** Usado pela reconciliação (Tarefa 11) e pelo reenvio com chave existente. */
    public function aplicarConsulta(string $notaId, ResultadoDeConsulta $r): Nota
    {
        return DB::transaction(function () use ($notaId, $r): Nota {
            $nota = Nota::query()->whereKey($notaId)->lockForUpdate()->firstOrFail();
            if ($nota->status !== StatusNota::Processando && $nota->status !== StatusNota::Erro) {
                return $nota;
            }
            $anterior = $nota->status;

            if ($r->situacao === SituacaoNaSefaz::Autorizada) {
                if ($r->xmlAutorizado === null) {
                    $nota->forceFill(['status' => StatusNota::Erro, 'mensagem_erro' => "A nota consta como autorizada na SEFAZ (protocolo {$r->protocolo}), mas o XML não pôde ser recomposto. Baixe o XML no portal da SEFAZ."])->save();
                    NotaEvento::registrar($nota, $anterior, StatusNota::Erro, $nota->mensagem_erro);

                    return $nota;
                }
                $nota->forceFill([
                    'status' => StatusNota::Autorizada, 'cstat' => $r->cstat, 'motivo' => $r->motivo, 'protocolo' => $r->protocolo,
                    'xml_autorizado' => $r->xmlAutorizado, 'emitido_em' => $nota->emitido_em ?? now(), 'mensagem_erro' => null,
                ])->save();
                NotaEvento::registrar($nota, $anterior, StatusNota::Autorizada, 'Conciliada com a SEFAZ');
            } elseif ($r->situacao === SituacaoNaSefaz::Cancelada) {
                $nota->forceFill(['status' => StatusNota::Cancelada, 'cstat' => $r->cstat, 'motivo' => $r->motivo])->save();
                NotaEvento::registrar($nota, $anterior, StatusNota::Cancelada, 'Cancelamento encontrado na SEFAZ');
            }

            return $nota;
        });
    }

    /** @return bool true quando o job deve ser despachado de novo com o próximo número */
    private function tratarNumeroOcupado(Nota $nota, ResultadoDeEmissao $r): bool
    {
        $chave = (string) $r->chaveOcupante;
        $emitente = Emitente::query()->findOrFail($nota->emitente_id);
        $consulta = $this->emissor->consultarPorChave($emitente, $chave, null, (string) $nota->ambiente);
        $limite = (int) config('fiscal.tentativas_numero_ocupado');

        if ($consulta->situacao === SituacaoNaSefaz::Cancelada && $nota->tentativas_numero < $limite) {
            $serie = EmitenteSerie::query()->where('emitente_id', $emitente->id)->where('modelo', ModeloDocumento::Nfe)->lockForUpdate()->firstOrFail();
            $nota->forceFill([
                'numero' => $serie->proximo_numero, 'tentativas_numero' => $nota->tentativas_numero + 1,
                'chave' => null, 'xml_enviado' => null, 'cstat' => '539', 'motivo' => $r->motivo,
            ])->save();
            $serie->update(['proximo_numero' => $serie->proximo_numero + 1]);
            NotaEvento::registrar($nota, StatusNota::Processando, StatusNota::Processando, "Número ocupado por nota cancelada ({$chave}); avançou para o {$nota->numero}");

            return true;
        }

        $mensagem = $consulta->situacao === SituacaoNaSefaz::Autorizada
            ? "O número {$nota->numero} já foi autorizado na SEFAZ pela chave {$chave}. Concilie manualmente antes de emitir de novo."
            : "Não foi possível liberar um número para a série (cStat 539, chave ocupante {$chave}). Verifique a numeração em Configurações > Fiscal.";
        $nota->forceFill(['status' => StatusNota::Erro, 'cstat' => '539', 'motivo' => $r->motivo, 'mensagem_erro' => $mensagem])->save();
        NotaEvento::registrar($nota, StatusNota::Processando, StatusNota::Erro, $mensagem);

        return false;
    }
}
