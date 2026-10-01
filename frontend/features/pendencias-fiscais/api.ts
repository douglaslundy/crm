import { api } from '@/lib/api';
import type { Produto } from '@/features/produtos/types';
import type { Servico } from '@/features/servicos/types';

export const QUERY_KEY_PENDENCIAS_FISCAIS = ['pendencias-fiscais'] as const;

export const pendenciasFiscaisApi = {
  listar: () => api<{ data: { produtos: Produto[]; servicos: Servico[] } }>('/api/app/produtos/pendencias-fiscais'),
  marcarRevisado: (produtoId: string) => api<{ data: Produto }>(`/api/app/produtos/${produtoId}/marcar-revisado`, { method: 'POST' }),
};
