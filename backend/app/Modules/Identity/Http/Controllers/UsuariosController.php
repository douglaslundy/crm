<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\Actions\ConvidarUsuario;
use App\Modules\Identity\Application\Actions\EditarUsuario;
use App\Modules\Identity\Application\PoliticaDeUsuarios;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Identity\Http\Requests\ConvidarUsuarioRequest;
use App\Modules\Identity\Http\Requests\EditarUsuarioRequest;
use App\Modules\Identity\Http\Resources\UsuarioDaEmpresaResource;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class UsuariosController
{
    public function __construct(private readonly TenantContext $contexto) {}

    public function index(Request $request, PoliticaDeUsuarios $politica): AnonymousResourceCollection
    {
        $politica->garantirPodeGerenciar($this->autor($request));

        return UsuarioDaEmpresaResource::collection(
            Usuario::query()->daEmpresa($this->contexto->require())->orderBy('nome')->get(),
        );
    }

    public function store(ConvidarUsuarioRequest $request, ConvidarUsuario $convidar): JsonResponse
    {
        $usuario = $convidar->executar($this->autor($request), $request->dados());

        return (new UsuarioDaEmpresaResource($usuario))->response()->setStatusCode(201);
    }

    public function update(EditarUsuarioRequest $request, string $id, EditarUsuario $editar): UsuarioDaEmpresaResource
    {
        $alvo = Usuario::query()->daEmpresa($this->contexto->require())->findOrFail($id);

        return new UsuarioDaEmpresaResource($editar->executar($this->autor($request), $alvo, $request->dados()));
    }

    private function autor(Request $request): Usuario
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return $autor;
    }
}
