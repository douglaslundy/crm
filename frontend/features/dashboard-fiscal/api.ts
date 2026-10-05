import { api } from '@/lib/api';
import type { DashboardFiscal } from './types';

export const dashboardFiscalApi = {
  buscar: () => api<{ data: DashboardFiscal }>('/api/app/dashboard/fiscal'),
};
