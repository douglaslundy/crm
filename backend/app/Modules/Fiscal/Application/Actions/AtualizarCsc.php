<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Domain\Models\Emitente;
use Illuminate\Support\Facades\Crypt;

final class AtualizarCsc
{
    /** @param array{csc_id_homologacao:?string,csc_token_homologacao:?string,csc_id_producao:?string,csc_token_producao:?string} $dados */
    public function executar(Emitente $emitente, array $dados): Emitente
    {
        $emitente->update([
            'csc_id_homologacao' => $dados['csc_id_homologacao'],
            'csc_token_homologacao_encrypted' => $dados['csc_token_homologacao'] !== null
                ? Crypt::encryptString($dados['csc_token_homologacao']) : null,
            'csc_id_producao' => $dados['csc_id_producao'],
            'csc_token_producao_encrypted' => $dados['csc_token_producao'] !== null
                ? Crypt::encryptString($dados['csc_token_producao']) : null,
        ]);

        return $emitente;
    }
}
