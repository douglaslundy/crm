<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Modules\Catalog\Domain\Enums\FonteFiscal;
use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Catalog\Domain\ValidadorCamposFiscais;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Support\Facades\DB;

final class CriarProduto
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    /** @param array{sku:string,nome:string,unidade:string,preco_centavos:int,gtin:?string,ncm:?string,cest:?string,origem:int|string|null,tributacao_icms:?string} $dados */
    public function executar(Usuario $autor, array $dados): Produto
    {
        return DB::transaction(function () use ($autor, $dados): Produto {
            /** @var Tenant $tenant */
            $tenant = Tenant::query()->with('plano.limites')->lockForUpdate()->findOrFail($autor->tenant_id);
            $this->entitlements->garantirCapacidade($tenant, Recurso::Produtos);

            return Produto::create([
                'sku' => $dados['sku'],
                'nome' => $dados['nome'],
                'unidade' => $dados['unidade'],
                'preco_centavos' => $dados['preco_centavos'],
                'gtin' => $dados['gtin'],
                'ncm' => ValidadorCamposFiscais::ncm($dados['ncm']),
                'cest' => ValidadorCamposFiscais::cest($dados['cest']),
                'origem' => ValidadorCamposFiscais::origem($dados['origem']),
                'tributacao_icms' => $dados['tributacao_icms'],
                'fiscal_fonte' => FonteFiscal::Manual,
            ]);
        });
    }
}
