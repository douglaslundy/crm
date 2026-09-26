<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Identity\Application\PoliticaDeUsuarios;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Identity\Notifications\ConviteDeUsuario;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

final class ConvidarUsuario
{
    public function __construct(
        private readonly PoliticaDeUsuarios $politica,
        private readonly EntitlementService $entitlements,
    ) {}

    /** @param array{nome: string, email: string, papel: string} $dados */
    public function executar(Usuario $autor, array $dados): Usuario
    {
        $papel = Papel::from($dados['papel']);
        $this->politica->garantirPodeAtribuir($autor, $papel);

        [$usuario, $tenant] = DB::transaction(function () use ($autor, $dados, $papel): array {
            // Trava a linha do tenant: dois convites simultâneos não passam juntos do limite.
            /** @var Tenant $tenant */
            $tenant = Tenant::query()->with('plano.limites')->lockForUpdate()->findOrFail($autor->tenant_id);
            $this->entitlements->garantirCapacidade($tenant, Recurso::Usuarios);

            // Senha aleatória nunca revelada: o convidado define a dele pelo link.
            $usuario = new Usuario(['nome' => $dados['nome'], 'email' => $dados['email'], 'password' => Str::password(32)]);
            $usuario->forceFill(['tenant_id' => $tenant->id, 'papel' => $papel, 'ativo' => true])->save();

            activity('usuarios')->performedOn($usuario)->causedBy($autor)->event('usuario_convidado')
                ->withProperties(['papel' => $papel->value])->log('Usuário convidado');

            return [$usuario, $tenant];
        });

        $token = Password::broker('convites')->createToken($usuario);
        $usuario->notify(new ConviteDeUsuario($token, $tenant->nomeDeExibicao()));

        return $usuario;
    }
}
