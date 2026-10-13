<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Identity\Domain\Models\Usuario;

final class CriarFunil
{
    public function __construct(private readonly PoliticaDoCrm $politica) {}

    public function executar(Usuario $autor, string $nome): Funil
    {
        $this->politica->garantirPodeConfigurar($autor);

        $ultima = Funil::query()->max('posicao');

        return Funil::create([
            'nome' => trim($nome),
            'padrao' => false,
            'ativo' => true,
            'posicao' => $ultima === null ? 0 : (int) $ultima + 1,
        ]);
    }
}
