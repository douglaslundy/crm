<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Identity\Application\PoliticaDeChavesDeApi;
use App\Modules\Identity\Domain\Enums\AmbienteDaChave;
use App\Modules\Identity\Domain\Exceptions\LimiteDeChavesAtingidoException;
use App\Modules\Identity\Domain\GeradorDeChaveDeApi;
use App\Modules\Identity\Domain\Models\ChaveDeApi;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Identity\Domain\ResultadoDaChave;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CriarChaveDeApi
{
    public function __construct(
        private readonly PoliticaDeChavesDeApi $politica,
        private readonly EntitlementService $entitlement,
        private readonly GeradorDeChaveDeApi $gerador,
    ) {}

    public function executar(Usuario $autor, string $nome, AmbienteDaChave $ambiente): ResultadoDaChave
    {
        $this->politica->garantirPodeGerenciar($autor);

        $nome = trim($nome);
        if ($nome === '' || mb_strlen($nome) > 80) {
            throw ValidationException::withMessages(['nome' => 'Informe um nome de até 80 caracteres.']);
        }

        $this->entitlement->garantirModulo($autor->tenant, Modulo::Api);

        return DB::transaction(function () use ($autor, $nome, $ambiente): ResultadoDaChave {
            // Trava a empresa para que duas criações simultâneas não passem do limite.
            Tenant::query()->whereKey($autor->tenant_id)->lockForUpdate()->first();

            $ativas = ChaveDeApi::query()->whereNull('revogada_em')->count();
            if ($ativas >= PoliticaDeChavesDeApi::LIMITE_DE_CHAVES_ATIVAS) {
                throw new LimiteDeChavesAtingidoException(PoliticaDeChavesDeApi::LIMITE_DE_CHAVES_ATIVAS);
            }

            $gerada = $this->gerador->gerar($ambiente);
            $chave = new ChaveDeApi;
            $chave->forceFill([
                'usuario_id' => $autor->id,
                'nome' => $nome,
                'ambiente' => $ambiente,
                'prefixo' => $gerada->prefixo,
                'ultimos4' => $gerada->ultimos4,
                'hash' => $gerada->hash,
            ])->save();

            // Nunca o texto da chave: só o que identifica (prefixo e últimos 4).
            activity('api')->performedOn($chave)->causedBy($autor)->event('chave_criada')
                ->withProperties(['nome' => $nome, 'ambiente' => $ambiente->value, 'prefixo' => $gerada->prefixo, 'ultimos4' => $gerada->ultimos4])
                ->log('Chave de API criada');

            return new ResultadoDaChave($chave, $gerada->texto);
        });
    }
}
