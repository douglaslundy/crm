<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Emissao;

use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\Models\NotaItem;
use App\Modules\Fiscal\Domain\Models\NotaPagamento;
use App\Modules\Fiscal\Domain\Resolvers\CrtResolver;
use App\Modules\Tenancy\Domain\Models\Tenant;
use LogicException;

/** Retrato imutável da nota, lido só do snapshot congelado. É o que o motor recebe. */
final readonly class NotaParaEmissao
{
    /**
     * @param  list<ItemParaEmissao>  $itens
     * @param  list<PagamentoParaEmissao>  $pagamentos
     */
    public function __construct(
        public string $notaId,
        public string $ambiente,
        public string $serie,
        public int $numero,
        public string $naturezaOperacao,
        public int $idDest,
        public EmitenteParaEmissao $emitente,
        public DestinatarioParaEmissao $destinatario,
        public array $itens,
        public array $pagamentos,
        public int $subtotalCentavos,
        public int $descontoCentavos,
        public int $totalCentavos,
        public ?string $informacoesComplementares,
    ) {}

    public static function deNota(Nota $nota, Emitente $emitente, Tenant $tenant): self
    {
        $d = $nota->destinatario ?? throw new LogicException('Nota sem snapshot do destinatário: prepare antes de emitir.');
        $crt = (new CrtResolver)->crt($emitente->regime_tributario);

        $itens = $nota->itens()->get()->map(fn (NotaItem $i): ItemParaEmissao => new ItemParaEmissao(
            $i->ordem, (string) $i->sku, (string) $i->descricao, (string) $i->unidade, $i->gtin, (string) $i->ncm, $i->cest,
            (string) $i->cfop, (int) $i->origem, (string) $i->csosn, $i->quantidade_milesimos, $i->valor_unitario_centavos,
            $i->desconto_centavos, $i->total_centavos,
        ))->values()->all();
        $pagamentos = $nota->pagamentos()->get()->map(fn (NotaPagamento $p): PagamentoParaEmissao => new PagamentoParaEmissao(
            $p->tpag, $p->xpag, $p->valor_centavos,
        ))->values()->all();

        return new self(
            $nota->id, (string) $nota->ambiente, (string) $nota->serie, (int) $nota->numero, $nota->natureza_operacao,
            strtoupper((string) $emitente->uf) === $d['uf'] ? 1 : 2,
            new EmitenteParaEmissao(
                preg_replace('/\D/', '', $tenant->cnpj) ?? '', $tenant->razao_social, $tenant->nome_fantasia,
                preg_replace('/\D/', '', (string) $emitente->inscricao_estadual) ?? '', $crt,
                (string) $emitente->logradouro, (string) $emitente->numero, (string) $emitente->bairro, (string) $emitente->codigo_ibge,
                (string) $emitente->cidade, strtoupper((string) $emitente->uf), (string) $emitente->cep, $emitente->cnae,
            ),
            new DestinatarioParaEmissao(
                $d['tipo'], $d['documento'], $d['nome'], $d['ind_ie_dest'], $d['ind_final'], $d['ie'], $d['logradouro'], $d['numero'],
                $d['bairro'], $d['codigo_ibge'], $d['cidade'], $d['uf'], $d['cep'], $d['email'],
            ),
            $itens, $pagamentos, $nota->subtotal_centavos, $nota->desconto_centavos, $nota->total_centavos, $nota->informacoes_complementares,
        );
    }
}
