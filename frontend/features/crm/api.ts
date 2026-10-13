import { api } from '@/lib/api';
import type {
  ConfiguracaoDoCrm, Etapa, Funil, ItemEntrada, MotivoDePerda, Negocio, NovoNegocioPayload, StatusDoNegocio,
} from './types';

export const QUERY_KEY_CRM = ['crm'] as const;

const BASE = '/api/app/crm';
const json = (corpo: unknown) => JSON.stringify(corpo);

export const crmApi = {
  funis: () => api<{ data: Funil[] }>(`${BASE}/funis`),
  criarFunil: (nome: string) => api<{ data: Funil }>(`${BASE}/funis`, { method: 'POST', body: json({ nome }) }),
  editarFunil: (id: string, dados: { nome?: string; ativo?: boolean }) =>
    api<{ data: Funil }>(`${BASE}/funis/${id}`, { method: 'PUT', body: json(dados) }),
  criarEtapa: (funilId: string, dados: { nome: string; probabilidade: number }) =>
    api<{ data: Etapa }>(`${BASE}/funis/${funilId}/etapas`, { method: 'POST', body: json(dados) }),
  editarEtapa: (id: string, dados: { nome?: string; probabilidade?: number; ativa?: boolean }) =>
    api<{ data: Etapa }>(`${BASE}/etapas/${id}`, { method: 'PUT', body: json(dados) }),
  ordenarEtapas: (funilId: string, etapas: string[]) =>
    api<{ data: Funil }>(`${BASE}/funis/${funilId}/etapas/ordem`, { method: 'PUT', body: json({ etapas }) }),

  motivos: () => api<{ data: MotivoDePerda[] }>(`${BASE}/motivos-de-perda`),
  criarMotivo: (nome: string) => api<{ data: MotivoDePerda }>(`${BASE}/motivos-de-perda`, { method: 'POST', body: json({ nome }) }),
  editarMotivo: (id: string, dados: { nome?: string; ativo?: boolean }) =>
    api<{ data: MotivoDePerda }>(`${BASE}/motivos-de-perda/${id}`, { method: 'PUT', body: json(dados) }),

  configuracao: () => api<{ data: ConfiguracaoDoCrm }>(`${BASE}/configuracao`),
  salvarConfiguracao: (vendedor_ve_so_os_seus: boolean) =>
    api<{ data: ConfiguracaoDoCrm }>(`${BASE}/configuracao`, { method: 'PUT', body: json({ vendedor_ve_so_os_seus }) }),

  negocios: (funilId: string, status: StatusDoNegocio) =>
    api<{ data: Negocio[] }>(`${BASE}/negocios?funil_id=${funilId}&status=${status}`),
  negocio: (id: string) => api<{ data: Negocio }>(`${BASE}/negocios/${id}`),
  criarNegocio: (dados: NovoNegocioPayload) => api<{ data: Negocio }>(`${BASE}/negocios`, { method: 'POST', body: json(dados) }),
  editarNegocio: (id: string, dados: { titulo?: string; previsao_fechamento?: string | null; valor_centavos?: number; responsavel_id?: string }) =>
    api<{ data: Negocio }>(`${BASE}/negocios/${id}`, { method: 'PUT', body: json(dados) }),
  definirItens: (id: string, itens: ItemEntrada[]) =>
    api<{ data: Negocio }>(`${BASE}/negocios/${id}/itens`, { method: 'PUT', body: json({ itens }) }),
  mover: (id: string, etapaId: string, posicao: number) =>
    api<{ data: Negocio }>(`${BASE}/negocios/${id}/mover`, { method: 'POST', body: json({ etapa_id: etapaId, posicao }) }),
  ganhar: (id: string) => api<{ data: Negocio }>(`${BASE}/negocios/${id}/ganhar`, { method: 'POST' }),
  perder: (id: string, motivoId: string, observacao?: string) =>
    api<{ data: Negocio }>(`${BASE}/negocios/${id}/perder`, {
      method: 'POST',
      body: json({ motivo_de_perda_id: motivoId, observacao: observacao || undefined }),
    }),
};
