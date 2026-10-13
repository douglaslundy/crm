<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Crm\Domain\Enums\TipoDeRegistro;
use App\Modules\Crm\Domain\Events\NegocioCriado;
use App\Modules\Crm\Domain\Events\NegocioGanho;
use App\Modules\Crm\Domain\Events\NegocioMovido;
use App\Modules\Crm\Domain\Events\NegocioPerdido;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\MotivoDePerda;
use App\Modules\Crm\Domain\Models\Negocio;
use Throwable;

/**
 * Listeners síncronos dos eventos do negócio. Os eventos saem depois do commit: uma falha aqui é
 * logada e nunca derruba a ação do vendedor (risco assumido na spec F7b §4).
 */
final class GravarTimelineDoNegocio
{
    public function __construct(private readonly RegistradorDaTimeline $registrador) {}

    public function criado(NegocioCriado $e): void
    {
        $this->proteger(function () use ($e): void {
            $titulo = $this->titulo($e->negocioId);
            $this->registrador->registrar(
                TipoDeRegistro::NegocioCriado, $e->clienteId, $e->negocioId, $e->autorId,
                "Negócio criado: {$titulo}", ['titulo' => $titulo],
            );
        });
    }

    public function movido(NegocioMovido $e): void
    {
        $this->proteger(function () use ($e): void {
            $titulo = $this->titulo($e->negocioId);
            $nomes = Etapa::query()->whereIn('id', [$e->etapaOrigemId, $e->etapaDestinoId])->pluck('nome', 'id');
            $de = (string) ($nomes[$e->etapaOrigemId] ?? '—');
            $para = (string) ($nomes[$e->etapaDestinoId] ?? '—');
            $this->registrador->registrar(
                TipoDeRegistro::NegocioMovido, $e->clienteId, $e->negocioId, $e->autorId,
                "Negócio movido de {$de} para {$para}: {$titulo}", ['titulo' => $titulo, 'de' => $de, 'para' => $para],
            );
        });
    }

    public function ganho(NegocioGanho $e): void
    {
        $this->proteger(function () use ($e): void {
            $titulo = $this->titulo($e->negocioId);
            $this->registrador->registrar(
                TipoDeRegistro::NegocioGanho, $e->clienteId, $e->negocioId, $e->autorId,
                "Negócio ganho: {$titulo}", ['titulo' => $titulo],
            );
        });
    }

    public function perdido(NegocioPerdido $e): void
    {
        $this->proteger(function () use ($e): void {
            $titulo = $this->titulo($e->negocioId);
            $motivo = (string) (MotivoDePerda::query()->whereKey($e->motivoId)->value('nome') ?? '—');
            $this->registrador->registrar(
                TipoDeRegistro::NegocioPerdido, $e->clienteId, $e->negocioId, $e->autorId,
                "Negócio perdido: {$titulo} ({$motivo})", ['titulo' => $titulo, 'motivo' => $motivo],
            );
        });
    }

    private function titulo(string $negocioId): string
    {
        return Negocio::query()->findOrFail($negocioId)->titulo;
    }

    private function proteger(callable $gravar): void
    {
        try {
            $gravar();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
