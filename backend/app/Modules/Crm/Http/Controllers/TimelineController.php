<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Controllers;

use App\Modules\Crm\Application\Actions\CriarNotaDoCliente;
use App\Modules\Crm\Application\Actions\ListarTimelineDoCliente;
use App\Modules\Crm\Domain\Contracts\DiretorioDeUsuarios;
use App\Modules\Crm\Domain\Models\RegistroDaTimeline;
use App\Modules\Crm\Http\Requests\NotaRequest;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TimelineController
{
    public function __construct(private readonly DiretorioDeUsuarios $usuarios) {}

    public function index(Request $request, string $id, ListarTimelineDoCliente $listar): JsonResponse
    {
        $pagina = $listar->executar($this->autor($request), $id);

        return response()->json([
            'data' => $this->apresentar($pagina->items()),
            'next_cursor' => $pagina->nextCursor()?->encode(),
        ]);
    }

    public function nota(NotaRequest $request, string $id, CriarNotaDoCliente $criar): JsonResponse
    {
        $registro = $criar->executar($this->autor($request), $id, $request->string('texto')->toString());

        return response()->json(['data' => $this->apresentar([$registro])[0]], 201);
    }

    /**
     * @param  iterable<RegistroDaTimeline>  $registros
     * @return list<array<string, mixed>>
     */
    private function apresentar(iterable $registros): array
    {
        $lista = [...$registros];
        $nomes = $this->usuarios->nomes(array_values(array_unique(array_filter(
            array_map(fn (RegistroDaTimeline $r): ?string => $r->autor_id, $lista),
        ))));

        return array_map(fn (RegistroDaTimeline $r): array => [
            'id' => $r->id,
            'tipo' => $r->tipo->value,
            'texto' => $r->texto,
            'dados' => $r->dados,
            'autor' => $r->autor_id !== null && isset($nomes[$r->autor_id]) ? ['id' => $r->autor_id, 'nome' => $nomes[$r->autor_id]] : null,
            'negocio_id' => $r->negocio_id,
            'atividade_id' => $r->atividade_id,
            'ocorreu_em' => $r->ocorreu_em->toIso8601String(),
        ], $lista);
    }

    private function autor(Request $request): Usuario
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return $autor;
    }
}
