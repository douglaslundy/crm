<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Customers\Domain\Enums\EstagioCliente;
use App\Modules\Customers\Domain\Enums\TipoCliente;
use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Fiscal\Domain\Enums\CertificadoStatus;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\EmitenteSerie;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;

/** Tenant com plano de NF-e, emitente apto (Simples, SP, certificado válido, série 1), cliente PF e produto completos. */
trait CenarioDeNota
{
    protected Tenant $tenant;

    protected Usuario $admin;

    protected Emitente $emitente;

    protected Cliente $cliente;

    protected Produto $produto;

    protected function montarCenario(Modulo ...$modulos): void
    {
        $this->tenant = Tenant::factory()->for(
            Plano::factory()->comModulos(...($modulos === [] ? [Modulo::FiscalNfe] : $modulos))->comLimites([Recurso::DocumentosMes->value => 100]),
        )->create(['cnpj' => '11444777000161']);
        $this->admin = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Admin]);
        app(TenantContext::class)->set($this->tenant->id);

        $this->emitente = Emitente::factory()->completo()->create([
            'inscricao_estadual' => '110042490114',
            'certificado_status' => CertificadoStatus::Valido,
            'certificado_validade' => now()->addYear()->toDateString(),
        ]);
        EmitenteSerie::factory()->for($this->emitente)->create(['modelo' => ModeloDocumento::Nfe, 'serie' => '1', 'proximo_numero' => 1]);

        $this->cliente = Cliente::factory()->comCpfValido()->create([
            'nome' => 'Maria Teste', 'estagio' => EstagioCliente::Cliente, 'tipo' => TipoCliente::Pf,
            'logradouro' => 'Rua A', 'numero' => '10', 'bairro' => 'Centro', 'cidade' => 'São Paulo',
            'uf' => 'SP', 'cep' => '01001000', 'codigo_ibge' => '3550308',
        ]);
        $this->produto = Produto::factory()->completo()->create(['preco_centavos' => 1000, 'sku' => 'P-1', 'nome' => 'Produto Um']);
    }
}
