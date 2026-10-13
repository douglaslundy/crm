<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Controllers;

use App\Modules\Crm\Application\Actions\AtualizarAtividade;
use App\Modules\Crm\Application\Actions\ConcluirAtividade;
use App\Modules\Crm\Application\Actions\CriarAtividade;
use App\Modules\Crm\Application\Actions\ExcluirAtividade;
use App\Modules\Crm\Application\Actions\ListarAgenda;
use App\Modules\Crm\Application\Actions\ReabrirAtividade;
use App\Modules\Crm\Application\Actions\ResumoDoCrm;
use App\Modules\Crm\Application\ApresentadorDeAtividades;
use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Models\Atividade;
use App\Modules\Crm\Http\Requests\AtividadeRequest;
use App\Modules\Identity\Domain\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class AtividadesController
{
    public function __construct(
        private readonly PoliticaDoCrm $politica,
        private readonly ApresentadorDeAtividades $apresentador,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $autor = $this->autor($request);
        $this->politica->garantirPodeLer($autor);

        $request->validate([
            'cliente_id' => ['nullable', 'uuid'],
            'negocio_id' => ['nullable', 'uuid'],
            'responsavel_id' => ['nullable', 'uuid'],
            'concluida' => ['nullable', 'boolean'],
        ]);

        $consulta = Atividade::query()->visiveisPara($autor);
        foreach (['cliente_id', 'negocio_id', 'responsavel_id'] as $filtro) {
            if ($request->filled($filtro)) {
                $consulta->where($filtro, $request->string($filtro)->toString());
            }
        }
        if ($request->filled('concluida')) {
            $request->boolean('concluida') ? $consulta->whereNotNull('concluida_em') : $consulta->whereNull('concluida_em');
        }

        $lista = $consulta->orderBy('agendada_para')->orderBy('id')->limit(200)->get();

        return response()->json(['data' => $this->apresentador->apresentar($lista)]);
    }

    public function agenda(Request $request, ListarAgenda $listar): JsonResponse
    {
        $request->validate([
            'de' => ['nullable', 'date_format:Y-m-d'],
            'ate' => ['nullable', 'date_format:Y-m-d'],
            'responsavel_id' => ['nullable', 'uuid'],
        ]);

        $fuso = (string) config('app.fuso_negocio');
        $de = $request->filled('de') ? Carbon::createFromFormat('Y-m-d', $request->string('de')->toString(), $fuso) : now($fuso);
        $ate = $request->filled('ate') ? Carbon::createFromFormat('Y-m-d', $request->string('ate')->toString(), $fuso) : now($fuso)->addDays(6);

        $lista = $listar->executar(
            $this->autor($request),
            $de->startOfDay(),
            $ate->endOfDay(),
            $request->filled('responsavel_id') ? $request->string('responsavel_id')->toString() : null,
        );

        return response()->json(['data' => $this->apresentador->apresentar($lista)]);
    }

    public function resumo(Request $request, ResumoDoCrm $resumo): JsonResponse
    {
        return response()->json(['data' => $resumo->executar($this->autor($request))]);
    }

    public function store(AtividadeRequest $request, CriarAtividade $criar): JsonResponse
    {
        /** @var array{tipo: string, titulo: string, agendada_para: string} $dados */
        $dados = $request->validated();

        return $this->uma($criar->executar($this->autor($request), $dados), 201);
    }

    public function update(AtividadeRequest $request, string $id, AtualizarAtividade $atualizar): JsonResponse
    {
        $autor = $this->autor($request);

        return $this->uma($atualizar->executar($autor, $this->doUsuario($autor, $id), $request->validated()));
    }

    public function destroy(Request $request, string $id, ExcluirAtividade $excluir): Response
    {
        $autor = $this->autor($request);
        $excluir->executar($autor, $this->doUsuario($autor, $id));

        return response()->noContent();
    }

    public function concluir(Request $request, string $id, ConcluirAtividade $concluir): JsonResponse
    {
        $autor = $this->autor($request);

        return $this->uma($concluir->executar($autor, $this->doUsuario($autor, $id)));
    }

    public function reabrir(Request $request, string $id, ReabrirAtividade $reabrir): JsonResponse
    {
        $autor = $this->autor($request);

        return $this->uma($reabrir->executar($autor, $this->doUsuario($autor, $id)));
    }

    /** 404 (e não 403) para atividade fora da visibilidade do usuário. */
    private function doUsuario(Usuario $autor, string $id): Atividade
    {
        return Atividade::query()->visiveisPara($autor)->findOrFail($id);
    }

    private function uma(Atividade $atividade, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->apresentador->apresentar([$atividade])[0]], $status);
    }

    private function autor(Request $request): Usuario
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return $autor;
    }
}
