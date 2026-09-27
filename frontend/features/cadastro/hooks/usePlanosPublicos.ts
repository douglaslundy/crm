import { useQuery } from '@tanstack/react-query';
import type { Plano } from '@/features/assinatura/types';
import { cadastroApi } from '../api';

export function usePlanosPublicos() {
  return useQuery<Plano[]>({
    queryKey: ['planos-publicos'],
    queryFn: async () => (await cadastroApi.planos()).data,
    staleTime: 10 * 60 * 1000,
  });
}
