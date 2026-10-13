<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Application\ValidadorDeAtividade;
use App\Modules\Crm\Domain\Enums\TipoDeAtividade;
use App\Modules\Crm\Domain\Models\Atividade;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Support\Facades\DB;

final class AtualizarAtividade
{
    public function __construct(
        private readonly PoliticaDoCrm $politica,
        private readonly ValidadorDeAtividade $validador,
    ) {}

    /** @param array{tipo?: string, titulo?: string, descricao?: ?string, agendada_para?: string, responsavel_id?: ?string} $dados */
    public function executar(Usuario $autor, Atividade $atividade, array $dados): Atividade
    {
        $this->politica->garantirPodeOperarNegocios($autor);

        return DB::transaction(function () use ($autor, $atividade, $dados): Atividade {
            $atividade = Atividade::query()->lockForUpdate()->findOrFail($atividade->id);
            $mudancas = [];

            if (isset($dados['tipo'])) {
                $mudancas['tipo'] = TipoDeAtividade::from($dados['tipo']);
            }
            if (isset($dados['titulo'])) {
                $mudancas['titulo'] = trim($dados['titulo']);
            }
            if (array_key_exists('descricao', $dados)) {
                $descricao = $dados['descricao'] === null ? null : trim($dados['descricao']);
                $mudancas['descricao'] = $descricao === '' ? null : $descricao;
            }
            if (isset($dados['agendada_para'])) {
                $mudancas['agendada_para'] = $this->validador->instante($dados['agendada_para']);
            }
            if (isset($dados['responsavel_id'])) {
                $mudancas['responsavel_id'] = $this->validador->responsavel($autor, $dados['responsavel_id']);
            }

            $atividade->update($mudancas);

            return $atividade->refresh();
        });
    }
}
