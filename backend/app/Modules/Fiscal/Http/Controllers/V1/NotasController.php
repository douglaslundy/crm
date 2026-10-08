<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Controllers\V1;

use App\Modules\Fiscal\Application\Actions\EmitirNotaPelaApi;
use App\Modules\Fiscal\Application\Actions\GerarDanfe;
use App\Modules\Fiscal\Application\Actions\IniciarCancelamento;
use App\Modules\Fiscal\Application\VerificadorDeAmbienteDaChave;
use App\Modules\Fiscal\Domain\Enums\AmbienteFiscal;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Http\Controllers\NotaController;
use App\Modules\Fiscal\Http\Requests\CancelarNotaRequest;
use App\Modules\Fiscal\Http\Requests\EmitirNotaApiRequest;
use App\Modules\Fiscal\Http\Resources\NotaResource;
use App\Modules\Fiscal\Http\Resources\NotaStatusResource;
use App\Modules\Identity\Domain\Enums\AmbienteDaChave;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Domain\Models\Idempotencia;
use App\Modules\Shared\Http\PaginadorPorCursor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Emissão e consultas da API. XML e PDF delegam ao controller do app (mesma resposta, sem copiar a lógica). */
final class NotasController
{
    public function __construct(private readonly NotaController $app, private readonly VerificadorDeAmbienteDaChave $ambientes) {}

    public function store(EmitirNotaApiRequest $request, EmitirNotaPelaApi $emitir): JsonResponse
    {
        /** @var Usuario $autor */
        $autor = $request->user();
        /** @var AmbienteDaChave $ambiente */
        $ambiente = $request->attributes->get('ambiente_da_chave');

        $reservaId = $request->attributes->get('idempotencia_reserva_id');
        $vincular = is_string($reservaId)
            ? function (string $notaId) use ($reservaId): void {
                Idempotencia::query()->whereKey($reservaId)->update(['nota_id' => $notaId]);
            }
        : null;

        $nota = $emitir->executar($autor, $ambiente, $request->dados(), $vincular);
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

    public function cancelar(CancelarNotaRequest $request, string $id, IniciarCancelamento $acao): JsonResponse
    {
        /** @var Usuario $autor */
        $autor = $request->user();
        $nota = $this->visiveis()->findOrFail($id);
        /** @var AmbienteDaChave $ambiente */
        $ambiente = $request->attributes->get('ambiente_da_chave');
        if (($daNota = AmbienteFiscal::tryFrom((string) $nota->ambiente)) !== null) {
            $this->ambientes->garantir($ambiente, $daNota);
        }

        return (new NotaStatusResource($acao->executar($nota->id, $request->justificativa(), $autor)))->response()->setStatusCode(202);
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
