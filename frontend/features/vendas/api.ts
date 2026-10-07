import { api } from '@/lib/api';
import type { ResultadoDaEmissaoDaVenda, Venda, VendaPayload } from './types';

export const QUERY_KEY_VENDAS = ['vendas'] as const;

export const vendasApi = {
  buscar: (id: string) => api<{ data: Venda }>(`/api/app/vendas/${id}`),
  criar: (dados: VendaPayload) => api<{ data: Venda }>('/api/app/vendas', { method: 'POST', body: JSON.stringify(dados) }),
  editar: (id: string, dados: VendaPayload) => api<{ data: Venda }>(`/api/app/vendas/${id}`, { method: 'PUT', body: JSON.stringify(dados) }),
  excluir: (id: string) => api<void>(`/api/app/vendas/${id}`, { method: 'DELETE' }),
  emitir: (id: string, apenasValidas: boolean) =>
    api<{ data: ResultadoDaEmissaoDaVenda }>(`/api/app/vendas/${id}/emitir`, { method: 'POST', body: JSON.stringify({ emitir_apenas_validas: apenasValidas }) }),
};
