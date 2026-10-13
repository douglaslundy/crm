<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Enums\StatusDoNegocio;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Identity\Domain\Models\Usuario;

final class AtualizarFunil
{
    public function __construct(private readonly PoliticaDoCrm $politica) {}

    /** @param array{nome?: string, ativo?: bool} $dados */
    public function executar(Usuario $autor, Funil $funil, array $dados): Funil
    {
        $this->politica->garantirPodeConfigurar($autor);

        if (($dados['ativo'] ?? true) === false) {
            if ($funil->padrao) {
                throw new RegraDoCrmException('O funil padrão não pode ser desativado.', 'FUNIL_PADRAO_PROTEGIDO');
            }
            if (Negocio::query()->where('funil_id', $funil->id)->where('status', StatusDoNegocio::Aberto)->exists()) {
                throw new RegraDoCrmException('Mova ou feche os negócios abertos antes de desativar o funil.', 'FUNIL_COM_NEGOCIOS');
            }
        }

        $funil->update(array_intersect_key($dados, ['nome' => 1, 'ativo' => 1]));

        return $funil;
    }
}
