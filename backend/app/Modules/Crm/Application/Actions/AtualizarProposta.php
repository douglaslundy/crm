<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Application\ValidadorDeProposta;
use App\Modules\Crm\Domain\Models\Proposta;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Support\Facades\DB;

final class AtualizarProposta
{
    public function __construct(private readonly PoliticaDoCrm $politica) {}

    /** @param array{validade_dias?: int, desconto_centavos?: int, observacoes?: ?string} $dados */
    public function executar(Usuario $autor, Proposta $proposta, array $dados): Proposta
    {
        $this->politica->garantirPodeOperarNegocios($autor);

        return DB::transaction(function () use ($proposta, $dados): Proposta {
            $proposta = Proposta::query()->lockForUpdate()->findOrFail($proposta->id);
            ValidadorDeProposta::garantirEditavel($proposta);

            foreach (['validade_dias', 'desconto_centavos', 'observacoes'] as $campo) {
                if (array_key_exists($campo, $dados)) {
                    $proposta->{$campo} = $dados[$campo];
                }
            }
            ValidadorDeProposta::garantirDescontoValido($proposta);
            $proposta->save();

            return $proposta->refresh()->load('itens');
        });
    }
}
