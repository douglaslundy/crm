<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Controllers;

use App\Modules\Crm\Application\Actions\RegistrarContato;
use App\Modules\Crm\Application\ApresentadorDeAtividades;
use App\Modules\Crm\Http\Requests\ContatoRequest;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Http\JsonResponse;

final class ContatosController
{
    public function store(ContatoRequest $request, RegistrarContato $registrar, ApresentadorDeAtividades $apresentador): JsonResponse
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        $resultado = $registrar->executar(
            $autor,
            $request->string('canal')->toString(),
            $request->filled('cliente_id') ? $request->string('cliente_id')->toString() : null,
            $request->filled('negocio_id') ? $request->string('negocio_id')->toString() : null,
        );

        return response()->json(['data' => [
            'atividade' => $apresentador->apresentar([$resultado['atividade']])[0],
            'url' => $resultado['url'],
        ]], 201);
    }
}
