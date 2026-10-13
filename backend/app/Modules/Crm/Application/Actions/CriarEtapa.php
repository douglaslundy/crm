<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Identity\Domain\Models\Usuario;

final class CriarEtapa
{
    public function __construct(private readonly PoliticaDoCrm $politica) {}

    public function executar(Usuario $autor, Funil $funil, string $nome, int $probabilidade): Etapa
    {
        $this->politica->garantirPodeConfigurar($autor);

        if ($probabilidade < 0 || $probabilidade > 100) {
            throw new RegraDoCrmException('A probabilidade vai de 0 a 100.', 'PROBABILIDADE_INVALIDA');
        }

        $ultima = Etapa::query()->where('funil_id', $funil->id)->max('posicao');

        return Etapa::create([
            'funil_id' => $funil->id,
            'nome' => trim($nome),
            'posicao' => $ultima === null ? 0 : (int) $ultima + 1,
            'probabilidade' => $probabilidade,
            'ativa' => true,
        ]);
    }
}
