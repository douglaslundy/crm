import { useQuery } from '@tanstack/react-query';
import { authApi } from '../api';
import type { Usuario } from '../types';

export const QUERY_KEY_USUARIO = ['me'] as const;

export function useUsuario() {
  return useQuery<Usuario>({
    queryKey: QUERY_KEY_USUARIO,
    queryFn: async () => (await authApi.me()).data,
    staleTime: 5 * 60 * 1000,
    // Papel, situação da assinatura ou desativação mudam no servidor: revalida ao voltar para a aba.
    refetchOnWindowFocus: 'always',
  });
}
