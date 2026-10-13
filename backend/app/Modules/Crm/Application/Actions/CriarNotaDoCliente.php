<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Application\RegistradorDaTimeline;
use App\Modules\Crm\Domain\Contracts\ConsultaDeClientes;
use App\Modules\Crm\Domain\Enums\TipoDeRegistro;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\RegistroDaTimeline;
use App\Modules\Identity\Domain\Models\Usuario;

final class CriarNotaDoCliente
{
    public const LIMITE = 2000;

    public function __construct(
        private readonly PoliticaDoCrm $politica,
        private readonly ConsultaDeClientes $clientes,
        private readonly RegistradorDaTimeline $timeline,
    ) {}

    public function executar(Usuario $autor, string $clienteId, string $texto): RegistroDaTimeline
    {
        $this->politica->garantirPodeOperarNegocios($autor);

        $texto = trim($texto);
        if ($texto === '' || mb_strlen($texto) > self::LIMITE) {
            throw new RegraDoCrmException('A nota precisa ter de 1 a '.self::LIMITE.' caracteres.', 'NOTA_INVALIDA');
        }

        if ($this->clientes->resumo($clienteId) === null) {
            throw new RegraDoCrmException('Cliente não encontrado.', 'CLIENTE_INVALIDO');
        }

        return $this->timeline->registrar(TipoDeRegistro::Nota, $clienteId, null, $autor->id, $texto);
    }
}
