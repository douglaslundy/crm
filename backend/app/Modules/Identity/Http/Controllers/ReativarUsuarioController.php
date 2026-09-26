<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\Actions\ReativarUsuario;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Identity\Http\Resources\UsuarioDaEmpresaResource;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Http\Request;

final class ReativarUsuarioController
{
    public function __invoke(Request $request, string $id, TenantContext $contexto, ReativarUsuario $reativar): UsuarioDaEmpresaResource
    {
        /** @var Usuario $autor */
        $autor = $request->user();
        $alvo = Usuario::query()->daEmpresa($contexto->require())->findOrFail($id);

        return new UsuarioDaEmpresaResource($reativar->executar($autor, $alvo));
    }
}
