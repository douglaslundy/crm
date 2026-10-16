<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Publico;

use App\Modules\Platform\Application\DesafioAntiRobo;
use App\Modules\Platform\Domain\Models\ContatoSite;
use App\Modules\Platform\Http\Requests\ContatoSiteRequest;
use Illuminate\Http\JsonResponse;

final class ContatoSiteController
{
    public function desafio(DesafioAntiRobo $desafio): JsonResponse
    {
        return response()->json(['data' => $desafio->gerar()]);
    }

    public function enviar(ContatoSiteRequest $request): JsonResponse
    {
        ContatoSite::create([...$request->safe()->only(['nome', 'email', 'mensagem']), 'ip' => $request->ip()]);

        return response()->json(['data' => ['recebido' => true]], 201);
    }
}
