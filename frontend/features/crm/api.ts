import { api } from '@/lib/api';
import type {
  Atividade, ConfiguracaoDoCrm, Etapa, Funil, ItemEntrada, MotivoDePerda, Negocio, NovaAtividadePayload,
  NovaPropostaPayload, NovoNegocioPayload, Proposta, RegistroDaTimeline, ResumoDoCrm, StatusDoNegocio,
} from './types';

export const QUERY_KEY_CRM = ['crm'] as const;

const BASE = '/api/app/crm';
const json = (corpo: unknown) => JSON.stringify(corpo);

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000';

/** O PDF abre em outra aba: a sessão vai pelo cookie. */
export const urlDoPdfDaProposta = (id: string) => `${API_URL}${BASE}/propostas/${id}/pdf`;

export const crmApi = {
  funis: () => api<{ data: Funil[] }>(`${BASE}/funis`),
  criarFunil: (nome: string) => api<{ data: Funil }>(`${BASE}/funis`, { method: 'POST', body: json({ nome }) }),
  editarFunil: (id: string, dados: { nome?: string; ativo?: boolean }) =>
    api<{ data: Funil }>(`${BASE}/funis/${id}`, { method: 'PUT', body: json(dados) }),
  criarEtapa: (funilId: string, dados: { nome: string; probabilidade: number }) =>
    api<{ data: Etapa }>(`${BASE}/funis/${funilId}/etapas`, { method: 'POST', body: json(dados) }),
  editarEtapa: (id: string, dados: { nome?: string; probabilidade?: number; ativa?: boolean; dias_para_parado?: number | null }) =>
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
  atividades: (filtros: { cliente_id?: string; negocio_id?: string; concluida?: boolean }) => {
    const q = new URLSearchParams();
    if (filtros.cliente_id) q.set('cliente_id', filtros.cliente_id);
    if (filtros.negocio_id) q.set('negocio_id', filtros.negocio_id);
    if (filtros.concluida !== undefined) q.set('concluida', filtros.concluida ? '1' : '0');
    return api<{ data: Atividade[] }>(`${BASE}/atividades?${q.toString()}`);
  },
  agenda: (de: string, ate: string, responsavelId?: string) => {
    const q = new URLSearchParams({ de, ate });
    if (responsavelId) q.set('responsavel_id', responsavelId);
    return api<{ data: Atividade[] }>(`${BASE}/agenda?${q.toString()}`);
  },
  resumo: () => api<{ data: ResumoDoCrm }>(`${BASE}/resumo`),
  criarAtividade: (dados: NovaAtividadePayload) =>
    api<{ data: Atividade }>(`${BASE}/atividades`, { method: 'POST', body: json(dados) }),
  editarAtividade: (id: string, dados: Partial<Omit<NovaAtividadePayload, 'cliente_id' | 'negocio_id'>>) =>
    api<{ data: Atividade }>(`${BASE}/atividades/${id}`, { method: 'PUT', body: json(dados) }),
  concluirAtividade: (id: string) => api<{ data: Atividade }>(`${BASE}/atividades/${id}/concluir`, { method: 'POST' }),
  reabrirAtividade: (id: string) => api<{ data: Atividade }>(`${BASE}/atividades/${id}/reabrir`, { method: 'POST' }),
  excluirAtividade: (id: string) => api<void>(`${BASE}/atividades/${id}`, { method: 'DELETE' }),
  registrarContato: (canal: 'WHATSAPP' | 'EMAIL', alvo: { cliente_id?: string; negocio_id?: string }) =>
    api<{ data: { atividade: Atividade; url: string } }>(`${BASE}/contatos`, { method: 'POST', body: json({ canal, ...alvo }) }),
  timeline: (clienteId: string, cursor?: string) =>
    api<{ data: RegistroDaTimeline[]; next_cursor: string | null }>(
      `${BASE}/clientes/${clienteId}/timeline${cursor ? `?cursor=${encodeURIComponent(cursor)}` : ''}`,
    ),
  criarNota: (clienteId: string, texto: string) =>
    api<{ data: RegistroDaTimeline }>(`${BASE}/clientes/${clienteId}/notas`, { method: 'POST', body: json({ texto }) }),

  propostas: (negocioId: string) => api<{ data: Proposta[] }>(`${BASE}/negocios/${negocioId}/propostas`),
  criarProposta: (negocioId: string, dados: NovaPropostaPayload) =>
    api<{ data: Proposta }>(`${BASE}/negocios/${negocioId}/propostas`, { method: 'POST', body: json(dados) }),
  enviarProposta: (id: string) => api<{ data: Proposta }>(`${BASE}/propostas/${id}/enviar`, { method: 'POST' }),
  cancelarProposta: (id: string) => api<{ data: Proposta }>(`${BASE}/propostas/${id}/cancelar`, { method: 'POST' }),
  gerarNotaDaProposta: (id: string) => api<{ data: Proposta }>(`${BASE}/propostas/${id}/nota`, { method: 'POST' }),
};
