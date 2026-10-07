<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Billing\Application\Actions\ReceberWebhook;
use App\Modules\Billing\Infrastructure\VerificadorDeAssinaturaDoWebhook;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class WebhookDoMercadoPagoController
{
    public function __invoke(Request $request, VerificadorDeAssinaturaDoWebhook $verificador, ReceberWebhook $receber): JsonResponse
    {
        // O PHP troca o ponto por sublinhado nos nomes da query: `data.id` chega como `data_id`.
        $dataId = $request->query('data_id') ?? data_get($request->query(), 'data.id');
        $dataId = is_string($dataId) ? $dataId : null;

        $valido = $verificador->valido(
            $request->header('x-signature'),
            $request->header('x-request-id'),
            $dataId,
            config('billing.mercadopago.webhook_secret'),
            CarbonImmutable::now(),
        );
        if (! $valido || $dataId === null) {
            return response()->json(['message' => 'Assinatura inválida.'], 401);
        }

        try {
            $receber->executar($request->json()->all(), $dataId);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Falha temporária. Tente de novo.'], 503);
        }

        return response()->json(['ok' => true]);
    }
}
