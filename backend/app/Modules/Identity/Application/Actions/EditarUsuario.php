<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Identity\Application\PoliticaDeUsuarios;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Support\Facades\DB;

final class EditarUsuario
{
    public function __construct(private readonly PoliticaDeUsuarios $politica) {}

    /** @param array{nome: string, papel: string} $dados */
    public function executar(Usuario $autor, Usuario $alvo, array $dados): Usuario
    {
        $papel = Papel::from($dados['papel']);
        $this->politica->garantirPodeAlterar($autor, $alvo);
        $this->politica->garantirPodeAtribuir($autor, $papel);

        return DB::transaction(function () use ($autor, $alvo, $papel, $dados): Usuario {
            $papelAnterior = $alvo->papel;
            $alvo->forceFill(['nome' => $dados['nome'], 'papel' => $papel])->save();

            activity('usuarios')->performedOn($alvo)->causedBy($autor)->event('usuario_editado')
                ->withProperties(['papel_de' => $papelAnterior->value, 'papel_para' => $papel->value])
                ->log('Usuário editado');

            return $alvo;
        });
    }
}
