<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Actions;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Shared\Domain\Cnpj;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Autoatendimento: plano com teste → TESTE; sem teste → PENDENTE (spec F1 §1). */
final class CadastrarEmpresa
{
    public const CNPJ_DUPLICADO = 'Este CNPJ já possui conta. Entre ou recupere a senha.';

    public const EMAIL_DUPLICADO = 'Este e-mail já está em uso.';

    /**
     * @param array{cnpj: string, razao_social: string, nome_fantasia: ?string, plano_id: string,
     *     responsavel_nome: string, email: string, password: string} $dados
     */
    public function executar(array $dados): Usuario
    {
        $plano = Plano::query()->whereKey($dados['plano_id'])->where('ativo', true)->where('visivel', true)->firstOrFail();
        $cnpj = Cnpj::de($dados['cnpj']);

        try {
            return DB::transaction(function () use ($dados, $plano, $cnpj): Usuario {
                $emTeste = $plano->dias_teste > 0;

                $tenant = new Tenant([
                    'razao_social' => $dados['razao_social'],
                    'nome_fantasia' => $dados['nome_fantasia'],
                    'cnpj' => $cnpj->valor,
                ]);
                $tenant->forceFill([
                    'plano_id' => $plano->id,
                    'situacao' => $emTeste ? SituacaoAssinatura::Teste : SituacaoAssinatura::Pendente,
                    'teste_termina_em' => $emTeste
                        ? today((string) config('app.fuso_negocio'))->addDays($plano->dias_teste)->toDateString()
                        : null,
                    'situacao_alterada_em' => now(),
                ])->save();

                $usuario = new Usuario([
                    'nome' => $dados['responsavel_nome'],
                    'email' => $dados['email'],
                    'password' => $dados['password'],
                ]);
                $usuario->forceFill(['tenant_id' => $tenant->id, 'papel' => Papel::Proprietario, 'ativo' => true])->save();

                activity('assinatura')
                    ->performedOn($tenant)
                    ->causedBy($usuario)
                    ->event('empresa_cadastrada')
                    ->withProperties(['plano' => $plano->nome, 'situacao' => $tenant->situacao->value])
                    ->log('Empresa cadastrada');

                return $usuario;
            });
        } catch (UniqueConstraintViolationException) {
            // Corrida entre dois cadastros: a validação passou nos dois.
            $campo = Tenant::query()->where('cnpj', $cnpj->valor)->exists() ? 'cnpj' : 'email';
            throw ValidationException::withMessages([
                $campo => $campo === 'cnpj' ? self::CNPJ_DUPLICADO : self::EMAIL_DUPLICADO,
            ]);
        }
    }
}
