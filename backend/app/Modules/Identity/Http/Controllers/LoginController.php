<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Resources\UsuarioResource;
use Illuminate\Http\JsonResponse;

final class LoginController
{
    public function __invoke(LoginRequest $request): JsonResponse
    {
        $request->autenticar();
        $request->session()->regenerate();

        return (new UsuarioResource($request->user()->load('tenant')))->response();
    }
}
