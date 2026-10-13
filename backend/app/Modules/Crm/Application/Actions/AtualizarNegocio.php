<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Contracts\DiretorioDeUsuarios;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;
use Illuminate\Support\Facades\DB;

final class AtualizarNegocio
{
    public function __construct(
        private readonly PoliticaDoCrm $politica,
        private readonly DiretorioDeUsuarios $usuarios,
    ) {}

    /** @param array{titulo?: string, previsao_fechamento?: ?string, responsavel_id?: ?string, valor_centavos?: ?int} $dados */
    public function executar(Usuario $autor, Negocio $negocio, array $dados): Negocio
    {
        $this->politica->garantirPodeOperarNegocios($autor);

        return DB::transaction(function () use ($autor, $negocio, $dados): Negocio {
            $negocio->garantirAberto();
            $alteracoes = [];

            if (array_key_exists('titulo', $dados)) {
                $alteracoes['titulo'] = trim($dados['titulo']);
            }
            if (array_key_exists('previsao_fechamento', $dados)) {
                $alteracoes['previsao_fechamento'] = $dados['previsao_fechamento'];
            }
            if (($dados['responsavel_id'] ?? null) !== null && $dados['responsavel_id'] !== $negocio->responsavel_id) {
                if (! $this->politica->podeReatribuir($autor)) {
                    throw new AcessoNegadoException('Só proprietário e administrador atribuem outro responsável.');
                }
                if (! $this->usuarios->ehDaEmpresa($dados['responsavel_id'])) {
                    throw new RegraDoCrmException('Responsável não encontrado.', 'RESPONSAVEL_INVALIDO');
                }
                $alteracoes['responsavel_id'] = $dados['responsavel_id'];
            }
            if (($dados['valor_centavos'] ?? null) !== null) {
                if ($negocio->itens()->exists()) {
                    throw new RegraDoCrmException('O valor é a soma dos itens. Altere os itens.', 'VALOR_DERIVADO_DOS_ITENS');
                }
                $alteracoes['valor_centavos'] = $dados['valor_centavos'];
            }

            $negocio->update($alteracoes);

            return $negocio->refresh()->load('itens');
        });
    }
}
