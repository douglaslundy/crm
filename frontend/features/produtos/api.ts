import { api } from '@/lib/api';
import type { CategoriaFiscalFormDados, ProdutoFormDados } from './schemas';
import type { CategoriaFiscalPadrao, Produto, ProdutoPayload } from './types';

export const QUERY_KEY_PRODUTOS = ['produtos'] as const;
export const QUERY_KEY_CATEGORIAS_FISCAIS = ['categorias-fiscais-padrao'] as const;

/** Converte os campos opcionais do formulário (string) para o formato que a API espera (null quando vazio). */
export function paraPayload(d: ProdutoFormDados): ProdutoPayload {
  return {
    sku: d.sku,
    nome: d.nome,
    unidade: d.unidade.toUpperCase(),
    preco_centavos: d.preco_centavos,
    gtin: d.gtin || null,
    ncm: d.ncm || null,
    cest: d.cest || null,
    origem: d.origem === '' ? null : Number(d.origem),
    tributacao_icms: d.tributacao_icms || null,
  };
}

export const produtosApi = {
  listar: () => api<{ data: Produto[] }>('/api/app/produtos'),
  criar: (dados: ProdutoPayload) => api<{ data: Produto }>('/api/app/produtos', { method: 'POST', body: JSON.stringify(dados) }),
  editar: (id: string, dados: ProdutoPayload) => api<{ data: Produto }>(`/api/app/produtos/${id}`, { method: 'PUT', body: JSON.stringify(dados) }),
  aplicarCategoria: (produtoId: string, categoriaId: string) =>
    api<{ data: Produto }>(`/api/app/produtos/${produtoId}/aplicar-categoria/${categoriaId}`, { method: 'POST' }),
};

export const categoriasFiscaisApi = {
  listar: () => api<{ data: CategoriaFiscalPadrao[] }>('/api/app/categorias-fiscais-padrao'),
  criar: (d: CategoriaFiscalFormDados) =>
    api<{ data: CategoriaFiscalPadrao }>('/api/app/categorias-fiscais-padrao', {
      method: 'POST',
      body: JSON.stringify({ categoria: d.categoria, ncm: d.ncm || null, origem: d.origem === '' ? null : Number(d.origem), tributacao_icms: d.tributacao_icms || null }),
    }),
};
