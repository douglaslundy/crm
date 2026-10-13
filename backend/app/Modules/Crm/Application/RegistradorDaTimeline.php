<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Crm\Domain\Enums\TipoDeRegistro;
use App\Modules\Crm\Domain\Models\RegistroDaTimeline;
use Carbon\CarbonInterface;

/** Único ponto que escreve na timeline do cliente. A F7c só adiciona listeners que chamam isto. */
final class RegistradorDaTimeline
{
    /** @param array<string, mixed> $dados */
    public function registrar(
        TipoDeRegistro $tipo,
        string $clienteId,
        ?string $negocioId,
        ?string $autorId,
        string $texto,
        array $dados = [],
        ?string $atividadeId = null,
        ?CarbonInterface $ocorreuEm = null,
    ): RegistroDaTimeline {
        return RegistroDaTimeline::create([
            'cliente_id' => $clienteId,
            'negocio_id' => $negocioId,
            'atividade_id' => $atividadeId,
            'tipo' => $tipo,
            'texto' => $texto,
            'dados' => $dados,
            'autor_id' => $autorId,
            'ocorreu_em' => $ocorreuEm ?? now(),
        ]);
    }
}
