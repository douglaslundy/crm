<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Controllers;

use App\Modules\Crm\Application\Actions\AtualizarProposta;
use App\Modules\Crm\Application\Actions\CancelarProposta;
use App\Modules\Crm\Application\Actions\CriarProposta;
use App\Modules\Crm\Application\Actions\EnviarProposta;
use App\Modules\Crm\Application\Actions\GerarRascunhoDeNotaDaProposta;
use App\Modules\Crm\Application\ApresentadorDePropostas;
use App\Modules\Crm\Application\GeradorDePdfDaProposta;
use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Crm\Domain\Models\Proposta;
use App\Modules\Crm\Http\Requests\PropostaRequest;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class PropostasController
{
    public function __construct(
        private readonly PoliticaDoCrm $politica,
        private readonly ApresentadorDePropostas $apresentador,
    ) {}

    public function index(Request $request, string $id): JsonResponse
    {
        $autor = $this->autor($request);
        $this->politica->garantirPodeLer($autor);
        $negocio = $this->negocio($autor, $id);

        $propostas = Proposta::query()->with('itens')->where('negocio_id', $negocio->id)->orderByDesc('numero')->get();

        return response()->json(['data' => $propostas->map(fn (Proposta $p): array => $this->apresentador->apresentar($p, true))->all()]);
    }

    public function store(PropostaRequest $request, string $id, CriarProposta $criar): JsonResponse
    {
        $autor = $this->autor($request);
        /** @var array{validade_dias?: int, desconto_centavos?: int, observacoes?: ?string} $dados */
        $dados = $request->validated();

        return $this->um($criar->executar($autor, $this->negocio($autor, $id), $dados), 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $autor = $this->autor($request);
        $this->politica->garantirPodeLer($autor);

        return $this->um($this->daVisibilidade($autor, $id));
    }

    public function update(PropostaRequest $request, string $id, AtualizarProposta $atualizar): JsonResponse
    {
        $autor = $this->autor($request);
        /** @var array{validade_dias?: int, desconto_centavos?: int, observacoes?: ?string} $dados */
        $dados = $request->validated();

        return $this->um($atualizar->executar($autor, $this->daVisibilidade($autor, $id), $dados));
    }

    public function enviar(Request $request, string $id, EnviarProposta $enviar): JsonResponse
    {
        $autor = $this->autor($request);

        return $this->um($enviar->executar($autor, $this->daVisibilidade($autor, $id)));
    }

    public function cancelar(Request $request, string $id, CancelarProposta $cancelar): JsonResponse
    {
        $autor = $this->autor($request);

        return $this->um($cancelar->executar($autor, $this->daVisibilidade($autor, $id)));
    }

    public function nota(Request $request, string $id, GerarRascunhoDeNotaDaProposta $gerar): JsonResponse
    {
        $autor = $this->autor($request);
        $proposta = $this->daVisibilidade($autor, $id);
        $gerar->executar($autor, $proposta);

        return $this->um($proposta->refresh());
    }

    public function pdf(Request $request, string $id, GeradorDePdfDaProposta $gerador): Response
    {
        $autor = $this->autor($request);
        $this->politica->garantirPodeLer($autor);
        $proposta = $this->daVisibilidade($autor, $id);

        return response($gerador->gerar($proposta), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="proposta-'.$proposta->numero.'.pdf"',
        ]);
    }

    /** 404 (e não 403) para negócio fora da visibilidade do usuário. */
    private function negocio(Usuario $autor, string $id): Negocio
    {
        return Negocio::query()->visiveisPara($autor)->findOrFail($id);
    }

    private function daVisibilidade(Usuario $autor, string $id): Proposta
    {
        return Proposta::query()
            ->with('itens')
            ->whereHas('negocio', fn ($q) => $q->visiveisPara($autor))
            ->findOrFail($id);
    }

    private function um(Proposta $proposta, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->apresentador->apresentar($proposta->loadMissing('itens'), true)], $status);
    }

    private function autor(Request $request): Usuario
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return $autor;
    }
}
