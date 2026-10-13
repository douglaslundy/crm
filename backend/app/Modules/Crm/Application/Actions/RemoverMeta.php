<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Models\MetaDoVendedor;
use App\Modules\Identity\Domain\Models\Usuario;

final class RemoverMeta
{
    public function __construct(private readonly PoliticaDoCrm $politica) {}

    public function executar(Usuario $autor, MetaDoVendedor $meta): void
    {
        $this->politica->garantirPodeConfigurar($autor);
        $meta->delete();
    }
}
