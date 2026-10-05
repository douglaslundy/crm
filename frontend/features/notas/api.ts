import { api } from '@/lib/api';
import type { Inutilizacao, InutilizacaoPayload, Nota, NotaPayload, NotaStatus, StatusNota } from './types';

export const QUERY_KEY_NOTAS = ['notas'] as const;

export const notasApi = {
  listar: (status?: StatusNota) => api<{ data: Nota[] }>(`/api/app/notas${status ? `?status=${status}` : ''}`),
  buscar: (id: string) => api<{ data: Nota }>(`/api/app/notas/${id}`),
  status: (id: string) => api<{ data: NotaStatus }>(`/api/app/notas/${id}/status`),
  criar: (dados: NotaPayload) => api<{ data: Nota }>('/api/app/notas', { method: 'POST', body: JSON.stringify(dados) }),
  editar: (id: string, dados: NotaPayload) => api<{ data: Nota }>(`/api/app/notas/${id}`, { method: 'PUT', body: JSON.stringify(dados) }),
  excluir: (id: string) => api<void>(`/api/app/notas/${id}`, { method: 'DELETE' }),
  emitir: (id: string) => api<{ data: NotaStatus }>(`/api/app/notas/${id}/emitir`, { method: 'POST' }),
  retransmitir: (id: string) => api<{ data: NotaStatus }>(`/api/app/notas/${id}/retransmitir`, { method: 'POST' }),
  cancelar: (id: string, justificativa: string) =>
    api<{ data: NotaStatus }>(`/api/app/notas/${id}/cancelar`, { method: 'POST', body: JSON.stringify({ justificativa }) }),
};

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000';

/** O download é uma navegação com cookie de sessão, não um fetch JSON. */
export const urlDoXml = (id: string): string => `${API_URL}/api/app/notas/${id}/xml`;

export const urlDoXmlCancelamento = (id: string): string => `${API_URL}/api/app/notas/${id}/xml-cancelamento`;
export const urlDoPdf = (id: string): string => `${API_URL}/api/app/notas/${id}/pdf`;

export const QUERY_KEY_INUTILIZACOES = ['inutilizacoes'] as const;

export const inutilizacoesApi = {
  listar: () => api<{ data: Inutilizacao[] }>('/api/app/inutilizacoes'),
  criar: (dados: InutilizacaoPayload) => api<{ data: Inutilizacao }>('/api/app/inutilizacoes', { method: 'POST', body: JSON.stringify(dados) }),
};
