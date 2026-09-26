<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Actions;

use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Models\Plano;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Cria ou edita um plano. Editar vale para todas as empresas que o usam (spec F1 §8). */
final class SalvarPlano
{
    /**
     * @param array{nome: string, descricao: ?string, preco_mensal_centavos: int, preco_anual_centavos: ?int,
     *     dias_teste: int, politica_excedente: string, preco_documento_excedente_centavos: ?int, ativo: bool,
     *     visivel: bool, ordem: int, modulos: list<string>, limites: array<string, int>} $dados
     */
    public function executar(?Plano $plano, array $dados, Usuario $autor): Plano
    {
        return DB::transaction(function () use ($plano, $dados, $autor): Plano {
            $novo = $plano === null;
            $plano ??= new Plano;
            $plano->fill(Arr::except($dados, ['modulos', 'limites']))->save();

            $plano->modulos()->delete();
            foreach ($dados['modulos'] as $modulo) {
                $plano->modulos()->create(['modulo' => $modulo]);
            }

            $plano->limites()->delete();
            foreach ($dados['limites'] as $recurso => $limite) {
                $plano->limites()->create(['recurso' => $recurso, 'limite' => $limite]);
            }

            activity('plataforma')
                ->performedOn($plano)
                ->causedBy($autor)
                ->event($novo ? 'plano_criado' : 'plano_editado')
                ->withProperties(['dados' => $dados])
                ->log($novo ? 'Plano criado' : 'Plano editado');

            return $plano->load(['modulos', 'limites'])->loadCount('tenants');
        });
    }
}
