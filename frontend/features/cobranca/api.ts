import { api } from '@/lib/api';
import type { AssinaturaDeCobranca, CheckoutPayload, CheckoutResultado, Cobranca } from './types';

export const QUERY_KEY_FATURAS = ['faturas'] as const;

export const cobrancaApi = {
  checkout: (dados: CheckoutPayload) => api<{ data: CheckoutResultado }>('/api/app/assinatura/checkout', { method: 'POST', body: JSON.stringify(dados) }),
  faturas: () => api<{ data: Cobranca[] }>('/api/app/assinatura/cobrancas'),
  cancelar: () => api<{ data: AssinaturaDeCobranca }>('/api/app/assinatura/cancelar', { method: 'POST' }),
};
