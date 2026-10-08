import { api } from '@/lib/api';
import type { AssinaturaDeCobranca, CheckoutPayload, CheckoutResultado, Cobranca, PagarPayload, TrocaDePlanoResultado } from './types';

export const QUERY_KEY_FATURAS = ['faturas'] as const;

export const cobrancaApi = {
  checkout: (dados: CheckoutPayload) => api<{ data: CheckoutResultado }>('/api/app/assinatura/checkout', { method: 'POST', body: JSON.stringify(dados) }),
  faturas: () => api<{ data: Cobranca[] }>('/api/app/assinatura/cobrancas'),
  pagar: (id: string, dados: PagarPayload) => api<{ data: Cobranca }>(`/api/app/assinatura/cobrancas/${id}/pagar`, { method: 'POST', body: JSON.stringify(dados) }),
  trocarPlano: (planoId: string) => api<{ data: TrocaDePlanoResultado }>('/api/app/assinatura/trocar-plano', { method: 'POST', body: JSON.stringify({ plano_id: planoId }) }),
  cancelarTroca: () => api<{ data: AssinaturaDeCobranca }>('/api/app/assinatura/trocar-plano', { method: 'DELETE' }),
  cancelar: () => api<{ data: AssinaturaDeCobranca }>('/api/app/assinatura/cancelar', { method: 'POST' }),
};
