import { useQuery } from '@tanstack/react-query';
import { dashboardFiscalApi } from '../api';
import type { DashboardFiscal } from '../types';

export const QUERY_KEY_DASHBOARD_FISCAL = ['dashboard-fiscal'] as const;

export function useDashboardFiscal() {
  return useQuery<DashboardFiscal>({
    queryKey: QUERY_KEY_DASHBOARD_FISCAL,
    queryFn: async () => (await dashboardFiscalApi.buscar()).data,
  });
}
