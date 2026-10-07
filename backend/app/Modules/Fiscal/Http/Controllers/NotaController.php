<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Controllers;

use App\Modules\Fiscal\Application\Actions\AtualizarRascunhoDeNota;
use App\Modules\Fiscal\Application\Actions\CriarRascunhoDeNota;
use App\Modules\Fiscal\Application\Actions\ExcluirRascunhoDeNota;
use App\Modules\Fiscal\Application\Actions\GerarDanfe;
use App\Modules\Fiscal\Application\Actions\IniciarCancelamento;
use App\Modules\Fiscal\Application\Actions\IniciarEmissao;
use App\Modules\Fiscal\Application\Actions\RetransmitirNota;
use App\Modules\Fiscal\Application\PoliticaDeNotas;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Exceptions\NotaSemXmlCancelamentoException;
use App\Modules\Fiscal\Domain\Exceptions\NotaSemXmlException;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Http\Requests\CancelarNotaRequest;
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
            'modelo' => ['nullable', Rule::in(['NFE', 'NFCE', 'DPS'])],
            'de' => ['nullable', 'date_format:Y-m-d'],
            'ate' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $notas = Nota::query()->with('cliente')
            ->when($dados['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($dados['modelo'] ?? null, fn ($q, $m) => $q->where('modelo', $m))
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

    public function retransmitir(Request $request, Nota $nota, RetransmitirNota $acao): JsonResponse
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
            'Content-Disposition' => 'attachment; filename="'.$this->nomeDoXml($nota).'"',
        ]);
    }

    public function pdf(Nota $nota, GerarDanfe $acao): Response
    {
        $arquivo = $acao->executar($nota);

        return response($arquivo->conteudo, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$arquivo->nome.'"',
        ]);
    }

    public function cancelar(CancelarNotaRequest $request, Nota $nota, IniciarCancelamento $acao): JsonResponse
    {
        $nota = $acao->executar($nota->id, $request->justificativa(), $this->autor($request));

        return (new NotaStatusResource($nota))->response()->setStatusCode(202);
    }

    public function xmlCancelamento(Nota $nota): Response
    {
        $nota->makeVisible('xml_cancelamento');
        if ($nota->status !== StatusNota::Cancelada || $nota->xml_cancelamento === null) {
            throw new NotaSemXmlCancelamentoException;
        }

        return response($nota->xml_cancelamento, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'attachment; filename="'.$this->nomeDoXml($nota, '-cancelamento').'"',
        ]);
    }

    /** A NFS-e leva o número oficial no nome; a NF-e e a NFC-e, o número da nota. */
    private function nomeDoXml(Nota $nota, string $sufixo = ''): string
    {
        return $nota->modelo === ModeloDocumento::Dps
            ? 'NFSe-'.($nota->numero_nfse ?? $nota->numero).$sufixo.'.xml'
            : 'NFe-'.$nota->numero.$sufixo.'.xml';
    }

    private function autor(Request $request): Usuario
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return $autor;
    }
}
