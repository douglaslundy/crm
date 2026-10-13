<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Models\MotivoDePerda;
use App\Modules\Identity\Domain\Models\Usuario;

final class CriarMotivoDePerda
{
    public function __construct(private readonly PoliticaDoCrm $politica) {}

    public function executar(Usuario $autor, string $nome): MotivoDePerda
    {
        $this->politica->garantirPodeConfigurar($autor);

        return MotivoDePerda::create(['nome' => trim($nome), 'ativo' => true]);
    }
}
