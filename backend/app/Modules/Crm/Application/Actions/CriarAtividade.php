<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Application\ValidadorDeAtividade;
use App\Modules\Crm\Domain\Enums\TipoDeAtividade;
use App\Modules\Crm\Domain\Models\Atividade;
use App\Modules\Identity\Domain\Models\Usuario;

final class CriarAtividade
{
    public function __construct(
        private readonly PoliticaDoCrm $politica,
        private readonly ValidadorDeAtividade $validador,
    ) {}

    /**
     * @param  array{tipo: string, titulo: string, descricao?: ?string, agendada_para: string, cliente_id?: ?string, negocio_id?: ?string, responsavel_id?: ?string}  $dados
     */
    public function executar(Usuario $autor, array $dados): Atividade
    {
        $this->politica->garantirPodeOperarNegocios($autor);

        [$clienteId, $negocioId] = $this->validador->alvo($autor, $dados['cliente_id'] ?? null, $dados['negocio_id'] ?? null);
        $descricao = isset($dados['descricao']) ? trim($dados['descricao']) : null;

        return Atividade::create([
            'cliente_id' => $clienteId,
            'negocio_id' => $negocioId,
            'tipo' => TipoDeAtividade::from($dados['tipo']),
            'titulo' => trim($dados['titulo']),
            'descricao' => $descricao === '' ? null : $descricao,
            'agendada_para' => $this->validador->instante($dados['agendada_para']),
            'responsavel_id' => $this->validador->responsavel($autor, $dados['responsavel_id'] ?? null),
            'criado_por' => $autor->id,
        ]);
    }
}
