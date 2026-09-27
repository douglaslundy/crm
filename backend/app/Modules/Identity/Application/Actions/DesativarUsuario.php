<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Identity\Application\PoliticaDeUsuarios;
use App\Modules\Identity\Domain\Exceptions\UsuarioJaInativoException;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Support\Facades\DB;

/** O desativado perde o acesso na próxima requisição (GarantirUsuarioAtivo). */
final class DesativarUsuario
{
    public function __construct(private readonly PoliticaDeUsuarios $politica) {}

    public function executar(Usuario $autor, Usuario $alvo): Usuario
    {
        $this->politica->garantirPodeDesativar($autor, $alvo);

        if (! $alvo->ativo) {
            throw new UsuarioJaInativoException;
        }

        return DB::transaction(function () use ($autor, $alvo): Usuario {
            $alvo->forceFill(['ativo' => false])->save();
            activity('usuarios')->performedOn($alvo)->causedBy($autor)->event('usuario_desativado')->log('Usuário desativado');

            return $alvo;
        });
    }
}
