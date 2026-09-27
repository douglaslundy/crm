<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Identity\Application\PoliticaDeUsuarios;
use App\Modules\Identity\Domain\Exceptions\UsuarioJaAtivoException;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Support\Facades\DB;

final class ReativarUsuario
{
    public function __construct(
        private readonly PoliticaDeUsuarios $politica,
        private readonly EntitlementService $entitlements,
    ) {}

    public function executar(Usuario $autor, Usuario $alvo): Usuario
    {
        $this->politica->garantirPodeAlterar($autor, $alvo);

        if ($alvo->ativo) {
            throw new UsuarioJaAtivoException;
        }

        return DB::transaction(function () use ($autor, $alvo): Usuario {
            /** @var Tenant $tenant */
            $tenant = Tenant::query()->with('plano.limites')->lockForUpdate()->findOrFail($alvo->tenant_id);
            $this->entitlements->garantirCapacidade($tenant, Recurso::Usuarios);

            $alvo->forceFill(['ativo' => true])->save();
            activity('usuarios')->performedOn($alvo)->causedBy($autor)->event('usuario_reativado')->log('Usuário reativado');

            return $alvo;
        });
    }
}
