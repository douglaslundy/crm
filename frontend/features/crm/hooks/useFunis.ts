import { useQuery } from '@tanstack/react-query';
import { crmApi, QUERY_KEY_CRM } from '../api';
import type { Funil } from '../types';

export const QUERY_KEY_FUNIS = [...QUERY_KEY_CRM, 'funis'] as const;

export function useFunis() {
  return useQuery<Funil[]>({ queryKey: QUERY_KEY_FUNIS, queryFn: async () => (await crmApi.funis()).data });
}
