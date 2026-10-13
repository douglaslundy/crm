<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Models\MotivoDePerda;
use App\Modules\Identity\Domain\Models\Usuario;

final class AtualizarMotivoDePerda
{
    public function __construct(private readonly PoliticaDoCrm $politica) {}

    /** @param array{nome?: string, ativo?: bool} $dados */
    public function executar(Usuario $autor, MotivoDePerda $motivo, array $dados): MotivoDePerda
    {
        $this->politica->garantirPodeConfigurar($autor);

        $motivo->update(array_intersect_key($dados, ['nome' => 1, 'ativo' => 1]));

        return $motivo;
    }
}
