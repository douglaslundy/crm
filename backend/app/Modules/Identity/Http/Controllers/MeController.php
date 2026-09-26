<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Http\Resources\UsuarioResource;
use Illuminate\Http\Request;

final class MeController
{
    public function __invoke(Request $request): UsuarioResource
    {
        return new UsuarioResource($request->user()->load('tenant'));
    }
}
