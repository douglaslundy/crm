<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Identity\Application\PoliticaDeChavesDeApi;
use App\Modules\Identity\Domain\Models\ChaveDeApi;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Support\Facades\DB;

/** Idempotente: revogar de novo mantém o instante da primeira revogação. */
final class RevogarChaveDeApi
{
    public function __construct(private readonly PoliticaDeChavesDeApi $politica) {}

    public function executar(Usuario $autor, string $id): ChaveDeApi
    {
        $this->politica->garantirPodeGerenciar($autor);

        return DB::transaction(function () use ($autor, $id): ChaveDeApi {
            $chave = ChaveDeApi::query()->lockForUpdate()->findOrFail($id);

            if (! $chave->estaRevogada()) {
                $chave->forceFill(['revogada_em' => now()])->save();
                activity('api')->performedOn($chave)->causedBy($autor)->event('chave_revogada')
                    ->withProperties(['nome' => $chave->nome, 'prefixo' => $chave->prefixo, 'ultimos4' => $chave->ultimos4])
                    ->log('Chave de API revogada');
            }

            return $chave;
        });
    }
}
