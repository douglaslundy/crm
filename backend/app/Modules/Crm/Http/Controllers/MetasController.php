<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Controllers;

use App\Modules\Crm\Application\Actions\DefinirMeta;
use App\Modules\Crm\Application\Actions\ListarMetas;
use App\Modules\Crm\Application\Actions\RemoverMeta;
use App\Modules\Crm\Domain\Contracts\DiretorioDeUsuarios;
use App\Modules\Crm\Domain\Enums\UnidadeDaMeta;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\MetaDoVendedor;
use App\Modules\Crm\Http\Requests\MetaRequest;
use App\Modules\Identity\Domain\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class MetasController
{
    public function __construct(private readonly DiretorioDeUsuarios $usuarios) {}

    public function index(Request $request, ListarMetas $listar): JsonResponse
    {
        /** @var Usuario $autor */
        $autor = $request->user();
        $mes = $this->mes((string) $request->query('mes', now((string) config('app.fuso_negocio'))->format('Y-m')));

        return response()->json(['data' => $this->apresentar($listar->executar($autor, $mes)->all())]);
    }

    public function update(MetaRequest $request, DefinirMeta $definir): JsonResponse
    {
        /** @var Usuario $autor */
        $autor = $request->user();
        /** @var array{usuario_id: string, mes: string, unidade: string, alvo: int} $dados */
        $dados = $request->validated();

        $meta = $definir->executar(
            $autor,
            $dados['usuario_id'],
            $this->mes($dados['mes']),
            UnidadeDaMeta::from($dados['unidade']),
            (int) $dados['alvo'],
        );

        return response()->json(['data' => $this->apresentar([$meta])[0]]);
    }

    public function destroy(Request $request, string $id, RemoverMeta $remover): Response
    {
        /** @var Usuario $autor */
        $autor = $request->user();
        $remover->executar($autor, MetaDoVendedor::query()->findOrFail($id));

        return response()->noContent();
    }

    private function mes(string $texto): Carbon
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $texto) !== 1) {
            throw RegraDoCrmException::periodoInvalido('Informe o mês no formato AAAA-MM.');
        }

        return Carbon::parse($texto.'-01')->startOfDay();
    }

    /**
     * @param  list<MetaDoVendedor>  $metas
     * @return list<array<string, mixed>>
     */
    private function apresentar(array $metas): array
    {
        $nomes = $this->usuarios->nomes(array_map(fn (MetaDoVendedor $m): string => $m->usuario_id, $metas));

        return array_map(fn (MetaDoVendedor $m): array => [
            'id' => $m->id,
            'usuario_id' => $m->usuario_id,
            'usuario_nome' => $nomes[$m->usuario_id] ?? null,
            'mes' => $m->mes->format('Y-m'),
            'unidade' => $m->unidade->value,
            'alvo' => $m->alvo,
        ], $metas);
    }
}
