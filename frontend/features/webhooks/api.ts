import { api } from '@/lib/api';
import type { WebhookDados } from './schemas';
import type { Entrega, Webhook, WebhookCriado } from './types';

export const QUERY_KEY_WEBHOOKS = ['webhooks'] as const;
export const queryKeyEntregas = (webhookId: string) => ['webhooks', webhookId, 'entregas'] as const;

export const webhooksApi = {
  listar: () => api<{ data: Webhook[] }>('/api/app/webhooks'),
  criar: (dados: WebhookDados) =>
    api<{ data: WebhookCriado }>('/api/app/webhooks', {
      method: 'POST',
      body: JSON.stringify({ ...dados, descricao: dados.descricao === '' ? null : dados.descricao }),
    }),
  atualizar: (id: string, dados: { ativo: boolean }) => api<{ data: Webhook }>(`/api/app/webhooks/${id}`, { method: 'PUT', body: JSON.stringify(dados) }),
  excluir: (id: string) => api<void>(`/api/app/webhooks/${id}`, { method: 'DELETE' }),
  entregas: (id: string) => api<{ data: Entrega[] }>(`/api/app/webhooks/${id}/entregas?limite=50`),
  testar: (id: string) => api<{ data: Entrega }>(`/api/app/webhooks/${id}/testar`, { method: 'POST' }),
  reenviar: (entregaId: string) => api<{ data: Entrega }>(`/api/app/webhooks/entregas/${entregaId}/reenviar`, { method: 'POST' }),
};
