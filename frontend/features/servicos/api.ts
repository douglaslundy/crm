import { api } from '@/lib/api';
import type { ServicoFormDados } from './schemas';
import type { Servico, ServicoPayload } from './types';

export const QUERY_KEY_SERVICOS = ['servicos'] as const;

export function paraPayload(d: ServicoFormDados): ServicoPayload {
  return {
    nome: d.nome,
    preco_centavos: d.preco_centavos,
    codigo_lc116: d.codigo_lc116 || null,
    c_trib_nac: d.c_trib_nac || null,
    codigo_municipal: d.codigo_municipal || null,
    aliquota_iss: d.aliquota_iss || null,
    nbs: d.nbs || null,
  };
}

export const servicosApi = {
  listar: () => api<{ data: Servico[] }>('/api/app/servicos'),
  criar: (dados: ServicoPayload) => api<{ data: Servico }>('/api/app/servicos', { method: 'POST', body: JSON.stringify(dados) }),
  editar: (id: string, dados: ServicoPayload) => api<{ data: Servico }>(`/api/app/servicos/${id}`, { method: 'PUT', body: JSON.stringify(dados) }),
};
