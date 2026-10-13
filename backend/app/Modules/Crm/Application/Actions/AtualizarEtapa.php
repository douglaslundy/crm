<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Enums\StatusDoNegocio;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Identity\Domain\Models\Usuario;

final class AtualizarEtapa
{
    public function __construct(private readonly PoliticaDoCrm $politica) {}

    /** @param array{nome?: string, probabilidade?: int, ativa?: bool} $dados */
    public function executar(Usuario $autor, Etapa $etapa, array $dados): Etapa
    {
        $this->politica->garantirPodeConfigurar($autor);

        if (isset($dados['probabilidade']) && ($dados['probabilidade'] < 0 || $dados['probabilidade'] > 100)) {
            throw new RegraDoCrmException('A probabilidade vai de 0 a 100.', 'PROBABILIDADE_INVALIDA');
        }

        if (($dados['ativa'] ?? true) === false
            && Negocio::query()->where('etapa_id', $etapa->id)->where('status', StatusDoNegocio::Aberto)->exists()) {
            throw new RegraDoCrmException('Mova os negócios abertos antes de desativar a etapa.', 'ETAPA_COM_NEGOCIOS');
        }

        $etapa->update(array_intersect_key($dados, ['nome' => 1, 'probabilidade' => 1, 'ativa' => 1]));

        return $etapa;
    }
}
