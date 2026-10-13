<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;

/** Quem faz o quê no CRM (spec F7a §5). Fiscal não tem acesso. */
final class PoliticaDoCrm
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    public function garantirPodeLer(Usuario $autor): void
    {
        $this->garantir($autor, [Papel::Proprietario, Papel::Admin, Papel::Vendedor, Papel::Leitura], 'Você não tem acesso ao CRM.');
    }

    public function garantirPodeOperarNegocios(Usuario $autor): void
    {
        $this->garantir($autor, [Papel::Proprietario, Papel::Admin, Papel::Vendedor], 'Você não tem permissão para alterar negócios.');
    }

    public function garantirPodeConfigurar(Usuario $autor): void
    {
        $this->garantir($autor, [Papel::Proprietario, Papel::Admin], 'Só proprietário e administrador configuram o CRM.');
    }

    public function podeReatribuir(Usuario $autor): bool
    {
        return in_array($autor->papel, [Papel::Proprietario, Papel::Admin], true);
    }

    /** @param list<Papel> $papeis */
    private function garantir(Usuario $autor, array $papeis, string $mensagem): void
    {
        if (! in_array($autor->papel, $papeis, true) || $autor->tenant === null) {
            throw new AcessoNegadoException($mensagem);
        }

        $this->entitlements->garantirModulo($autor->tenant, Modulo::Crm);
    }
}
