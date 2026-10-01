export type TributacaoIcms = 'NORMAL' | 'ST';
export type FonteFiscal = 'MANUAL' | 'PADRAO';

export interface Produto {
  id: string;
  sku: string;
  nome: string;
  unidade: string;
  preco_centavos: number;
  gtin: string | null;
  ncm: string | null;
  cest: string | null;
  origem: number | null;
  tributacao_icms: TributacaoIcms | null;
  fiscal_fonte: FonteFiscal;
  fiscal_revisado_em: string | null;
  pendente_fiscal: boolean;
}

export interface ProdutoPayload {
  sku: string;
  nome: string;
  unidade: string;
  preco_centavos: number;
  gtin: string | null;
  ncm: string | null;
  cest: string | null;
  origem: number | null;
  tributacao_icms: TributacaoIcms | null;
}

export interface CategoriaFiscalPadrao {
  id: string;
  categoria: string;
  ncm: string | null;
  origem: number | null;
  tributacao_icms: TributacaoIcms | null;
}
