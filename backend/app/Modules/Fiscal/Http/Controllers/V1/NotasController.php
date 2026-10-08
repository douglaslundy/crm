<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Controllers\V1;

use App\Modules\Fiscal\Application\Actions\EmitirNotaPelaApi;
use App\Modules\Fiscal\Application\Actions\GerarDanfe;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Http\Controllers\NotaController;
use App\Modules\Fiscal\Http\Requests\EmitirNotaApiRequest;
use App\Modules\Fiscal\Http\Resources\NotaResource;
use App\Modules\Fiscal\Http\Resources\NotaStatusResource;
use App\Modules\Identity\Domain\Enums\AmbienteDaChave;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Http\PaginadorPorCursor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Emissão e consultas da API. XML e PDF delegam ao controller do app (mesma resposta, sem copiar a lógica). */
final class NotasController
{
    public function __construct(private readonly NotaController $app) {}

    public function store(EmitirNotaApiRequest $request, EmitirNotaPelaApi $emitir): JsonResponse
    {
        /** @var Usuario $autor */
        $autor = $request->user();
        /** @var AmbienteDaChave $ambiente */
        $ambiente = $request->attributes->get('ambiente_da_chave');

        $nota = $emitir->executar($autor, $ambiente, $request->dados());
        $request->attributes->set('idempotencia_nota_id', $nota->id);

        return response()->json(['data' => ['id' => $nota->id, 'modelo' => $nota->modelo->value, 'status' => $nota->status->value]], 202);
    }

    public function index(Request $request, PaginadorPorCursor $paginador): JsonResponse
    {
        $filtros = $request->validate([
            'status' => ['nullable', Rule::in(array_column(StatusNota::cases(), 'value'))],
            'modelo' => ['nullable', Rule::in(['NFE', 'NFCE', 'DPS'])],
            'de' => ['nullable', 'date_format:Y-m-d'],
            'ate' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $consulta = $this->visiveis()->with('cliente')
            ->when($filtros['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filtros['modelo'] ?? null, fn ($q, $m) => $q->where('modelo', $m))
            ->when($filtros['de'] ?? null, fn ($q, $d) => $q->whereDate('notas.created_at', '>=', $d))
            ->when($filtros['ate'] ?? null, fn ($q, $d) => $q->whereDate('notas.created_at', '<=', $d));

        return response()->json($paginador->paginar($consulta, $request, fn (Nota $nota): array => (new NotaResource($nota))->resolve($request)));
    }

    public function show(string $id): NotaResource
    {
        return new NotaResource($this->visiveis()->with(['cliente', 'itens', 'pagamentos', 'eventos'])->findOrFail($id));
    }

    public function status(string $id): NotaStatusResource
    {
        return new NotaStatusResource($this->visiveis()->findOrFail($id));
    }

    public function pdf(string $id, GerarDanfe $acao): Response
    {
        return $this->app->pdf($this->visiveis()->findOrFail($id), $acao);
    }

    public function xml(string $id): Response
    {
        return $this->app->xml($this->visiveis()->findOrFail($id));
    }

    public function xmlCancelamento(string $id): Response
    {
        return $this->app->xmlCancelamento($this->visiveis()->findOrFail($id));
    }

    /**
     * A API só enxerga notas já emitidas: rascunho é do app.
     *
     * @return Builder<Nota>
     */
    private function visiveis(): Builder
    {
        return Nota::query()->where('status', '!=', StatusNota::Rascunho->value);
    }
}
