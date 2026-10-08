<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Modules\Identity\Domain\Enums\AmbienteDaChave;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\GeradorDeChaveDeApi;
use App\Modules\Identity\Domain\Models\ChaveDeApi;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;

/** Tenant com plano que tem o módulo API (requisições ilimitadas), um administrador e uma chave `live_` válida. */
trait CenarioDeApi
{
    protected Tenant $tenant;

    protected Usuario $admin;

    protected ChaveDeApi $chave;

    /** Texto inteiro da chave criada em montarApi(). */
    protected string $textoDaChave;

    /** @param array<string, int> $limites sobrescreve os limites do plano (chave = valor de Recurso) */
    protected function montarApi(array $limites = [], Modulo ...$modulos): void
    {
        $this->tenant = Tenant::factory()->for(
            Plano::factory()->comModulos(Modulo::Api, ...$modulos)->comLimites($limites + [Recurso::ApiRequisicoesMin->value => -1, Recurso::Produtos->value => 100, Recurso::Clientes->value => 100]),
        )->create();
        app(TenantContext::class)->set($this->tenant->id);
        $this->admin = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Admin]);
        [$this->chave, $this->textoDaChave] = $this->novaChave($this->admin);
    }

    /** @return array{0: ChaveDeApi, 1: string} */
    protected function novaChave(Usuario $autor, AmbienteDaChave $ambiente = AmbienteDaChave::Live): array
    {
        $gerada = (new GeradorDeChaveDeApi)->gerar($ambiente);
        $anterior = app(TenantContext::class)->id();
        app(TenantContext::class)->set($autor->tenant_id);
        $chave = ChaveDeApi::factory()->create([
            'usuario_id' => $autor->id, 'ambiente' => $ambiente,
            'hash' => $gerada->hash, 'prefixo' => $gerada->prefixo, 'ultimos4' => $gerada->ultimos4,
        ]);
        app(TenantContext::class)->set($anterior);

        return [$chave, $gerada->texto];
    }

    /** Requisição autenticada por chave de API (por padrão a do cenário). */
    protected function comoApi(?string $texto = null): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        return $this->withHeaders(['Authorization' => 'Bearer '.($texto ?? $this->textoDaChave), 'Accept' => 'application/json']);
    }

    /** Roda $criar com o contexto de outro tenant (para semear dados alheios) e volta ao tenant do cenário. */
    protected function emOutroTenant(callable $criar): mixed
    {
        $outro = Tenant::factory()->for(Plano::factory()->comModulos(Modulo::Api))->create();
        app(TenantContext::class)->set($outro->id);
        try {
            return $criar($outro);
        } finally {
            app(TenantContext::class)->set($this->tenant->id);
        }
    }
}
