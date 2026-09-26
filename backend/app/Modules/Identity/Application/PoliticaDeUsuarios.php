<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;

/** Quem pode gerenciar quem, dentro da empresa (spec F1 §9). */
final class PoliticaDeUsuarios
{
    public function garantirPodeGerenciar(Usuario $autor): void
    {
        if (! in_array($autor->papel, [Papel::Proprietario, Papel::Admin], true)) {
            throw new AcessoNegadoException('Somente o proprietário ou um administrador podem gerenciar usuários.');
        }
    }

    public function garantirPodeAtribuir(Usuario $autor, Papel $papel): void
    {
        $this->garantirPodeGerenciar($autor);

        if ($papel === Papel::Proprietario && $autor->papel !== Papel::Proprietario) {
            throw new AcessoNegadoException('Somente o proprietário pode conceder o papel de proprietário.');
        }
    }

    public function garantirPodeAlterar(Usuario $autor, Usuario $alvo): void
    {
        $this->garantirPodeGerenciar($autor);

        if ($alvo->papel === Papel::Proprietario) {
            throw new AcessoNegadoException('O proprietário da conta não pode ser alterado.');
        }
    }

    public function garantirPodeDesativar(Usuario $autor, Usuario $alvo): void
    {
        if ($autor->is($alvo)) {
            throw new AcessoNegadoException('Você não pode desativar a si mesmo.');
        }

        $this->garantirPodeAlterar($autor, $alvo);
    }
}
