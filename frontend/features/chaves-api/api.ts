import { api } from '@/lib/api';
import type { ChaveDados } from './schemas';
import type { ChaveCriada, ChaveDeApi } from './types';

export const QUERY_KEY_CHAVES_API = ['chaves-api'] as const;

export const chavesApi = {
  listar: () => api<{ data: ChaveDeApi[] }>('/api/app/chaves-api'),
  criar: (dados: ChaveDados) => api<{ data: ChaveCriada }>('/api/app/chaves-api', { method: 'POST', body: JSON.stringify(dados) }),
  revogar: (id: string) => api<{ data: ChaveDeApi }>(`/api/app/chaves-api/${id}`, { method: 'DELETE' }),
};
