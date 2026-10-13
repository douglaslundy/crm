<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Modules\Crm\Domain\Contracts\ConsultaDeClientes;
use App\Modules\Crm\Domain\ResumoDoCliente;
use App\Modules\Customers\Application\Actions\ConverterEmCliente;
use App\Modules\Customers\Application\Actions\CriarCliente;
use App\Modules\Customers\Domain\Enums\EstagioCliente;
use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Identity\Domain\Models\Usuario;

final class ConsultaDeClientesDoCustomers implements ConsultaDeClientes
{
    public function __construct(
        private readonly CriarCliente $criar,
        private readonly ConverterEmCliente $converter,
    ) {}

    public function resumo(string $id): ?ResumoDoCliente
    {
        return $this->resumos([$id])[$id] ?? null;
    }

    public function resumos(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $mapa = [];
        foreach (Cliente::query()->whereIn('id', $ids)->get() as $cliente) {
            $mapa[$cliente->id] = new ResumoDoCliente(
                $cliente->id,
                $cliente->nome,
                $cliente->telefone,
                $cliente->email,
                $cliente->estagio === EstagioCliente::Lead,
            );
        }

        return $mapa;
    }

    public function criarLead(Usuario $autor, string $nome, ?string $telefone, ?string $email): string
    {
        return $this->criar->executar($autor, [
            'tipo' => 'PF',
            'nome' => $nome,
            'telefone' => $telefone,
            'email' => $email === null ? null : mb_strtolower($email),
            'estagio' => 'LEAD',
            'tags' => [],
        ])->id;
    }

    public function converterEmCliente(string $id): void
    {
        $this->converter->executar(Cliente::query()->findOrFail($id));
    }
}
