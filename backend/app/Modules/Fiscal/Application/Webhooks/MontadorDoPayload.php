<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Webhooks;

use App\Modules\Fiscal\Domain\Enums\EventoDeWebhook;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Http\Resources\NotaResource;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;

/**
 * Corpo do webhook: o mesmo recurso da nota que a API devolve (itens, pagamentos e eventos), nunca o XML.
 * O JSON fica congelado na entrega: reenvios e retentativas levam exatamente este conteúdo.
 */
class MontadorDoPayload
{
    /** @return array<string, mixed> */
    public function paraNota(Nota $nota, EventoDeWebhook $evento, string $eventoId, ?CarbonInterface $criadoEm = null): array
    {
        $nota = Nota::query()->with(['cliente', 'itens', 'pagamentos', 'eventos'])->findOrFail($nota->id);

        return [
            'id' => $eventoId,
            'tipo' => $evento->value,
            'criado_em' => ($criadoEm ?? now())->toIso8601String(),
            'ambiente' => $nota->ambiente,
            'data' => ['nota' => (new NotaResource($nota))->resolve(Request::create('/'))],
        ];
    }

    /** @return array<string, mixed> */
    public function paraTeste(string $eventoId, string $ambiente): array
    {
        return [
            'id' => $eventoId,
            'tipo' => EventoDeWebhook::Teste->value,
            'criado_em' => now()->toIso8601String(),
            'ambiente' => $ambiente,
            'data' => ['mensagem' => 'Este é um evento de teste enviado pela Plataforma Fiscal.'],
        ];
    }
}
