<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http;

use Illuminate\Http\JsonResponse;

final class HealthController
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }
}
