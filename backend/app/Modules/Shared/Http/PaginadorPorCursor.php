<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http;

use App\Modules\Shared\Domain\Exceptions\CursorInvalidoException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Paginação por cursor do /api/v1, estável por (updated_at, id): inserções e alterações durante a leitura
 * não fazem o integrador pular itens (um registro alterado durante a leitura reaparece no fim). O cursor é opaco para quem consome.
 */
final class PaginadorPorCursor
{
    public const LIMITE_PADRAO = 50;

    public const LIMITE_MAXIMO = 100;

    private const FORMATO_DO_BANCO = 'Y-m-d H:i:s';

    private const ISO_8601 = '/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?)?$/';

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  callable(TModel): array<string, mixed>  $mapa
     * @param  list<string>  $colunasDeBusca  colunas fixas do código (nunca vindas da requisição) onde `?q=` procura
     * @return array{data: list<array<string, mixed>>, links: array{proxima: ?string}, meta: array{limite: int}}
     */
    public function paginar(Builder $query, Request $request, callable $mapa, array $colunasDeBusca = []): array
    {
        $dados = Validator::make($request->query(), [
            'limite' => ['sometimes', 'integer', 'between:1,'.self::LIMITE_MAXIMO],
            'cursor' => ['sometimes', 'string', 'max:300'],
            'q' => ['sometimes', 'string', 'max:100'],
            'atualizado_desde' => ['sometimes', 'string', 'regex:'.self::ISO_8601, function (string $atributo, mixed $valor, \Closure $falhar): void {
                if ($this->lerData((string) $valor) === null) {
                    $falhar('Use uma data ISO-8601 existente, por exemplo 2026-10-08T12:00:00Z.');
                }
            }],
        ], [
            'limite.between' => 'O limite deve estar entre 1 e '.self::LIMITE_MAXIMO.'.',
            'atualizado_desde.regex' => 'Use uma data ISO-8601, por exemplo 2026-10-08T12:00:00Z.',
        ])->validate();

        $limite = (int) ($dados['limite'] ?? self::LIMITE_PADRAO);
        $tabela = $query->getModel()->getTable();

        $query->orderBy("{$tabela}.updated_at")->orderBy("{$tabela}.id");

        if (isset($dados['atualizado_desde'])) {
            $query->where("{$tabela}.updated_at", '>=', $this->lerData($dados['atualizado_desde'])?->utc()->format(self::FORMATO_DO_BANCO));
        }

        if (isset($dados['q']) && $dados['q'] !== '' && $colunasDeBusca !== []) {
            $termo = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($dados['q'])).'%';
            $query->where(function (Builder $busca) use ($colunasDeBusca, $tabela, $termo): void {
                foreach ($colunasDeBusca as $coluna) {
                    $busca->orWhereRaw("LOWER({$tabela}.{$coluna}) LIKE ? ESCAPE '\\'", [$termo]);
                }
            });
        }

        if (isset($dados['cursor'])) {
            [$momento, $id] = $this->decodificar($dados['cursor']);
            $query->where(function (Builder $depois) use ($tabela, $momento, $id): void {
                $depois->where("{$tabela}.updated_at", '>', $momento)
                    ->orWhere(fn (Builder $empate) => $empate->where("{$tabela}.updated_at", $momento)->where("{$tabela}.id", '>', $id));
            });
        }

        $itens = $query->limit($limite + 1)->get();
        $pagina = $itens->take($limite);
        $ultimo = $pagina->last();
        $atualizadoEm = $ultimo?->getAttribute('updated_at');
        $proxima = $itens->count() > $limite && $ultimo !== null && $atualizadoEm instanceof CarbonInterface
            ? $request->fullUrlWithQuery(['cursor' => $this->codificar($atualizadoEm->format(self::FORMATO_DO_BANCO), (string) $ultimo->getKey())])
            : null;

        return [
            'data' => $pagina->map(fn (Model $item): array => $mapa($item))->values()->all(),
            'links' => ['proxima' => $proxima],
            'meta' => ['limite' => $limite],
        ];
    }

    /** Data ISO-8601 que realmente existe (2026-02-30 e 2026-13-45 não passam). */
    private function lerData(string $texto): ?CarbonImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $texto, $p) !== 1 || ! checkdate((int) $p[2], (int) $p[3], (int) $p[1])) {
            return null;
        }

        try {
            return CarbonImmutable::parse($texto);
        } catch (\Throwable) {
            return null;
        }
    }

    private function codificar(string $momento, string $id): string
    {
        return base64_encode((string) json_encode(['u' => $momento, 'i' => $id]));
    }

    /** @return array{0: string, 1: string} */
    private function decodificar(string $cursor): array
    {
        $json = base64_decode($cursor, true);
        $dados = $json === false ? null : json_decode($json, true);

        if (! is_array($dados) || ! is_string($dados['u'] ?? null) || ! is_string($dados['i'] ?? null)
            || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $dados['u']) !== 1
            || \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dados['u']) === false
            || \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dados['u'])->format('Y-m-d H:i:s') !== $dados['u']
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $dados['i']) !== 1) {
            throw new CursorInvalidoException;
        }

        return [$dados['u'], $dados['i']];
    }
}
