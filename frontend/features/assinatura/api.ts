import { api } from '@/lib/api';
import type { Assinatura } from './types';

export const assinaturaApi = {
  buscar: () => api<{ data: Assinatura }>('/api/app/assinatura'),
};
