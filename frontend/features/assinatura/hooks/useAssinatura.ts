import { useQuery } from '@tanstack/react-query';
import { assinaturaApi } from '../api';
import type { Assinatura } from '../types';

export const QUERY_KEY_ASSINATURA = ['assinatura'] as const;

export function useAssinatura() {
  return useQuery<Assinatura>({
    queryKey: QUERY_KEY_ASSINATURA,
    queryFn: async () => (await assinaturaApi.buscar()).data,
  });
}
