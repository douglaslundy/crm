import type { Plano } from '@/features/assinatura/types';
import type { SituacaoAssinatura } from '@/features/auth/types';
import { api } from '@/lib/api';
import type { Empresa, FiltrosEmpresas, Metricas, PaginaCursor, PlanoEntrada } from './types';

const json = (metodo: string, corpo?: unknown): RequestInit => ({
  method: metodo,
  body: corpo === undefined ? undefined : JSON.stringify(corpo),
});

export const adminApi = {
  metricas: () => api<{ data: Metricas }>('/api/admin/metricas'),
  planos: () => api<{ data: Plano[] }>('/api/admin/planos'),
  plano: (id: string) => api<{ data: Plano }>(`/api/admin/planos/${id}`),
  criarPlano: (dados: PlanoEntrada) => api<{ data: Plano }>('/api/admin/planos', json('POST', dados)),
  atualizarPlano: (id: string, dados: PlanoEntrada) => api<{ data: Plano }>(`/api/admin/planos/${id}`, json('PUT', dados)),
  desativarPlano: (id: string) => api<{ data: Plano }>(`/api/admin/planos/${id}/desativar`, json('POST')),
  empresas: (filtros: FiltrosEmpresas, cursor?: string) => {
    const params = new URLSearchParams();
    for (const [chave, valor] of Object.entries({ ...filtros, cursor })) {
      if (valor) params.set(chave, valor);
    }
    const qs = params.toString();
    return api<PaginaCursor<Empresa>>(`/api/admin/empresas${qs ? `?${qs}` : ''}`);
  },
  empresa: (id: string) => api<{ data: Empresa }>(`/api/admin/empresas/${id}`),
  mudarSituacao: (id: string, dados: { situacao: SituacaoAssinatura; motivo: string }) =>
    api<{ data: Empresa }>(`/api/admin/empresas/${id}/situacao`, json('POST', dados)),
  trocarPlano: (id: string, dados: { plano_id: string }) =>
    api<{ data: Empresa }>(`/api/admin/empresas/${id}/plano`, json('POST', dados)),
};
