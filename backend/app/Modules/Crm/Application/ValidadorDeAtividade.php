<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Crm\Domain\Contracts\ConsultaDeClientes;
use App\Modules\Crm\Domain\Contracts\DiretorioDeUsuarios;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/** Regras comuns de criar e atualizar atividade: alvo (cliente e negócio), responsável e instante. */
final class ValidadorDeAtividade
{
    public function __construct(
        private readonly PoliticaDoCrm $politica,
        private readonly ConsultaDeClientes $clientes,
        private readonly DiretorioDeUsuarios $usuarios,
    ) {}

    /** @return array{0: string, 1: ?string} cliente_id e negocio_id */
    public function alvo(Usuario $autor, ?string $clienteId, ?string $negocioId): array
    {
        if ($negocioId !== null) {
            $negocio = Negocio::query()->visiveisPara($autor)->find($negocioId)
                ?? throw new RegraDoCrmException('Negócio não encontrado.', 'NEGOCIO_INVALIDO');

            if ($clienteId !== null && $clienteId !== $negocio->cliente_id) {
                throw new RegraDoCrmException('O negócio não pertence a este cliente.', 'NEGOCIO_INVALIDO');
            }

            return [$negocio->cliente_id, $negocio->id];
        }

        if ($clienteId === null) {
            throw new RegraDoCrmException('Informe o cliente ou o negócio.', 'CLIENTE_INVALIDO');
        }

        if ($this->clientes->resumo($clienteId) === null) {
            throw new RegraDoCrmException('Cliente não encontrado.', 'CLIENTE_INVALIDO');
        }

        return [$clienteId, null];
    }

    public function responsavel(Usuario $autor, ?string $responsavelId): string
    {
        if ($responsavelId === null || $responsavelId === $autor->id) {
            return $autor->id;
        }

        if (! $this->politica->podeReatribuir($autor)) {
            throw new AcessoNegadoException('Só proprietário e administrador atribuem atividades a outros usuários.');
        }

        if (! $this->usuarios->ehDaEmpresa($responsavelId)) {
            throw new RegraDoCrmException('Responsável não encontrado nesta empresa.', 'RESPONSAVEL_INVALIDO');
        }

        return $responsavelId;
    }

    public function instante(string $iso): CarbonInterface
    {
        return Carbon::parse($iso)->utc();
    }
}
