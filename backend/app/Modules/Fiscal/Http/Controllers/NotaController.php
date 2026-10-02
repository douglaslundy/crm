<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Controllers;

use App\Modules\Fiscal\Application\Actions\AtualizarRascunhoDeNota;
use App\Modules\Fiscal\Application\Actions\CriarRascunhoDeNota;
use App\Modules\Fiscal\Application\Actions\ExcluirRascunhoDeNota;
use App\Modules\Fiscal\Application\Actions\IniciarEmissao;
use App\Modules\Fiscal\Application\PoliticaDeNotas;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Exceptions\NotaSemXmlException;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Http\Requests\SalvarNotaRequest;
use App\Modules\Fiscal\Http\Resources\NotaResource;
use App\Modules\Fiscal\Http\Resources\NotaStatusResource;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class NotaController
{
    public function __construct(private readonly PoliticaDeNotas $politica) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $dados = $request->validate([
            'status' => ['nullable', Rule::in(array_column(StatusNota::cases(), 'value'))],
            'de' => ['nullable', 'date_format:Y-m-d'],
            'ate' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $notas = Nota::query()->with('cliente')
            ->when($dados['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($dados['de'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($dados['ate'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->latest('created_at')->paginate(25);

        return NotaResource::collection($notas);
    }

    public function show(Nota $nota): NotaResource
    {
        return new NotaResource($nota->load(['cliente', 'itens', 'pagamentos', 'eventos']));
    }

    public function status(Nota $nota): NotaStatusResource
    {
        return new NotaStatusResource($nota);
    }

    public function store(SalvarNotaRequest $request, CriarRascunhoDeNota $acao): JsonResponse
    {
        $nota = $acao->executar($request->dados(), $this->autor($request));

        return (new NotaResource($nota->load(['cliente', 'itens', 'pagamentos', 'eventos'])))->response()->setStatusCode(201);
    }

    public function update(SalvarNotaRequest $request, Nota $nota, AtualizarRascunhoDeNota $acao): NotaResource
    {
        $nota = $acao->executar($nota, $request->dados(), $this->autor($request));

        return new NotaResource($nota->load(['cliente', 'itens', 'pagamentos', 'eventos']));
    }

    public function destroy(Request $request, Nota $nota, ExcluirRascunhoDeNota $acao): Response
    {
        $this->politica->garantirPodeRascunhar($this->autor($request));
        $acao->executar($nota);

        return response()->noContent();
    }

    public function emitir(Request $request, Nota $nota, IniciarEmissao $acao): JsonResponse
    {
        $nota = $acao->executar($nota->id, $this->autor($request));

        return (new NotaStatusResource($nota))->response()->setStatusCode(202);
    }

    public function xml(Nota $nota): Response
    {
        $nota->makeVisible('xml_autorizado');
        if ($nota->status !== StatusNota::Autorizada || $nota->xml_autorizado === null) {
            throw new NotaSemXmlException;
        }

        return response($nota->xml_autorizado, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'attachment; filename="NFe-'.$nota->numero.'.xml"',
        ]);
    }

    private function autor(Request $request): Usuario
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return $autor;
    }
}
