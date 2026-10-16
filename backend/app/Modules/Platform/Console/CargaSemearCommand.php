<?php

declare(strict_types=1);

namespace App\Modules\Platform\Console;

use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Crm\Application\Actions\CriarNegocio;
use App\Modules\Crm\Application\PreparadorDoCrm;
use App\Modules\Crm\Domain\Enums\TipoDeItem;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Customers\Domain\Enums\EstagioCliente;
use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Fiscal\Domain\Enums\CertificadoStatus;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\EmitenteSerie;
use App\Modules\Identity\Domain\Enums\AmbienteDaChave;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\GeradorDeChaveDeApi;
use App\Modules\Identity\Domain\Models\ChaveDeApi;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Shared\Domain\AmbienteDeCarga;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Console\Command;
use RuntimeException;

/** Só staging. Cria empresas fictícias (`CARGA-n`) com tudo que os cenários do k6 usam e grava as contas em JSON. */
final class CargaSemearCommand extends Command
{
    public const SENHA = 'Senha123';

    public const NOME_DO_PLANO = 'CARGA (não usar)';

    protected $signature = 'carga:semear {--empresas=50} {--saida=}';

    protected $description = 'Semeia empresas fictícias para o teste de carga (só com APP_AMBIENTE=staging).';

    public function handle(TenantContext $contexto, PreparadorDoCrm $preparador, CriarNegocio $criarNegocio): int
    {
        try {
            AmbienteDeCarga::exigir('carga:semear');
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if (Tenant::query()->where('razao_social', 'like', 'CARGA-%')->exists()) {
            $this->error('Já existem empresas de carga. Rode carga:limpar antes.');

            return self::FAILURE;
        }

        $plano = Plano::factory()
            ->comModulos(Modulo::Crm, Modulo::Api, Modulo::FiscalNfe)
            ->comLimites([
                Recurso::ApiRequisicoesMin->value => 300, Recurso::Clientes->value => Plano::ILIMITADO,
                Recurso::Produtos->value => 1000, Recurso::DocumentosMes->value => 1000000,
            ])
            ->create(['nome' => self::NOME_DO_PLANO, 'visivel' => false]);

        $empresas = [];
        $total = (int) $this->option('empresas');
        for ($i = 1; $i <= $total; $i++) {
            $empresas[] = $this->semearEmpresa($i, $plano, $contexto, $preparador, $criarNegocio);
        }
        $contexto->clear();

        $saida = (string) ($this->option('saida') ?: storage_path('app/carga/contas.json'));
        if (! is_dir(dirname($saida))) {
            mkdir(dirname($saida), 0770, true);
        }
        file_put_contents($saida, json_encode(['senha' => self::SENHA, 'empresas' => $empresas], JSON_THROW_ON_ERROR));
        $this->info("{$total} empresas semeadas. Contas em {$saida} (contém chaves de API: não vai para o git).");

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function semearEmpresa(int $i, Plano $plano, TenantContext $contexto, PreparadorDoCrm $preparador, CriarNegocio $criarNegocio): array
    {
        $tenant = Tenant::factory()->create(['plano_id' => $plano->id, 'razao_social' => "CARGA-{$i}", 'cnpj' => self::cnpj($i)]);
        $contexto->set($tenant->id);

        $admin = Usuario::factory()->for($tenant)->create(['papel' => Papel::Admin, 'email' => "carga{$i}.u1@carga.local"]);
        $vendedor = Usuario::factory()->for($tenant)->create(['papel' => Papel::Vendedor, 'email' => "carga{$i}.u2@carga.local"]);

        $emitente = Emitente::factory()->completo()->create([
            'inscricao_estadual' => '110042490114', 'certificado_status' => CertificadoStatus::Valido,
            'certificado_validade' => now()->addYear()->toDateString(),
        ]);
        EmitenteSerie::factory()->for($emitente)->create(['modelo' => ModeloDocumento::Nfe, 'serie' => '1']);

        $clientes = Cliente::factory()->comCpfValido()->count(10)->create([
            'estagio' => EstagioCliente::Cliente, 'logradouro' => 'Rua A', 'numero' => '10', 'bairro' => 'Centro',
            'cidade' => 'São Paulo', 'uf' => 'SP', 'cep' => '01001000', 'codigo_ibge' => '3550308',
        ]);
        $produto = Produto::factory()->completo()->create(['preco_centavos' => 1000, 'sku' => 'CARGA-1', 'nome' => 'Produto de carga']);

        $preparador->garantir($admin);
        $funil = Funil::query()->where('padrao', true)->with('etapas')->firstOrFail();
        $etapaIds = $funil->etapas->sortBy('posicao')->pluck('id')->values()->all();

        $negocioIds = [];
        for ($n = 1; $n <= 20; $n++) {
            $negocio = $criarNegocio->executar($admin, [
                'titulo' => "Negócio de carga {$n}", 'funil_id' => $funil->id, 'etapa_id' => $etapaIds[$n % count($etapaIds)],
                'cliente_id' => $clientes[$n % 10]->id,
                'itens' => [['tipo' => TipoDeItem::Produto->value, 'item_id' => $produto->id, 'quantidade' => 1]],
            ]);
            $negocioIds[] = $negocio->id;
        }

        $gerada = (new GeradorDeChaveDeApi)->gerar(AmbienteDaChave::Test);
        ChaveDeApi::factory()->create(['usuario_id' => $admin->id, 'ambiente' => AmbienteDaChave::Test, 'hash' => $gerada->hash, 'prefixo' => $gerada->prefixo, 'ultimos4' => $gerada->ultimos4]);

        return [
            'indice' => $i, 'tenant_id' => $tenant->id,
            'usuarios' => [['email' => $admin->email, 'papel' => 'ADMIN'], ['email' => $vendedor->email, 'papel' => 'VENDEDOR']],
            'chave_api' => $gerada->texto, 'funil_id' => $funil->id, 'etapa_ids' => $etapaIds,
            'cliente_ids' => $clientes->pluck('id')->all(), 'produto_id' => $produto->id, 'negocio_ids' => $negocioIds,
        ];
    }

    /** CNPJ com dígitos verificadores válidos: base de 8 dígitos a partir do índice, filial 0001. */
    private static function cnpj(int $i): string
    {
        $base = str_pad((string) (10000000 + $i), 8, '0', STR_PAD_LEFT).'0001';
        foreach ([[5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]] as $pesos) {
            $soma = 0;
            foreach ($pesos as $k => $peso) {
                $soma += (int) $base[$k] * $peso;
            }
            $resto = $soma % 11;
            $base .= (string) ($resto < 2 ? 0 : 11 - $resto);
        }

        return $base;
    }
}
