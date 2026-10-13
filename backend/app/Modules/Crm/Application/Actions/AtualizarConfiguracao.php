<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Models\ConfiguracaoDoCrm;
use App\Modules\Identity\Domain\Models\Usuario;

final class AtualizarConfiguracao
{
    public function __construct(private readonly PoliticaDoCrm $politica) {}

    public function executar(Usuario $autor, bool $vendedorVeSoOsSeus): ConfiguracaoDoCrm
    {
        $this->politica->garantirPodeConfigurar($autor);

        $configuracao = ConfiguracaoDoCrm::query()->first() ?? new ConfiguracaoDoCrm;
        $configuracao->fill(['vendedor_ve_so_os_seus' => $vendedorVeSoOsSeus])->save();

        return $configuracao;
    }
}
