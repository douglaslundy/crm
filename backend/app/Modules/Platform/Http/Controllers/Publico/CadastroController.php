<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Publico;

use App\Modules\Identity\Http\Resources\UsuarioResource;
use App\Modules\Platform\Application\Actions\CadastrarEmpresa;
use App\Modules\Platform\Http\Requests\CadastroRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

final class CadastroController
{
    public function __invoke(CadastroRequest $request, CadastrarEmpresa $cadastrar): JsonResponse
    {
        $usuario = $cadastrar->executar($request->dados());

        Auth::guard('web')->login($usuario);
        $request->session()->regenerate();

        return (new UsuarioResource($usuario->load('tenant')))->response()->setStatusCode(201);
    }
}
