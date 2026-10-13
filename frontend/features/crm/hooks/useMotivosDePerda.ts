import { useQuery } from '@tanstack/react-query';
import { crmApi, QUERY_KEY_CRM } from '../api';
import type { MotivoDePerda } from '../types';

export const QUERY_KEY_MOTIVOS = [...QUERY_KEY_CRM, 'motivos'] as const;

export function useMotivosDePerda() {
  return useQuery<MotivoDePerda[]>({ queryKey: QUERY_KEY_MOTIVOS, queryFn: async () => (await crmApi.motivos()).data });
}
