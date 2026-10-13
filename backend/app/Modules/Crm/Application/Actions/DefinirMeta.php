<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Contracts\DiretorioDeUsuarios;
use App\Modules\Crm\Domain\Enums\UnidadeDaMeta;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\MetaDoVendedor;
use App\Modules\Identity\Domain\Models\Usuario;
use Carbon\CarbonInterface;

final class DefinirMeta
{
    public function __construct(
        private readonly PoliticaDoCrm $politica,
        private readonly DiretorioDeUsuarios $usuarios,
    ) {}

    public function executar(Usuario $autor, string $usuarioId, CarbonInterface $mes, UnidadeDaMeta $unidade, int $alvo): MetaDoVendedor
    {
        $this->politica->garantirPodeConfigurar($autor);

        if ($alvo <= 0) {
            throw RegraDoCrmException::metaInvalida('A meta precisa ser maior que zero.');
        }

        if (! $this->usuarios->ehDaEmpresa($usuarioId)) {
            throw RegraDoCrmException::metaInvalida('Escolha um usuário ativo da empresa.');
        }

        return MetaDoVendedor::query()->updateOrCreate(
            ['usuario_id' => $usuarioId, 'mes' => $mes->copy()->startOfMonth()->toDateString()],
            ['unidade' => $unidade, 'alvo' => $alvo],
        );
    }
}
