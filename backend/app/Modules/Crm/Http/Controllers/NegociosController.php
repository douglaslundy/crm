<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Controllers;

use App\Modules\Crm\Application\Actions\AtualizarNegocio;
use App\Modules\Crm\Application\Actions\CriarNegocio;
use App\Modules\Crm\Application\Actions\DefinirItensDoNegocio;
use App\Modules\Crm\Application\Actions\GanharNegocio;
use App\Modules\Crm\Application\Actions\MoverNegocio;
use App\Modules\Crm\Application\Actions\PerderNegocio;
use App\Modules\Crm\Application\ApresentadorDeNegocios;
use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Enums\StatusDoNegocio;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Crm\Http\Requests\AtualizarNegocioRequest;
use App\Modules\Crm\Http\Requests\CriarNegocioRequest;
use App\Modules\Crm\Http\Requests\ItensDoNegocioRequest;
use App\Modules\Crm\Http\Requests\MoverNegocioRequest;
use App\Modules\Crm\Http\Requests\PerderNegocioRequest;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class NegociosController
{
    public function __construct(
        private readonly PoliticaDoCrm $politica,
        private readonly ApresentadorDeNegocios $apresentador,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $autor = $this->autor($request);
        $this->politica->garantirPodeLer($autor);

        $request->validate([
            'status' => ['nullable', Rule::enum(StatusDoNegocio::class)],
            'funil_id' => ['nullable', 'uuid'],
            'responsavel_id' => ['nullable', 'uuid'],
            'cliente_id' => ['nullable', 'uuid'],
        ]);

        $status = StatusDoNegocio::tryFrom($request->string('status', 'ABERTO')->toString()) ?? StatusDoNegocio::Aberto;
        $consulta = Negocio::query()->visiveisPara($autor)->with('itens')->where('status', $status);

        foreach (['funil_id', 'responsavel_id', 'cliente_id'] as $filtro) {
            if ($request->filled($filtro)) {
                $consulta->where($filtro, $request->string($filtro)->toString());
            }
        }

        $consulta = $status === StatusDoNegocio::Aberto
            ? $consulta->orderBy('posicao_na_etapa')->orderBy('id')
            : $consulta->orderByDesc('fechado_em')->orderBy('id');

        return response()->json(['data' => $this->apresentador->apresentar($consulta->get())]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $autor = $this->autor($request);
        $this->politica->garantirPodeLer($autor);

        return $this->um($this->doUsuario($autor, $id));
    }

    public function store(CriarNegocioRequest $request, CriarNegocio $criar): JsonResponse
    {
        /** @var array<string, mixed> $dados */
        $dados = $request->validated();

        return $this->um($criar->executar($this->autor($request), $dados), 201);
    }

    public function update(AtualizarNegocioRequest $request, string $id, AtualizarNegocio $atualizar): JsonResponse
    {
        $autor = $this->autor($request);

        return $this->um($atualizar->executar($autor, $this->doUsuario($autor, $id), $request->validated()));
    }

    public function itens(ItensDoNegocioRequest $request, string $id, DefinirItensDoNegocio $definir): JsonResponse
    {
        $autor = $this->autor($request);
        /** @var list<array{tipo: string, item_id: string, quantidade: int}> $itens */
        $itens = $request->validated('itens');

        return $this->um($definir->executar($autor, $this->doUsuario($autor, $id), $itens));
    }

    public function mover(MoverNegocioRequest $request, string $id, MoverNegocio $mover): JsonResponse
    {
        $autor = $this->autor($request);

        return $this->um($mover->executar(
            $autor,
            $this->doUsuario($autor, $id),
            $request->string('etapa_id')->toString(),
            $request->integer('posicao'),
        ));
    }

    public function ganhar(Request $request, string $id, GanharNegocio $ganhar): JsonResponse
    {
        $autor = $this->autor($request);

        return $this->um($ganhar->executar($autor, $this->doUsuario($autor, $id)));
    }

    public function perder(PerderNegocioRequest $request, string $id, PerderNegocio $perder): JsonResponse
    {
        $autor = $this->autor($request);

        return $this->um($perder->executar(
            $autor,
            $this->doUsuario($autor, $id),
            $request->string('motivo_de_perda_id')->toString(),
            $request->filled('observacao') ? $request->string('observacao')->toString() : null,
        ));
    }

    /** 404 (e não 403) para negócio fora da visibilidade do usuário. */
    private function doUsuario(Usuario $autor, string $id): Negocio
    {
        return Negocio::query()->visiveisPara($autor)->with('itens')->findOrFail($id);
    }

    private function um(Negocio $negocio, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->apresentador->apresentar([$negocio])[0]], $status);
    }

    private function autor(Request $request): Usuario
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return $autor;
    }
}
