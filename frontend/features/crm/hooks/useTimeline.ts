import { useInfiniteQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { ApiError } from '@/lib/api';
import { crmApi, QUERY_KEY_CRM } from '../api';

export function useTimelineDoCliente(clienteId: string) {
  return useInfiniteQuery({
    queryKey: [...QUERY_KEY_CRM, 'timeline', clienteId],
    queryFn: ({ pageParam }) => crmApi.timeline(clienteId, pageParam),
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (ultima) => ultima.next_cursor ?? undefined,
  });
}

export function useCriarNota(clienteId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (texto: string) => crmApi.criarNota(clienteId, texto),
    onSuccess: () => {
      toast.success('Nota registrada.');
      void queryClient.invalidateQueries({ queryKey: [...QUERY_KEY_CRM, 'timeline', clienteId] });
    },
    onError: (erro) => toast.error(erro instanceof ApiError ? erro.message : 'Não foi possível salvar a nota.'),
  });
}
