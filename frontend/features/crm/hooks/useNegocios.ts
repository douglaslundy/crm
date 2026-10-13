import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { ApiError } from '@/lib/api';
import { crmApi, QUERY_KEY_CRM } from '../api';
import { aplicarMovimento } from '../movimento';
import type { Negocio, StatusDoNegocio } from '../types';

export const QUERY_KEY_NEGOCIOS = [...QUERY_KEY_CRM, 'negocios'] as const;

export const chaveDosNegocios = (funilId: string, status: StatusDoNegocio) =>
  [...QUERY_KEY_NEGOCIOS, funilId, status] as const;

export function useNegocios(funilId: string | undefined, status: StatusDoNegocio) {
  return useQuery<Negocio[]>({
    queryKey: chaveDosNegocios(funilId ?? '', status),
    queryFn: async () => (await crmApi.negocios(funilId ?? '', status)).data,
    enabled: funilId !== undefined,
  });
}

/** Move com atualização otimista; em erro volta ao estado anterior e avisa. */
export function useMoverNegocio(funilId: string) {
  const queryClient = useQueryClient();
  const chave = chaveDosNegocios(funilId, 'ABERTO');

  return useMutation({
    mutationFn: ({ id, etapaId, posicao }: { id: string; etapaId: string; posicao: number }) =>
      crmApi.mover(id, etapaId, posicao),
    onMutate: async ({ id, etapaId, posicao }) => {
      await queryClient.cancelQueries({ queryKey: chave });
      const anterior = queryClient.getQueryData<Negocio[]>(chave);
      if (anterior) queryClient.setQueryData(chave, aplicarMovimento(anterior, id, etapaId, posicao));
      return { anterior };
    },
    onError: (erro, _vars, contexto) => {
      if (contexto?.anterior) queryClient.setQueryData(chave, contexto.anterior);
      toast.error(erro instanceof ApiError ? erro.message : 'Não foi possível mover o negócio.');
    },
    onSettled: () => queryClient.invalidateQueries({ queryKey: chave }),
  });
}
